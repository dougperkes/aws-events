# AWS 101 Resources

Click here first: [Workshop Join Link](https://catalog.us-east-1.prod.workshops.aws/join?access-code=f3c6-06d01e-a0)

## Deploy Compute section

User Data

```
#!/bin/bash
dnf update -y
# Install and start the PHP web server
dnf install -y httpd wget php-json php
systemctl start httpd
systemctl enable httpd

# Install AWS SDK for PHP
wget https://docs.aws.amazon.com/aws-sdk-php/v3/download/aws.zip
unzip aws.zip -d /var/www/html/sdk
rm aws.zip

# Install the web pages for our lab
if [ ! -f /var/www/html/index.html ]; then
  rm -f index.html
fi
cd /var/www/html
wget https://dougperkes.github.io/aws-events/workshops/aws101/resources/index.php
```