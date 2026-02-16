<?php
/**
 * Generate API Key
 * 
 * Generates a secure random API key for use in .env file
 * Usage: php generate_api_key.php
 */

// Generate a secure random API key (32 bytes = 64 hex characters)
$apiKey = bin2hex(random_bytes(32));

// Also generate a cron secret
$cronSecret = bin2hex(random_bytes(32));

echo "========================================\n";
echo "API Keys Generated\n";
echo "========================================\n\n";

echo "API_KEY (for API authentication):\n";
echo $apiKey . "\n\n";

echo "CRON_SECRET (for cron job authentication):\n";
echo $cronSecret . "\n\n";

echo "========================================\n";
echo "Add these to your server/.env file:\n";
echo "========================================\n\n";

echo "ENABLE_API_KEY_AUTH=true\n";
echo "API_KEY=" . $apiKey . "\n";
echo "CRON_SECRET=" . $cronSecret . "\n\n";

echo "And to your client/.env file:\n";
echo "VITE_API_KEY=" . $apiKey . "\n";
echo "VITE_ENABLE_API_KEY=true\n\n";

echo "========================================\n";
echo "To DISABLE API key authentication:\n";
echo "========================================\n";
echo "Set ENABLE_API_KEY_AUTH=false in server/.env\n";
echo "Set VITE_ENABLE_API_KEY=false in client/.env\n\n";
