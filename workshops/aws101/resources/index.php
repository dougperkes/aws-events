<?php
// Load the real AWS SDK first so we can detect whether it is available
if (file_exists(__DIR__ . '/sdk/aws-autoloader.php')) {
    require __DIR__ . '/sdk/aws-autoloader.php';
}

// Mock S3 client - only defined (and used) when the real SDK is NOT available
if (!class_exists('Aws\S3\S3Client') && !class_exists('S3Client')) {
    class S3Client {
        public function __construct($options) {}
        public function listObjectsV2($params) {
            return [
                'Contents' => [
                    ['Key' => 'example1.txt', 'Size' => 1024],
                    ['Key' => 'example2.txt', 'Size' => 2048],
                    ['Key' => 'example-folder/file3.txt', 'Size' => 3072]
                ]
            ];
        }
    }
}

// Fetch EC2 instance metadata (IMDSv2)
function getInstanceMetadata($path) {
    // Get token first (IMDSv2)
    $tokenCurl = curl_init();
    curl_setopt_array($tokenCurl, [
        CURLOPT_URL => 'http://169.254.169.254/latest/api/token',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['X-aws-ec2-metadata-token-ttl-seconds: 60'],
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_TIMEOUT => 2,
    ]);
    $token = curl_exec($tokenCurl);
    curl_close($tokenCurl);

    if (empty($token)) return 'N/A';

    // Use token to get metadata
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => 'http://169.254.169.254/latest/meta-data/' . $path,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['X-aws-ec2-metadata-token: ' . $token],
        CURLOPT_TIMEOUT => 2,
    ]);
    $result = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    return ($httpCode === 200 && $result) ? $result : 'N/A';
}

// Cache metadata values
$instanceId = getInstanceMetadata('instance-id');
$availabilityZone = getInstanceMetadata('placement/availability-zone');
$privateIp = getInstanceMetadata('local-ipv4');
$instanceType = getInstanceMetadata('instance-type');
$region = getInstanceMetadata('placement/region');

// External API used by Step 3. The base URL and method are fixed server-side;
// only the resource (path + query string) can be changed by the user. This
// stops the form being used to make the instance call arbitrary hosts
// (e.g. the instance metadata endpoint at 169.254.169.254).
const API_BASE_URL = 'https://api.worldbank.org';
const API_HOST = 'api.worldbank.org';
const API_METHOD = 'GET';
const API_DEFAULT_RESOURCE = '/v2/region/ECS/country?format=json&per_page=100';
const API_MAX_RESOURCE_LENGTH = 512;
const API_TIMEOUT_SECONDS = 10;

$exampleApis = array(
    array(
        'name' => 'World Bank Countries API Test',
        'url' => API_BASE_URL,
        'method' => API_METHOD,
        'resource' => API_DEFAULT_RESOURCE,
        'description' => 'Testing API connectivity by retrieving Europe & Central Asia countries from the World Bank API'
    )
);

// Example resources shown as hints under the API Resource field
$exampleResources = array(
    '/v2/region/ECS/country?format=json&per_page=100' => 'Europe & Central Asia countries',
    '/v2/region/NAC/country?format=json' => 'North America countries',
    '/v2/country/DE?format=json' => 'A single country (Germany)',
    '/v2/country/US/indicator/SP.POP.TOTL?format=json&mrv=5' => 'US population, last 5 years',
);

// Validate a user-supplied API resource and build the full URL on the fixed host.
// Returns the URL, or null if the resource is not acceptable.
function buildApiUrl($resource) {
    $resource = trim((string) $resource);

    // Must be a relative path: starts with a single '/', no whitespace/control chars.
    // Rejecting '//' and '@' prevents tricks like '//evil.com' or '@evil.com/' that
    // would change the host once concatenated onto the base URL.
    if ($resource === '' || strlen($resource) > API_MAX_RESOURCE_LENGTH
        || $resource[0] !== '/' || strpos($resource, '//') === 0
        || strpos($resource, '@') !== false || strpos($resource, '\\') !== false
        || preg_match('/[\s\x00-\x1F\x7F]/', $resource)) {
        return null;
    }

    // The World Bank API returns XML by default; ask for JSON if not specified
    if (stripos($resource, 'format=') === false) {
        $resource .= (strpos($resource, '?') === false ? '?' : '&') . 'format=json';
    }

    $url = API_BASE_URL . $resource;

    // Belt and braces: confirm the final URL still points at the expected host
    $parts = parse_url($url);
    if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== API_HOST
        || isset($parts['user']) || isset($parts['port'])) {
        return null;
    }
    return $url;
}

