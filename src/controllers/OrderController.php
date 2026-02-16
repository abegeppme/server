<?php
/**
 * Order Controller - Complete Implementation
 */

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../utils/PaymentBreakdownCalculator.php';
require_once __DIR__ . '/../utils/RuntimeSettings.php';
require_once __DIR__ . '/../payment/PaymentGatewayFactory.php';
require_once __DIR__ . '/../utils/CountryManager.php';
require_once __DIR__ . '/../services/EmailService.php';
require_once __DIR__ . '/../services/NotificationService.php';
require_once __DIR__ . '/../services/PushNotificationService.php';

class OrderController extends BaseController {
    private $auth;
    private $breakdownCalculator;
    private $countryManager;
    private $emailService;
    private $notificationService;
    private $pushService;
    
    public function __construct() {
        parent::__construct();
        $this->auth = new AuthMiddleware();
        $this->breakdownCalculator = new PaymentBreakdownCalculator();
        $this->countryManager = new CountryManager();
        $this->emailService = new EmailService();
        $this->notificationService = new NotificationService();
        $this->pushService = new PushNotificationService();
    }
    
    public function index() {
        $user = $this->auth->requireAuth();
        $pagination = $this->getPaginationParams();
        
        $status = $_GET['status'] ?? null;
        $role = $user['role'];
        
        $query = "
            SELECT o.*, 
                   c.name as customer_name, c.email as customer_email,
                   v.name as vendor_name, v.email as vendor_email,
                   co.name as country_name, cur.symbol as currency_symbol
            FROM orders o
            INNER JOIN users c ON o.customer_id = c.id
            INNER JOIN users v ON o.vendor_id = v.id
            INNER JOIN countries co ON o.country_id = co.id
            INNER JOIN currencies cur ON o.currency_code = cur.code
            WHERE 1=1
        ";
        
        $params = [];
        
        // Filter by user role
        if ($role === 'CUSTOMER') {
            $query .= " AND o.customer_id = ?";
            $params[] = $user['id'];
        } elseif ($role === 'VENDOR') {
            $query .= " AND o.vendor_id = ?";
            $params[] = $user['id'];
        }
        
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
        
        // Get total count
        $countQuery = str_replace('SELECT o.*,', 'SELECT COUNT(*) as total', $query);
        $countQuery = preg_replace('/ORDER BY.*$/', '', $countQuery);
        $countQuery = preg_replace('/LIMIT.*$/', '', $countQuery);
        $countStmt = $this->db->prepare($countQuery);
        $countStmt->execute(array_slice($params, 0, -2));
        $total = $countStmt->fetch()['total'];
        
        $this->sendResponse([
            'orders' => $orders,
            'pagination' => [
                'page' => $pagination['page'],
                'limit' => $pagination['limit'],
                'total' => $total,
                'pages' => ceil($total / $pagination['limit']),
            ],
        ]);
    }
    
