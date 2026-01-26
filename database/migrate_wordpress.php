<?php
/**
 * WordPress to AbegEppMe Database Migration Script
 * 
 * This script migrates users and related data from WordPress to the new AbegEppMe database.
 * 
 * Usage:
 * 1. Update the WordPress database connection settings below
 * 2. Update the AbegEppMe database connection settings
 * 3. Run: php migrate_wordpress.php
 * 
 * IMPORTANT: Backup both databases before running this script!
 */

require_once __DIR__ . '/../config/database.php';

// WordPress Database Configuration
$wp_db_config = [
    'host' => 'localhost',
    'dbname' => 'your_wordpress_db',
    'username' => 'your_wp_username',
    'password' => 'your_wp_password',
    'prefix' => 'wp_', // WordPress table prefix
];

// Connect to WordPress database
try {
    $wp_pdo = new PDO(
        "mysql:host={$wp_db_config['host']};dbname={$wp_db_config['dbname']};charset=utf8mb4",
        $wp_db_config['username'],
        $wp_db_config['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
    echo "✓ Connected to WordPress database\n";
} catch (PDOException $e) {
    die("✗ WordPress database connection failed: " . $e->getMessage() . "\n");
}

// Connect to AbegEppMe database
try {
    $abe_pdo = Database::getInstance()->getConnection();
    echo "✓ Connected to AbegEppMe database\n\n";
} catch (Exception $e) {
    die("✗ AbegEppMe database connection failed: " . $e->getMessage() . "\n");
}

/**
 * Generate UUID v4
 */
function generateUUID() {
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

/**
 * Map WordPress user role to AbegEppMe role
 */
function mapUserRole($wp_role) {
    $role_map = [
        'administrator' => 'ADMIN',
        'shop_vendor' => 'VENDOR',      // Dokan vendor role
        'seller' => 'VENDOR',           // Alternative vendor role
        'customer' => 'CUSTOMER',
        'subscriber' => 'CUSTOMER',
    ];
    
    return $role_map[$wp_role] ?? 'CUSTOMER';
}

/**
 * Map WordPress user status
 */
function mapUserStatus($wp_status) {
    // Check if user is verified (you may need to adjust based on your verification logic)
    // This is a placeholder - adjust based on your WordPress setup
    return 'ACTIVE'; // or 'PENDING_VERIFICATION' if needed
}

/**
 * Migrate Users
 */
function migrateUsers($wp_pdo, $abe_pdo, $prefix) {
    echo "Migrating users...\n";
    
    $stmt = $wp_pdo->prepare("
        SELECT 
            u.ID,
            u.user_email as email,
            u.user_login,
            u.user_nicename as name,
            u.user_pass as password,
            u.user_registered as created_at,
            um_role.meta_value as role,
            um_phone.meta_value as phone,
            um_avatar.meta_value as avatar
        FROM {$prefix}users u
        LEFT JOIN {$prefix}usermeta um_role ON u.ID = um_role.user_id AND um_role.meta_key = '{$prefix}capabilities'
        LEFT JOIN {$prefix}usermeta um_phone ON u.ID = um_phone.user_id AND um_phone.meta_key = 'phone'
        LEFT JOIN {$prefix}usermeta um_avatar ON u.ID = um_avatar.user_id AND um_avatar.meta_key = 'avatar'
        ORDER BY u.ID
    ");
    
    $stmt->execute();
    $users = $stmt->fetchAll();
    
    $migrated = 0;
    $skipped = 0;
    
    foreach ($users as $wp_user) {
        // Check if user already exists
        $check_stmt = $abe_pdo->prepare("SELECT id FROM users WHERE email = ?");
        $check_stmt->execute([$wp_user['email']]);
        if ($check_stmt->fetch()) {
            echo "  ⚠ Skipping {$wp_user['email']} (already exists)\n";
            $skipped++;
            continue;
        }
        
        // Extract role from WordPress capabilities
        $role = 'CUSTOMER';
        if (!empty($wp_user['role'])) {
            $capabilities = maybe_unserialize($wp_user['role']);
            if (is_array($capabilities)) {
                foreach (array_keys($capabilities) as $wp_role) {
                    $role = mapUserRole($wp_role);
                    if ($role !== 'CUSTOMER') break; // Prioritize ADMIN/VENDOR
                }
            }
        }
        
        // Generate new UUID
        $new_id = generateUUID();
        
        // Insert into AbegEppMe database
        $insert_stmt = $abe_pdo->prepare("
            INSERT INTO users (
                id, email, name, password, role, status, phone, avatar, created_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?
            )
        ");
        
        $insert_stmt->execute([
            $new_id,
            $wp_user['email'],
            $wp_user['name'] ?: $wp_user['user_login'],
            $wp_user['password'], // WordPress hashed password (should be compatible)
            $role,
            mapUserStatus($wp_user['status'] ?? 'active'),
            $wp_user['phone'] ?: null,
            $wp_user['avatar'] ?: null,
            $wp_user['created_at']
        ]);
        
        // Create mapping table if it doesn't exist
        try {
            $abe_pdo->exec("
                CREATE TABLE IF NOT EXISTS wordpress_user_mapping (
                    wordpress_id BIGINT PRIMARY KEY,
                    abegeppme_id CHAR(36) NOT NULL,
                    email VARCHAR(255) NOT NULL,
                    migrated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_abegeppme_id (abegeppme_id),
                    INDEX idx_email (email)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (PDOException $e) {
            // Table might already exist
        }
        
        // Store WordPress ID mapping for reference
        $mapping_stmt = $abe_pdo->prepare("
            INSERT INTO wordpress_user_mapping (wordpress_id, abegeppme_id, email)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE abegeppme_id = VALUES(abegeppme_id)
        ");
        
        $mapping_stmt->execute([
            $wp_user['ID'],
            $new_id,
            $wp_user['email']
        ]);
        
        $migrated++;
        echo "  ✓ Migrated: {$wp_user['email']} ({$role})\n";
    }
    
    echo "\nUsers migrated: {$migrated}\n";
    echo "Users skipped: {$skipped}\n\n";
    
    return $migrated;
}

/**
 * Migrate Vendor Subaccounts (Dokan)
 */
function migrateSubaccounts($wp_pdo, $abe_pdo, $prefix) {
    echo "Migrating vendor subaccounts...\n";
    
    // Get WordPress user ID to AbegEppMe ID mapping
    $mapping_stmt = $abe_pdo->query("SELECT wordpress_id, abegeppme_id FROM wordpress_user_mapping");
    $mappings = [];
    while ($row = $mapping_stmt->fetch()) {
        $mappings[$row['wordpress_id']] = $row['abegeppme_id'];
    }
    
    // Get vendors with subaccount info
    $stmt = $wp_pdo->prepare("
        SELECT 
            u.ID as user_id,
            um_subaccount.meta_value as subaccount_code,
            um_account_number.meta_value as account_number,
            um_account_name.meta_value as account_name,
            um_bank_code.meta_value as bank_code,
            um_transfer_recipient.meta_value as transfer_recipient
        FROM {$prefix}users u
        INNER JOIN {$prefix}usermeta um_subaccount ON u.ID = um_subaccount.user_id 
            AND um_subaccount.meta_key = 'dokan_subaccount_code'
        LEFT JOIN {$prefix}usermeta um_account_number ON u.ID = um_account_number.user_id 
            AND um_account_number.meta_key = 'dokan_account_number'
        LEFT JOIN {$prefix}usermeta um_account_name ON u.ID = um_account_name.user_id 
            AND um_account_name.meta_key = 'dokan_account_name'
        LEFT JOIN {$prefix}usermeta um_bank_code ON u.ID = um_bank_code.user_id 
            AND um_bank_code.meta_key = 'dokan_bank_code'
        LEFT JOIN {$prefix}usermeta um_transfer_recipient ON u.ID = um_transfer_recipient.user_id 
            AND um_transfer_recipient.meta_key = 'dokan_transfer_recipient'
        WHERE um_subaccount.meta_value IS NOT NULL AND um_subaccount.meta_value != ''
    ");
    
    $stmt->execute();
    $subaccounts = $stmt->fetchAll();
    
    $migrated = 0;
    
    foreach ($subaccounts as $subaccount) {
        if (!isset($mappings[$subaccount['user_id']])) {
            echo "  ⚠ Skipping subaccount for WP user {$subaccount['user_id']} (user not migrated)\n";
            continue;
        }
        
        $abe_user_id = $mappings[$subaccount['user_id']];
        
        // Check if subaccount already exists
        $check_stmt = $abe_pdo->prepare("SELECT id FROM subaccounts WHERE user_id = ?");
        $check_stmt->execute([$abe_user_id]);
        if ($check_stmt->fetch()) {
            echo "  ⚠ Skipping subaccount for user {$abe_user_id} (already exists)\n";
            continue;
        }
        
        $insert_stmt = $abe_pdo->prepare("
            INSERT INTO subaccounts (
                id, user_id, subaccount_code, account_number, account_name, 
                bank_code, transfer_recipient, status
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, 'active'
            )
        ");
        
        $insert_stmt->execute([
            generateUUID(),
            $abe_user_id,
            $subaccount['subaccount_code'],
            $subaccount['account_number'] ?: null,
            $subaccount['account_name'] ?: null,
            $subaccount['bank_code'] ?: null,
            $subaccount['transfer_recipient'] ?: null,
        ]);
        
        $migrated++;
        echo "  ✓ Migrated subaccount for user {$abe_user_id}\n";
    }
    
    echo "\nSubaccounts migrated: {$migrated}\n\n";
    
    return $migrated;
}

/**
 * WordPress maybe_unserialize helper
 */
function maybe_unserialize($data) {
    if (is_serialized($data)) {
        return unserialize($data);
    }
    return $data;
}

function is_serialized($data) {
    if (!is_string($data)) {
        return false;
    }
    $data = trim($data);
    if ('N;' == $data) {
        return true;
    }
    if (!preg_match('/^([adObis]):/', $data, $badions)) {
        return false;
    }
    switch ($badions[1]) {
        case 'a':
        case 'O':
        case 's':
            if (preg_match("/^{$badions[1]}:[0-9]+:.*[;}]\$/s", $data)) {
                return true;
            }
            break;
        case 'b':
        case 'i':
        case 'd':
            if (preg_match("/^{$badions[1]}:[0-9.E-]+;\$/", $data)) {
                return true;
            }
            break;
    }
    return false;
}

// Run migration
echo "========================================\n";
echo "WordPress to AbegEppMe Migration\n";
echo "========================================\n\n";

echo "⚠️  WARNING: This will migrate data from WordPress to AbegEppMe.\n";
echo "⚠️  Make sure you have backups of both databases!\n\n";

// Uncomment to run migration
// migrateUsers($wp_pdo, $abe_pdo, $wp_db_config['prefix']);
// migrateSubaccounts($wp_pdo, $abe_pdo, $wp_db_config['prefix']);

echo "\n✓ Migration script ready. Uncomment the migration functions to run.\n";
echo "✓ Review the script and update database credentials before running.\n";