// Shorthand for HTML-escaping values from external sources
function h($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Function to populate API input form from example API
function populateApiInput($exampleApi) {
    echo '<form method="post">';
    echo '<label for="apiUrl">API Invoke URL:</label>';
    echo '<input type="text" id="apiUrl" name="apiUrl" value="' . h($exampleApi['url']) . '" readonly>';
    echo '<label for="httpMethod">HTTP Method:</label>';
    echo '<input type="text" id="httpMethod" name="httpMethod" value="' . h($exampleApi['method']) . '" readonly>';
    echo '<label for="apiResource">API Resource:</label>';
    echo '<input type="text" id="apiResource" name="apiResource" value="' . h($exampleApi['resource']) . '" required>';
    echo '<button type="submit" class="workshop-btn">';
    echo '<i class="fas fa-paper-plane"></i> Test API Connection';
    echo '</button>';
    echo '</form>';
}

// Render a World Bank API response.
// Success shape: [ {page, pages, per_page, total, ...}, [ records... ] ]
// Error shape:   [ {"message": [ {id, key, value} ]} ]
function displayJson($json) {
    if (!is_array($json) || !isset($json[0]) || !is_array($json[0])) {
        echo '<p>Error: unexpected response format from the API.</p>';
        return;
    }

    if (isset($json[0]['message'])) {
        foreach ((array) $json[0]['message'] as $msg) {
            echo '<p>API error: ' . h(($msg['key'] ?? '') . ' - ' . ($msg['value'] ?? '')) . '</p>';
        }
        return;
    }

    $paging = $json[0];
    $records = (isset($json[1]) && is_array($json[1])) ? $json[1] : array();

    echo '<p>Returned ' . count($records) . ' of ' . h($paging['total'] ?? count($records))
        . ' records (page ' . h($paging['page'] ?? 1) . ' of ' . h($paging['pages'] ?? 1) . ').</p>';

    foreach ($records as $record) {
        echo '<div class="response-container">';
        echo '<table>';

        if (isset($record['indicator'])) {
            // Indicator record, e.g. /v2/country/US/indicator/SP.POP.TOTL
            echo '<tr class="country-header"><td colspan="2">'
                . h(($record['country']['value'] ?? '') . ' - ' . ($record['date'] ?? '')) . '</td></tr>';
            echo '<tr><td>Indicator</td><td>' . h($record['indicator']['value'] ?? '') . '</td></tr>';
            $value = $record['value'] ?? null;
            echo '<tr><td>Value</td><td>' . (is_numeric($value) ? h(number_format($value)) : 'N/A') . '</td></tr>';
        } elseif (isset($record['iso2Code'])) {
            // Country record, e.g. /v2/country/DE or /v2/region/ECS/country
            echo '<tr class="country-header"><td colspan="2">' . h($record['name'] ?? '') . '</td></tr>';
            echo '<tr><td>ISO Codes</td><td>' . h($record['iso2Code'] . ' / ' . ($record['id'] ?? '')) . '</td></tr>';
            if (!empty($record['capitalCity'])) {
                echo '<tr><td>Capital</td><td>' . h($record['capitalCity']) . '</td></tr>';
            }
            if (!empty($record['region']['value'])) {
                echo '<tr><td>Region</td><td>' . h($record['region']['value']) . '</td></tr>';
            }
            if (!empty($record['incomeLevel']['value'])) {
                echo '<tr><td>Income Level</td><td>' . h($record['incomeLevel']['value']) . '</td></tr>';
            }
            if (!empty($record['latitude']) && !empty($record['longitude'])) {
                echo '<tr><td>Coordinates</td><td>' . h($record['latitude'] . ', ' . $record['longitude']) . '</td></tr>';
            }
        } else {
            // Any other World Bank resource: show the raw record
            echo '<tr><td><pre>' . h(json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre></td></tr>';
        }

        echo '</table>';
        echo '</div>';
    }
}

// Get AWS region and bucket name from cookies or use defaults
function getAwsRegion() {
    if (isset($_COOKIE['aws_region'])) {
        return $_COOKIE['aws_region'];
    }
    return 'us-east-1';
}

function getS3BucketName() {
    if (isset($_COOKIE['s3_bucket'])) {
        return $_COOKIE['s3_bucket'];
    }
    return 'networking-in-aws';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EC2 Connection Tester</title>
    <link rel="icon" type="image/ico" href="https://a0.awsstatic.com/libra-css/images/site/fav/favicon.ico">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <style>
        .instance-banner {
            background: linear-gradient(135deg, #232f3e 0%, #37475a 100%);
            color: #fff;
            border-radius: 12px;
            padding: 1.2rem 1.5rem;
            margin-bottom: 1.5rem;
            display: flex;
            flex-wrap: wrap;
            gap: 1.5rem;
            align-items: center;
            font-size: 0.9rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        .instance-banner .banner-item {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .instance-banner .banner-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #adb5bd;
        }
        .instance-banner .banner-value {
            font-weight: 600;
            font-family: 'Courier New', monospace;
            font-size: 0.95rem;
        }
        .instance-banner .az-highlight {
            background: #ff9900;
            color: #232f3e;
            padding: 2px 10px;
            border-radius: 6px;
            font-weight: 700;
            font-size: 1rem;
        }
        .what-you-built {
            background: #f0f7ff;
            border-left: 4px solid #0073bb;
            border-radius: 0 8px 8px 0;
            padding: 1rem 1.2rem;
            margin-bottom: 1rem;
        }
        .what-you-built h3 {
            margin: 0 0 0.5rem 0;
            color: #0073bb;
            font-size: 1rem;
        }
        .what-you-built ul {
            margin: 0;
            padding-left: 1.2rem;
        }
        .what-you-built li {
            margin-bottom: 0.3rem;
            font-size: 0.9rem;
            color: #333;
        }
    </style>
</head>

<body>
    <header>
        <img src="https://d0.awsstatic.com/logos/powered-by-aws.png" alt="Logo">
    </header>

    <main>
        <!-- Instance Info Banner — always visible -->
        <div class="instance-banner">
            <div class="banner-item">
                <span class="banner-label">Instance ID</span>
                <span class="banner-value"><?php echo $instanceId; ?></span>
            </div>
            <div class="banner-item">
                <span class="banner-label">Availability Zone</span>
                <span class="az-highlight"><?php echo $availabilityZone; ?></span>
            </div>
            <div class="banner-item">
                <span class="banner-label">Private IP</span>
                <span class="banner-value"><?php echo $privateIp; ?></span>
            </div>
            <div class="banner-item">
                <span class="banner-label">Instance Type</span>
                <span class="banner-value"><?php echo $instanceType; ?></span>
            </div>
            <div class="banner-item">
                <span class="banner-label">Region</span>
                <span class="banner-value"><?php echo $region; ?></span>
            </div>
        </div>

        <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && 
                 (isset($_POST['apiResource']) || isset($_POST['bucketName']) || isset($_POST['exampleApi']))): ?>
            <button onclick='location.href="/"' class="workshop-btn">
                <i class="fas fa-arrow-left"></i> Go Back
            </button>
            <button onclick='location.reload()' class="workshop-btn">
                <i class="fas fa-refresh"></i> Refresh
            </button>
            
            <?php
            if (isset($_POST['bucketName']) && isset($_POST['regionName'])) {
                // S3 functionality
                $userBucketName = $_POST['bucketName'];
                $userPrefix = $_POST['bucketPrefix'];
                $postRegion = $_POST['regionName'];
                
                // Store bucket and region in cookies for consistent use across pages
                setcookie('s3_bucket', $userBucketName, time() + 86400, '/');
                setcookie('aws_region', $postRegion, time() + 86400, '/');
                
                try {
                    // Create an S3 client with IAM role credentials from the EC2 instance
                    // Use the real SDK client when present, otherwise fall back to the mock
                    $clientClass = class_exists('Aws\S3\S3Client') ? 'Aws\S3\S3Client' : 'S3Client';
                    $s3client = new $clientClass(['region' => $postRegion, 'version' => 'latest']);
                    
                    // List objects in the specified bucket
                    $objects = $s3client->listObjectsV2([
                        'Bucket' => $userBucketName,
                        'Prefix' => $userPrefix,
                    ]);
                    
                    echo '<div class="card">';
                    echo '<h2><i class="fas fa-database"></i> S3 Bucket Contents</h2>';
                    echo '<table>';
                    echo '<tr><th>Object Key</th><th>Size (bytes)</th></tr>';
                    
                    // 'Contents' is absent when the bucket/prefix is empty
                    foreach ($objects['Contents'] ?? [] as $object) {
                        echo '<tr>';
                        echo '<td>' . htmlspecialchars($object['Key']) . '</td>';
                        echo '<td>' . htmlspecialchars((string) $object['Size']) . '</td>';
                        echo '</tr>';
                    }
                    
                    echo '</table>';
                    echo '</div>';
                } catch (\Aws\Exception\AwsException $e) {
                    $msg = $e->getAwsErrorMessage() ?: $e->getMessage();
                    echo '<div class="card"><p>Error: ' . htmlspecialchars($msg) . '</p></div>';
                } catch (\Exception $e) {
                    echo '<div class="card"><p>Error: ' . htmlspecialchars($e->getMessage()) . '</p></div>';
                }
            } 
            elseif (isset($_POST['apiResource'])) {
                // Only the resource comes from the user; the posted apiUrl and
                // httpMethod are display-only and deliberately ignored.
                $requestUrl = buildApiUrl($_POST['apiResource']);

                if ($requestUrl === null) {
                    echo '<div class="card"><p>Error: the API Resource must be a path starting with "/", '
                        . 'for example ' . h(API_DEFAULT_RESOURCE) . '</p></div>';
                } else {
                    $curl = curl_init();
                    curl_setopt_array($curl, [
                        CURLOPT_URL => $requestUrl,
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_HTTPHEADER => ['Accept: application/json'],
                        CURLOPT_HTTPGET => true,
                        CURLOPT_FOLLOWLOCATION => false,
                        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                        CURLOPT_CONNECTTIMEOUT => API_TIMEOUT_SECONDS,
                        CURLOPT_TIMEOUT => API_TIMEOUT_SECONDS,
                    ]);
                    $response = curl_exec($curl);
                    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

                    echo '<div class="card">';
                    echo '<h2><i class="fas fa-reply"></i> API Response</h2>';
                    echo '<p><strong>' . h(API_METHOD) . '</strong> ' . h($requestUrl) . ' &rarr; HTTP ' . h($httpCode) . '</p>';

                    if (curl_errno($curl)) {
                        echo '<p>Error: ' . h(curl_error($curl)) . '</p>';
                    } elseif ($httpCode !== 200) {
                        echo '<p>Error: the API returned HTTP ' . h($httpCode) . '.</p>';
                    } else {
                        $decoded = json_decode($response, true);
                        if ($decoded === null) {
                            echo '<p>Error: the response was not valid JSON. Add format=json to the resource.</p>';
                        } else {
                            displayJson($decoded);
                        }
                    }
                    echo '</div>';
                    curl_close($curl);
                }
            }
            elseif (isset($_POST['exampleApi'])) {
                $exampleApiName = $_POST['exampleApi'];
                foreach ($exampleApis as $exampleApi) {
                    if ($exampleApi['name'] === $exampleApiName) {
                        echo '<div class="card">';
                        echo '<h2><i class="fas fa-code"></i> Example API Configuration</h2>';
                        populateApiInput($exampleApi);
                        echo '</div>';
                    }
                }
            }
            ?>

        <?php else: ?>
            <?php
            // Get current step from cookie or default to 1
            $currentStep = isset($_COOKIE['workshop_step']) ? intval($_COOKIE['workshop_step']) : 1;
            
            // If a step is completed, update the current step
            if (isset($_GET['step_completed'])) {
                $completedStep = intval($_GET['step_completed']);
                if ($completedStep >= $currentStep) {
                    $currentStep = $completedStep + 1;
                    setcookie('workshop_step', $currentStep, time() + 86400, '/'); // Set cookie for 1 day
                }
            }
            ?>
            
            <div class="workshop-progress">
                <div class="step <?php echo ($currentStep >= 1) ? 'active' : ''; ?>">
                    <div class="step-number"><span>1</span></div>
                    <div class="step-title">EC2 Test</div>
                </div>
                <div class="step <?php echo ($currentStep >= 2) ? 'active' : ''; ?>">
                    <div class="step-number"><span>2</span></div>
                    <div class="step-title">S3 Testing</div>
                </div>
                <div class="step <?php echo ($currentStep >= 3) ? 'active' : ''; ?>">
                    <div class="step-number"><span>3</span></div>
                    <div class="step-title">API Testing</div>
                </div>
            </div>
            
            <!-- Step 1: EC2 Connection -->
            <div class="card">
                <h2><i class="fas fa-server"></i> Step 1: Test EC2 Connection</h2>
                <div class="test-result">
                    <div class="status-indicator status-success">
                        <i class="fas fa-check-circle"></i>
                        <span>Successfully connected to EC2 instance</span>
                    </div>

                    <div class="what-you-built">
                        <h3><i class="fas fa-graduation-cap"></i> What you built and why</h3>
                        <ul>
                            <li><strong>VPC with public &amp; private subnets</strong> — isolated network with controlled internet access</li>
                            <li><strong>NAT Gateway</strong> — allows your private instance to reach the internet (for updates &amp; APIs) without being directly exposed</li>
                            <li><strong>Security Groups</strong> — firewall rules that only allow HTTP traffic through the Load Balancer</li>
                            <li><strong>IAM Role + Instance Profile</strong> — grants your EC2 instance permissions to use AWS services (SSM, S3) without storing credentials</li>
                            <li><strong>EC2 instance in a private subnet</strong> — your web server, not directly reachable from the internet</li>
                            <li><strong>Application Load Balancer</strong> — distributes traffic and provides a public DNS endpoint for your website</li>
                        </ul>
                    </div>

                    <p>This page is being served by instance <strong><?php echo $instanceId; ?></strong> in <strong><?php echo $availabilityZone; ?></strong>.</p>
                    <p><em>Tip: After completing the Auto Scaling challenge, refresh this page multiple times — the Availability Zone in the banner will change as the Load Balancer routes requests to different instances!</em></p>

                    <?php if ($currentStep == 1): ?>
                    <a href="?step_completed=1" class="workshop-btn">
                        <i class="fas fa-check"></i> Mark Step 1 Complete
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Step 2: S3 Connection -->
            <?php if ($currentStep >= 2): ?>
            <div class="card">
                <h2><i class="fas fa-database"></i> Step 2: Test S3 Connection</h2>
                <form method="post">
                    <label for="bucketName">S3 Bucket Name:</label>
                    <input type="text" id="bucketName" name="bucketName" value="networking-in-aws" required>
                    
                    <label for="bucketPrefix">S3 Bucket Prefix: (optional)</label>
                    <input type="text" id="bucketPrefix" name="bucketPrefix" value="">
                    
                    <label for="regionName">S3 Bucket Region:</label>
                    <input type="text" id="regionName" name="regionName" value="<?php echo $region !== 'N/A' ? $region : 'us-east-1'; ?>" required>
                    
                    <button type="submit" class="workshop-btn">
                        <i class="fas fa-magnifying-glass"></i> Browse Bucket
                    </button>
                </form>
                <?php if ($currentStep == 2): ?>
                <div class="note-box" style="margin-top: 1rem;">
                    <p><i class="fas fa-lightbulb"></i> After testing S3 connectivity, mark this step as complete to proceed.</p>
                    <a href="?step_completed=2" class="workshop-btn">
                        <i class="fas fa-check"></i> Mark Step 2 Complete
                    </a>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Step 3: API Testing -->
            <?php if ($currentStep >= 3): ?>
            <div class="card">
                <h2><i class="fas fa-cloud"></i> Step 3: Test API Connection</h2>
                <div class="info-box">
                    <p><i class="fas fa-info-circle"></i> In this step:</p>
                    <ul>
                        <li>Test outbound API connectivity from the private subnet via the NAT Gateway</li>
                        <li>Verify your EC2 instance can reach external APIs on the internet</li>
                        <li>Retrieve country data from the public World Bank API as a test (no API key required)</li>
                    </ul>
                </div>

                <form method="post">
                    <label for="apiUrl">API Invoke URL:</label>
                    <input type="text" id="apiUrl" name="apiUrl" value="<?php echo h($exampleApis[0]['url']); ?>" readonly>
                    
                    <label for="httpMethod">HTTP Method:</label>
                    <input type="text" id="httpMethod" name="httpMethod" value="<?php echo h($exampleApis[0]['method']); ?>" readonly>
                    
                    <label for="apiResource">API Resource:</label>
                    <input type="text" id="apiResource" name="apiResource" value="<?php echo h($exampleApis[0]['resource']); ?>" required>
                    <p><small>Try one of these resources:</small></p>
                    <ul>
                        <?php foreach ($exampleResources as $exampleResource => $exampleLabel): ?>
                        <li><small><code><?php echo h($exampleResource); ?></code> - <?php echo h($exampleLabel); ?></small></li>
                        <?php endforeach; ?>
                    </ul>
                    
                    <button type="submit" class="workshop-btn">
                        <i class="fas fa-paper-plane"></i> Test API Connection
                    </button>
                </form>

                <div class="note-box">
                    <p><i class="fas fa-lightbulb"></i> This test proves your private EC2 instance can communicate with the public internet through the NAT Gateway you configured earlier.</p>
                    <?php if ($currentStep == 3): ?>
                    <a href="?step_completed=3" class="workshop-btn">
                        <i class="fas fa-check"></i> Mark Step 3 Complete
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </main>

    <script>
        function showTab(tabId) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Deactivate all tab buttons
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Show selected tab content
            document.getElementById(tabId).classList.add('active');
            
            // Activate selected tab button
            event.target.classList.add('active');
        }
    </script>
</body>
</html>
