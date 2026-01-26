<?php
/**
 * Payment Controller
 */

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../payment/PaymentGatewayFactory.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../utils/CountryManager.php';
require_once __DIR__ . '/../services/EmailService.php';
require_once __DIR__ . '/../services/NotificationService.php';

class PaymentController extends BaseController {
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
    
    public function create() {
        $data = $this->getRequestBody();
        $action = $data['action'] ?? null;
        
        switch ($action) {
            case 'initialize':
                $this->initializePayment($data);
                break;
            case 'verify':
                $this->verifyPayment($data);
                break;
            default:
                $this->sendError('Invalid action. Use "initialize" or "verify"', 400);
        }
    }
    
    private function initializePayment($data) {
        $user = $this->auth->requireAuth();
        $orderId = $data['order_id'] ?? null;
        
        if (!$orderId) {
            $this->sendError('Order ID is required', 400);
        }
        
        // Get order
        $orderStmt = $this->db->prepare("
            SELECT o.*, c.email as customer_email, c.name as customer_name,
                   v.email as vendor_email, v.name as vendor_name
            FROM orders o
            INNER JOIN users c ON o.customer_id = c.id
            INNER JOIN users v ON o.vendor_id = v.id
            WHERE o.id = ?
        ");
        $orderStmt->execute([$orderId]);
        $order = $orderStmt->fetch();
        
        if (!$order) {
            $this->sendError('Order not found', 404);
        }
        
        if ($order['customer_id'] !== $user['id']) {
            $this->sendError('Unauthorized', 403);
        }
        
        // Get country and payment gateway
        $country = $this->countryManager->getCountry($order['country_id']);
        if (!$country) {
            $this->sendError('Country not found', 404);
        }
        
        $gateway = PaymentGatewayFactory::getGatewayForCountry($order['country_id']);
        
        // Generate payment reference
        $reference = 'ABEGEPPME_' . $order['order_number'] . '_' . time();
        
        // Get payment breakdown if exists
        $breakdownStmt = $this->db->prepare("SELECT * FROM payment_breakdowns WHERE order_id = ?");
        $breakdownStmt->execute([$orderId]);
        $breakdown = $breakdownStmt->fetch();
        
        // Prepare payment data
        $paymentData = [
            'order_id' => $orderId,
            'reference' => $reference,
            'amount' => floatval($order['total']),
            'currency' => $order['currency_code'],
            'customer_email' => $order['customer_email'],
            'customer_name' => $order['customer_name'],
            'customer' => [
                'name' => $order['customer_name'],
                'email' => $order['customer_email'],
            ],
            'vendor' => [
                'name' => $order['vendor_name'],
                'email' => $order['vendor_email'],
            ],
            'callback_url' => getenv('API_BASE_URL') . '/orders/' . $orderId . '/callback',
        ];
        
        // Add split configuration if split payment method
        if ($breakdown && $breakdown['payment_method_type'] === 'split') {
            // Get vendor subaccount
            $subaccountStmt = $this->db->prepare("
                SELECT subaccount_code FROM subaccounts 
                WHERE user_id = ? AND country_id = ? AND status = 'active'
            ");
            $subaccountStmt->execute([$order['vendor_id'], $order['country_id']]);
            $subaccount = $subaccountStmt->fetch();
            
            if ($subaccount && $breakdown['insurance_subaccount']) {
                $paymentData['split'] = [
                    'type' => 'flat',
                    'bearer_type' => 'account',
                    'subaccounts' => [
                        [
                            'subaccount' => $subaccount['subaccount_code'],
                            'share' => intval($breakdown['vendor_initial_amount']),
                        ],
                        [
                            'subaccount' => $breakdown['insurance_subaccount'],
                            'share' => intval($breakdown['insurance_amount']),
                        ],
                    ],
                ];
            }
        }
        
        // Initialize payment
        try {
            $result = $gateway->initializePayment($paymentData);
            
            // Save payment record
            $paymentId = $this->generateUUID();
            $insertStmt = $this->db->prepare("
                INSERT INTO payments (
                    id, order_id, country_id, payment_gateway, paystack_ref, 
                    paystack_auth_url, amount, currency_code, status,
                    customer_email, customer_name, vendor_email, vendor_name
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'INITIALIZED', ?, ?, ?, ?)
            ");
            $insertStmt->execute([
                $paymentId,
                $orderId,
                $order['country_id'],
                $country['payment_gateway'],
                $reference,
                $result['authorization_url'],
                $order['total'],
                $order['currency_code'],
                $order['customer_email'],
                $order['customer_name'],
                $order['vendor_email'],
                $order['vendor_name'],
            ]);
            
            $this->sendResponse([
                'authorization_url' => $result['authorization_url'],
                'reference' => $reference,
                'payment_id' => $paymentId,
            ]);
        } catch (Exception $e) {
            $this->sendError('Payment initialization failed: ' . $e->getMessage(), 500);
        }
    }
    
    private function verifyPayment($data) {
        $reference = $data['reference'] ?? null;
        $orderId = $data['order_id'] ?? null;
        
        if (!$reference || !$orderId) {
            $this->sendError('Reference and order ID are required', 400);
        }
        
        // Get payment
        $paymentStmt = $this->db->prepare("
            SELECT p.*, o.country_id, c.payment_gateway, c.payment_gateway_config
            FROM payments p
            INNER JOIN orders o ON p.order_id = o.id
            INNER JOIN countries c ON o.country_id = c.id
            WHERE p.paystack_ref = ? AND p.order_id = ?
        ");
        $paymentStmt->execute([$reference, $orderId]);
        $payment = $paymentStmt->fetch();
        
        if (!$payment) {
            $this->sendError('Payment not found', 404);
        }
        
        // Get gateway
        $config = json_decode($payment['payment_gateway_config'], true);
        $gateway = PaymentGatewayFactory::create($payment['payment_gateway'], $config);
        
        try {
            $verification = $gateway->verifyPayment($reference);
            
            if ($verification['status'] === 'success') {
                // Update payment
                $updateStmt = $this->db->prepare("
                    UPDATE payments 
                    SET status = 'PAID', paid_at = NOW() 
                    WHERE id = ?
                ");
                $updateStmt->execute([$payment['id']]);
                
                // Update order
                $orderUpdateStmt = $this->db->prepare("
                    UPDATE orders 
                    SET status = 'IN_SERVICE' 
                    WHERE id = ?
                ");
                $orderUpdateStmt->execute([$orderId]);
                
                // Send notifications
                $customerStmt = $this->db->prepare("SELECT * FROM users WHERE id = (SELECT customer_id FROM orders WHERE id = ?)");
                $customerStmt->execute([$orderId]);
                $customer = $customerStmt->fetch();
                
                if ($customer) {
                    $this->emailService->sendPaymentReceived($payment, $customer);
                    $this->notificationService->notifyPaymentReceived($payment, $customer);
                }
            }
            
            $this->sendResponse([
                'verified' => true,
                'status' => $verification['status'],
                'payment' => $payment,
            ]);
        } catch (Exception $e) {
            $this->sendError('Payment verification failed: ' . $e->getMessage(), 500);
        }
    }
    
    public function postWebhooks($provider) {
        $payload = file_get_contents('php://input');
        $signature = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? $_SERVER['HTTP_FLW_SIGNATURE'] ?? '';
        
        // Get country from payload or request
        $data = json_decode($payload, true);
        $countryId = $_GET['country'] ?? $data['data']['metadata']['country_id'] ?? 'NG';
        
        try {
            $country = $this->countryManager->getCountry($countryId);
            $config = json_decode($country['payment_gateway_config'], true);
            $gateway = PaymentGatewayFactory::create($country['payment_gateway'], $config);
            
            // Verify signature
            if (!$gateway->verifyWebhookSignature($payload, $signature)) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => 'Invalid signature']);
                exit();
            }
            
            // Handle webhook
            $webhookData = $gateway->handleWebhook($data);
            
            // Process webhook event
            $this->processWebhookEvent($webhookData, $country['payment_gateway']);
            
            http_response_code(200);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }
    
    private function processWebhookEvent($webhookData, $gateway) {
        $event = $webhookData['event'] ?? '';
        $reference = $webhookData['reference'] ?? '';
        
        // Find payment by reference
        $stmt = $this->db->prepare("SELECT * FROM payments WHERE paystack_ref = ?");
        $stmt->execute([$reference]);
        $payment = $stmt->fetch();
        
        if (!$payment) {
            return; // Payment not found
        }
        
        if ($event === 'charge.success') {
            // Update payment status
            $updateStmt = $this->db->prepare("
                UPDATE payments SET status = 'PAID', paid_at = NOW() WHERE id = ?
            ");
            $updateStmt->execute([$payment['id']]);
            
            // Update order status
            $orderStmt = $this->db->prepare("
                UPDATE orders SET status = 'IN_SERVICE' WHERE id = ?
            ");
            $orderStmt->execute([$payment['order_id']]);
            
            // Process immediate transfers if Individual payment method
            $breakdownStmt = $this->db->prepare("SELECT * FROM payment_breakdowns WHERE order_id = ?");
            $breakdownStmt->execute([$payment['order_id']]);
            $breakdown = $breakdownStmt->fetch();
            
            if ($breakdown && $breakdown['payment_method_type'] === 'individual' && !$breakdown['individual_transfers_processed']) {
                $this->processImmediateTransfers($payment, $breakdown, $gateway);
            }
        }
    }
    
    private function processImmediateTransfers($payment, $breakdown, $gatewayName) {
        // Get gateway
        $orderStmt = $this->db->prepare("SELECT country_id FROM orders WHERE id = ?");
        $orderStmt->execute([$payment['order_id']]);
        $order = $orderStmt->fetch();
        
        $country = $this->countryManager->getCountry($order['country_id']);
        $config = json_decode($country['payment_gateway_config'], true);
        $gateway = PaymentGatewayFactory::create($gatewayName, $config);
        
        // Get vendor subaccount
        $vendorStmt = $this->db->prepare("
            SELECT subaccount_code FROM subaccounts 
            WHERE user_id = (SELECT vendor_id FROM orders WHERE id = ?) 
            AND country_id = ?
        ");
        $vendorStmt->execute([$payment['order_id'], $order['country_id']]);
        $vendorSubaccount = $vendorStmt->fetch();
        
        if ($breakdown['individual_transfer_method'] === 'bulk') {
            // Bulk transfer
            $transfers = [];
            if ($vendorSubaccount && $breakdown['vendor_initial_amount'] > 0) {
                $transfers[] = [
                    'amount' => $breakdown['vendor_initial_amount'] / 100,
                    'recipient' => $vendorSubaccount['subaccount_code'],
                    'reference' => 'ABEGEPPME_VENDOR_' . $payment['order_id'] . '_' . time(),
                ];
            }
            if ($breakdown['insurance_amount'] > 0 && $breakdown['insurance_subaccount']) {
                $transfers[] = [
                    'amount' => $breakdown['insurance_amount'] / 100,
                    'recipient' => $breakdown['insurance_subaccount'],
                    'reference' => 'ABEGEPPME_INS_' . $payment['order_id'] . '_' . time(),
                ];
            }
            
            if (!empty($transfers)) {
                $result = $gateway->createBulkTransfer($transfers);
                // Update breakdown
                $updateStmt = $this->db->prepare("
                    UPDATE payment_breakdowns 
                    SET individual_transfers_processed = 1, 
                        individual_transfer_method = 'bulk',
                        individual_transfer_refs = ?
                    WHERE order_id = ?
                ");
                $updateStmt->execute([json_encode($result), $payment['order_id']]);
            }
        } else {
            // Single transfers
            $transferRefs = [];
            
            if ($vendorSubaccount && $breakdown['vendor_initial_amount'] > 0) {
                $result = $gateway->createTransfer(
                    $breakdown['vendor_initial_amount'] / 100,
                    $vendorSubaccount['subaccount_code'],
                    'Vendor initial payment'
                );
                $transferRefs['vendor'] = $result;
            }
            
            if ($breakdown['insurance_amount'] > 0 && $breakdown['insurance_subaccount']) {
                $result = $gateway->createTransfer(
                    $breakdown['insurance_amount'] / 100,
                    $breakdown['insurance_subaccount'],
                    'Insurance payment'
                );
                $transferRefs['insurance'] = $result;
            }
            
            if (!empty($transferRefs)) {
                $updateStmt = $this->db->prepare("
                    UPDATE payment_breakdowns 
                    SET individual_transfers_processed = 1, 
                        individual_transfer_method = 'single',
                        individual_transfer_refs = ?
                    WHERE order_id = ?
                ");
                $updateStmt->execute([json_encode($transferRefs), $payment['order_id']]);
            }
        }
    }
}
