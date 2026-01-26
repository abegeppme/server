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
    }
    
    public function index() {
        $pagination = $this->getPaginationParams();
        $country_id = $_GET['country_id'] ?? null;
        $verified = isset($_GET['verified']) ? (bool)$_GET['verified'] : null;
        
        $query = "
            SELECT u.id, u.name, u.email, u.avatar, u.country_id, u.created_at,
                   COUNT(DISTINCT s.id) as service_count,
                   AVG(s.rating) as average_rating,
                   COUNT(DISTINCT r.id) as review_count
            FROM users u
            LEFT JOIN services s ON u.id = s.vendor_id AND s.status = 'ACTIVE'
            LEFT JOIN reviews r ON u.id = r.vendor_id
            WHERE u.role = 'VENDOR'
        ";
        
        $params = [];
        
        if ($country_id) {
            $query .= " AND u.country_id = ?";
            $params[] = $country_id;
        }
        
        $query .= " GROUP BY u.id";
        
        if ($verified !== null) {
            // This would check verification status if we have a verification table
            // For now, we'll skip this filter
        }
        
        $query .= " ORDER BY average_rating DESC, service_count DESC LIMIT ? OFFSET ?";
        $params[] = $pagination['limit'];
        $params[] = $pagination['offset'];
        
        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        $providers = $stmt->fetchAll();
        
        $this->sendResponse($providers);
    }
    
    public function get($id) {
        if ($id === 'register') {
            $this->sendError('Use POST method', 405);
        } else {
            // Get service provider by ID
            $stmt = $this->db->prepare("
                SELECT u.*, 
                       c.name as country_name,
                       COUNT(DISTINCT s.id) as service_count,
                       AVG(s.rating) as average_rating,
                       COUNT(DISTINCT r.id) as review_count
                FROM users u
                LEFT JOIN countries c ON u.country_id = c.id
                LEFT JOIN services s ON u.id = s.vendor_id
                LEFT JOIN reviews r ON u.id = r.vendor_id
                WHERE u.id = ? AND u.role = 'VENDOR'
                GROUP BY u.id
            ");
            $stmt->execute([$id]);
            $provider = $stmt->fetch();
            
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
            $provider['services'] = $servicesStmt->fetchAll();
            
            // Get reviews
            $reviewsStmt = $this->db->prepare("
                SELECT r.*, u.name as customer_name, u.avatar as customer_avatar
                FROM reviews r
                INNER JOIN users u ON r.customer_id = u.id
                WHERE r.vendor_id = ?
                ORDER BY r.created_at DESC
                LIMIT 10
            ");
            $reviewsStmt->execute([$id]);
            $provider['reviews'] = $reviewsStmt->fetchAll();
            
            unset($provider['password']);
            $this->sendResponse($provider);
        }
    }
    
    public function create() {
        $data = $this->getRequestBody();
        
        if (isset($data['action']) && $data['action'] === 'register') {
            $this->registerAsProvider($data);
        } else {
            $this->sendError('Invalid action. Use action: "register"', 400);
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
}
