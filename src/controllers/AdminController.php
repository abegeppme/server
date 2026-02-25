<?php
/**
 * Admin Controller - Complete Implementation
 */

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../payment/PaymentGatewayFactory.php';
require_once __DIR__ . '/../utils/CountryManager.php';
require_once __DIR__ . '/../utils/RuntimeSettings.php';
require_once __DIR__ . '/../services/EmailService.php';
require_once __DIR__ . '/../services/NotificationService.php';

class AdminController extends BaseController {
    private $auth;
    private $countryManager;
    private $emailService;
    private $notificationService;
    
    public function __construct() {
        parent::__construct();
        $this->auth = new AuthMiddleware();
        $this->countryManager = new CountryManager();
        $this->emailService = new EmailService();
        $this->notificationService = new NotificationService();
        $this->ensureAppSettingsTable();
        $this->ensureVerificationTables();
    }
    
    public function get($resource) {
        $user = $this->auth->requireAdmin();
        
        switch ($resource) {
            case 'stats':
                $this->getStats();
                break;
            case 'orders':
                $this->getAllOrders();
                break;
            case 'users':
                $this->getAllUsers();
                break;
            case 'providers':
                $this->getProviders();
                break;
            case 'services':
                $this->getServices();
                break;
            case 'categories':
                $this->getCategories();
                break;
            case 'mailing-list':
                $this->getMailingList();
                break;
            case 'mailing-list-export':
                $this->exportMailingListCsv();
                break;
            case 'mailing-list-mailchimp':
                $this->getMailingListForMailchimp();
                break;
            case 'logs':
                $this->getTransactionLogs();
                break;
            case 'payment-settings':
                $this->getPaymentSettings();
                break;
            case 'payout-audit':
                $this->getPayoutAudit();
                break;
            case 'verifications':
                $this->getVerificationSubmissions();
                break;
            case 'disputes':
                $this->getDisputes();
                break;
            default:
                $this->sendError('Resource not found', 404);
        }
    }
    
    public function post($resource) {
        $user = $this->auth->requireAdmin();
        
        switch ($resource) {
            case 'users':
                $this->moderateUser();
                break;
            case 'providers':
                $this->moderateProvider();
                break;
            case 'orders':
                $this->moderateOrder();
                break;
            case 'categories':
                $this->moderateCategory();
                break;
            case 'services':
                $this->moderateService();
                break;
            case 'disputes':
                $this->moderateDispute();
                break;
            case 'payment-settings':
                $this->updatePaymentSettings();
                break;
            case 'mailing-list':
                $this->moderateMailingList();
                break;
            case 'verifications':
                $this->moderateVerification();
                break;
            default:
                $this->sendError('Resource not found', 404);
        }
    }

    public function create() {
        // For POST /api/admin/{resource}, BaseController calls create() and does not pass {resource}.
        // Parse it from URL then delegate to post($resource).
        $requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
        $segments = explode('/', trim($requestUri, '/'));
        $resource = end($segments);

        if (!$resource || $resource === 'admin' || $resource === 'api') {
            $this->sendError('Resource not found', 404);
        }

        $this->post($resource);
    }
    
