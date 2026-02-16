<?php
/**
 * Service Provider Controller - Complete Implementation
 */

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../payment/PaymentGatewayFactory.php';
require_once __DIR__ . '/../utils/CountryManager.php';

class ServiceProviderController extends BaseController {
    private $auth;
    private $countryManager;
    
    public function __construct() {
        parent::__construct();
        $this->auth = new AuthMiddleware();
        $this->countryManager = new CountryManager();
        $this->ensureVerificationTables();
        $this->seedDefaultVerificationRequirements();
    }
    
    public function index() {
        $pagination = $this->getPaginationParams();
        $country_id = $_GET['country_id'] ?? null;
        $category_id = $_GET['category_id'] ?? null;
        $search = $_GET['search'] ?? null;
        $latitude = isset($_GET['latitude']) ? (float)$_GET['latitude'] : null;
        $longitude = isset($_GET['longitude']) ? (float)$_GET['longitude'] : null;
        $min_rating = isset($_GET['min_rating']) ? (float)$_GET['min_rating'] : null;
        $sort_by = $_GET['sort_by'] ?? 'distance';
        $verified = isset($_GET['verified']) ? (bool)$_GET['verified'] : null;
        
        // Check if business_name and description columns exist
        $hasBusinessName = $this->checkColumnExists('users', 'business_name');
        $hasDescription = $this->checkColumnExists('users', 'description');
        $hasVendorVerified = $this->checkColumnExists('users', 'vendor_verified');
        
        // Check if reviews table exists
        $hasReviewsTable = $this->checkTableExists('reviews');
        
        // Check if country_id column exists
        $hasCountryId = $this->checkColumnExists('users', 'country_id');
        
        // Check if store_image or business_image columns exist
        $hasStoreImage = $this->checkColumnExists('users', 'store_image');
        $hasBusinessImage = $this->checkColumnExists('users', 'business_image');
        $hasLogo = $this->checkColumnExists('users', 'logo');
        
        // Base query with aggregations
        $query = "
            SELECT u.id, u.name, u.email, u.avatar, u.phone, u.created_at,
                   " . ($hasCountryId ? "u.country_id," : "NULL as country_id,") . "
                   " . ($hasVendorVerified ? "u.vendor_verified, u.vendor_verified_at," : "1 as vendor_verified, NULL as vendor_verified_at,") . "
                   " . ($hasBusinessName ? "u.business_name," : "NULL as business_name,") . "
                   " . ($hasDescription ? "u.description," : "NULL as description,") . "
                   " . ($hasStoreImage ? "u.store_image," : "NULL as store_image,") . "
                   " . ($hasBusinessImage ? "u.business_image," : "NULL as business_image,") . "
                   " . ($hasLogo ? "u.logo," : "NULL as logo,") . "
                   COUNT(DISTINCT s.id) as service_count";
        
        // Add reviews aggregation only if table exists
        if ($hasReviewsTable) {
            $query .= ",
                   COALESCE(AVG(r.rating), 0) as average_rating,
                   COUNT(DISTINCT r.id) as review_count";
        } else {
            $query .= ",
                   0 as average_rating,
                   0 as review_count";
        }
        
        // Add distance calculation if location provided
        if ($latitude !== null && $longitude !== null) {
            // Note: This is a simplified distance calculation
            // For production, consider using a proper geospatial function
            $query .= ", (
                6371 * acos(
                    cos(radians(?)) * cos(radians(COALESCE(u.latitude, 0))) *
                    cos(radians(COALESCE(u.longitude, 0)) - radians(?)) +
                    sin(radians(?)) * sin(radians(COALESCE(u.latitude, 0)))
                )
            ) as distance";
        }
        
        $query .= "
            FROM users u
            LEFT JOIN services s ON u.id = s.vendor_id AND s.status = 'ACTIVE'";
        
        // Join reviews only if table exists
        if ($hasReviewsTable) {
            $query .= "
            LEFT JOIN reviews r ON u.id = r.vendor_id";
        }
        
        // Join categories if filtering by category
        if ($category_id) {
            $query .= "
                INNER JOIN service_provider_categories spc ON u.id = spc.vendor_id
            ";
        }
        
