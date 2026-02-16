<?php
/**
 * Migration Controller
 * Handles WordPress database migration via API
 */

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../services/MigrationService.php';

class MigrationController extends BaseController {
    private $auth;
    private $migrationService;
    
    public function __construct() {
        parent::__construct();
        $this->auth = new AuthMiddleware();
        $this->migrationService = new MigrationService();
    }
    
    public function index() {
        $appEnv = getenv('APP_ENV') ?: 'development';
        $requiresAuth = $appEnv !== 'development';
        
        $this->sendResponse([
            'message' => 'WordPress Migration API',
            'environment' => $appEnv,
            'requires_auth' => $requiresAuth,
            'endpoints' => [
                'POST /api/migration' => 'Run WordPress migration (action: run) or seed admin (action: seed-admin)',
                'GET /api/migration/status' => 'Get migration status',
            ],
            'note' => $requiresAuth 
                ? 'Admin authentication required in production'
                : 'No authentication required in development mode',
        ]);
    }
    
    public function create() {
        $data = $this->getRequestBody();
        $action = $data['action'] ?? null;
        
        // Allow migration without auth in development, or require admin in production
        $appEnv = getenv('APP_ENV') ?: 'development';
        
        if ($appEnv !== 'development') {
            // In production, require admin authentication
            $user = $this->auth->requireAdmin();
        }
        // In development, allow without auth (with warning)
        
        if ($action === 'run') {
            $this->runMigration($data);
        } elseif ($action === 'seed-admin') {
            $this->seedAdminAccount($data);
        } else {
            $this->sendError('Invalid action. Use action: "run" or "seed-admin"', 400);
        }
    }
    
