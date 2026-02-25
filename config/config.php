<?php
/**
 * Application Configuration
 */

// Load environment variables
if (file_exists(__DIR__ . '/../.env')) {
    $lines = file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        $existingEnv = getenv($name);
        $isMissingOrEmpty =
            $existingEnv === false ||
            $existingEnv === null ||
            trim((string)$existingEnv) === '';

        if ($isMissingOrEmpty || (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV))) {
            putenv(sprintf('%s=%s', $name, $value));
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

// Application Settings
define('APP_NAME', 'AbegEppMe API');
define('APP_VERSION', '1.0.0');
define('APP_ENV', getenv('APP_ENV') ?: 'development');

// Database Configuration
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'abegeppme');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');

// API Configuration
define('API_BASE_URL', getenv('API_BASE_URL') ?: 'http://localhost/api');
define('CORS_ORIGIN', getenv('CORS_ORIGIN') ?: 'http://localhost:5173');

// Paystack Configuration (to be configured later)
define('PAYSTACK_SECRET_KEY', getenv('PAYSTACK_SECRET_KEY') ?: '');
define('PAYSTACK_PUBLIC_KEY', getenv('PAYSTACK_PUBLIC_KEY') ?: '');
define('PAYSTACK_WEBHOOK_SECRET', getenv('PAYSTACK_WEBHOOK_SECRET') ?: '');

// Payment Settings
define('PAYMENT_METHOD_TYPE', getenv('PAYMENT_METHOD_TYPE') ?: 'individual');
define('TRANSFER_METHOD', getenv('TRANSFER_METHOD') ?: 'single');
define('VENDOR_INITIAL_PERCENTAGE', floatval(getenv('VENDOR_INITIAL_PERCENTAGE') ?: 50));
define('INSURANCE_PERCENTAGE', floatval(getenv('INSURANCE_PERCENTAGE') ?: 1));
define('COMMISSION_PERCENTAGE', floatval(getenv('COMMISSION_PERCENTAGE') ?: 5));
define('VAT_PERCENTAGE', floatval(getenv('VAT_PERCENTAGE') ?: 7.5));
define('SERVICE_CHARGE', floatval(getenv('SERVICE_CHARGE') ?: 250));
define('INSURANCE_SUBACCOUNT_CODE', getenv('INSURANCE_SUBACCOUNT_CODE') ?: '');

// JWT/Auth Configuration
define('JWT_SECRET', getenv('JWT_SECRET') ?: 'your-secret-key-change-this');
define('JWT_EXPIRY', getenv('JWT_EXPIRY') ?: 86400); // 24 hours

// File Upload
define('UPLOAD_MAX_SIZE', intval(getenv('UPLOAD_MAX_SIZE') ?: 10485760)); // 10MB
define('UPLOAD_DIR', getenv('UPLOAD_DIR') ?: __DIR__ . '/../uploads/');

// Error Reporting
if (APP_ENV === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}