        // Check if is_vendor column exists, otherwise fall back to role
        $hasIsVendor = $this->checkColumnExists('users', 'is_vendor');
        
        if ($hasIsVendor) {
            $query .= " WHERE u.is_vendor = 1 AND u.status = 'ACTIVE'";
        } else {
            $query .= " WHERE u.role = 'VENDOR' AND u.status = 'ACTIVE'";
        }

        // Public market should only show verified providers by default.
        if ($hasVendorVerified) {
            if ($verified === null || $verified === true) {
                $query .= " AND u.vendor_verified = 1";
            } else {
                $query .= " AND (u.vendor_verified = 0 OR u.vendor_verified IS NULL)";
            }
        }
        
        $params = [];
        
        // Add location params for distance calculation
        if ($latitude !== null && $longitude !== null) {
            $params[] = $latitude;
            $params[] = $longitude;
            $params[] = $latitude;
        }
        
        // Apply filters
        if ($country_id && $hasCountryId) {
            $query .= " AND u.country_id = ?";
            $params[] = $country_id;
        }
        
        if ($category_id) {
            $query .= " AND spc.category_id = ?";
            $params[] = $category_id;
        }
        
        if ($search) {
            $query .= " AND (u.name LIKE ? OR u.email LIKE ?";
            $searchTerm = "%{$search}%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            
            if ($hasBusinessName) {
                $query .= " OR u.business_name LIKE ?";
                $params[] = $searchTerm;
            }
            if ($hasDescription) {
                $query .= " OR u.description LIKE ?";
                $params[] = $searchTerm;
            }
            $query .= ")";
        }
        
        $query .= " GROUP BY u.id";
        
        // Filter by minimum rating
        if ($min_rating !== null) {
            $query .= " HAVING average_rating >= ?";
            $params[] = $min_rating;
        }
        
        // Sorting
        switch ($sort_by) {
            case 'rating':
                $query .= " ORDER BY average_rating DESC, review_count DESC";
                break;
            case 'reviews':
                $query .= " ORDER BY review_count DESC, average_rating DESC";
                break;
            case 'name':
                if ($hasBusinessName) {
                    $query .= " ORDER BY COALESCE(u.business_name, u.name) ASC";
                } else {
                    $query .= " ORDER BY u.name ASC";
                }
                break;
            case 'distance':
            default:
                if ($latitude !== null && $longitude !== null) {
                    $query .= " ORDER BY distance ASC, average_rating DESC";
                } else {
                    $query .= " ORDER BY average_rating DESC, service_count DESC";
                }
                break;
        }
        
        $query .= " LIMIT ? OFFSET ?";
        $params[] = $pagination['limit'];
        $params[] = $pagination['offset'];
        
        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        $providers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get total count for pagination
        $countQuery = "
            SELECT COUNT(DISTINCT u.id) as total
            FROM users u
            LEFT JOIN services s ON u.id = s.vendor_id AND s.status = 'ACTIVE'";
        
        // Join reviews only if table exists
        if ($hasReviewsTable) {
            $countQuery .= "
            LEFT JOIN reviews r ON u.id = r.vendor_id";
        }
        
        if ($category_id) {
            $countQuery .= " INNER JOIN service_provider_categories spc ON u.id = spc.vendor_id";
        }
        
        if ($hasIsVendor) {
            $countQuery .= " WHERE u.is_vendor = 1 AND u.status = 'ACTIVE'";
        } else {
            $countQuery .= " WHERE u.role = 'VENDOR' AND u.status = 'ACTIVE'";
        }

        if ($hasVendorVerified) {
            if ($verified === null || $verified === true) {
                $countQuery .= " AND u.vendor_verified = 1";
            } else {
                $countQuery .= " AND (u.vendor_verified = 0 OR u.vendor_verified IS NULL)";
            }
        }
        $countParams = [];
        
        if ($country_id && $hasCountryId) {
            $countQuery .= " AND u.country_id = ?";
            $countParams[] = $country_id;
        }
        
        if ($category_id) {
            $countQuery .= " AND spc.category_id = ?";
            $countParams[] = $category_id;
        }
        
