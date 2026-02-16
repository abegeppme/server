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
     * Normalize legacy WordPress media URLs
     * - If value is already an absolute http/https URL, return as-is
     * - If it's a relative path (e.g. 2023/01/image.jpg), prefix with WP_UPLOADS_BASE_URL
     * - If WP_UPLOADS_BASE_URL is not set, return original value
     */
    private function normalizeMediaUrl(?string $value): ?string {
        if (!$value) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        // Absolute URL already
        if (preg_match('~^https?://~i', $trimmed)) {
            return $trimmed;
        }

        $base = getenv('WP_UPLOADS_BASE_URL') ?: '';
        if ($base === '') {
            // No base configured, return as-is
            return $trimmed;
        }

        $base = rtrim($base, '/');
        $path = ltrim($trimmed, '/');

        return $base . '/' . $path;
    }

    /**
     * Get attachment URL by attachment ID
     */
    private function getAttachmentUrlById(PDO $wp_pdo, string $prefix, $attachmentId): ?string {
        if (!$attachmentId) {
            return null;
        }

        $attachmentId = intval($attachmentId);
        if ($attachmentId <= 0) {
            return null;
        }

        $stmt = $wp_pdo->prepare("
            SELECT guid 
            FROM {$prefix}posts 
            WHERE ID = ? AND post_type = 'attachment'
            LIMIT 1
        ");
        $stmt->execute([$attachmentId]);
        $row = $stmt->fetch();
        if (!$row || empty($row['guid'])) {
            return null;
        }

        return $this->normalizeMediaUrl($row['guid']);
    }

    /**
     * Build a full URL for a WordPress media value
     * Accepts:
     *  - absolute URL (returns as-is)
     *  - relative path (prefixes WP_UPLOADS_BASE_URL)
     *  - numeric attachment ID (looks up attachment guid)
     */
    private function resolveMediaValue(PDO $wp_pdo, string $prefix, $value): ?string {
        if ($value === null || $value === '') {
            return null;
        }

        // If numeric, treat as attachment ID
        if (is_numeric($value)) {
            return $this->getAttachmentUrlById($wp_pdo, $prefix, $value);
        }

        // If array with url or id
        if (is_array($value)) {
            if (!empty($value['url'])) {
                return $this->normalizeMediaUrl($value['url']);
            }
            if (!empty($value['id'])) {
                return $this->getAttachmentUrlById($wp_pdo, $prefix, $value['id']);
            }
        }

        // String path or URL
        return $this->normalizeMediaUrl((string)$value);
    }
    
    /**
     * Check if column exists in table
     */
    private function checkColumnExists($table, $column) {
        try {
            $stmt = $this->db->prepare("
                SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND COLUMN_NAME = ?
            ");
            $stmt->execute([$table, $column]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            return false;
        }
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
        $hasStoreImage = $this->checkColumnExists('users', 'store_image');
        $hasBusinessImage = $this->checkColumnExists('users', 'business_image');
        $hasLogo = $this->checkColumnExists('users', 'logo');
        $hasBusinessName = $this->checkColumnExists('users', 'business_name');
        $hasDescription = $this->checkColumnExists('users', 'description');
        $hasBanner = $this->checkColumnExists('users', 'banner');

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
                um_avatar.meta_value as avatar,
                um_profile.meta_value as dokan_profile_settings
            FROM {$prefix}users u
            LEFT JOIN {$prefix}usermeta um_role ON u.ID = um_role.user_id 
                AND um_role.meta_key = '{$prefix}capabilities'
            LEFT JOIN {$prefix}usermeta um_phone ON u.ID = um_phone.user_id 
                AND um_phone.meta_key = 'phone'
            LEFT JOIN {$prefix}usermeta um_avatar ON u.ID = um_avatar.user_id 
                AND um_avatar.meta_key = 'avatar'
            LEFT JOIN {$prefix}usermeta um_profile ON u.ID = um_profile.user_id
                AND um_profile.meta_key = 'dokan_profile_settings'
            ORDER BY u.ID
        ");
        
        $stmt->execute();
        $users = $stmt->fetchAll();
        
        $migrated = 0;
        $skipped = 0;
        $user_mapping = [];
        
        foreach ($users as $wp_user) {
            // Determine role
            $capabilities = $this->maybe_unserialize($wp_user['capabilities']);
            $role = 'CUSTOMER';
            if (is_array($capabilities)) {
                if (isset($capabilities['administrator'])) {
                    $role = 'ADMIN';
                } elseif (isset($capabilities['shop_vendor']) || 
                          isset($capabilities['seller']) || 
                          isset($capabilities['dokan_vendor'])) {
                    $role = 'VENDOR';
                }
            }
            
            // Check if user already exists
            $check_stmt = $this->db->prepare("SELECT id FROM users WHERE email = ?");
            $check_stmt->execute([$wp_user['email']]);
            if ($check_stmt->fetch()) {
                // Get existing user ID for mapping
                $existing_stmt = $this->db->prepare("SELECT id FROM users WHERE email = ?");
                $existing_stmt->execute([$wp_user['email']]);
                $existing = $existing_stmt->fetch();
                if ($existing) {
                    $user_mapping[$wp_user['ID']] = $existing['id'];
                    $skipped++;
                }
                continue;
            }
            
            $new_id = $this->generateUUID();
            
            // Build INSERT statement dynamically based on available columns
            $columns = ['id', 'email', 'name', 'password', 'role', 'status', 'phone', 'avatar', 'created_at'];
            $placeholders = ['?', '?', '?', '?', '?', 'ACTIVE', '?', '?', '?'];

            // Normalize avatar path using WP_UPLOADS_BASE_URL if needed
            $avatarUrl = $this->normalizeMediaUrl($wp_user['avatar'] ?? null);

            // Parse Dokan profile settings for vendor media
            $profileSettings = $this->maybe_unserialize($wp_user['dokan_profile_settings'] ?? null);
            $storeName = null;
            $storeBanner = null;
            $storeLogo = null;
            $storeGravatar = null;
            $storeDescription = null;

            if (is_array($profileSettings)) {
                $storeName = $profileSettings['store_name'] ?? null;
                $storeBanner = $profileSettings['banner'] ?? null;
                $storeLogo = $profileSettings['logo'] ?? null;
                $storeGravatar = $profileSettings['gravatar'] ?? null;
                $storeDescription = $profileSettings['store_description'] ?? ($profileSettings['description'] ?? null);
            }

            $storeBannerUrl = $this->resolveMediaValue($wp_pdo, $prefix, $storeBanner);
            $storeLogoUrl = $this->resolveMediaValue($wp_pdo, $prefix, $storeLogo);
            $storeGravatarUrl = $this->resolveMediaValue($wp_pdo, $prefix, $storeGravatar);

            $values = [
                $new_id,
                $wp_user['email'],
                $wp_user['name'] ?: $wp_user['display_name'] ?: $wp_user['user_login'],
                $wp_user['password'], // Keep WordPress password hash
                $role,
                $wp_user['phone'] ?: null,
                $avatarUrl,
                $wp_user['created_at']
            ];

            // Optional vendor fields (if columns exist)
            if ($hasBusinessName) {
                $columns[] = 'business_name';
                $placeholders[] = '?';
                $values[] = $storeName;
            }

            if ($hasDescription) {
                $columns[] = 'description';
                $placeholders[] = '?';
                $values[] = $storeDescription;
            }

            if ($hasBanner) {
                $columns[] = 'banner';
                $placeholders[] = '?';
                $values[] = $storeBannerUrl;
            }

            if ($hasStoreImage) {
                $columns[] = 'store_image';
                $placeholders[] = '?';
                $values[] = $storeBannerUrl;
            }

            if ($hasBusinessImage) {
                $columns[] = 'business_image';
                $placeholders[] = '?';
                $values[] = $storeLogoUrl;
            }

            if ($hasLogo) {
                $columns[] = 'logo';
                $placeholders[] = '?';
                $values[] = $storeLogoUrl ?: $storeGravatarUrl;
            }
            
            if ($hasCountryId) {
                $columns[] = 'country_id';
                $placeholders[] = '?';
                $values[] = 'NG'; // Default country
            }
            
            // Check for is_vendor and is_customer columns
            $hasIsVendor = $this->checkColumnExists('users', 'is_vendor');
            $hasIsCustomer = $this->checkColumnExists('users', 'is_customer');
            
            if ($hasIsVendor) {
                $columns[] = 'is_vendor';
                $placeholders[] = '?';
                $values[] = ($role === 'VENDOR' || $role === 'ADMIN') ? 1 : 0;
            }
            
            if ($hasIsCustomer) {
                $columns[] = 'is_customer';
                $placeholders[] = '?';
                $values[] = 1; // All users can be customers
            }
            
            $insert_stmt = $this->db->prepare("
                INSERT INTO users (" . implode(', ', $columns) . ")
                VALUES (" . implode(', ', $placeholders) . ")
            ");
            
            $insert_stmt->execute($values);
            
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
     * Migrate services (WooCommerce products) and their media
     */
    public function migrateServices(PDO $wp_pdo, string $prefix, array $user_mapping): array {
        $tableCheck = $this->db->query("SHOW TABLES LIKE 'services'");
        if ($tableCheck->rowCount() === 0) {
            return ['migrated' => 0, 'skipped' => 0, 'message' => 'services table does not exist'];
        }

        $hasCountryId = $this->checkColumnExists('services', 'country_id');
        $hasCurrencyCode = $this->checkColumnExists('services', 'currency_code');

        // Fetch products with vendor mapping
        $stmt = $wp_pdo->prepare("
            SELECT 
                p.ID as product_id,
                p.post_author as vendor_id,
                p.post_title as title,
                p.post_content as description,
                p.post_status as status,
                pm_price.meta_value as price,
                pm_regular_price.meta_value as regular_price,
                pm_sale_price.meta_value as sale_price,
                pm_thumbnail.meta_value as thumbnail_id,
                pm_gallery.meta_value as gallery_ids
            FROM {$prefix}posts p
            LEFT JOIN {$prefix}postmeta pm_price 
                ON p.ID = pm_price.post_id AND pm_price.meta_key = '_price'
            LEFT JOIN {$prefix}postmeta pm_regular_price 
                ON p.ID = pm_regular_price.post_id AND pm_regular_price.meta_key = '_regular_price'
            LEFT JOIN {$prefix}postmeta pm_sale_price 
                ON p.ID = pm_sale_price.post_id AND pm_sale_price.meta_key = '_sale_price'
            LEFT JOIN {$prefix}postmeta pm_thumbnail 
                ON p.ID = pm_thumbnail.post_id AND pm_thumbnail.meta_key = '_thumbnail_id'
            LEFT JOIN {$prefix}postmeta pm_gallery 
                ON p.ID = pm_gallery.post_id AND pm_gallery.meta_key = '_product_image_gallery'
            WHERE p.post_type = 'product'
        ");
        $stmt->execute();
        $products = $stmt->fetchAll();

        $migrated = 0;
        $skipped = 0;

        foreach ($products as $product) {
            if (!isset($user_mapping[$product['vendor_id']])) {
                $skipped++;
                continue;
            }

            $vendorId = $user_mapping[$product['vendor_id']];

            // Check if service already exists (by title + vendor)
            $checkStmt = $this->db->prepare("
                SELECT id FROM services WHERE vendor_id = ? AND title = ?
            ");
            $checkStmt->execute([$vendorId, $product['title']]);
            if ($checkStmt->fetch()) {
                $skipped++;
                continue;
            }

            // Price fallback
            $price = $product['price'] ?? $product['sale_price'] ?? $product['regular_price'] ?? 0;
            $price = floatval($price);

            // Resolve images
            $images = [];
            $thumbnailUrl = $this->getAttachmentUrlById($wp_pdo, $prefix, $product['thumbnail_id']);
            if ($thumbnailUrl) {
                $images[] = $thumbnailUrl;
            }

            $galleryUrls = [];
            if (!empty($product['gallery_ids'])) {
                $ids = array_filter(array_map('trim', explode(',', $product['gallery_ids'])));
                foreach ($ids as $id) {
                    $url = $this->getAttachmentUrlById($wp_pdo, $prefix, $id);
                    if ($url) {
                        $galleryUrls[] = $url;
                    }
                }
            }

            $status = ($product['status'] === 'publish') ? 'ACTIVE' : 'DRAFT';

            $columns = ['id', 'vendor_id', 'title', 'description', 'price', 'status', 'images', 'gallery', 'created_at', 'updated_at'];
            $values = [
                $this->generateUUID(),
                $vendorId,
                $product['title'],
                $product['description'],
                $price,
                $status,
                json_encode($images),
                json_encode($galleryUrls),
                date('Y-m-d H:i:s'),
                date('Y-m-d H:i:s'),
            ];

            if ($hasCountryId) {
                $columns[] = 'country_id';
                $values[] = 'NG';
            }
            if ($hasCurrencyCode) {
                $columns[] = 'currency_code';
                $values[] = 'NGN';
            }

            $placeholders = rtrim(str_repeat('?,', count($values)), ',');
            $insert = $this->db->prepare("
                INSERT INTO services (" . implode(', ', $columns) . ")
                VALUES (" . $placeholders . ")
            ");
            $insert->execute($values);
            $migrated++;
        }

        return [
            'migrated' => $migrated,
            'skipped' => $skipped,
        ];
    }
    
    /**
     * Migrate vendor categories from WordPress
     *
     * NOTE: In your old site, service providers chose **WooCommerce product categories**
     * (taxonomy = `product_cat`) via their products, not via user meta.
     *
     * This method:
     * - Finds all published WooCommerce products for each vendor (post_author = vendor user ID)
     * - Collects all `product_cat` terms attached to those products
     * - Maps each WP term (name/slug) to our `service_categories` table
     * - Creates rows in `service_provider_categories` linking vendor → category
     */
    public function migrateVendorCategories(PDO $wp_pdo, string $prefix, array $user_mapping): array {
        // Check if service_provider_categories table exists
        $tableCheck = $this->db->query("SHOW TABLES LIKE 'service_provider_categories'");
        if ($tableCheck->rowCount() === 0) {
            return ['migrated' => 0, 'skipped' => 0, 'message' => 'service_provider_categories table does not exist'];
        }
        
        /**
         * Strategy:
         *  - wp_posts.post_type = 'product' (WooCommerce products)
         *  - wp_posts.post_status IN ('publish', 'wc-completed', 'wc-processing', 'wc-on-hold') etc.
         *  - wp_posts.post_author = vendor user ID (Dokan vendor)
         *  - wp_term_relationships.object_id = wp_posts.ID
         *  - wp_term_taxonomy.taxonomy = 'product_cat'
         */
        $stmt = $wp_pdo->prepare("
            SELECT DISTINCT
                p.post_author AS vendor_id,
                t.term_id,
                t.name AS category_name,
                t.slug AS category_slug
            FROM {$prefix}posts p
            INNER JOIN {$prefix}term_relationships tr 
                ON tr.object_id = p.ID
            INNER JOIN {$prefix}term_taxonomy tt 
                ON tr.term_taxonomy_id = tt.term_taxonomy_id
            INNER JOIN {$prefix}terms t 
                ON tt.term_id = t.term_id
            WHERE tt.taxonomy = 'product_cat'
              AND p.post_type = 'product'
              AND p.post_status IN ('publish', 'wc-completed', 'wc-processing', 'wc-on-hold', 'wc-pending', 'wc-on-hold')
        ");
        
        $stmt->execute();
        $vendor_categories = $stmt->fetchAll();
        
        $migrated = 0;
        $skipped = 0;
        $category_mapping = [];
        
        // First, create a mapping of WordPress category names to our category IDs
        foreach ($vendor_categories as $vc) {
            $category_name = $vc['category_name'];
            
            // Try to find matching category in our system (by name or slug)
            $cat_stmt = $this->db->prepare("
                SELECT id FROM service_categories 
                WHERE name = ? OR slug = ?
                LIMIT 1
            ");
            $cat_stmt->execute([$category_name, $vc['category_slug']]);
            $category = $cat_stmt->fetch();
            
            if ($category) {
                $category_mapping[$vc['term_id']] = $category['id'];
            }
        }
        
        // Now assign categories to vendors
        foreach ($vendor_categories as $vc) {
            $wp_vendor_id = $vc['vendor_id'];
            
            // Skip if vendor wasn't migrated
            if (!isset($user_mapping[$wp_vendor_id])) {
                $skipped++;
                continue;
            }
            
            $abe_vendor_id = $user_mapping[$wp_vendor_id];
            
            // Skip if category wasn't found in our system
            if (!isset($category_mapping[$vc['term_id']])) {
                $skipped++;
                continue;
            }
            
            $category_id = $category_mapping[$vc['term_id']];
            
            // Check if already assigned
            $check_stmt = $this->db->prepare("
                SELECT id FROM service_provider_categories 
                WHERE vendor_id = ? AND category_id = ?
            ");
            $check_stmt->execute([$abe_vendor_id, $category_id]);
            if ($check_stmt->fetch()) {
                $skipped++;
                continue;
            }
            
            // Assign category (first one as primary)
            $is_primary = ($migrated === 0 || !isset($primary_set[$abe_vendor_id])) ? 1 : 0;
            if ($is_primary) {
                $primary_set[$abe_vendor_id] = true;
            }
            
            $insert_stmt = $this->db->prepare("
                INSERT INTO service_provider_categories (id, vendor_id, category_id, is_primary)
                VALUES (?, ?, ?, ?)
            ");
            $insert_stmt->execute([
                $this->generateUUID(),
                $abe_vendor_id,
                $category_id,
                $is_primary
            ]);
            
            $migrated++;
        }
        
        return [
            'migrated' => $migrated,
            'skipped' => $skipped,
            'category_mappings' => count($category_mapping),
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
                $values[] = 'NG'; // Default country
            }
            
            $columns[] = 'subaccount_code';
            $placeholders[] = '?';
            $values[] = $subaccount['subaccount_code'];
            
            $columns[] = 'account_number';
            $placeholders[] = '?';
            $values[] = $subaccount['account_number'] ?: null;
            
            $columns[] = 'account_name';
            $placeholders[] = '?';
            $values[] = $subaccount['account_name'] ?: null;
            
            if ($subaccountHasBankName) {
                $columns[] = 'bank_name';
                $placeholders[] = '?';
                $values[] = $subaccount['bank_name'] ?: null;
            }
            
            $columns[] = 'bank_code';
            $placeholders[] = '?';
            $values[] = $subaccount['bank_code'] ?: null;
            
            $columns[] = 'transfer_recipient';
            $placeholders[] = '?';
            $values[] = $subaccount['transfer_recipient'] ?: null;
            
            $columns[] = 'status';
            $placeholders[] = '?';
            $values[] = 'active';
            
            $insert_stmt = $this->db->prepare("
                INSERT INTO subaccounts (" . implode(', ', $columns) . ")
                VALUES (" . implode(', ', $placeholders) . ")
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
     * Migrate orders from WordPress
     */
    public function migrateOrders(PDO $wp_pdo, string $prefix, array $user_mapping): array {
        // Check if columns exist
        $orderColumnsStmt = $this->db->query("SHOW COLUMNS FROM orders LIKE 'country_id'");
        $orderHasCountryId = $orderColumnsStmt->rowCount() > 0;
        $currencyStmt = $this->db->query("SHOW COLUMNS FROM orders LIKE 'currency_code'");
        $orderHasCurrencyCode = $currencyStmt->rowCount() > 0;
        
        $stmt = $wp_pdo->prepare("
            SELECT 
                p.ID as order_id,
                p.post_date,
                p.post_status,
                pm_order_total.meta_value as order_total,
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
            AND p.post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold')
        ");
        
        $stmt->execute();
        $orders = $stmt->fetchAll();
        
        $migrated = 0;
        $skipped = 0;
        
        foreach ($orders as $wp_order) {
            // Map customer and vendor IDs
            if (!isset($user_mapping[$wp_order['customer_id']]) || 
                !isset($user_mapping[$wp_order['vendor_id']])) {
                $skipped++;
                continue;
            }
            
            $abe_customer_id = $user_mapping[$wp_order['customer_id']];
            $abe_vendor_id = $user_mapping[$wp_order['vendor_id']];
            
            // Check if order already exists
            $check_stmt = $this->db->prepare("
                SELECT id FROM orders WHERE order_number = ?
            ");
            $order_number = 'WP-' . $wp_order['order_id'];
            $check_stmt->execute([$order_number]);
            if ($check_stmt->fetch()) {
                $skipped++;
                continue;
            }
            
            $new_order_id = $this->generateUUID();
            $order_total = floatval($wp_order['order_total'] ?: 0);
            
            // Map status
            $status_map = [
                'wc-completed' => 'COMPLETED',
                'wc-processing' => 'PROCESSING',
                'wc-on-hold' => 'ON_HOLD',
            ];
            $status = $status_map[$wp_order['post_status']] ?? 'PENDING';
            
            // Calculate breakdown (simplified - you may need to get actual breakdown from WP meta)
            $subtotal = $order_total * 0.9; // Estimate
            $service_charge = 250;
            $vat_amount = ($subtotal + $service_charge) * 0.075;
            $final_total = $subtotal + $service_charge + $vat_amount;
            
            // Build INSERT statement
            $columns = ['id', 'order_number', 'customer_id', 'vendor_id', 'subtotal', 'service_charge', 'vat_amount', 'total', 'status', 'created_at'];
            $placeholders = ['?', '?', '?', '?', '?', '?', '?', '?', '?', '?'];
            $values = [
                $new_order_id,
                $order_number,
                $abe_customer_id,
                $abe_vendor_id,
                $subtotal,
                $service_charge,
                $vat_amount,
                $final_total,
                $status,
                $wp_order['post_date']
            ];
            
            if ($orderHasCountryId) {
                array_splice($columns, 4, 0, 'country_id');
                array_splice($values, 4, 0, 'NG');
            }
            
            if ($orderHasCurrencyCode) {
                $insertPos = $orderHasCountryId ? 5 : 4;
                array_splice($columns, $insertPos, 0, 'currency_code');
                array_splice($values, $insertPos, 0, 'NGN');
            }
            
            $insert_stmt = $this->db->prepare("
                INSERT INTO orders (" . implode(', ', $columns) . ")
                VALUES (" . implode(', ', array_fill(0, count($values), '?')) . ")
            ");
            
            $insert_stmt->execute($values);
            $migrated++;
        }
        
        return [
            'migrated' => $migrated,
            'skipped' => $skipped,
        ];
    }
}
