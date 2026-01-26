# .env File Template

Copy this into your `server/.env` file (lines 18-20 and full template):

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
SMTP_USER=your-email@gmail.com
SMTP_PASS=your-app-password
FROM_EMAIL=noreply@abegeppme.com
FROM_NAME=AbegEppMe

# Push Notifications (Firebase Cloud Messaging)
FCM_SERVER_KEY=your-fcm-server-key-here

# Payment Gateway Configuration
# Note: Payment gateway configs are stored in countries table
# These are fallback/default values

# Paystack (Default for Nigeria)
PAYSTACK_SECRET_KEY=sk_test_your_paystack_secret_key
PAYSTACK_PUBLIC_KEY=pk_test_your_paystack_public_key
PAYSTACK_WEBHOOK_SECRET=your_webhook_secret

# Flutterwave (Default for Kenya)
FLUTTERWAVE_SECRET_KEY=FLWSECK_TEST_your_flutterwave_secret_key
FLUTTERWAVE_PUBLIC_KEY=FLWPUBK_TEST_your_flutterwave_public_key
FLUTTERWAVE_WEBHOOK_HASH=your_webhook_hash

# Payment Settings
PAYMENT_METHOD_TYPE=individual
TRANSFER_METHOD=single
VENDOR_INITIAL_PERCENTAGE=40
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

## Lines 18-20 Specific Values

```env
# Application Environment
APP_ENV=development
```

**Explanation:**
- **Line 18:** Comment line (can be empty or removed)
- **Line 19:** `APP_ENV=development` - Set to `development` for testing, `production` for live
- **Line 20:** `APP_VERSION=1.0.0` - Application version

## Required Values for Testing

### Minimum Required (for basic testing):
```env
APP_ENV=development
DB_HOST=localhost
DB_NAME=abegeppme
DB_USER=root
DB_PASS=root
JWT_SECRET=any-random-string-here
API_BASE_URL=http://localhost/abegeppme-v4/server
```

### Optional (can be empty for now):
- `SMTP_*` - Email sending (optional)
- `FCM_SERVER_KEY` - Push notifications (optional)
- `PAYSTACK_*` / `FLUTTERWAVE_*` - Payment gateways (optional, needed for payment testing)
- `INSURANCE_SUBACCOUNT_CODE` - Insurance subaccount (optional)

## Quick Setup

1. **Create `.env` file** in `server/` directory
2. **Copy the template above**
3. **Update these values:**
   - `DB_NAME` - Your database name (e.g., `abegeppme`)
   - `DB_USER` - MySQL username (usually `root` for MAMP)
   - `DB_PASS` - MySQL password (usually `root` for MAMP)
   - `JWT_SECRET` - Any random string (change in production)
   - `API_BASE_URL` - Your server URL

4. **Save the file**

## Testing Without Payment Keys

You can test most features without payment gateway keys:
- ✅ Authentication
- ✅ User management
- ✅ Service management
- ✅ Order creation (without payment)
- ✅ Chat
- ✅ Reviews

Payment testing requires:
- Paystack keys (for Nigeria)
- Flutterwave keys (for Kenya)
