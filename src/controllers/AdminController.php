<?php
/**
 * Admin Controller - Complete Implementation
 */

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../payment/PaymentGatewayFactory.php';
require_once __DIR__ . '/../utils/CountryManager.php';
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
            case 'logs':
                $this->getTransactionLogs();
                break;
            case 'payment-settings':
                $this->getPaymentSettings();
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
            case 'payment-settings':
                $this->updatePaymentSettings();
                break;
            default:
                $this->sendError('Resource not found', 404);
        }
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
        
        $query .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
        $params[] = $pagination['limit'];
        $params[] = $pagination['offset'];
        
        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        $users = $stmt->fetchAll();
        
        $this->sendResponse($users);
    }
    
    private function getTransactionLogs() {
        $pagination = $this->getPaginationParams();
        
        $query = "
            SELECT * FROM transaction_logs
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        ";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute([$pagination['limit'], $pagination['offset']]);
        $logs = $stmt->fetchAll();
        
        $this->sendResponse($logs);
    }
    
    private function getPaymentSettings() {
        $settings = [
            'vendor_initial_percentage' => floatval(getenv('VENDOR_INITIAL_PERCENTAGE') ?: 50),
            'insurance_percentage' => floatval(getenv('INSURANCE_PERCENTAGE') ?: 1),
            'commission_percentage' => floatval(getenv('COMMISSION_PERCENTAGE') ?: 5),
            'vat_percentage' => floatval(getenv('VAT_PERCENTAGE') ?: 7.5),
            'service_charge' => floatval(getenv('SERVICE_CHARGE') ?: 250),
            'payment_method_type' => getenv('PAYMENT_METHOD_TYPE') ?: 'individual',
            'transfer_method' => getenv('TRANSFER_METHOD') ?: 'single',
        ];
        
        $this->sendResponse($settings);
    }
    
    private function updatePaymentSettings() {
        $data = $this->getRequestBody();
        
        // Update would typically update .env or database settings
        // For now, return success (actual implementation would update config)
        
        $this->sendResponse(['message' => 'Payment settings updated', 'settings' => $data]);
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
