<?php
/**
 * Service Provider (Vendor) Controller
 */

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';

class ServiceProviderController extends BaseController {
    private $auth;

    public function __construct() {
        parent::__construct();
        $this->auth = new AuthMiddleware();
        $this->ensureProviderSupportTables();
        $this->ensureVerificationTables();
    }
    
    public function index() {
        $pagination = $this->getPaginationParams();
        $search = $_GET['search'] ?? null;
        $country_id = $_GET['country_id'] ?? null;
        $category = $_GET['category_id'] ?? null;
        $verified = isset($_GET['verified']) ? strtolower((string)$_GET['verified']) === 'true' : null;
        
        $hasIsVendor = $this->checkColumnExists('users', 'is_vendor');
        $hasVendorVerified = $this->checkColumnExists('users', 'vendor_verified');
        $hasProfiles = $this->checkTableExists('service_provider_profiles');

        $vendorWhere = $hasIsVendor
            ? "(u.is_vendor = 1 OR u.role = 'VENDOR')"
            : "u.role = 'VENDOR'";

        $profileJoin = $hasProfiles ? "LEFT JOIN service_provider_profiles spp ON spp.user_id = u.id" : "";

        $query = "
            SELECT
                   u.id, u.name, u.email, u.avatar, u.phone, u.role, u.created_at,
                   " . ($hasVendorVerified ? "u.vendor_verified, u.vendor_verified_at," : "0 AS vendor_verified, NULL AS vendor_verified_at,") . "
                   " . ($hasProfiles ? "spp.business_name, spp.description, spp.logo," : "NULL AS business_name, NULL AS description, NULL AS logo,") . "
                   c.name as country_name, 
                   (
                     SELECT GROUP_CONCAT(DISTINCT s.category)
                     FROM services s
                     WHERE s.vendor_id = u.id AND s.status = 'ACTIVE'
                   ) as categories,
                   (
                     SELECT COUNT(*)
                     FROM services s2
                     WHERE s2.vendor_id = u.id AND s2.status = 'ACTIVE'
                   ) as service_count,
                   (
                     SELECT ROUND(AVG(r.rating), 2)
                     FROM reviews r
                     WHERE r.vendor_id = u.id
                   ) as average_rating,
                   (
                     SELECT COUNT(*)
                     FROM reviews r2
                     WHERE r2.vendor_id = u.id
                   ) as review_count
            FROM users u
            LEFT JOIN countries c ON BINARY c.id = BINARY CAST(u.country_id AS CHAR(2))
            {$profileJoin}
            WHERE {$vendorWhere}
        ";
        
        $params = [];
        
        if ($search) {
            $query .= " AND (u.name LIKE ? OR u.email LIKE ? " . ($hasProfiles ? " OR spp.business_name LIKE ?" : "") . ")";
            $term = "%$search%";
            $params[] = $term;
            $params[] = $term;
            if ($hasProfiles) {
                $params[] = $term;
            }
        }

        if ($country_id) {
            $query .= " AND u.country_id = ?";
            $params[] = $country_id;
        }

        if ($hasVendorVerified && $verified !== null) {
            $query .= " AND u.vendor_verified = ?";
            $params[] = $verified ? 1 : 0;
        }

        if ($category) {
            $query .= " AND EXISTS (
                SELECT 1 FROM services s3
                WHERE s3.vendor_id = u.id
                  AND s3.status = 'ACTIVE'
                  AND s3.category = ?
            )";
            $params[] = $category;
        }
        
        $query .= " ORDER BY u.created_at DESC LIMIT ? OFFSET ?";
        $params[] = $pagination['limit'];
        $params[] = $pagination['offset'];
        
        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        $providers = $stmt->fetchAll();
        
