<?php
/**
 * Complete WordPress to AbegEppMe Database Migration Script
 * 
 * Migrates all data from WordPress database to new AbegEppMe database
 * 
 * Usage:
 * 1. Update database connection settings below
 * 2. Run: php migrate_wordpress_complete.php
 * 
 * IMPORTANT: Backup both databases before running!
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/config.php';

// WordPress Database Configuration
$wp_db_config = [
    'host' => 'localhost',
    'dbname' => 'u302767073_tLEcf', // Your WordPress database name
    'username' => 'root', // Your MySQL username
    'password' => 'root', // Your MySQL password
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
        'shop_vendor' => 'VENDOR',
        'seller' => 'VENDOR',
        'dokan_vendor' => 'VENDOR',
        'customer' => 'CUSTOMER',
        'subscriber' => 'CUSTOMER',
    ];
    
    return $role_map[$wp_role] ?? 'CUSTOMER';
}

/**
 * Check if WordPress string is serialized
 */
function is_serialized($data) {
    if (!is_string($data)) return false;
    $data = trim($data);
    if ('N;' == $data) return true;
    if (!preg_match('/^([adObis]):/', $data)) return false;
    return true;
}

function maybe_unserialize($data) {
    if (is_serialized($data)) {
        return unserialize($data);
    }
    return $data;
}

/**
 * Create mapping table
 */
function createMappingTable($abe_pdo) {
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
        echo "✓ Mapping table ready\n";
    } catch (PDOException $e) {
        // Table might already exist
    }
}

/**
 * Migrate Users
 */