        if ($search) {
            $countQuery .= " AND (u.name LIKE ? OR u.email LIKE ?";
            $searchTerm = "%{$search}%";
            $countParams[] = $searchTerm;
            $countParams[] = $searchTerm;
            
            if ($hasBusinessName) {
                $countQuery .= " OR u.business_name LIKE ?";
                $countParams[] = $searchTerm;
            }
            if ($hasDescription) {
                $countQuery .= " OR u.description LIKE ?";
                $countParams[] = $searchTerm;
            }
            $countQuery .= ")";
        }
        
        $countStmt = $this->db->prepare($countQuery);
        $countStmt->execute($countParams);
        $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Get categories for each provider
        foreach ($providers as &$provider) {
            $catStmt = $this->db->prepare("
                SELECT sc.id, sc.name, sc.slug, spc.is_primary
                FROM service_provider_categories spc
                INNER JOIN service_categories sc ON spc.category_id = sc.id
                WHERE spc.vendor_id = ? AND sc.is_active = 1
                ORDER BY spc.is_primary DESC, sc.name ASC
            ");
            $catStmt->execute([$provider['id']]);
            $provider['categories'] = $catStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format numeric values
            $provider['average_rating'] = (float)$provider['average_rating'];
            $provider['service_count'] = (int)$provider['service_count'];
            $provider['review_count'] = (int)$provider['review_count'];
            if (isset($provider['distance'])) {
                $provider['distance'] = (float)$provider['distance'];
            }
        }
        
        $this->sendResponse([
            'providers' => $providers,
            'pagination' => [
                'current_page' => $pagination['page'],
                'per_page' => $pagination['limit'],
                'total' => (int)$total,
                'total_pages' => (int)ceil($total / $pagination['limit']),
            ]
        ]);
    }
    