        // Get total count
        $countQuery = "SELECT COUNT(*) as total FROM users u " . $profileJoin . " WHERE {$vendorWhere}";
        if ($search) {
            $countQuery .= " AND (u.name LIKE ? OR u.email LIKE ? " . ($hasProfiles ? " OR spp.business_name LIKE ?" : "") . ")";
        }
        if ($country_id) {
            $countQuery .= " AND u.country_id = ?";
        }
        if ($hasVendorVerified && $verified !== null) {
            $countQuery .= " AND u.vendor_verified = ?";
        }
        if ($category) {
            $countQuery .= " AND EXISTS (
                SELECT 1 FROM services s3
                WHERE s3.vendor_id = u.id
                  AND s3.status = 'ACTIVE'
                  AND s3.category = ?
            )";
        }
        
        $countStmt = $this->db->prepare($countQuery);
        // Slice params to remove limit/offset
        $countParams = array_slice($params, 0, count($params) - 2);
        $countStmt->execute($countParams);
        $total = $countStmt->fetch()['total'];
        
        $this->sendResponse([
            'providers' => $providers,
            'pagination' => [
                'page' => $pagination['page'],
                'limit' => $pagination['limit'],
                'total' => $total,
                'pages' => ceil($total / $pagination['limit']),
            ],
        ]);
    }
    
    public function get($id) {
        if ($id === 'verification-requirements') {
            $this->getVerificationRequirements();
            return;
        }

        if ($id === 'verification') {
            $this->getMyVerification();
            return;
        }

        $this->getProviderProfile($id);
    }

    public function create() {
        $data = $this->getRequestBody();
        $action = $data['action'] ?? '';

        if ($action === 'register') {
            $this->registerProvider($data);
            return;
        }

        if ($action === 'submit-verification') {
            $this->submitVerification($data);
            return;
        }

        $this->sendError('Invalid action', 400);
    }

    private function getProviderProfile(string $id) {
        $hasProfiles = $this->checkTableExists('service_provider_profiles');
        $hasVendorVerified = $this->checkColumnExists('users', 'vendor_verified');

        $profileJoin = $hasProfiles ? "LEFT JOIN service_provider_profiles spp ON spp.user_id = u.id" : "";

        $stmt = $this->db->prepare("
            SELECT
                u.id, u.name, u.email, u.phone, u.avatar, u.country_id, u.created_at,
                " . ($hasProfiles ? "spp.business_name, spp.description, spp.logo," : "NULL AS business_name, NULL AS description, NULL AS logo,") . "
                " . ($hasVendorVerified ? "u.vendor_verified, u.vendor_verified_at," : "0 AS vendor_verified, NULL AS vendor_verified_at,") . "
                ROUND(COALESCE((SELECT AVG(r.rating) FROM reviews r WHERE r.vendor_id = u.id), 0), 2) AS average_rating,
                COALESCE((SELECT COUNT(*) FROM reviews r2 WHERE r2.vendor_id = u.id), 0) AS review_count
            FROM users u
            {$profileJoin}
            WHERE u.id = ?
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $provider = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$provider) {
            $this->sendError('Service provider not found', 404);
        }

        $servicesStmt = $this->db->prepare("
            SELECT
                s.id, s.vendor_id, s.title, s.description, s.price, s.category,
                s.images, s.gallery, s.status, s.featured, s.rating, s.review_count, s.created_at
            FROM services s
            WHERE s.vendor_id = ? AND s.status = 'ACTIVE'
            ORDER BY s.created_at DESC
        ");
        $servicesStmt->execute([$id]);
        $services = $servicesStmt->fetchAll(PDO::FETCH_ASSOC);
        $services = array_map(function ($service) {
            $service['images'] = !empty($service['images']) ? (json_decode($service['images'], true) ?: []) : [];
            $service['gallery'] = !empty($service['gallery']) ? (json_decode($service['gallery'], true) ?: []) : [];
            return $service;
        }, $services);

        $reviewsStmt = $this->db->prepare("
            SELECT
                r.id, r.order_id, r.customer_id, r.vendor_id, r.service_id, r.rating, r.comment, r.images, r.status, r.created_at,
                u.name AS customer_name, u.avatar AS customer_avatar
            FROM reviews r
            INNER JOIN users u ON r.customer_id = u.id
            WHERE r.vendor_id = ? AND r.status IN ('APPROVED', 'PENDING')
            ORDER BY r.created_at DESC
        ");
        $reviewsStmt->execute([$id]);
        $reviews = $reviewsStmt->fetchAll(PDO::FETCH_ASSOC);
        $reviews = array_map(function ($review) {
            $review['images'] = !empty($review['images']) ? (json_decode($review['images'], true) ?: []) : [];
            return $review;
        }, $reviews);

        $provider['business_name'] = $provider['business_name'] ?: $provider['name'];
        $provider['description'] = $provider['description'] ?: '';
        $provider['services'] = $services;
        $provider['reviews'] = $reviews;
        $provider['service_count'] = count($services);

        $this->sendResponse($provider);
    }

    private function registerProvider(array $data) {
        $user = $this->auth->requireAuth();

        if ($this->checkColumnExists('users', 'is_vendor')) {
            $stmt = $this->db->prepare("
                UPDATE users
                SET is_vendor = 1,
                    status = CASE WHEN status = 'PENDING_VERIFICATION' THEN 'ACTIVE' ELSE status END
                WHERE id = ?
            ");
            $stmt->execute([$user['id']]);
        } else {
            $stmt = $this->db->prepare("UPDATE users SET role = 'VENDOR' WHERE id = ?");
            $stmt->execute([$user['id']]);
        }

        $businessNameProvided = array_key_exists('business_name', $data);
        $descriptionProvided = array_key_exists('description', $data);
        $phoneProvided = array_key_exists('phone', $data);
        $logoProvided = array_key_exists('logo', $data);

        $businessName = $businessNameProvided ? trim((string)$data['business_name']) : '';
        $description = $descriptionProvided ? trim((string)$data['description']) : '';
        $phone = $phoneProvided ? trim((string)$data['phone']) : '';
        $logo = $logoProvided ? trim((string)$data['logo']) : '';

        if ($businessName === '') {
            $businessName = $user['name'] ?? $user['email'] ?? 'Service Provider';
        }

        $existsStmt = $this->db->prepare("
            SELECT id, business_name, description, phone, logo
            FROM service_provider_profiles
            WHERE user_id = ?
            LIMIT 1
        ");
        $existsStmt->execute([$user['id']]);
        $existing = $existsStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            if (!$businessNameProvided) {
                $businessName = $existing['business_name'] ?: ($user['name'] ?? $user['email'] ?? 'Service Provider');
            }
            if (!$descriptionProvided) {
                $description = $existing['description'] ?? '';
            }
            if (!$phoneProvided) {
                $phone = $existing['phone'] ?? '';
            }
            if (!$logoProvided) {
                $logo = $existing['logo'] ?? '';
            }

            $updateStmt = $this->db->prepare("
                UPDATE service_provider_profiles
                SET business_name = ?, description = ?, phone = ?, logo = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $updateStmt->execute([$businessName, $description, $phone, $logo, $existing['id']]);
        } else {
            $insertStmt = $this->db->prepare("
                INSERT INTO service_provider_profiles (id, user_id, business_name, description, phone, logo, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $insertStmt->execute([
                $this->generateUUID(),
                $user['id'],
                $businessName,
                $description,
                $phone,
                $logo
            ]);
        }

        $this->sendResponse([
            'message' => 'Service provider profile saved successfully',
            'user_id' => $user['id']
        ]);
    }

    private function getVerificationRequirements() {
        $countryId = strtoupper($_GET['country_id'] ?? 'NG');

        $stmt = $this->db->prepare("
            SELECT country_id, field_key, field_label, field_type, is_required, sort_order, options_json
            FROM provider_verification_requirements
            WHERE country_id = ?
            ORDER BY sort_order ASC, field_label ASC
        ");
        $stmt->execute([$countryId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $rows = array_map(function ($row) {
            $row['is_required'] = (int)($row['is_required'] ?? 0) === 1;
            $row['options'] = !empty($row['options_json']) ? (json_decode($row['options_json'], true) ?: null) : null;
            unset($row['options_json']);
            return $row;
        }, $rows);

        $this->sendResponse($rows);
    }

    private function getMyVerification() {
        $user = $this->auth->requireAuth();
        $countryId = strtoupper($_GET['country_id'] ?? ($user['country_id'] ?? 'NG'));

        $stmt = $this->db->prepare("
            SELECT id, user_id, country_id, payload_json, status, rejection_reason, reviewed_by, reviewed_at, created_at, updated_at
            FROM provider_verification_submissions
            WHERE user_id = ? AND country_id = ?
            LIMIT 1
        ");
        $stmt->execute([$user['id'], $countryId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $this->sendResponse(null);
        }

        $row['payload'] = json_decode($row['payload_json'] ?? '{}', true) ?: [];
        unset($row['payload_json']);
        $this->sendResponse($row);
    }

    private function submitVerification(array $data) {
        $user = $this->auth->requireAuth();
        $countryId = strtoupper($data['country_id'] ?? ($user['country_id'] ?? 'NG'));
        $payload = $data['payload'] ?? null;

        if (!is_array($payload) || empty($payload)) {
            $this->sendError('payload object is required', 400);
        }

        $existsStmt = $this->db->prepare("
            SELECT id
            FROM provider_verification_submissions
            WHERE user_id = ? AND country_id = ?
            LIMIT 1
        ");
        $existsStmt->execute([$user['id'], $countryId]);
        $existing = $existsStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $updateStmt = $this->db->prepare("
                UPDATE provider_verification_submissions
                SET payload_json = ?, status = 'PENDING', rejection_reason = NULL, reviewed_by = NULL, reviewed_at = NULL, updated_at = NOW()
                WHERE id = ?
            ");
            $updateStmt->execute([json_encode($payload), $existing['id']]);
            $submissionId = $existing['id'];
        } else {
            $submissionId = $this->generateUUID();
            $insertStmt = $this->db->prepare("
                INSERT INTO provider_verification_submissions (id, user_id, country_id, payload_json, status, created_at, updated_at)
                VALUES (?, ?, ?, ?, 'PENDING', NOW(), NOW())
            ");
            $insertStmt->execute([$submissionId, $user['id'], $countryId, json_encode($payload)]);
        }

        $this->sendResponse([
            'message' => 'Verification submitted successfully',
            'submission_id' => $submissionId,
            'status' => 'PENDING'
        ]);
    }

    private function ensureProviderSupportTables() {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS service_provider_profiles (
                id CHAR(36) NOT NULL PRIMARY KEY,
                user_id CHAR(36) NOT NULL UNIQUE,
                business_name VARCHAR(255) NULL,
                description TEXT NULL,
                phone VARCHAR(30) NULL,
                logo VARCHAR(500) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_spp_user_id (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        if (!$this->checkColumnExists('service_provider_profiles', 'logo')) {
            $this->db->exec("ALTER TABLE service_provider_profiles ADD COLUMN logo VARCHAR(500) NULL AFTER phone");
        }
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

        // Seed Nigeria defaults once.
        $seed = [
            ['nin_number', 'NIN Number', 'text', 1, 10],
            ['nin_slip', 'NIN Slip', 'document', 1, 20],
            ['cac_certificate', 'CAC Certificate', 'document', 1, 30],
            ['cac_number', 'CAC Number', 'text', 1, 40],
            ['utility_bill', 'Utility Bill', 'document', 1, 50],
            ['passport_photo', 'Passport Photograph', 'image', 1, 60],
        ];
        $stmt = $this->db->prepare("
            INSERT INTO provider_verification_requirements
            (id, country_id, field_key, field_label, field_type, is_required, sort_order, created_at, updated_at)
            VALUES (?, 'NG', ?, ?, ?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                field_label = VALUES(field_label),
                field_type = VALUES(field_type),
                is_required = VALUES(is_required),
                sort_order = VALUES(sort_order),
                updated_at = NOW()
        ");
        foreach ($seed as $item) {
            $stmt->execute([
                $this->generateUUID(),
                $item[0],
                $item[1],
                $item[2],
                $item[3],
                $item[4],
            ]);
        }
    }
}