    private function seedAdminAccount($data) {
        $email = $data['email'] ?? 'admin@abegeppme.com';
        $password = $data['password'] ?? 'admin123';
        $name = $data['name'] ?? 'Admin User';
        
        // Check if admin already exists
        $checkStmt = $this->db->prepare("
            SELECT id, email, role FROM users 
            WHERE email = ? OR role = 'ADMIN' 
            LIMIT 1
        ");
        $checkStmt->execute([$email]);
        $existing = $checkStmt->fetch();
        
        if ($existing) {
            $this->sendResponse([
                'message' => 'Admin account already exists',
                'email' => $existing['email'],
                'id' => $existing['id'],
                'role' => $existing['role'],
                'note' => 'Use existing credentials to sign in',
            ]);
            return;
        }
        
        // Create admin user
        $userId = $this->generateUUID();
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        
        $insertStmt = $this->db->prepare("
            INSERT INTO users (id, email, name, password, role, status, country_id, preferred_currency)
            VALUES (?, ?, ?, ?, 'ADMIN', 'ACTIVE', 'NG', 'NGN')
        ");
        $insertStmt->execute([$userId, $email, $name, $hashedPassword]);
        
        $this->sendResponse([
            'message' => 'Admin account created successfully',
            'email' => $email,
            'password' => $password,
            'id' => $userId,
            'note' => 'Please change the password after first login',
            'sign_in_url' => '/api/auth',
        ], 201);
    }
    
    private function runMigration($data) {
        // WordPress Database Configuration from request or defaults
        $wp_db_config = [
            'host' => $data['wp_host'] ?? 'localhost',
            'dbname' => $data['wp_dbname'] ?? 'u302767073_tLEcf',
            'username' => $data['wp_username'] ?? 'root',
            'password' => $data['wp_password'] ?? 'root',
            'prefix' => $data['wp_prefix'] ?? 'wp_',
        ];
        
            // Migration options
            $options = [
                'migrate_users' => $data['migrate_users'] ?? true,
                'migrate_subaccounts' => $data['migrate_subaccounts'] ?? true,
                'migrate_orders' => $data['migrate_orders'] ?? true,
                'migrate_categories' => $data['migrate_categories'] ?? true,
                'migrate_services' => $data['migrate_services'] ?? true,
            ];
        
        try {
            // Connect to WordPress database
            $wp_pdo = new PDO(
                "mysql:host={$wp_db_config['host']};dbname={$wp_db_config['dbname']};charset=utf8mb4",
                $wp_db_config['username'],
                $wp_db_config['password'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
            
            // Create mapping table
            $this->migrationService->createMappingTable();
            
            $results = [
                'users' => ['migrated' => 0, 'skipped' => 0, 'total' => 0],
                'subaccounts' => ['migrated' => 0, 'skipped' => 0, 'total' => 0],
                'orders' => ['migrated' => 0, 'skipped' => 0, 'total' => 0],
                'categories' => ['migrated' => 0, 'skipped' => 0, 'total' => 0],
                'services' => ['migrated' => 0, 'skipped' => 0, 'total' => 0],
            ];
            
            $user_mapping = [];
            
            // Migrate users
            if ($options['migrate_users']) {
                $userResult = $this->migrationService->migrateUsers($wp_pdo, $wp_db_config['prefix']);
                $results['users'] = [
                    'migrated' => $userResult['migrated'],
                    'skipped' => $userResult['skipped'],
                    'total' => $userResult['migrated'] + $userResult['skipped'],
                ];
                $user_mapping = $userResult['mapping'];
            }
            
            // Migrate subaccounts
            if ($options['migrate_subaccounts'] && !empty($user_mapping)) {
                $subaccountResult = $this->migrationService->migrateSubaccounts(
                    $wp_pdo, 
                    $wp_db_config['prefix'], 
                    $user_mapping
                );
                $results['subaccounts'] = [
                    'migrated' => $subaccountResult['migrated'],
                    'skipped' => $subaccountResult['skipped'],
                    'total' => $subaccountResult['migrated'] + $subaccountResult['skipped'],
                ];
            }
            
            // Migrate orders
            if ($options['migrate_orders'] && !empty($user_mapping)) {
                $orderResult = $this->migrationService->migrateOrders(
                    $wp_pdo, 
                    $wp_db_config['prefix'], 
                    $user_mapping
                );
                $results['orders'] = [
                    'migrated' => $orderResult['migrated'],
                    'skipped' => $orderResult['skipped'],
                    'total' => $orderResult['migrated'] + $orderResult['skipped'],
                ];
            }
            
            // Migrate vendor categories
            if ($options['migrate_categories'] && !empty($user_mapping)) {
                $categoryResult = $this->migrationService->migrateVendorCategories(
                    $wp_pdo, 
                    $wp_db_config['prefix'], 
                    $user_mapping
                );
                $results['categories'] = [
                    'migrated' => $categoryResult['migrated'] ?? 0,
                    'skipped' => $categoryResult['skipped'] ?? 0,
                    'total' => ($categoryResult['migrated'] ?? 0) + ($categoryResult['skipped'] ?? 0),
                    'category_mappings' => $categoryResult['category_mappings'] ?? 0,
                ];
            }

            // Migrate services (WooCommerce products)
            if ($options['migrate_services'] && !empty($user_mapping)) {
                $servicesResult = $this->migrationService->migrateServices(
                    $wp_pdo,
                    $wp_db_config['prefix'],
                    $user_mapping
                );
                $results['services'] = [
                    'migrated' => $servicesResult['migrated'] ?? 0,
                    'skipped' => $servicesResult['skipped'] ?? 0,
                    'total' => ($servicesResult['migrated'] ?? 0) + ($servicesResult['skipped'] ?? 0),
                ];
            }
            
            $this->sendResponse([
                'success' => true,
                'message' => 'Migration completed successfully',
                'note' => 'Existing data was skipped. Safe to rerun.',
                'results' => $results,
            ]);
            
        } catch (Exception $e) {
            $this->sendError('Migration failed: ' . $e->getMessage(), 500);
        }
    }
    
    public function get($id) {
        if ($id === 'status') {
            // Get migration status
            try {
                $mappingStmt = $this->db->query("SELECT COUNT(*) as total FROM wordpress_user_mapping");
                $mapping = $mappingStmt->fetch();
                
                $this->sendResponse([
                    'migrated_users' => intval($mapping['total'] ?? 0),
                    'migration_table_exists' => true,
                ]);
            } catch (Exception $e) {
                $this->sendResponse([
                    'migrated_users' => 0,
                    'migration_table_exists' => false,
                ]);
            }
        } else {
            $this->sendError('Invalid endpoint', 404);
        }
    }
}
