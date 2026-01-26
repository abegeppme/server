<?php
/**
 * Migration Service
 * Handles WordPress to AbegEppMe database migration
 */

require_once __DIR__ . '/../../config/database.php';

class MigrationService {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * Generate UUID v4
     */
    private function generateUUID() {
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
    private function mapUserRole($wp_role) {
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
    private function is_serialized($data) {
        if (!is_string($data)) return false;
        $data = trim($data);
        if ('N;' == $data) return true;
        if (!preg_match('/^([adObis]):/', $data)) return false;
        return true;
    }
    
    private function maybe_unserialize($data) {
        if ($this->is_serialized($data)) {
            return unserialize($data);
        }
        return $data;
    }
    
    /**
     * Create mapping table
     */
    public function createMappingTable() {
        try {
            $this->db->exec("
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
    }
    
    /**
     * Migrate users from WordPress
     */
    public function migrateUsers(PDO $wp_pdo, string $prefix): array {
        // Check if country_id column exists (once per migration)
        $columnsStmt = $this->db->query("SHOW COLUMNS FROM users LIKE 'country_id'");
        $hasCountryId = $columnsStmt->rowCount() > 0;
        
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
            // Check if user already exists by email
            $check_stmt = $this->db->prepare("SELECT id FROM users WHERE email = ?");
            $check_stmt->execute([$wp_user['email']]);
            $existing = $check_stmt->fetch();
            
            if ($existing) {
                // Check if mapping exists
                $mapping_check = $this->db->prepare("
                    SELECT abegeppme_id FROM wordpress_user_mapping WHERE wordpress_id = ?
                ");
                $mapping_check->execute([$wp_user['ID']]);
                $mapping = $mapping_check->fetch();
                
                if ($mapping) {
                    // Mapping exists, use existing user
                    $user_mapping[$wp_user['ID']] = $mapping['abegeppme_id'];
                } else {
                    // User exists but no mapping, create mapping
                    $mapping_stmt = $this->db->prepare("
                        INSERT INTO wordpress_user_mapping (wordpress_id, abegeppme_id, email)
                        VALUES (?, ?, ?)
                        ON DUPLICATE KEY UPDATE abegeppme_id = VALUES(abegeppme_id)
                    ");
                    $mapping_stmt->execute([
                        $wp_user['ID'],
                        $existing['id'],
                        $wp_user['email']
                    ]);
                    $user_mapping[$wp_user['ID']] = $existing['id'];
                }
                $skipped++;
                continue;
            }
            
            // Extract role
            $role = 'CUSTOMER';
            if (!empty($wp_user['capabilities'])) {
                $capabilities = $this->maybe_unserialize($wp_user['capabilities']);
                if (is_array($capabilities)) {
                    foreach (array_keys($capabilities) as $wp_role) {
                        $mapped_role = $this->mapUserRole($wp_role);
                        if ($mapped_role !== 'CUSTOMER') {
                            $role = $mapped_role;
                            break;
                        }
                    }
                }
            }
            
            $new_id = $this->generateUUID();
            
            // Insert user
            if ($hasCountryId) {
                $insert_stmt = $this->db->prepare("
                    INSERT INTO users (
                        id, email, name, password, role, status, phone, avatar, 
                        country_id, preferred_currency, created_at
                    ) VALUES (?, ?, ?, ?, ?, 'ACTIVE', ?, ?, ?, 'NGN', ?)
                ");
                $insert_stmt->execute([
                    $new_id,
                    $wp_user['email'],
                    $wp_user['name'] ?: $wp_user['display_name'] ?: $wp_user['user_login'],
                    $wp_user['password'],
                    $role,
                    $wp_user['phone'] ?: null,
                    $wp_user['avatar'] ?: null,
                    'NG',
                    $wp_user['created_at']
                ]);
            } else {
                $insert_stmt = $this->db->prepare("
                    INSERT INTO users (
                        id, email, name, password, role, status, phone, avatar, created_at
                    ) VALUES (?, ?, ?, ?, ?, 'ACTIVE', ?, ?, ?)
                ");
                $insert_stmt->execute([
                    $new_id,
                    $wp_user['email'],
                    $wp_user['name'] ?: $wp_user['display_name'] ?: $wp_user['user_login'],
                    $wp_user['password'],
                    $role,
                    $wp_user['phone'] ?: null,
                    $wp_user['avatar'] ?: null,
                    $wp_user['created_at']
                ]);
            }
            
            // Store mapping
            $mapping_stmt = $this->db->prepare("
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
        }
        
        return [
            'migrated' => $migrated,
            'skipped' => $skipped,
            'mapping' => $user_mapping,
        ];
    }
    
    /**
     * Migrate vendor subaccounts
     */
    public function migrateSubaccounts(PDO $wp_pdo, string $prefix, array $user_mapping): array {
        // Check if columns exist (once per migration)
        $subaccountColumnsStmt = $this->db->query("SHOW COLUMNS FROM subaccounts LIKE 'country_id'");
        $subaccountHasCountryId = $subaccountColumnsStmt->rowCount() > 0;
        $bankNameStmt = $this->db->query("SHOW COLUMNS FROM subaccounts LIKE 'bank_name'");
        $subaccountHasBankName = $bankNameStmt->rowCount() > 0;
        
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
        $skipped = 0;
        
        foreach ($subaccounts as $subaccount) {
            if (!isset($user_mapping[$subaccount['user_id']])) {
                $skipped++;
                continue;
            }
            
            $abe_user_id = $user_mapping[$subaccount['user_id']];
            
            // Check if subaccount already exists (by user_id or subaccount_code)
            $check_stmt = $this->db->prepare("
                SELECT id FROM subaccounts 
                WHERE user_id = ? OR subaccount_code = ?
            ");
            $check_stmt->execute([$abe_user_id, $subaccount['subaccount_code']]);
            if ($check_stmt->fetch()) {
                $skipped++; // Skip if already exists
                continue;
            }
            
            // Build INSERT statement based on available columns
            $columns = ['id', 'user_id'];
            $placeholders = ['?', '?'];
            $values = [$this->generateUUID(), $abe_user_id];
            
            if ($subaccountHasCountryId) {
                $columns[] = 'country_id';
                $placeholders[] = '?';
                $values[] = 'NG';
            }
            
            $columns[] = 'subaccount_code';
            $placeholders[] = '?';
            $values[] = $subaccount['subaccount_code'];
            
            if ($subaccount['account_number']) {
                $columns[] = 'account_number';
                $placeholders[] = '?';
                $values[] = $subaccount['account_number'];
            }
            
            if ($subaccount['account_name']) {
                $columns[] = 'account_name';
                $placeholders[] = '?';
                $values[] = $subaccount['account_name'];
            }
            
            if ($subaccount['bank_code']) {
                $columns[] = 'bank_code';
                $placeholders[] = '?';
                $values[] = $subaccount['bank_code'];
            }
            
            if ($subaccountHasBankName && $subaccount['bank_name']) {
                $columns[] = 'bank_name';
                $placeholders[] = '?';
                $values[] = $subaccount['bank_name'];
            }
            
            if ($subaccount['transfer_recipient']) {
                $columns[] = 'transfer_recipient';
                $placeholders[] = '?';
                $values[] = $subaccount['transfer_recipient'];
            }
            
            $columns[] = 'status';
            $placeholders[] = '?';
            $values[] = 'active';
            
            $columnsStr = implode(', ', $columns);
            $placeholdersStr = implode(', ', $placeholders);
            
            $insert_stmt = $this->db->prepare("
                INSERT INTO subaccounts ({$columnsStr})
                VALUES ({$placeholdersStr})
            ");
            $insert_stmt->execute($values);
            
            $migrated++;
        }
        
        return [
            'migrated' => $migrated,
            'skipped' => $skipped,
        ];
    }
    
    /**
     * Migrate orders
     */
    public function migrateOrders(PDO $wp_pdo, string $prefix, array $user_mapping): array {
        // Check if country_id and currency_code columns exist (once per migration)
        $orderColumnsStmt = $this->db->query("SHOW COLUMNS FROM orders LIKE 'country_id'");
        $orderHasCountryId = $orderColumnsStmt->rowCount() > 0;
        $orderCurrencyStmt = $this->db->query("SHOW COLUMNS FROM orders LIKE 'currency_code'");
        $orderHasCurrency = $orderCurrencyStmt->rowCount() > 0;
        
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
                $skipped++;
                continue;
            }
            
            $customer_id = $user_mapping[$wp_order['customer_id']];
            $vendor_id = null;
            
            if (!empty($wp_order['vendor_id']) && isset($user_mapping[$wp_order['vendor_id']])) {
                $vendor_id = $user_mapping[$wp_order['vendor_id']];
            }
            
            if (!$vendor_id) {
                $skipped++;
                continue;
            }
            
            // Check if order already exists (by checking if order_number pattern exists)
            $order_number = 'ORD-' . $wp_order['order_id'];
            $order_check = $this->db->prepare("SELECT id FROM orders WHERE order_number = ?");
            $order_check->execute([$order_number]);
            if ($order_check->fetch()) {
                $skipped++; // Order already migrated
                continue;
            }
            
            // Map status
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
            
            $new_order_id = $this->generateUUID();
            
            // Calculate breakdown
            $subtotal = $total * 0.9;
            $service_charge = 250;
            $vat_amount = ($subtotal + $service_charge) * 0.075;
            $final_total = $subtotal + $service_charge + $vat_amount;
            
            if ($orderHasCountryId && $orderHasCurrency) {
                $insert_stmt = $this->db->prepare("
                    INSERT INTO orders (
                        id, order_number, customer_id, vendor_id, country_id, currency_code,
                        subtotal, service_charge, vat_amount, total, status, created_at
                    ) VALUES (?, ?, ?, ?, 'NG', 'NGN', ?, ?, ?, ?, ?, ?)
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
            } else {
                $insert_stmt = $this->db->prepare("
                    INSERT INTO orders (
                        id, order_number, customer_id, vendor_id,
                        subtotal, service_charge, vat_amount, total, status, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
            }
            
            $migrated++;
        }
        
        return [
            'migrated' => $migrated,
            'skipped' => $skipped,
        ];
    }
}