    public function postForceComplete($order_id) {
        $user = $this->auth->requireAdmin();
        
        // Get order
        $orderStmt = $this->db->prepare("SELECT * FROM orders WHERE id = ?");
        $orderStmt->execute([$order_id]);
        $order = $orderStmt->fetch();
        
        if (!$order) {
            $this->sendError('Order not found', 404);
        }
        
        // Force complete
        $updateStmt = $this->db->prepare("
            UPDATE orders 
            SET customer_confirmed = 1, 
                vendor_complete = 1,
                status = 'COMPLETED',
                completed_at = NOW()
            WHERE id = ?
        ");
        $updateStmt->execute([$order_id]);
        
        // Process balance payment
        $this->processBalancePayment($order_id);
        
        // Notify parties
        $customerStmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
        $customerStmt->execute([$order['customer_id']]);
        $customer = $customerStmt->fetch();
        
        $vendorStmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
        $vendorStmt->execute([$order['vendor_id']]);
        $vendor = $vendorStmt->fetch();
        
        if ($customer) {
            $this->notificationService->create(
                $customer['id'],
                'ORDER_FORCE_COMPLETED',
                'Order Force Completed',
                "Admin has force completed order #{$order['order_number']}",
                ['order_id' => $order_id]
            );
        }
        
        if ($vendor) {
            $this->notificationService->create(
                $vendor['id'],
                'ORDER_FORCE_COMPLETED',
                'Order Force Completed',
                "Admin has force completed order #{$order['order_number']}",
                ['order_id' => $order_id]
            );
        }
        
        $this->sendResponse(['message' => 'Order force completed', 'order_id' => $order_id]);
    }
    
    public function postResolve($dispute_id) {
        $user = $this->auth->requireAdmin();
        $data = $this->getRequestBody();
        
        $resolution = $data['resolution'] ?? '';
        $winner = $data['winner'] ?? null; // 'customer' or 'vendor'
        $refundAmount = floatval($data['refund_amount'] ?? 0);
        
        if (empty($resolution)) {
            $this->sendError('Resolution is required', 400);
        }
        
        // Get dispute
        $disputeStmt = $this->db->prepare("
            SELECT d.*, o.*
            FROM disputes d
            INNER JOIN orders o ON d.order_id = o.id
            WHERE d.id = ?
        ");
        $disputeStmt->execute([$dispute_id]);
        $dispute = $disputeStmt->fetch();
        
        if (!$dispute) {
            $this->sendError('Dispute not found', 404);
        }
        
        if ($dispute['status'] !== 'PENDING') {
            $this->sendError('Dispute already resolved', 400);
        }
        
        // Update dispute
        $updateStmt = $this->db->prepare("
            UPDATE disputes 
            SET status = 'RESOLVED',
                resolved_by = ?,
                resolution = ?,
                resolved_at = NOW()
            WHERE id = ?
        ");
        $updateStmt->execute([$user['id'], $resolution, $dispute_id]);
        
        // Handle refund if applicable
        if ($refundAmount > 0 && $winner === 'customer') {
            // Process refund through payment gateway
            $paymentStmt = $this->db->prepare("
                SELECT * FROM payments 
                WHERE order_id = ?
            ");
            $paymentStmt->execute([$dispute['order_id']]);
            $payment = $paymentStmt->fetch();
            
            if ($payment) {
                // Refund logic would go here
                // This depends on payment gateway refund API
            }
        }
        
        // Update order status
        $orderStatus = $winner === 'customer' ? 'REFUNDED' : 'COMPLETED';
        $orderUpdateStmt = $this->db->prepare("
            UPDATE orders 
            SET status = ? 
            WHERE id = ?
        ");
        $orderUpdateStmt->execute([$orderStatus, $dispute['order_id']]);
        
        // Notify parties
        $customerStmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
        $customerStmt->execute([$dispute['customer_id']]);
        $customer = $customerStmt->fetch();
        
        $vendorStmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
        $vendorStmt->execute([$dispute['vendor_id']]);
        $vendor = $vendorStmt->fetch();
        
        if ($customer) {
            $this->notificationService->create(
                $customer['id'],
                'DISPUTE_RESOLVED',
                'Dispute Resolved',
                "Your dispute for order #{$dispute['order_number']} has been resolved",
                ['dispute_id' => $dispute_id, 'order_id' => $dispute['order_id']]
            );
        }
        
        if ($vendor) {
            $this->notificationService->create(
                $vendor['id'],
                'DISPUTE_RESOLVED',
                'Dispute Resolved',
                "The dispute for order #{$dispute['order_number']} has been resolved",
                ['dispute_id' => $dispute_id, 'order_id' => $dispute['order_id']]
            );
        }
        
        $this->sendResponse(['message' => 'Dispute resolved', 'dispute_id' => $dispute_id]);
    }
    
    private function getStats() {
        // Platform statistics
        $stats = [];
        
        // Total orders
        $ordersStmt = $this->db->query("SELECT COUNT(*) as total FROM orders");
        $stats['total_orders'] = $ordersStmt->fetch()['total'];
        
        // Total revenue
        $revenueStmt = $this->db->query("
            SELECT SUM(total) as total_revenue 
            FROM orders 
            WHERE status IN ('COMPLETED', 'IN_SERVICE', 'AWAITING_CONFIRMATION')
        ");
        $stats['total_revenue'] = floatval($revenueStmt->fetch()['total_revenue'] ?? 0);
        
        // Total users
        $usersStmt = $this->db->query("SELECT COUNT(*) as total FROM users");
        $stats['total_users'] = $usersStmt->fetch()['total'];
        
        // Total vendors
        $vendorsStmt = $this->db->query("SELECT COUNT(*) as total FROM users WHERE role = 'VENDOR'");
        $stats['total_vendors'] = $vendorsStmt->fetch()['total'];
        
        // Pending disputes
        $disputesStmt = $this->db->query("SELECT COUNT(*) as total FROM disputes WHERE status = 'PENDING'");
        $stats['pending_disputes'] = $disputesStmt->fetch()['total'];
        
        // Orders by status
        $statusStmt = $this->db->query("
            SELECT status, COUNT(*) as count 
            FROM orders 
            GROUP BY status
        ");
        $stats['orders_by_status'] = $statusStmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // Recent orders (last 30 days)
        $recentStmt = $this->db->query("
            SELECT COUNT(*) as total 
            FROM orders 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $stats['recent_orders_30d'] = $recentStmt->fetch()['total'];
        
        $this->sendResponse($stats);
    }
    
    private function getAllOrders() {
        $pagination = $this->getPaginationParams();
        $status = $_GET['status'] ?? null;
        $q = trim($_GET['q'] ?? '');
        
        $query = "
            SELECT o.*, 
                   c.name as customer_name, c.email as customer_email,
                   v.name as vendor_name, v.email as vendor_email
            FROM orders o
            INNER JOIN users c ON o.customer_id = c.id
            INNER JOIN users v ON o.vendor_id = v.id
            WHERE 1=1
        ";
        
        $params = [];
        
        if ($status) {
            $query .= " AND o.status = ?";
            $params[] = $status;
        }

        if ($q !== '') {
            $query .= " AND (o.order_number LIKE ? OR c.name LIKE ? OR c.email LIKE ? OR v.name LIKE ? OR v.email LIKE ?)";
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        
        $query .= " ORDER BY o.created_at DESC LIMIT ? OFFSET ?";
        $params[] = $pagination['limit'];
        $params[] = $pagination['offset'];
        
        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        $orders = $stmt->fetchAll();
        
        $this->sendResponse($orders);
    }
    
    private function getAllUsers() {
        $pagination = $this->getPaginationParams();
        $role = $_GET['role'] ?? null;
        $status = $_GET['status'] ?? null;
        $q = trim($_GET['q'] ?? '');
        
        $query = "
            SELECT id, email, name, role, status, country_id, created_at
            FROM users
            WHERE 1=1
        ";
        
        $params = [];
        
        if ($role) {
            $query .= " AND role = ?";
            $params[] = $role;
        }

        if ($status) {
            $query .= " AND status = ?";
            $params[] = $status;
        }

        if ($q !== '') {
            $query .= " AND (name LIKE ? OR email LIKE ?)";
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
        }
        
        $query .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
        $params[] = $pagination['limit'];
        $params[] = $pagination['offset'];
        
        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        $users = $stmt->fetchAll();
        
        $this->sendResponse($users);
    }

    private function getProviders() {
        $pagination = $this->getPaginationParams();
        $status = $_GET['status'] ?? null;
        $q = trim($_GET['q'] ?? '');

        $hasVendorFlag = $this->checkColumnExists('users', 'is_vendor');
        $hasProfiles = $this->checkTableExists('service_provider_profiles');
        $where = $hasVendorFlag ? "u.is_vendor = 1" : "u.role = 'VENDOR'";
        $params = [];

        if ($status) {
            $where .= " AND u.status = ?";
            $params[] = $status;
        }

        if ($q !== '') {
            $where .= " AND (u.name LIKE ? OR u.email LIKE ?" . ($hasProfiles ? " OR spp.business_name LIKE ?" : "") . ")";
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            if ($hasProfiles) {
                $params[] = $like;
            }
        }

        $profileJoin = $hasProfiles ? "LEFT JOIN service_provider_profiles spp ON spp.user_id = u.id" : "";
        $profileSelect = $hasProfiles
            ? "spp.business_name, spp.description, spp.phone"
            : "NULL AS business_name, NULL AS description, NULL AS phone";

        $query = "
            SELECT u.id, u.name, u.email, u.status, u.created_at,
                   u.vendor_verified, u.vendor_verified_at,
                   {$profileSelect}
            FROM users u
            {$profileJoin}
            WHERE {$where}
            ORDER BY u.created_at DESC
            LIMIT ? OFFSET ?
        ";
        $params[] = $pagination['limit'];
        $params[] = $pagination['offset'];

        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        $providers = $stmt->fetchAll();
        $this->sendResponse($providers);
    }

    private function getServices() {
        $pagination = $this->getPaginationParams();
        $status = $_GET['status'] ?? null;
        $q = trim($_GET['q'] ?? '');
        $params = [];

        $query = "
            SELECT s.id, s.title, s.status, s.price, s.created_at,
                   u.name AS vendor_name, u.email AS vendor_email,
                   c.name AS category_name
            FROM services s
            INNER JOIN users u ON s.vendor_id = u.id
            LEFT JOIN service_categories c ON s.category_id = c.id
            WHERE 1=1
        ";
        if ($status) {
            $query .= " AND s.status = ?";
            $params[] = $status;
        }

        if ($q !== '') {
            $query .= " AND (s.title LIKE ? OR u.name LIKE ? OR u.email LIKE ? OR c.name LIKE ?)";
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $query .= " ORDER BY s.created_at DESC LIMIT ? OFFSET ?";
        $params[] = $pagination['limit'];
        $params[] = $pagination['offset'];

        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        $services = $stmt->fetchAll();
        $this->sendResponse($services);
    }

    private function getCategories() {
        $stmt = $this->db->query("
            SELECT id, name, slug, parent_id, sort_order, is_active, created_at, updated_at
            FROM service_categories
            ORDER BY sort_order ASC, name ASC
        ");
        $this->sendResponse($stmt->fetchAll());
    }
    
    private function getTransactionLogs() {
        $pagination = $this->getPaginationParams();
        $status = $_GET['status'] ?? null;
        $q = trim($_GET['q'] ?? '');
        $params = [];
        
        $query = "
            SELECT * FROM transaction_logs
            WHERE 1=1
        ";

        if ($status) {
            $query .= " AND status = ?";
            $params[] = $status;
        }

        if ($q !== '') {
            $query .= " AND (reference LIKE ? OR transaction_reference LIKE ? OR type LIKE ? OR event_type LIKE ?)";
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $query .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
        $params[] = $pagination['limit'];
        $params[] = $pagination['offset'];
        
        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        $logs = $stmt->fetchAll();
        
        $this->sendResponse($logs);
    }

    private function getMailingList() {
        if (!$this->checkTableExists('user_contact_preferences')) {
            $this->sendResponse([]);
        }

        $pagination = $this->getPaginationParams();
        $q = trim($_GET['q'] ?? '');
        $params = [];
        $query = "
            SELECT id, user_id, email, name, username, mailing_list_opt_in, terms_accepted_at, source, mailchimp_synced_at, created_at
            FROM user_contact_preferences
            WHERE mailing_list_opt_in = 1
        ";

        if ($q !== '') {
            $query .= " AND (email LIKE ? OR name LIKE ? OR username LIKE ?)";
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $query .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
        $params[] = $pagination['limit'];
        $params[] = $pagination['offset'];

        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        $this->sendResponse($stmt->fetchAll());
    }

    private function exportMailingListCsv() {
        if (!$this->checkTableExists('user_contact_preferences')) {
            $this->sendError('Mailing list table not found', 404);
        }

        $stmt = $this->db->query("
            SELECT email, name, username, terms_accepted_at, source, created_at
            FROM user_contact_preferences
            WHERE mailing_list_opt_in = 1
            ORDER BY created_at DESC
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=\"mailing-list-export.csv\"');

        $output = fopen('php://output', 'w');
        fputcsv($output, ['email', 'name', 'username', 'terms_accepted_at', 'source', 'created_at']);
        foreach ($rows as $row) {
            fputcsv($output, [
                $row['email'] ?? '',
                $row['name'] ?? '',
                $row['username'] ?? '',
                $row['terms_accepted_at'] ?? '',
                $row['source'] ?? '',
                $row['created_at'] ?? '',
            ]);
        }
        fclose($output);
        exit();
    }

    private function getMailingListForMailchimp() {
        if (!$this->checkTableExists('user_contact_preferences')) {
            $this->sendResponse([]);
        }

        $stmt = $this->db->query("
            SELECT email, name
            FROM user_contact_preferences
            WHERE mailing_list_opt_in = 1
            ORDER BY created_at DESC
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $payload = array_map(function ($row) {
            $fullName = trim($row['name'] ?? '');
            $first = $fullName;
            $last = '';
            if (strpos($fullName, ' ') !== false) {
                $parts = preg_split('/\s+/', $fullName, 2);
                $first = $parts[0] ?? '';
                $last = $parts[1] ?? '';
            }
            return [
                'email_address' => $row['email'] ?? '',
                'status' => 'subscribed',
                'merge_fields' => [
                    'FNAME' => $first,
                    'LNAME' => $last,
                ],
            ];
        }, $rows);

        $this->sendResponse($payload);
    }

    private function moderateMailingList() {
        if (!$this->checkTableExists('user_contact_preferences')) {
            $this->sendError('Mailing list table not found', 404);
        }

        $data = $this->getRequestBody();
        $action = $data['action'] ?? '';

        if ($action !== 'mark-synced') {
            $this->sendError('Unsupported mailing-list action', 400);
        }

        $ids = $data['ids'] ?? [];
        if (!is_array($ids) || count($ids) === 0) {
            $this->sendError('ids array is required', 400);
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $query = "
            UPDATE user_contact_preferences
            SET mailchimp_synced_at = NOW(), updated_at = NOW()
            WHERE id IN ({$placeholders}) AND mailing_list_opt_in = 1
        ";
        $stmt = $this->db->prepare($query);
        $stmt->execute($ids);

        $this->sendResponse([
            'message' => 'Mailing list contacts marked as synced',
            'updated' => $stmt->rowCount(),
        ]);
    }

    private function moderateUser() {
        $data = $this->getRequestBody();
        $action = $data['action'] ?? '';
        $userId = $data['user_id'] ?? '';

        if (!$userId || !$action) {
            $this->sendError('action and user_id are required', 400);
        }

        switch ($action) {
            case 'activate':
                $this->updateUserStatus($userId, 'ACTIVE');
                break;
            case 'deactivate':
                $this->updateUserStatus($userId, 'INACTIVE');
                break;
            case 'promote-manager':
                $this->updateUserRole($userId, 'MANAGER');
                break;
            case 'promote-admin':
                $this->updateUserRole($userId, 'ADMIN');
                break;
            case 'make-vendor':
                $this->setVendorCapability($userId, true);
                break;
            case 'remove-vendor':
                $this->setVendorCapability($userId, false);
                break;
            default:
                $this->sendError('Unsupported user action', 400);
        }
    }

    private function moderateProvider() {
        $data = $this->getRequestBody();
        $action = $data['action'] ?? '';
        $providerId = $data['provider_id'] ?? '';

        if (!$providerId || !$action) {
            $this->sendError('action and provider_id are required', 400);
        }

        switch ($action) {
            case 'verify':
                if ($this->checkColumnExists('users', 'vendor_verified')) {
                    $stmt = $this->db->prepare("UPDATE users SET vendor_verified = 1, vendor_verified_at = NOW() WHERE id = ?");
                    $stmt->execute([$providerId]);
                }
                $this->sendResponse(['message' => 'Provider verified']);
                break;
            case 'activate':
                $this->updateUserStatus($providerId, 'ACTIVE');
                break;
            case 'deactivate':
                $this->updateUserStatus($providerId, 'INACTIVE');
                break;
            default:
                $this->sendError('Unsupported provider action', 400);
        }
    }

    private function moderateOrder() {
        $data = $this->getRequestBody();
        $action = $data['action'] ?? '';
        $orderId = $data['order_id'] ?? '';

        if (!$orderId || !$action) {
            $this->sendError('action and order_id are required', 400);
        }

        switch ($action) {
            case 'force-complete':
                $this->postForceComplete($orderId);
                break;
            case 'refund':
                $this->markOrderRefunded($orderId);
                break;
            case 'cancel':
                $stmt = $this->db->prepare("UPDATE orders SET status = 'CANCELLED' WHERE id = ?");
                $stmt->execute([$orderId]);
                $this->sendResponse(['message' => 'Order cancelled']);
                break;
            default:
                $this->sendError('Unsupported order action', 400);
        }
    }

    private function moderateDispute() {
        $data = $this->getRequestBody();
        $action = $data['action'] ?? '';
        $disputeId = $data['dispute_id'] ?? '';

        if ($action !== 'resolve') {
            $this->sendError('Unsupported dispute action', 400);
        }
        if (!$disputeId) {
            $this->sendError('dispute_id is required', 400);
        }

        $this->postResolve($disputeId);
    }

    private function moderateCategory() {
        $data = $this->getRequestBody();
        $action = $data['action'] ?? '';
        $categoryId = $data['category_id'] ?? '';

        if (!$categoryId || !$action) {
            $this->sendError('action and category_id are required', 400);
        }

        switch ($action) {
            case 'activate':
                $stmt = $this->db->prepare("UPDATE service_categories SET is_active = 1, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$categoryId]);
                $this->sendResponse(['message' => 'Category activated']);
                break;
            case 'deactivate':
                $stmt = $this->db->prepare("UPDATE service_categories SET is_active = 0, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$categoryId]);
                $this->sendResponse(['message' => 'Category deactivated']);
                break;
            case 'delete':
                $stmt = $this->db->prepare("DELETE FROM service_categories WHERE id = ?");
                $stmt->execute([$categoryId]);
                $this->sendResponse(['message' => 'Category deleted']);
                break;
            default:
                $this->sendError('Unsupported category action', 400);
        }
    }

    private function moderateService() {
        $data = $this->getRequestBody();
        $action = $data['action'] ?? '';
        $serviceId = $data['service_id'] ?? '';

        if (!$serviceId || !$action) {
            $this->sendError('action and service_id are required', 400);
        }

        switch ($action) {
            case 'activate':
                $stmt = $this->db->prepare("UPDATE services SET status = 'ACTIVE' WHERE id = ?");
                $stmt->execute([$serviceId]);
                $this->sendResponse(['message' => 'Service activated']);
                break;
            case 'deactivate':
                $stmt = $this->db->prepare("UPDATE services SET status = 'INACTIVE' WHERE id = ?");
                $stmt->execute([$serviceId]);
                $this->sendResponse(['message' => 'Service deactivated']);
                break;
            case 'delete':
                $stmt = $this->db->prepare("DELETE FROM services WHERE id = ?");
                $stmt->execute([$serviceId]);
                $this->sendResponse(['message' => 'Service deleted']);
                break;
            default:
                $this->sendError('Unsupported service action', 400);
        }
    }

    private function updateUserStatus(string $userId, string $status) {
        $stmt = $this->db->prepare("UPDATE users SET status = ? WHERE id = ?");
        $stmt->execute([$status, $userId]);
        $this->sendResponse(['message' => "User status updated to {$status}"]);
    }

    private function updateUserRole(string $userId, string $role) {
        $stmt = $this->db->prepare("UPDATE users SET role = ? WHERE id = ?");
        $stmt->execute([$role, $userId]);
        $this->sendResponse(['message' => "User promoted to {$role}"]);
    }

    private function setVendorCapability(string $userId, bool $enabled) {
        if ($this->checkColumnExists('users', 'is_vendor')) {
            $stmt = $this->db->prepare("UPDATE users SET is_vendor = ? WHERE id = ?");
            $stmt->execute([$enabled ? 1 : 0, $userId]);
            $this->sendResponse(['message' => $enabled ? 'Vendor capability granted' : 'Vendor capability removed']);
        }

        // Fallback for older schema
        if ($enabled) {
            $stmt = $this->db->prepare("UPDATE users SET role = 'VENDOR' WHERE id = ?");
            $stmt->execute([$userId]);
            $this->sendResponse(['message' => 'User set as vendor']);
        } else {
            $stmt = $this->db->prepare("UPDATE users SET role = 'CUSTOMER' WHERE id = ? AND role = 'VENDOR'");
            $stmt->execute([$userId]);
            $this->sendResponse(['message' => 'Vendor role removed']);
        }
    }

    private function markOrderRefunded(string $orderId) {
        $stmt = $this->db->prepare("UPDATE orders SET status = 'REFUNDED' WHERE id = ?");
        $stmt->execute([$orderId]);
        $this->sendResponse(['message' => 'Order marked as refunded']);
    }
    
    private function getPaymentSettings() {
        $global = [
            'vendor_initial_percentage' => floatval(RuntimeSettings::get('VENDOR_INITIAL_PERCENTAGE', 50)),
            'insurance_percentage' => floatval(RuntimeSettings::get('INSURANCE_PERCENTAGE', 1)),
            'commission_percentage' => floatval(RuntimeSettings::get('COMMISSION_PERCENTAGE', 5)),
            'vat_percentage' => floatval(RuntimeSettings::get('VAT_PERCENTAGE', 7.5)),
            'service_charge' => floatval(RuntimeSettings::get('SERVICE_CHARGE', 250)),
            'payment_method_type' => (string)RuntimeSettings::get('PAYMENT_METHOD_TYPE', 'individual'),
            'transfer_method' => (string)RuntimeSettings::get('TRANSFER_METHOD', 'single'),
        ];

        $countriesStmt = $this->db->query("
            SELECT id, name, payment_gateway, payment_gateway_config, status
            FROM countries
            ORDER BY name ASC
        ");
        $countries = $countriesStmt->fetchAll();
        $countries = array_map(function ($country) {
            $country['payment_gateway_config'] = json_decode($country['payment_gateway_config'] ?? '{}', true) ?: [];
            return $country;
        }, $countries);

        $this->sendResponse([
            'global' => $global,
            'countries' => $countries,
        ]);
    }
    
    private function updatePaymentSettings() {
        $data = $this->getRequestBody();
        $global = $data['global'] ?? null;
        $countries = $data['countries'] ?? null;

        if ($global && is_array($global)) {
            $allowed = [
                'VENDOR_INITIAL_PERCENTAGE' => $global['vendor_initial_percentage'] ?? null,
                'INSURANCE_PERCENTAGE' => $global['insurance_percentage'] ?? null,
                'COMMISSION_PERCENTAGE' => $global['commission_percentage'] ?? null,
                'VAT_PERCENTAGE' => $global['vat_percentage'] ?? null,
                'SERVICE_CHARGE' => $global['service_charge'] ?? null,
                'PAYMENT_METHOD_TYPE' => $global['payment_method_type'] ?? null,
                'TRANSFER_METHOD' => $global['transfer_method'] ?? null,
            ];

            foreach ($allowed as $key => $value) {
                if ($value !== null && $value !== '') {
                    $this->setAppSetting($key, $value);
                }
            }
        }

        if ($countries && is_array($countries)) {
            $stmt = $this->db->prepare("
                UPDATE countries
                SET payment_gateway = ?,
                    payment_gateway_config = ?,
                    status = ?
                WHERE id = ?
            ");
            foreach ($countries as $country) {
                $id = $country['id'] ?? '';
                if (!$id) {
                    continue;
                }
                $gateway = $country['payment_gateway'] ?? null;
                $config = $country['payment_gateway_config'] ?? [];
                $status = $country['status'] ?? 'ACTIVE';
                $stmt->execute([
                    $gateway,
                    json_encode($config),
                    $status,
                    $id,
                ]);
            }
        }

        $this->sendResponse(['message' => 'Payment settings updated successfully']);
    }

    private function getPayoutAudit() {
        $pagination = $this->getPaginationParams();
        $countryId = trim($_GET['country_id'] ?? '');
        $q = trim($_GET['q'] ?? '');
        $status = trim($_GET['status'] ?? '');

        $hasIsVendor = $this->checkColumnExists('users', 'is_vendor');
        $hasSubCountry = $this->checkColumnExists('subaccounts', 'country_id');
        $hasVerifiedAt = $this->checkColumnExists('subaccounts', 'account_verified_at');

        $countryExpr = $hasSubCountry ? "COALESCE(sa.country_id, u.country_id)" : "u.country_id";
        $verifiedAtExpr = $hasVerifiedAt ? "sa.account_verified_at" : "sa.updated_at";
        $vendorWhere = $hasIsVendor ? "(u.is_vendor = 1 OR u.role = 'VENDOR')" : "(u.role = 'VENDOR')";

        $query = "
            SELECT
                u.id AS user_id,
                u.name AS user_name,
                u.email AS user_email,
                {$countryExpr} AS country_id,
                c.name AS country_name,
                sa.subaccount_code,
                sa.account_number,
                sa.account_name,
                sa.bank_code,
                sa.bank_name,
                sa.status AS subaccount_status,
                {$verifiedAtExpr} AS verified_at,
                sa.updated_at
            FROM users u
            LEFT JOIN subaccounts sa ON sa.user_id = u.id
            LEFT JOIN countries c ON c.id = {$countryExpr}
            WHERE {$vendorWhere}
        ";
        $params = [];

        if ($countryId !== '') {
            $query .= " AND {$countryExpr} = ?";
            $params[] = $countryId;
        }
        if ($q !== '') {
            $query .= " AND (u.name LIKE ? OR u.email LIKE ?)";
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
        }

        if ($status === 'verified') {
            $query .= " AND sa.subaccount_code IS NOT NULL AND sa.subaccount_code <> ''";
        } elseif ($status === 'unverified') {
            $query .= " AND (sa.subaccount_code IS NULL OR sa.subaccount_code = '')";
        }

        $query .= " ORDER BY u.created_at DESC LIMIT ? OFFSET ?";
        $params[] = $pagination['limit'];
        $params[] = $pagination['offset'];

        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $normalized = array_map(function ($row) {
            $accountNumber = (string)($row['account_number'] ?? '');
            $tail = $accountNumber !== '' ? substr($accountNumber, -4) : '';
            $masked = $tail !== '' ? '****' . $tail : null;
            $isVerified = !empty($row['subaccount_code']) && !empty($row['account_name']) && ($row['subaccount_status'] ?? '') !== 'inactive';
            return [
                'user_id' => $row['user_id'],
                'user_name' => $row['user_name'],
                'user_email' => $row['user_email'],
                'country_id' => $row['country_id'],
                'country_name' => $row['country_name'],
                'subaccount_code' => $row['subaccount_code'],
                'account_number' => $accountNumber,
                'masked_account_number' => $masked,
                'account_name' => $row['account_name'],
                'bank_code' => $row['bank_code'],
                'bank_name' => $row['bank_name'],
                'subaccount_status' => $row['subaccount_status'],
                'is_verified' => $isVerified,
                'verified_at' => $row['verified_at'] ?: $row['updated_at'],
            ];
        }, $rows);

        $this->sendResponse($normalized);
    }

    private function getVerificationSubmissions() {
        $pagination = $this->getPaginationParams();
        $userId = trim($_GET['user_id'] ?? '');
        $status = trim($_GET['status'] ?? '');
        $countryId = trim($_GET['country_id'] ?? '');
        $q = trim($_GET['q'] ?? '');

        $query = "
            SELECT
                s.*,
                u.name AS user_name,
                u.email AS user_email,
                u.vendor_verified,
                u.vendor_verified_at,
                c.name AS country_name,
                reviewer.name AS reviewed_by_name
            FROM provider_verification_submissions s
            INNER JOIN users u ON s.user_id = u.id
            LEFT JOIN users reviewer ON s.reviewed_by = reviewer.id
            LEFT JOIN countries c ON s.country_id = c.id
            WHERE 1=1
        ";
        $params = [];

        if ($userId !== '') {
            $query .= " AND s.user_id = ?";
            $params[] = $userId;
        }
        if ($status !== '') {
            $query .= " AND s.status = ?";
            $params[] = strtoupper($status);
        }
        if ($countryId !== '') {
            $query .= " AND s.country_id = ?";
            $params[] = strtoupper($countryId);
        }
        if ($q !== '') {
            $query .= " AND (u.name LIKE ? OR u.email LIKE ?)";
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $query .= " ORDER BY s.updated_at DESC LIMIT ? OFFSET ?";
        $params[] = $pagination['limit'];
        $params[] = $pagination['offset'];

        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $rows = array_map(function ($row) {
            $row['payload'] = json_decode($row['payload_json'] ?? '{}', true) ?: [];
            return $row;
        }, $rows);

        $this->sendResponse($rows);
    }

    private function moderateVerification() {
        $data = $this->getRequestBody();
        $action = $data['action'] ?? '';
        $userId = $data['user_id'] ?? '';
        $countryId = strtoupper($data['country_id'] ?? '');
        $rejectionReason = trim($data['rejection_reason'] ?? '');
        $admin = $this->auth->requireAdmin();

        if (!$userId || !$countryId) {
            $this->sendError('user_id and country_id are required', 400);
        }

        if ($action !== 'approve' && $action !== 'reject') {
            $this->sendError('Unsupported verification action', 400);
        }

        $submissionStmt = $this->db->prepare("
            SELECT id
            FROM provider_verification_submissions
            WHERE user_id = ? AND country_id = ?
            LIMIT 1
        ");
        $submissionStmt->execute([$userId, $countryId]);
        $submission = $submissionStmt->fetch(PDO::FETCH_ASSOC);
        if (!$submission) {
            $this->sendError('Verification submission not found', 404);
        }

        if ($action === 'approve') {
            $stmt = $this->db->prepare("
                UPDATE provider_verification_submissions
                SET status = 'APPROVED',
                    rejection_reason = NULL,
                    reviewed_by = ?,
                    reviewed_at = NOW(),
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$admin['id'], $submission['id']]);
            if ($this->checkColumnExists('users', 'vendor_verified')) {
                $userStmt = $this->db->prepare("UPDATE users SET vendor_verified = 1, vendor_verified_at = NOW() WHERE id = ?");
                $userStmt->execute([$userId]);
            }
            $this->sendResponse(['message' => 'Service provider verification approved']);
        }

        $stmt = $this->db->prepare("
            UPDATE provider_verification_submissions
            SET status = 'REJECTED',
                rejection_reason = ?,
                reviewed_by = ?,
                reviewed_at = NOW(),
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$rejectionReason ?: 'Not specified', $admin['id'], $submission['id']]);
        if ($this->checkColumnExists('users', 'vendor_verified')) {
            $userStmt = $this->db->prepare("UPDATE users SET vendor_verified = 0, vendor_verified_at = NULL WHERE id = ?");
            $userStmt->execute([$userId]);
        }
        $this->sendResponse(['message' => 'Service provider verification rejected']);
    }

    private function ensureAppSettingsTable() {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS app_settings (
                id CHAR(36) NOT NULL PRIMARY KEY,
                `key` VARCHAR(128) NOT NULL UNIQUE,
                value_json LONGTEXT NULL,
                updated_by CHAR(36) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
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

    private function setAppSetting(string $key, $value): void {
        $valueJson = json_encode($value);
        $stmt = $this->db->prepare("SELECT id FROM app_settings WHERE `key` = ? LIMIT 1");
        $stmt->execute([$key]);
        $existing = $stmt->fetch();
        if ($existing) {
            $update = $this->db->prepare("UPDATE app_settings SET value_json = ?, updated_at = NOW() WHERE id = ?");
            $update->execute([$valueJson, $existing['id']]);
            return;
        }
        $insert = $this->db->prepare("
            INSERT INTO app_settings (id, `key`, value_json, created_at, updated_at)
            VALUES (?, ?, ?, NOW(), NOW())
        ");
        $insert->execute([$this->generateUUID(), $key, $valueJson]);
    }
    
    private function getDisputes() {
        $pagination = $this->getPaginationParams();
        $status = $_GET['status'] ?? null;
        
        $query = "
            SELECT d.*, 
                   o.order_number,
                   c.name as customer_name,
                   v.name as vendor_name,
                   u.name as raised_by_name
            FROM disputes d
            INNER JOIN orders o ON d.order_id = o.id
            INNER JOIN users c ON o.customer_id = c.id
            INNER JOIN users v ON o.vendor_id = v.id
            INNER JOIN users u ON d.raised_by = u.id
            WHERE 1=1
        ";
        
        $params = [];
        
        if ($status) {
            $query .= " AND d.status = ?";
            $params[] = $status;
        }
        
        $query .= " ORDER BY d.created_at DESC LIMIT ? OFFSET ?";
        $params[] = $pagination['limit'];
        $params[] = $pagination['offset'];
        
        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        $disputes = $stmt->fetchAll();
        
        $this->sendResponse($disputes);
    }
    
    private function processBalancePayment(string $orderId) {
        // Same logic as in OrderController
        $breakdownStmt = $this->db->prepare("
            SELECT pb.*, o.vendor_id, o.country_id, o.currency_code
            FROM payment_breakdowns pb
            INNER JOIN orders o ON pb.order_id = o.id
            WHERE pb.order_id = ? AND pb.balance_paid = 0
        ");
        $breakdownStmt->execute([$orderId]);
        $breakdown = $breakdownStmt->fetch();
        
        if (!$breakdown || $breakdown['vendor_balance_amount'] <= 0) {
            return;
        }
        
        $subaccountStmt = $this->db->prepare("
            SELECT * FROM subaccounts 
            WHERE user_id = ? AND country_id = ? AND status = 'active'
            LIMIT 1
        ");
        $subaccountStmt->execute([$breakdown['vendor_id'], $breakdown['country_id']]);
        $subaccount = $subaccountStmt->fetch();
        
        if (!$subaccount) {
            return;
        }
        
        try {
            $gateway = PaymentGatewayFactory::getGatewayForCountry($breakdown['country_id']);
            
            $result = $gateway->createTransfer(
                $breakdown['vendor_balance_amount'],
                $subaccount['subaccount_code'],
                'Order completion balance payment'
            );
            
            $updateStmt = $this->db->prepare("
                UPDATE payment_breakdowns 
                SET balance_paid = 1 
                WHERE order_id = ?
            ");
            $updateStmt->execute([$orderId]);
        } catch (Exception $e) {
            error_log("Balance payment failed: " . $e->getMessage());
        }
    }
}
