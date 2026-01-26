<?php
/**
 * Admin Account Seeder
 * Creates a temporary admin account for migration/testing
 * 
 * Usage: php seed_admin.php
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/config.php';

try {
    $db = Database::getInstance()->getConnection();
    
    $email = 'admin@abegeppme.com';
    $password = 'admin123';
    $name = 'Admin User';
    
    // Check if admin already exists
    $checkStmt = $db->prepare("SELECT id, email FROM users WHERE email = ? OR role = 'ADMIN' LIMIT 1");
    $checkStmt->execute([$email]);
    $existing = $checkStmt->fetch();
    
    if ($existing) {
        echo "✓ Admin account already exists:\n";
        echo "  Email: {$existing['email']}\n";
        echo "  ID: {$existing['id']}\n";
        exit(0);
    }
    
    // Generate UUID
    $userId = sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
    
    // Hash password
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    
    // Create admin user
    // Check if country_id column exists
    $columnsStmt = $db->query("SHOW COLUMNS FROM users LIKE 'country_id'");
    $hasCountryId = $columnsStmt->rowCount() > 0;
    
    if ($hasCountryId) {
        $insertStmt = $db->prepare("
            INSERT INTO users (id, email, name, password, role, status, country_id, preferred_currency)
            VALUES (?, ?, ?, ?, 'ADMIN', 'ACTIVE', 'NG', 'NGN')
        ");
        $insertStmt->execute([$userId, $email, $name, $hashedPassword]);
    } else {
        // Fallback for schema without country_id
        $insertStmt = $db->prepare("
            INSERT INTO users (id, email, name, password, role, status)
            VALUES (?, ?, ?, ?, 'ADMIN', 'ACTIVE')
        ");
        $insertStmt->execute([$userId, $email, $name, $hashedPassword]);
    }
    
    echo "✓ Admin account created successfully!\n\n";
    echo "Credentials:\n";
    echo "  Email: {$email}\n";
    echo "  Password: {$password}\n";
    echo "  Role: ADMIN\n\n";
    echo "⚠️  IMPORTANT: Change the password after first login!\n";
    
} catch (Exception $e) {
    die("✗ Error: " . $e->getMessage() . "\n");
}