    public function get($id) {
        $user = $this->auth->requireAuth();
        
        $stmt = $this->db->prepare("
            SELECT o.*, 
                   c.name as customer_name, c.email as customer_email, c.phone as customer_phone,
                   v.name as vendor_name, v.email as vendor_email, v.phone as vendor_phone,
                   co.name as country_name, cur.symbol as currency_symbol,
                   pb.*
            FROM orders o
            INNER JOIN users c ON o.customer_id = c.id
            INNER JOIN users v ON o.vendor_id = v.id
            INNER JOIN countries co ON o.country_id = co.id
            INNER JOIN currencies cur ON o.currency_code = cur.code
            LEFT JOIN payment_breakdowns pb ON o.id = pb.order_id
            WHERE o.id = ?
        ");
        $stmt->execute([$id]);
        $order = $stmt->fetch();
        
        if (!$order) {
            $this->sendError('Order not found', 404);
        }
        
        // Check authorization
        if ($user['role'] !== 'ADMIN' && $order['customer_id'] !== $user['id'] && $order['vendor_id'] !== $user['id']) {
            $this->sendError('Unauthorized', 403);
        }
        
        // Get order items
        $itemsStmt = $this->db->prepare("
            SELECT oi.*, s.title as service_title, s.description as service_description
            FROM order_items oi
            INNER JOIN services s ON oi.service_id = s.id
            WHERE oi.order_id = ?
        ");
        $itemsStmt->execute([$id]);
        $order['items'] = $itemsStmt->fetchAll();
        
        // Get payment info
        $paymentStmt = $this->db->prepare("SELECT * FROM payments WHERE order_id = ?");
        $paymentStmt->execute([$id]);
        $order['payment'] = $paymentStmt->fetch();
        
        $this->sendResponse($order);
    }
    
    public function create() {
        $user = $this->auth->requireAuth();
        $data = $this->getRequestBody();
        
        // Validate required fields
        $vendorId = $data['vendor_id'] ?? null;
        $serviceId = $data['service_id'] ?? null;
        $quantity = intval($data['quantity'] ?? 1);
        $paymentMethodType = $data['payment_method_type'] ?? 'individual';
        
        if (!$vendorId || !$serviceId) {
            $this->sendError('vendor_id and service_id are required', 400);
        }
        
        // Get service
        $serviceStmt = $this->db->prepare("
            SELECT s.*, u.country_id, u.preferred_currency
            FROM services s
            INNER JOIN users u ON s.vendor_id = u.id
            WHERE s.id = ? AND s.vendor_id = ? AND s.status = 'ACTIVE'
        ");
        $serviceStmt->execute([$serviceId, $vendorId]);
        $service = $serviceStmt->fetch();
        
        if (!$service) {
            $this->sendError('Service not found or not available', 404);
        }
        
        // Get country and currency
        $countryId = $data['country_id'] ?? $service['country_id'] ?? $user['country_id'] ?? 'NG';
        $country = $this->countryManager->getCountry($countryId);
        if (!$country) {
            $this->sendError('Country not found', 404);
        }
        
        $currencyCode = $data['currency_code'] ?? $service['currency_code'] ?? $country['currency_code'];
        
        // Price is negotiated - must be provided in order creation
        // This order is created from an invoice with negotiated price
        $subtotal = $data['subtotal'] ?? $data['price'] ?? null;
        if ($subtotal === null || $subtotal <= 0) {
            $this->sendError('Price (subtotal) is required. This should be the negotiated price from chat/invoice.', 400);
        }
        $subtotal = floatval($subtotal);
        
        $serviceCharge = floatval($data['service_charge'] ?? RuntimeSettings::get('SERVICE_CHARGE', 250));
        
        // Calculate payment breakdown
        $breakdown = $this->breakdownCalculator->calculate([
            'subtotal' => $subtotal,
            'service_charge' => $serviceCharge,
        ], $paymentMethodType);
        
        $total = $breakdown['total'];
        $vatAmount = $breakdown['vat_amount'];
        
        // Generate order number
        $orderNumber = 'ORD-' . strtoupper(substr(uniqid(), -8)) . '-' . time();
        $orderId = $this->generateUUID();
        
        // Create order
        $orderStmt = $this->db->prepare("
            INSERT INTO orders (
                id, order_number, customer_id, vendor_id, country_id, currency_code,
                subtotal, service_charge, vat_amount, total, status, payment_method_type, payment_method_set_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDING', ?, NOW())
        ");
        $orderStmt->execute([
            $orderId,
            $orderNumber,
            $user['id'],
            $vendorId,
            $countryId,
            $currencyCode,
            $subtotal,
            $serviceCharge,
            $vatAmount,
            $total,
            $paymentMethodType,
        ]);
        
        // Create order item
        $itemId = $this->generateUUID();
        $itemStmt = $this->db->prepare("
            INSERT INTO order_items (id, order_id, service_id, quantity, price, currency_code)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $itemStmt->execute([
            $itemId,
            $orderId,
            $serviceId,
            $quantity,
            $service['price'],
            $currencyCode,
        ]);
        
        // Create payment breakdown
        $breakdownId = $this->generateUUID();
        $breakdownStmt = $this->db->prepare("
            INSERT INTO payment_breakdowns (
                id, order_id, currency_code, total, subtotal, service_charge, vat_amount,
                vendor_initial_pct, insurance_pct, commission_pct, vat_pct,
                vendor_initial_amount, insurance_amount, commission_amount, vendor_balance_amount,
                payment_method_type, insurance_subaccount, snapshot
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $breakdownStmt->execute([
            $breakdownId,
            $orderId,
            $currencyCode,
            $breakdown['total'],
            $breakdown['subtotal'],
            $breakdown['service_charge'],
            $breakdown['vat_amount'],
            $breakdown['vendor_initial_pct'],
            $breakdown['insurance_pct'],
            $breakdown['commission_pct'],
            $breakdown['vat_pct'],
            $breakdown['vendor_initial_amount'],
            $breakdown['insurance_amount'],
            $breakdown['commission_amount'],
            $breakdown['vendor_balance_amount'],
            $breakdown['payment_method_type'],
            $breakdown['insurance_subaccount'],
            json_encode($breakdown['snapshot']),
        ]);
        
        // Get vendor
        $vendorStmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
        $vendorStmt->execute([$vendorId]);
        $vendor = $vendorStmt->fetch();
        
        // Send notifications
        $this->notificationService->notifyOrderCreated([
            'id' => $orderId,
            'order_number' => $orderNumber,
        ], $vendor);
        
        $this->pushService->sendOrderNotification($vendorId, [
            'id' => $orderId,
            'order_number' => $orderNumber,
        ]);
        
        // Get created order
        $getStmt = $this->db->prepare("SELECT * FROM orders WHERE id = ?");
        $getStmt->execute([$orderId]);
        $createdOrder = $getStmt->fetch();
        
        $this->sendResponse([
            'order' => $createdOrder,
            'breakdown' => $breakdown,
        ], 201);
    }
    
    public function postComplete($id) {
        $user = $this->auth->requireVendor();
        
        // Get order
        $orderStmt = $this->db->prepare("
            SELECT o.*, c.email as customer_email, c.name as customer_name
            FROM orders o
            INNER JOIN users c ON o.customer_id = c.id
            WHERE o.id = ? AND o.vendor_id = ?
        ");
        $orderStmt->execute([$id, $user['id']]);
        $order = $orderStmt->fetch();
        
        if (!$order) {
            $this->sendError('Order not found', 404);
        }
        
        if ($order['status'] !== 'IN_SERVICE') {
            $this->sendError('Order is not in service', 400);
        }
        
        $data = $this->getRequestBody();
        $completionDocuments = $data['completion_documents'] ?? [];
        
        // Update order - Set 7-day auto-release date
        $autoReleaseDate = date('Y-m-d H:i:s', strtotime('+7 days'));
        $updateStmt = $this->db->prepare("
            UPDATE orders 
            SET vendor_complete = 1,
                vendor_completed_at = NOW(),
                auto_release_date = ?,
                status = 'AWAITING_CONFIRMATION',
                completion_documents = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $updateStmt->execute([$autoReleaseDate, json_encode($completionDocuments), $id]);
        
        // Notify customer
        $customerStmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
        $customerStmt->execute([$order['customer_id']]);
        $customer = $customerStmt->fetch();
        
        if ($customer) {
            $this->emailService->sendServiceComplete($order, $customer);
            $this->notificationService->notifyServiceComplete($order, $customer);
            $this->pushService->sendToUser($customer['id'], 
                'Service Completed',
                "The service for order #{$order['order_number']} has been completed. Please confirm to release payment.",
                ['type' => 'order_complete', 'order_id' => $id]
            );
        }
        
        $this->sendResponse(['message' => 'Service marked as complete', 'order_id' => $id]);
    }
    
    public function postConfirm($id) {
        $user = $this->auth->requireAuth();
        
        // Get order
        $orderStmt = $this->db->prepare("
            SELECT o.*, v.email as vendor_email, v.name as vendor_name
            FROM orders o
            INNER JOIN users v ON o.vendor_id = v.id
            WHERE o.id = ? AND o.customer_id = ?
        ");
        $orderStmt->execute([$id, $user['id']]);
        $order = $orderStmt->fetch();
        
        if (!$order) {
            $this->sendError('Order not found', 404);
        }
        
        if ($order['status'] !== 'AWAITING_CONFIRMATION') {
            $this->sendError('Order is not awaiting confirmation', 400);
        }
        
        if (!$order['vendor_complete']) {
            $this->sendError('Vendor has not marked service as complete', 400);
        }
        
        // Update order - Start 48-hour hold period
        $payoutReleaseDate = date('Y-m-d H:i:s', strtotime('+48 hours'));
        $updateStmt = $this->db->prepare("
            UPDATE orders 
            SET customer_confirmed = 1,
                customer_confirmed_at = NOW(),
                payout_release_date = ?,
                status = 'AWAITING_PAYOUT',
                updated_at = NOW()
            WHERE id = ?
        ");
        $updateStmt->execute([$payoutReleaseDate, $id]);
        
        // DO NOT process balance payment immediately - wait for 48-hour hold period
        // Payment will be released by cron job after 48 hours if no dispute is raised
        
        // Notify vendor
        $vendorStmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
        $vendorStmt->execute([$order['vendor_id']]);
        $vendor = $vendorStmt->fetch();
        
        if ($vendor) {
            $this->pushService->sendToUser($vendor['id'],
                'Order Confirmed',
                "Customer has confirmed order #{$order['order_number']}. Balance payment has been released.",
                ['type' => 'order_confirmed', 'order_id' => $id]
            );
        }
        
        $this->sendResponse(['message' => 'Order confirmed and balance payment released', 'order_id' => $id]);
    }
    
    public function postDispute($id) {
        $user = $this->auth->requireAuth();
        $data = $this->getRequestBody();
        
        $reason = $data['reason'] ?? '';
        $description = $data['description'] ?? '';
        
        if (empty($reason) || empty($description)) {
            $this->sendError('Reason and description are required', 400);
        }
        
        // Get order
        $orderStmt = $this->db->prepare("
            SELECT * FROM orders 
            WHERE id = ? AND (customer_id = ? OR vendor_id = ?)
        ");
        $orderStmt->execute([$id, $user['id'], $user['id']]);
        $order = $orderStmt->fetch();
        
        if (!$order) {
            $this->sendError('Order not found', 404);
        }
        
        // Check if dispute already exists
        $disputeStmt = $this->db->prepare("SELECT * FROM disputes WHERE order_id = ?");
        $disputeStmt->execute([$id]);
        if ($disputeStmt->fetch()) {
            $this->sendError('Dispute already exists for this order', 400);
        }
        
        // Create dispute
        $disputeId = $this->generateUUID();
        $disputeInsertStmt = $this->db->prepare("
            INSERT INTO disputes (id, order_id, raised_by, reason, description, status)
            VALUES (?, ?, ?, ?, ?, 'PENDING')
        ");
        $disputeInsertStmt->execute([$disputeId, $id, $user['id'], $reason, $description]);
        
        // Update order status
        $orderUpdateStmt = $this->db->prepare("UPDATE orders SET status = 'IN_DISPUTE' WHERE id = ?");
        $orderUpdateStmt->execute([$id]);
        
        // Notify admin
        $adminStmt = $this->db->prepare("SELECT * FROM users WHERE role = 'ADMIN' LIMIT 1");
        $adminStmt->execute();
        $admin = $adminStmt->fetch();
        
        if ($admin) {
            $this->notificationService->create(
                $admin['id'],
                'DISPUTE_RAISED',
                'New Dispute Raised',
                "A dispute has been raised for order #{$order['order_number']}",
                ['order_id' => $id, 'dispute_id' => $disputeId]
            );
        }
        
        $this->sendResponse(['message' => 'Dispute raised successfully', 'dispute_id' => $disputeId], 201);
    }
    
    public function postPayout($id) {
        $user = $this->auth->requireVendor();
        
        // Get vendor balance
        $balanceStmt = $this->db->prepare("
            SELECT SUM(pb.vendor_balance_amount) as total_balance
            FROM payment_breakdowns pb
            INNER JOIN orders o ON pb.order_id = o.id
            WHERE o.vendor_id = ? AND o.status = 'COMPLETED' AND pb.balance_paid = 0
        ");
        $balanceStmt->execute([$user['id']]);
        $balance = $balanceStmt->fetch();
        
        $totalBalance = floatval($balance['total_balance'] ?? 0);
        
        if ($totalBalance <= 0) {
            $this->sendError('No balance available for payout', 400);
        }
        
        // Get vendor subaccount
        $subaccountStmt = $this->db->prepare("
            SELECT * FROM subaccounts 
            WHERE user_id = ? AND status = 'active'
            LIMIT 1
        ");
        $subaccountStmt->execute([$user['id']]);
        $subaccount = $subaccountStmt->fetch();
        
        if (!$subaccount) {
            $this->sendError('No active subaccount found. Please set up your bank account.', 400);
        }
        
        // Get country for gateway
        $countryStmt = $this->db->prepare("SELECT country_id FROM users WHERE id = ?");
        $countryStmt->execute([$user['id']]);
        $userData = $countryStmt->fetch();
        $countryId = $userData['country_id'] ?? 'NG';
        
        try {
            $gateway = PaymentGatewayFactory::getGatewayForCountry($countryId);
            
            // Create transfer
            $result = $gateway->createTransfer(
                $totalBalance,
                $subaccount['subaccount_code'],
                'Vendor balance payout'
            );
            
            // Mark balances as paid
            $updateStmt = $this->db->prepare("
                UPDATE payment_breakdowns pb
                INNER JOIN orders o ON pb.order_id = o.id
                SET pb.balance_paid = 1
                WHERE o.vendor_id = ? AND o.status = 'COMPLETED' AND pb.balance_paid = 0
            ");
            $updateStmt->execute([$user['id']]);
            
            // Create transfer record
            $transferId = $this->generateUUID();
            $transferStmt = $this->db->prepare("
                INSERT INTO transfers (id, vendor_id, amount, currency_code, transfer_reference, status, country_id)
                VALUES (?, ?, ?, ?, ?, 'SUCCESS', ?)
            ");
            $transferStmt->execute([
                $transferId,
                $user['id'],
                $totalBalance,
                $subaccount['currency_code'] ?? 'NGN',
                $result['reference'] ?? '',
                $countryId,
            ]);
            
            $this->sendResponse([
                'message' => 'Payout processed successfully',
                'amount' => $totalBalance,
                'transfer_reference' => $result['reference'] ?? '',
            ]);
        } catch (Exception $e) {
            $this->sendError('Payout failed: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Process balance payment (release escrow after completion)
     */
    private function processBalancePayment(string $orderId) {
        // Get payment breakdown
        $breakdownStmt = $this->db->prepare("
            SELECT pb.*, o.vendor_id, o.country_id, o.currency_code
            FROM payment_breakdowns pb
            INNER JOIN orders o ON pb.order_id = o.id
            WHERE pb.order_id = ? AND pb.balance_paid = 0
        ");
        $breakdownStmt->execute([$orderId]);
        $breakdown = $breakdownStmt->fetch();
        
        if (!$breakdown || $breakdown['vendor_balance_amount'] <= 0) {
            return; // No balance to pay
        }
        
        // Get vendor subaccount
        $subaccountStmt = $this->db->prepare("
            SELECT * FROM subaccounts 
            WHERE user_id = ? AND country_id = ? AND status = 'active'
            LIMIT 1
        ");
        $subaccountStmt->execute([$breakdown['vendor_id'], $breakdown['country_id']]);
        $subaccount = $subaccountStmt->fetch();
        
        if (!$subaccount) {
            return; // No subaccount, balance will be paid on next payout request
        }
        
        try {
            $gateway = PaymentGatewayFactory::getGatewayForCountry($breakdown['country_id']);
            
            $result = $gateway->createTransfer(
                $breakdown['vendor_balance_amount'],
                $subaccount['subaccount_code'],
                'Order completion balance payment'
            );
            
            // Mark as paid
            $updateStmt = $this->db->prepare("
                UPDATE payment_breakdowns 
                SET balance_paid = 1 
                WHERE order_id = ?
            ");
            $updateStmt->execute([$orderId]);
            
            // Create transfer record
            $transferId = $this->generateUUID();
            $transferStmt = $this->db->prepare("
                INSERT INTO transfers (id, order_id, vendor_id, amount, currency_code, transfer_reference, status, country_id)
                VALUES (?, ?, ?, ?, ?, ?, 'SUCCESS', ?)
            ");
            $transferStmt->execute([
                $transferId,
                $orderId,
                $breakdown['vendor_id'],
                $breakdown['vendor_balance_amount'],
                $breakdown['currency_code'],
                $result['reference'] ?? '',
                $breakdown['country_id'],
            ]);
        } catch (Exception $e) {
            // Log error but don't fail the order confirmation
            error_log("Balance payment failed for order {$orderId}: " . $e->getMessage());
        }
    }
}
