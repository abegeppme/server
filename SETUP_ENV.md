# Environment Setup Guide

## Step 1: Create .env File

Since `.env` files are protected, you need to create it manually:

1. **Navigate to the server directory:**
   ```
   C:\MAMP\htdocs\abegeppme-v4\server\
   ```

2. **Create a new file named `.env`** (no extension)

3. **Copy and paste this content:**

```env
# AbegEppMe Backend Environment Configuration

# Application Environment
APP_ENV=development
APP_VERSION=1.0.0

# Database Configuration
DB_HOST=localhost
DB_NAME=abegeppme
DB_USER=root
DB_PASS=root
DB_PORT=3306

# API Configuration
API_BASE_URL=http://localhost/abegeppme-v4/server
CORS_ORIGIN=http://localhost:5173

# JWT Authentication
JWT_SECRET=abegeppme-secret-key-change-this-in-production-2024
JWT_EXPIRY=86400

# Email Configuration (SMTP)
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USER=
SMTP_PASS=
FROM_EMAIL=noreply@abegeppme.com
FROM_NAME=AbegEppMe

# Push Notifications (Firebase Cloud Messaging)
FCM_SERVER_KEY=

# Payment Gateway Configuration
# Note: Payment gateway configs are stored in countries table
# These are fallback/default values

# Paystack (Default for Nigeria)
PAYSTACK_SECRET_KEY=
PAYSTACK_PUBLIC_KEY=
PAYSTACK_WEBHOOK_SECRET=

# Flutterwave (Default for Kenya)
FLUTTERWAVE_SECRET_KEY=
FLUTTERWAVE_PUBLIC_KEY=
FLUTTERWAVE_WEBHOOK_HASH=

# Payment Settings
PAYMENT_METHOD_TYPE=individual
TRANSFER_METHOD=single
VENDOR_INITIAL_PERCENTAGE=50
INSURANCE_PERCENTAGE=1
COMMISSION_PERCENTAGE=5
VAT_PERCENTAGE=7.5
SERVICE_CHARGE=250
INSURANCE_SUBACCOUNT_CODE=

# File Upload
UPLOAD_MAX_SIZE=10485760
UPLOAD_DIR=uploads/

# Logging
LOG_LEVEL=debug
LOG_FILE=logs/app.log
```

4. **Update the values** according to your setup:
   - `DB_NAME`: Your database name
   - `DB_USER`: Your MySQL username (usually `root` for MAMP)
   - `DB_PASS`: Your MySQL password (usually `root` for MAMP)
   - `JWT_SECRET`: Change this to a secure random string
   - `API_BASE_URL`: Should match your server URL

## Step 2: Verify Database Connection

Make sure your database exists and is accessible. You can test the connection by running:

```php
php -r "require 'config/database.php'; \$db = Database::getInstance()->getConnection(); echo 'Connected!';"
```

## Step 3: Test the API

### Test Root Endpoint

Open your browser or Postman and visit:
```
http://localhost/abegeppme-v4/server/
```

You should see:
```json
{
  "success": true,
  "message": "AbegEppMe API v1.0.0",
  "endpoints": {
    "auth": "/api/auth",
    "users": "/api/users",
    ...
  }
}
```

### Test API Endpoint

Visit:
```
http://localhost/abegeppme-v4/server/api/auth
```

This should return the auth endpoints info.

## Troubleshooting

### If you get 404 errors:

1. **Check Apache mod_rewrite is enabled:**
   - Open MAMP
   - Go to Apache → httpd.conf
   - Make sure this line is NOT commented: `LoadModule rewrite_module modules/mod_rewrite.so`

2. **Check .htaccess file exists** in the `server/` directory

3. **Check file permissions** - Apache needs read access

4. **Try accessing directly:**
   ```
   http://localhost/abegeppme-v4/server/index.php
   ```
   This should work even without .htaccess

### If you get 500 errors:

1. **Check PHP error log** in MAMP
2. **Check database connection** - verify credentials in `.env`
3. **Check file permissions** - make sure PHP can read all files

## Quick Test Commands

### Using curl (if available):
```bash
curl http://localhost/abegeppme-v4/server/
```

### Using PowerShell:
```powershell
Invoke-WebRequest -Uri "http://localhost/abegeppme-v4/server/" -Method GET
```
