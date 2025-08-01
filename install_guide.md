# DMOZ-Style Directory Installation Guide

## Prerequisites

- Debian 12 with LEMP stack (Linux, Nginx, MySQL/MariaDB, PHP)
- PHP 8.1+ with extensions: pdo, pdo_mysql, curl, json, mbstring
- MySQL/MariaDB 8.0+
- Nginx web server
- SSL certificate (recommended)

## Step 1: Database Setup

1. Create the database and user:

```sql
CREATE DATABASE dmoz_directory CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'dmoz_user'@'localhost' IDENTIFIED BY 'your_secure_password';
GRANT ALL PRIVILEGES ON dmoz_directory.* TO 'dmoz_user'@'localhost';
FLUSH PRIVILEGES;
```

2. Import the database schema using the SQL from the "Database Schema" artifact.

## Step 2: File Setup

1. Create the web directory:
```bash
sudo mkdir -p /var/www/directory
cd /var/www/directory
```

2. Create all PHP files from the artifacts in this directory:
   - `config.php`
   - `website_checker.php` 
   - `ai_scanner.php`
   - `index.php`
   - `submit.php`
   - `admin_login.php`
   - `admin_dashboard.php`

3. Create additional required files:

**admin_logout.php:**
```php
<?php
require_once 'config.php';
session_destroy();
header('Location: admin_login.php');
exit;
?>
```

**admin_moderate.php:** (Extended moderation page)
```php
<?php
require_once 'config.php';
require_admin();

// Similar to dashboard but with pagination and filters for all pending submissions
// This would be a full-page version of the submissions table
?>
```

4. Create logs directory:
```bash
sudo mkdir logs
sudo chown www-data:www-data logs
sudo chmod 755 logs
```

## Step 3: Configuration

1. Edit `config.php` and update:
   - Database credentials
   - Site name and URL
   - Admin email
   - OpenAI API key (if using AI features)

2. Change the default admin password:
```sql
UPDATE admin_users SET password_hash = '$2y$10$NEW_HASH_HERE' WHERE username = 'admin';
```

Generate new hash with:
```php
echo password_hash('your_new_password', PASSWORD_DEFAULT);
```

## Step 4: Nginx Configuration

Create `/etc/nginx/sites-available/directory`:

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name yourdomain.com www.yourdomain.com;
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name yourdomain.com www.yourdomain.com;

    root /var/www/directory;
    index index.php index.html;

    # SSL Configuration
    ssl_certificate /path/to/your/certificate.pem;
    ssl_certificate_key /path/to/your/private.key;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers ECDHE-RSA-AES256-GCM-SHA512:DHE-RSA-AES256-GCM-SHA512:ECDHE-RSA-AES256-GCM-SHA384:DHE-RSA-AES256-GCM-SHA384;

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "no-referrer-when-downgrade" always;

    # PHP handling
    location ~ \.php$ {
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    # Deny access to sensitive files
    location ~ /\. {
        deny all;
    }
    
    location ~ ^/(config|website_checker|ai_scanner)\.php$ {
        deny all;
    }

    # Gzip compression
    gzip on;
    gzip_vary on;
    gzip_min_length 1024;
    gzip_types text/plain text/css text/xml text/javascript application/javascript application/xml+rss application/json;

    # Cache static files
    location ~* \.(jpg|jpeg|png|gif|ico|css|js)$ {
        expires 1M;
        add_header Cache-Control "public, immutable";
    }
}
```

Enable the site:
```bash
sudo ln -s /etc/nginx/sites-available/directory /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

## Step 5: Set Permissions

```bash
sudo chown -R www-data:www-data /var/www/directory
sudo chmod -R 755 /var/www/directory
sudo chmod 600 /var/www/directory/config.php
```

## Step 6: Cron Jobs (Optional but Recommended)

Add to www-data crontab:
```bash
sudo crontab -u www-data -e
```

Add these lines:
```cron
# Check website status every hour
0 * * * * /usr/bin/php /var/www/directory/website_checker.php

# Run AI scans every 6 hours (if enabled)
0 */6 * * * /usr/bin/php /var/www/directory/ai_scanner.php
```

## Step 7: AI Integration Setup (Optional)

1. Get OpenAI API key from https://platform.openai.com/api-keys
2. Update `config.php`:
   ```php
   define('OPENAI_API_KEY', 'sk-your-api-key-here');
   define('AI_SCAN_ENABLED', true);
   ```

## Step 8: Security Considerations

1. **Firewall Setup:**
```bash
sudo ufw allow 'Nginx Full'
sudo ufw allow ssh
sudo ufw enable
```

2. **Fail2ban for brute force protection:**
```bash
sudo apt install fail2ban
```

Create `/etc/fail2ban/jail.local`:
```ini
[DEFAULT]
bantime = 3600
findtime = 600
maxretry = 5

[nginx-http-auth]
enabled = true
port = http,https
logpath = /var/log/nginx/error.log
```

3. **Regular backups:**
```bash
# Database backup script
mysqldump -u dmoz_user -p dmoz_directory > backup_$(date +%Y%m%d).sql
```

## Step 9: Testing

1. Visit your domain to test the public directory
2. Submit a test website through the form
3. Log in to admin panel at `/admin_login.php`
4. Test website status checking and approval process

## Additional Features to Implement

### Enhanced Moderation Page
Create `admin_moderate.php` with:
- Bulk actions (approve/reject multiple sites)
- Advanced filtering and sorting
- AI scan results display
- Website preview iframe

### Search Analytics
Create `admin_analytics.php` to show:
- Popular search terms  
- Traffic statistics
- Category performance
- Submission trends

### API Endpoints
For mobile apps or external integrations:
- `/api/search.php` - Search endpoints
- `/api/categories.php` - Category listings
- `/api/submit.php` - Programmatic submissions

### Advanced AI Features
- Content quality scoring
- Automatic categorization suggestions
- Duplicate detection
- Language detection and filtering
- Adult content detection

## Maintenance

1. **Regular Updates:**
   - Monitor security patches for PHP, Nginx, MySQL
   - Update SSL certificates before expiration
   - Review and rotate API keys periodically

2. **Database Maintenance:**
   - Regular optimization of search indexes
   - Archive old rejected submissions
   - Monitor database size and performance

3. **Log Monitoring:**
   - Check error logs regularly
   - Monitor failed login attempts
   - Track submission patterns for spam

## Troubleshooting

**Common Issues:**

1. **Database Connection Errors:**
   - Check MySQL service status
   - Verify credentials in config.php
   - Check firewall rules

2. **File Permission Issues:**
   - Ensure www-data owns web files
   - Check logs directory permissions

3. **AI Scanning Failures:**
   - Verify OpenAI API key
   - Check curl SSL settings
   - Monitor rate limiting

4. **Search Not Working:**
   - Verify FULLTEXT indexes exist
   - Check for special characters in queries
   - Monitor MySQL query logs

This directory system provides a solid foundation for a DMOZ-style directory with modern features like AI content analysis and automated status checking.