function migrateUsers($wp_pdo, $abe_pdo, $prefix) {
    echo "\n=== Migrating Users ===\n";
    
    $stmt = $wp_pdo->prepare("
        SELECT 
            u.ID,
            u.user_email as email,
            u.user_login,
            u.user_nicename as name,
            u.user_pass as password,
            u.user_registered as created_at,
            u.display_name,
            um_role.meta_value as capabilities,
            um_phone.meta_value as phone,
            um_avatar.meta_value as avatar
        FROM {$prefix}users u
        LEFT JOIN {$prefix}usermeta um_role ON u.ID = um_role.user_id 
            AND um_role.meta_key = '{$prefix}capabilities'
        LEFT JOIN {$prefix}usermeta um_phone ON u.ID = um_phone.user_id 
            AND um_phone.meta_key IN ('phone', 'billing_phone')
        LEFT JOIN {$prefix}usermeta um_avatar ON u.ID = um_avatar.user_id 
            AND um_avatar.meta_key IN ('avatar', 'profile_picture')
        ORDER BY u.ID
    ");
    
    $stmt->execute();
    $users = $stmt->fetchAll();
    
    $migrated = 0;
    $skipped = 0;
    $user_mapping = [];
    
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
        if (!empty($wp_user['capabilities'])) {
            $capabilities = maybe_unserialize($wp_user['capabilities']);
            if (is_array($capabilities)) {
                foreach (array_keys($capabilities) as $wp_role) {
                    $mapped_role = mapUserRole($wp_role);
                    if ($mapped_role !== 'CUSTOMER') {
                        $role = $mapped_role;
                        break; // Prioritize ADMIN/VENDOR
                    }
                }
            }
        }
        
        // Generate new UUID
        $new_id = generateUUID();
        
        // Set default country to Nigeria for existing users
        $country_id = 'NG';
        
        // Insert into AbegEppMe database
        $insert_stmt = $abe_pdo->prepare("
            INSERT INTO users (
                id, email, name, password, role, status, phone, avatar, 
                country_id, preferred_currency, created_at
            ) VALUES (
                ?, ?, ?, ?, ?, 'ACTIVE', ?, ?, ?, 'NGN', ?
            )
        ");
        
        $insert_stmt->execute([
            $new_id,
            $wp_user['email'],
            $wp_user['name'] ?: $wp_user['display_name'] ?: $wp_user['user_login'],
            $wp_user['password'], // WordPress hashed password
            $role,
            $wp_user['phone'] ?: null,
            $wp_user['avatar'] ?: null,
            $country_id,
            $wp_user['created_at']
        ]);
        
        // Store mapping
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
        
        $user_mapping[$wp_user['ID']] = $new_id;
        $migrated++;
        echo "  ✓ Migrated: {$wp_user['email']} ({$role})\n";
    }
    
    echo "\nUsers migrated: {$migrated}\n";
    echo "Users skipped: {$skipped}\n";
    
    return $user_mapping;
}

/**
 * Migrate Vendor Subaccounts
 */
function migrateSubaccounts($wp_pdo, $abe_pdo, $prefix, $user_mapping) {
    echo "\n=== Migrating Vendor Subaccounts ===\n";
    
    $stmt = $wp_pdo->prepare("
        SELECT 
            u.ID as user_id,
            um_subaccount.meta_value as subaccount_code,
            um_account_number.meta_value as account_number,
            um_account_name.meta_value as account_name,
            um_bank_code.meta_value as bank_code,
            um_bank_name.meta_value as bank_name,
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
        LEFT JOIN {$prefix}usermeta um_bank_name ON u.ID = um_bank_name.user_id 
            AND um_bank_name.meta_key = 'dokan_bank_name'
        LEFT JOIN {$prefix}usermeta um_transfer_recipient ON u.ID = um_transfer_recipient.user_id 
            AND um_transfer_recipient.meta_key = 'dokan_transfer_recipient'
        WHERE um_subaccount.meta_value IS NOT NULL AND um_subaccount.meta_value != ''
    ");
    
    $stmt->execute();
    $subaccounts = $stmt->fetchAll();
    
    $migrated = 0;
    
    foreach ($subaccounts as $subaccount) {
        if (!isset($user_mapping[$subaccount['user_id']])) {
            echo "  ⚠ Skipping subaccount for WP user {$subaccount['user_id']} (user not migrated)\n";
            continue;
        }
        
        $abe_user_id = $user_mapping[$subaccount['user_id']];
        
        // Check if subaccount already exists
        $check_stmt = $abe_pdo->prepare("SELECT id FROM subaccounts WHERE user_id = ?");
        $check_stmt->execute([$abe_user_id]);
        if ($check_stmt->fetch()) {
            echo "  ⚠ Skipping subaccount for user {$abe_user_id} (already exists)\n";
            continue;
        }
        
        $insert_stmt = $abe_pdo->prepare("
            INSERT INTO subaccounts (
                id, user_id, country_id, subaccount_code, account_number, account_name, 
                bank_code, bank_name, transfer_recipient, status
            ) VALUES (
                ?, ?, 'NG', ?, ?, ?, ?, ?, ?, 'active'
            )
        ");
        
        $insert_stmt->execute([
            generateUUID(),
            $abe_user_id,
            $subaccount['subaccount_code'],
            $subaccount['account_number'] ?: null,
            $subaccount['account_name'] ?: null,
            $subaccount['bank_code'] ?: null,
            $subaccount['bank_name'] ?: null,
            $subaccount['transfer_recipient'] ?: null,
        ]);
        
        $migrated++;
        echo "  ✓ Migrated subaccount for user {$abe_user_id}\n";
    }
    
    echo "\nSubaccounts migrated: {$migrated}\n";
    
    return $migrated;
}

/**
 * Migrate Orders (WooCommerce)
 */
function migrateOrders($wp_pdo, $abe_pdo, $prefix, $user_mapping) {
    echo "\n=== Migrating Orders ===\n";
    
    // Get WooCommerce orders
    $stmt = $wp_pdo->prepare("
        SELECT 
            p.ID as order_id,
            p.post_date as created_at,
            p.post_status,
            pm_order_total.meta_value as total,
            pm_customer_id.meta_value as customer_id,
            pm_vendor_id.meta_value as vendor_id,
            pm_payment_method.meta_value as payment_method
        FROM {$prefix}posts p
        LEFT JOIN {$prefix}postmeta pm_order_total ON p.ID = pm_order_total.post_id 
            AND pm_order_total.meta_key = '_order_total'
        LEFT JOIN {$prefix}postmeta pm_customer_id ON p.ID = pm_customer_id.post_id 
            AND pm_customer_id.meta_key = '_customer_user'
        LEFT JOIN {$prefix}postmeta pm_vendor_id ON p.ID = pm_vendor_id.post_id 
            AND pm_vendor_id.meta_key = '_dokan_vendor_id'
        LEFT JOIN {$prefix}postmeta pm_payment_method ON p.ID = pm_payment_method.post_id 
            AND pm_payment_method.meta_key = '_payment_method'
        WHERE p.post_type = 'shop_order'
        ORDER BY p.ID
    ");
    
    $stmt->execute();
    $orders = $stmt->fetchAll();
    
    $migrated = 0;
    $skipped = 0;
    
    foreach ($orders as $wp_order) {
        if (empty($wp_order['customer_id']) || !isset($user_mapping[$wp_order['customer_id']])) {
            echo "  ⚠ Skipping order {$wp_order['order_id']} (customer not found)\n";
            $skipped++;
            continue;
        }
        
        $customer_id = $user_mapping[$wp_order['customer_id']];
        $vendor_id = null;
        
        if (!empty($wp_order['vendor_id']) && isset($user_mapping[$wp_order['vendor_id']])) {
            $vendor_id = $user_mapping[$wp_order['vendor_id']];
        }
        
        if (!$vendor_id) {
            echo "  ⚠ Skipping order {$wp_order['order_id']} (vendor not found)\n";
            $skipped++;
            continue;
        }
        
        // Map WooCommerce status to our status
        $status_map = [
            'wc-pending' => 'PENDING',
            'wc-processing' => 'PROCESSING',
            'wc-on-hold' => 'PENDING',
            'wc-completed' => 'COMPLETED',
            'wc-cancelled' => 'CANCELLED',
            'wc-refunded' => 'REFUNDED',
            'wc-failed' => 'PENDING',
        ];
        $status = $status_map[$wp_order['post_status']] ?? 'PENDING';
        
        $total = floatval($wp_order['total'] ?? 0);
        $order_number = 'ORD-' . $wp_order['order_id'];
        
        $new_order_id = generateUUID();
        
        // Calculate breakdown (simplified - you may need to get actual breakdown from meta)
        $subtotal = $total * 0.9; // Estimate
        $service_charge = 250;
        $vat_amount = ($subtotal + $service_charge) * 0.075;
        $final_total = $subtotal + $service_charge + $vat_amount;
        
        $insert_stmt = $abe_pdo->prepare("
            INSERT INTO orders (
                id, order_number, customer_id, vendor_id, country_id, currency_code,
                subtotal, service_charge, vat_amount, total, status, created_at
            ) VALUES (
                ?, ?, ?, ?, 'NG', 'NGN', ?, ?, ?, ?, ?, ?
            )
        ");
        
        $insert_stmt->execute([
            $new_order_id,
            $order_number,
            $customer_id,
            $vendor_id,
            $subtotal,
            $service_charge,
            $vat_amount,
            $final_total,
            $status,
            $wp_order['created_at']
        ]);
        
        $migrated++;
        echo "  ✓ Migrated order {$order_number}\n";
    }
    
    echo "\nOrders migrated: {$migrated}\n";
    echo "Orders skipped: {$skipped}\n";
    
    return $migrated;
}

// Run migration
echo "========================================\n";
echo "WordPress to AbegEppMe Complete Migration\n";
echo "========================================\n\n";

echo "⚠️  WARNING: This will migrate all data from WordPress to AbegEppMe.\n";
echo "⚠️  Make sure you have backups of both databases!\n\n";

// Create mapping table
createMappingTable($abe_pdo);

// Uncomment to run migration
// $user_mapping = migrateUsers($wp_pdo, $abe_pdo, $wp_db_config['prefix']);
// migrateSubaccounts($wp_pdo, $abe_pdo, $wp_db_config['prefix'], $user_mapping);
// migrateOrders($wp_pdo, $abe_pdo, $wp_db_config['prefix'], $user_mapping);

echo "\n✓ Migration script ready. Uncomment the migration functions to run.\n";
echo "✓ Review the script and update database credentials before running.\n";