    public function get($id) {
        if ($id === 'register') {
            $this->sendError('Use POST method', 405);
        } elseif ($id === 'verification') {
            $this->getMyVerificationData();
        } elseif ($id === 'verification-requirements') {
            $this->getVerificationRequirements();
        } else {
            // Check if reviews table exists
            $hasReviewsTable = $this->checkTableExists('reviews');
            
            // Check if is_vendor column exists
            $hasIsVendor = $this->checkColumnExists('users', 'is_vendor');
            $hasVendorVerified = $this->checkColumnExists('users', 'vendor_verified');
            
            // Check if country_id column exists
            $hasCountryId = $this->checkColumnExists('users', 'country_id');
            $hasCountriesTable = $this->checkTableExists('countries');
            
            // Check if store_image or business_image columns exist
            $hasStoreImage = $this->checkColumnExists('users', 'store_image');
            $hasBusinessImage = $this->checkColumnExists('users', 'business_image');
            $hasLogo = $this->checkColumnExists('users', 'logo');
            
            // Get service provider by ID
            $roleCondition = $hasIsVendor ? "u.is_vendor = 1" : "u.role = 'VENDOR'";
            
            $query = "
                SELECT u.*, 
                       " . (($hasCountryId && $hasCountriesTable) ? "c.name as country_name," : "NULL as country_name,") . "
                       " . ($hasStoreImage ? "u.store_image," : "NULL as store_image,") . "
                       " . ($hasBusinessImage ? "u.business_image," : "NULL as business_image,") . "
                       " . ($hasLogo ? "u.logo," : "NULL as logo,") . "
                       COUNT(DISTINCT s.id) as service_count";
            
            // Add reviews aggregation only if table exists
            if ($hasReviewsTable) {
                $query .= ",
                       COALESCE(AVG(r.rating), 0) as average_rating,
                       COUNT(DISTINCT r.id) as review_count";
            } else {
                $query .= ",
                       0 as average_rating,
                       0 as review_count";
            }
            
            $query .= "
                FROM users u";
            
            // Join countries only if country_id column exists
            if ($hasCountryId && $hasCountriesTable) {
                $query .= "
                LEFT JOIN countries c ON u.country_id = c.id";
            }
            
            $query .= "
                LEFT JOIN services s ON u.id = s.vendor_id";
            
            // Join reviews only if table exists
            if ($hasReviewsTable) {
                $query .= "
                LEFT JOIN reviews r ON u.id = r.vendor_id";
            }
            
            $query .= "
                WHERE u.id = ? AND {$roleCondition}
                GROUP BY u.id
            ";
            if ($hasVendorVerified) {
                $query = str_replace("GROUP BY u.id", "AND u.vendor_verified = 1 GROUP BY u.id", $query);
            }
            
            $stmt = $this->db->prepare($query);
            $stmt->execute([$id]);
            $provider = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$provider) {
                $this->sendError('Service provider not found', 404);
            }
            
            // Get services
            $servicesStmt = $this->db->prepare("
                SELECT * FROM services 
                WHERE vendor_id = ? AND status = 'ACTIVE'
                ORDER BY featured DESC, created_at DESC
            ");
            $servicesStmt->execute([$id]);
            $provider['services'] = $servicesStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get reviews only if table exists
            if ($hasReviewsTable) {
                $reviewsStmt = $this->db->prepare("
                    SELECT r.*, u.name as customer_name, u.avatar as customer_avatar
                    FROM reviews r
                    INNER JOIN users u ON r.customer_id = u.id
                    WHERE r.vendor_id = ?
                    ORDER BY r.created_at DESC
                    LIMIT 10
                ");
                $reviewsStmt->execute([$id]);
                $provider['reviews'] = $reviewsStmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $provider['reviews'] = [];
            }
            
            unset($provider['password']);
            $this->sendResponse($provider);
        }
    }
    
    public function create() {
        $data = $this->getRequestBody();
        
        if (isset($data['action']) && $data['action'] === 'register') {
            $this->registerAsProvider($data);
        } elseif (isset($data['action']) && $data['action'] === 'submit-verification') {
            $this->submitVerification($data);
        } else {
            $this->sendError('Invalid action. Use action: "register" or "submit-verification"', 400);
        }
    }
    
    private function registerAsProvider($data) {
        $user = $this->auth->requireAuth();
        
        // Check if already a vendor
        if ($user['role'] === 'VENDOR') {
            $this->sendError('You are already registered as a service provider', 400);
        }
        
        // Validate required fields
        $businessName = $data['business_name'] ?? $user['name'];
        $phone = $data['phone'] ?? null;
        $countryId = $data['country_id'] ?? $user['country_id'] ?? 'NG';
        
        // Update user role to VENDOR
        $updateStmt = $this->db->prepare("
            UPDATE users 
            SET role = 'VENDOR', 
                phone = COALESCE(?, phone),
                country_id = COALESCE(?, country_id),
                status = 'PENDING_VERIFICATION'
            WHERE id = ?
        ");
        $updateStmt->execute([$phone, $countryId, $user['id']]);
        
        // Create subaccount (if bank details provided)
        if (!empty($data['bank_account_number']) && !empty($data['bank_code'])) {
            $this->createSubaccount($user['id'], $data, $countryId);
        }
        
        // Get updated user
        $getStmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
        $getStmt->execute([$user['id']]);
        $updated = $getStmt->fetch();
        unset($updated['password']);
        
        $this->sendResponse([
            'message' => 'Successfully registered as service provider. Verification pending.',
            'user' => $updated,
        ], 201);
    }
    
    private function createSubaccount(string $userId, array $data, string $countryId) {
        // Get country and payment gateway
        $country = $this->countryManager->getCountry($countryId);
        if (!$country || !$country['payment_gateway']) {
            return; // No payment gateway configured
        }
        
        try {
            $config = json_decode($country['payment_gateway_config'], true);
            $gateway = PaymentGatewayFactory::create($country['payment_gateway'], $config);
            
            // Create transfer recipient
            $recipient = $gateway->createRecipient([
                'account_number' => $data['bank_account_number'],
                'bank_code' => $data['bank_code'],
                'account_name' => $data['account_name'] ?? $data['business_name'],
                'currency' => $country['currency_code'],
            ]);
            
            // Save subaccount
            $subaccountId = $this->generateUUID();
            $insertStmt = $this->db->prepare("
                INSERT INTO subaccounts (
                    id, user_id, country_id, subaccount_code, 
                    account_number, account_name, bank_code, bank_name,
                    transfer_recipient, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
                ON DUPLICATE KEY UPDATE
                    subaccount_code = VALUES(subaccount_code),
                    account_number = VALUES(account_number),
                    account_name = VALUES(account_name),
                    bank_code = VALUES(bank_code),
                    bank_name = VALUES(bank_name),
                    transfer_recipient = VALUES(transfer_recipient)
            ");
            
            $insertStmt->execute([
                $subaccountId,
                $userId,
                $countryId,
                $recipient['recipient_code'] ?? '',
                $data['bank_account_number'],
                $data['account_name'] ?? $data['business_name'],
                $data['bank_code'],
                $data['bank_name'] ?? null,
                $recipient['recipient_code'] ?? null,
            ]);
        } catch (Exception $e) {
            // Log error but don't fail registration
            error_log("Subaccount creation failed: " . $e->getMessage());
        }
    }
    
    public function getServices($provider_id) {
        $pagination = $this->getPaginationParams();
        $status = $_GET['status'] ?? 'ACTIVE';
        
        $query = "
            SELECT * FROM services 
            WHERE vendor_id = ?
        ";
        
        $params = [$provider_id];
        
        if ($status) {
            $query .= " AND status = ?";
            $params[] = $status;
        }
        
        $query .= " ORDER BY featured DESC, created_at DESC LIMIT ? OFFSET ?";
        $params[] = $pagination['limit'];
        $params[] = $pagination['offset'];
        
        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        $services = $stmt->fetchAll();
        
        $this->sendResponse($services);
    }

    private function getVerificationRequirements() {
        $countryId = strtoupper($_GET['country_id'] ?? 'NG');
        $stmt = $this->db->prepare("
            SELECT id, country_id, field_key, field_label, field_type, is_required, sort_order, options_json
            FROM provider_verification_requirements
            WHERE country_id = ?
            ORDER BY sort_order ASC
        ");
        $stmt->execute([$countryId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $requirements = array_map(function ($row) {
            return [
                'id' => $row['id'],
                'country_id' => $row['country_id'],
                'field_key' => $row['field_key'],
                'field_label' => $row['field_label'],
                'field_type' => $row['field_type'],
                'is_required' => intval($row['is_required']) === 1,
                'sort_order' => intval($row['sort_order']),
                'options' => json_decode($row['options_json'] ?? '[]', true) ?: [],
            ];
        }, $rows);
        $this->sendResponse($requirements);
    }

    private function getMyVerificationData() {
        $user = $this->auth->requireAuth();
        $countryId = strtoupper($_GET['country_id'] ?? ($user['country_id'] ?? 'NG'));
        $requirements = $this->fetchRequirements($countryId);

        $stmt = $this->db->prepare("
            SELECT *
            FROM provider_verification_submissions
            WHERE user_id = ? AND country_id = ?
            ORDER BY updated_at DESC
            LIMIT 1
        ");
        $stmt->execute([$user['id'], $countryId]);
        $submission = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($submission) {
            $submission['payload'] = json_decode($submission['payload_json'] ?? '{}', true) ?: [];
        }

        $this->sendResponse([
            'country_id' => $countryId,
            'requirements' => $requirements,
            'submission' => $submission ?: null,
        ]);
    }

    private function submitVerification(array $data) {
        $user = $this->auth->requireAuth();
        $countryId = strtoupper($data['country_id'] ?? ($user['country_id'] ?? 'NG'));
        $payload = $data['payload'] ?? null;
        if (!is_array($payload)) {
            $this->sendError('payload object is required', 400);
        }

        $requirements = $this->fetchRequirements($countryId);
        foreach ($requirements as $req) {
            if (!empty($req['is_required'])) {
                $value = $payload[$req['field_key']] ?? null;
                if ($value === null || $value === '') {
                    $this->sendError("{$req['field_label']} is required", 400);
                }
            }
        }

        $existingStmt = $this->db->prepare("
            SELECT id FROM provider_verification_submissions
            WHERE user_id = ? AND country_id = ?
            LIMIT 1
        ");
        $existingStmt->execute([$user['id'], $countryId]);
        $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $stmt = $this->db->prepare("
                UPDATE provider_verification_submissions
                SET payload_json = ?, status = 'PENDING', rejection_reason = NULL, reviewed_by = NULL, reviewed_at = NULL, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([json_encode($payload), $existing['id']]);
            $submissionId = $existing['id'];
        } else {
            $submissionId = $this->generateUUID();
            $stmt = $this->db->prepare("
                INSERT INTO provider_verification_submissions (
                    id, user_id, country_id, payload_json, status, created_at, updated_at
                ) VALUES (?, ?, ?, ?, 'PENDING', NOW(), NOW())
            ");
            $stmt->execute([$submissionId, $user['id'], $countryId, json_encode($payload)]);
        }

        if ($this->checkColumnExists('users', 'vendor_verified')) {
            $resetStmt = $this->db->prepare("UPDATE users SET vendor_verified = 0, vendor_verified_at = NULL WHERE id = ?");
            $resetStmt->execute([$user['id']]);
        }

        $this->sendResponse([
            'message' => 'Verification data submitted successfully. Awaiting admin review.',
            'submission_id' => $submissionId,
        ]);
    }

    private function fetchRequirements(string $countryId): array {
        $stmt = $this->db->prepare("
            SELECT field_key, field_label, field_type, is_required, sort_order, options_json
            FROM provider_verification_requirements
            WHERE country_id = ?
            ORDER BY sort_order ASC
        ");
        $stmt->execute([$countryId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(function ($row) {
            return [
                'field_key' => $row['field_key'],
                'field_label' => $row['field_label'],
                'field_type' => $row['field_type'],
                'is_required' => intval($row['is_required']) === 1,
                'sort_order' => intval($row['sort_order']),
                'options' => json_decode($row['options_json'] ?? '[]', true) ?: [],
            ];
        }, $rows);
    }

    private function ensureVerificationTables() {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS provider_verification_requirements (
                id CHAR(36) NOT NULL PRIMARY KEY,
                country_id CHAR(2) NOT NULL,
                field_key VARCHAR(100) NOT NULL,
                field_label VARCHAR(150) NOT NULL,
                field_type VARCHAR(40) NOT NULL DEFAULT 'text',
                is_required TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                options_json TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_verification_req_country_field (country_id, field_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS provider_verification_submissions (
                id CHAR(36) NOT NULL PRIMARY KEY,
                user_id CHAR(36) NOT NULL,
                country_id CHAR(2) NOT NULL,
                payload_json LONGTEXT NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
                rejection_reason TEXT NULL,
                reviewed_by CHAR(36) NULL,
                reviewed_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_verification_submission_user_country (user_id, country_id),
                INDEX idx_verification_submission_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    private function seedDefaultVerificationRequirements() {
        $defaults = [
            ['country' => 'NG', 'key' => 'nin_number', 'label' => 'NIN Number', 'type' => 'text', 'required' => 1, 'sort' => 10],
            ['country' => 'NG', 'key' => 'nin_slip_url', 'label' => 'NIN Slip (Image/Document URL)', 'type' => 'document', 'required' => 1, 'sort' => 20],
            ['country' => 'NG', 'key' => 'cac_certificate_url', 'label' => 'CAC Certificate (Image/Document URL)', 'type' => 'document', 'required' => 1, 'sort' => 30],
            ['country' => 'NG', 'key' => 'cac_number', 'label' => 'CAC Number', 'type' => 'text', 'required' => 1, 'sort' => 40],
            ['country' => 'NG', 'key' => 'utility_bill_url', 'label' => 'Utility Bill (Image/Document URL)', 'type' => 'document', 'required' => 1, 'sort' => 50],
            ['country' => 'NG', 'key' => 'passport_photo_url', 'label' => 'Passport Photograph (Image URL)', 'type' => 'image', 'required' => 1, 'sort' => 60],
        ];

        $stmt = $this->db->prepare("
            INSERT IGNORE INTO provider_verification_requirements (
                id, country_id, field_key, field_label, field_type, is_required, sort_order, options_json, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");

        foreach ($defaults as $row) {
            $stmt->execute([
                $this->generateUUID(),
                $row['country'],
                $row['key'],
                $row['label'],
                $row['type'],
                $row['required'],
                $row['sort'],
                json_encode([]),
            ]);
        }
    }
}
