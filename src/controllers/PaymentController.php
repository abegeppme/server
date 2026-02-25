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

    public function handleRequest($method, $id = null, $sub_resource = null) {
        if ($method === 'POST' && $id === 'initialize') {
            $data = $this->getRequestBody();
            $this->initializePayment($data);
            return;
        }

        if ($method === 'GET' && $id === 'verify' && !empty($sub_resource)) {
            $this->verifyPaymentByReference($sub_resource);
            return;
        }

        if ($method === 'GET' && $id === 'banks') {
            $this->getBanks();
            return;
        }

        if ($method === 'POST' && $id === 'resolve-account') {
            $this->resolveBankAccount();
            return;
        }

        if ($id === 'bank-details' && $method === 'GET') {
            $this->getUserBankDetails();
            return;
        }

        if ($id === 'bank-details' && $method === 'POST') {
            $this->saveUserBankDetails();
            return;
        }

        if ($method === 'POST' && $id === 'webhooks' && !empty($sub_resource)) {
            $this->postWebhooks($sub_resource);
            return;
        }

        parent::handleRequest($method, $id, $sub_resource);
    }
    
    public function create() {
        $data = $this->getRequestBody();
        $action = $data['action'] ?? null;

        // Support path-based style: POST /api/payments/initialize
        if ($action === null) {
            $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
            if (substr($requestPath, -11) === '/initialize') {
                $action = 'initialize';
            }
        }
        
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

    private function verifyPaymentByReference($reference) {
        if (empty($reference)) {
            $this->sendError('Reference is required', 400);
        }

        $paymentStmt = $this->db->prepare("
            SELECT p.*, o.id as order_id, o.country_id, c.payment_gateway, c.payment_gateway_config
            FROM payments p
            INNER JOIN orders o ON p.order_id = o.id
            INNER JOIN countries c ON o.country_id = c.id
            WHERE p.paystack_ref = ?
            LIMIT 1
        ");
        $paymentStmt->execute([$reference]);
        $payment = $paymentStmt->fetch();

        if (!$payment) {
            $this->sendError('Payment not found', 404);
        }

        $this->verifyPayment([
            'reference' => $reference,
            'order_id' => $payment['order_id'],
        ]);
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

    private function getBanks() {
        $user = $this->auth->requireAuth();
        $countryId = $_GET['country_id'] ?? $user['country_id'] ?? 'NG';
        $country = $this->countryManager->getCountry($countryId);
        if (!$country) {
            $this->sendError('Country not found', 404);
        }

        $gatewayName = strtolower($country['payment_gateway'] ?? '');
        $config = json_decode($country['payment_gateway_config'] ?? '{}', true) ?: [];

        if ($gatewayName === 'paystack') {
            $response = $this->gatewayRequest($gatewayName, $config, 'GET', '/bank?country=' . strtolower($countryId));
            $banks = array_map(function ($item) {
                return [
                    'code' => (string)($item['code'] ?? ''),
                    'name' => (string)($item['name'] ?? ''),
                ];
            }, $response['data'] ?? []);

            $this->sendResponse([
                'country_id' => $countryId,
                'gateway' => 'paystack',
                'banks' => array_values(array_filter($banks, function ($bank) {
                    return $bank['code'] !== '' && $bank['name'] !== '';
                })),
            ]);
        }

        if ($gatewayName === 'flutterwave') {
            $response = $this->gatewayRequest($gatewayName, $config, 'GET', '/banks/' . strtoupper($countryId));
            $banks = array_map(function ($item) {
                return [
                    'code' => (string)($item['code'] ?? ''),
                    'name' => (string)($item['name'] ?? ''),
                ];
            }, $response['data'] ?? []);

            $this->sendResponse([
                'country_id' => $countryId,
                'gateway' => 'flutterwave',
                'banks' => array_values(array_filter($banks, function ($bank) {
                    return $bank['code'] !== '' && $bank['name'] !== '';
                })),
            ]);
        }

        $this->sendResponse([
            'country_id' => $countryId,
            'gateway' => $gatewayName,
            'banks' => [],
        ]);
    }

    private function resolveBankAccount() {
        $user = $this->auth->requireAuth();
        $data = $this->getRequestBody();
        $countryId = $data['country_id'] ?? $user['country_id'] ?? 'NG';
        $bankCode = trim($data['bank_code'] ?? '');
        $accountNumber = preg_replace('/\D+/', '', $data['account_number'] ?? '');

        if ($bankCode === '' || $accountNumber === '') {
            $this->sendError('bank_code and account_number are required', 400);
        }

        if (strtoupper($countryId) === 'NG' && strlen($accountNumber) !== 10) {
            $this->sendError('Nigerian account number must be exactly 10 digits', 400);
        }

        $country = $this->countryManager->getCountry($countryId);
        if (!$country) {
            $this->sendError('Country not found', 404);
        }

        $gatewayName = strtolower($country['payment_gateway'] ?? '');
        $config = json_decode($country['payment_gateway_config'] ?? '{}', true) ?: [];

        if ($gatewayName === 'paystack') {
            $query = '/bank/resolve?account_number=' . urlencode($accountNumber) . '&bank_code=' . urlencode($bankCode);
            $response = $this->gatewayRequest($gatewayName, $config, 'GET', $query);
            $data = $response['data'] ?? [];
            $this->sendResponse([
                'account_name' => $data['account_name'] ?? '',
                'account_number' => $data['account_number'] ?? $accountNumber,
                'bank_code' => $bankCode,
                'bank_name' => $data['bank_name'] ?? null,
            ]);
        }

        if ($gatewayName === 'flutterwave') {
            $response = $this->gatewayRequest($gatewayName, $config, 'POST', '/accounts/resolve', [
                'account_number' => $accountNumber,
                'account_bank' => $bankCode,
            ]);
            $data = $response['data'] ?? [];
            $this->sendResponse([
                'account_name' => $data['account_name'] ?? '',
                'account_number' => $data['account_number'] ?? $accountNumber,
                'bank_code' => $bankCode,
                'bank_name' => $data['bank_name'] ?? null,
            ]);
        }

        $this->sendError('Bank account resolution not configured for this country/gateway', 400);
    }

    private function getUserBankDetails() {
        $user = $this->auth->requireAuth();
        $countryId = $_GET['country_id'] ?? $user['country_id'] ?? 'NG';
        $hasVerifiedAt = $this->checkColumnExists('subaccounts', 'account_verified_at');

        $hasCountryId = $this->checkColumnExists('subaccounts', 'country_id');
        $query = $hasCountryId
            ? "SELECT * FROM subaccounts WHERE user_id = ? AND country_id = ? LIMIT 1"
            : "SELECT * FROM subaccounts WHERE user_id = ? LIMIT 1";

        $stmt = $this->db->prepare($query);
        $params = $hasCountryId ? [$user['id'], $countryId] : [$user['id']];
        $stmt->execute($params);
        $record = $stmt->fetch();

        if ($record) {
            $accountNumber = (string)($record['account_number'] ?? '');
            $record['masked_account_number'] = $accountNumber !== '' ? ('****' . substr($accountNumber, -4)) : null;
            $record['is_verified'] = !empty($record['subaccount_code']) && !empty($record['account_name']) && (($record['status'] ?? 'active') !== 'inactive');
            $record['verified_at'] = $hasVerifiedAt ? ($record['account_verified_at'] ?? $record['updated_at']) : ($record['updated_at'] ?? null);
        }

        $this->sendResponse([
            'country_id' => $countryId,
            'has_details' => !!$record,
            'details' => $record ?: null,
        ]);
    }

    private function saveUserBankDetails() {
        $user = $this->auth->requireAuth();
        $data = $this->getRequestBody();
        $countryId = strtoupper($data['country_id'] ?? $user['country_id'] ?? 'NG');
        $bankCode = trim($data['bank_code'] ?? '');
        $accountNumber = preg_replace('/\D+/', '', $data['account_number'] ?? '');

        if ($bankCode === '' || $accountNumber === '') {
            $this->sendError('bank_code and account_number are required', 400);
        }

        $country = $this->countryManager->getCountry($countryId);
        if (!$country) {
            $this->sendError('Country not found', 404);
        }

        $gatewayName = strtolower($country['payment_gateway'] ?? '');
        $config = json_decode($country['payment_gateway_config'] ?? '{}', true) ?: [];

        // Resolve account name first for safety.
        $resolved = $this->resolveAccountNameInternal($gatewayName, $config, $bankCode, $accountNumber, $countryId);
        $accountName = $resolved['account_name'] ?? '';
        $bankName = $resolved['bank_name'] ?? null;
        if ($accountName === '') {
            $this->sendError('Unable to resolve account name for this bank/account combination', 400);
        }

        $subaccountCode = null;
        $transferRecipient = null;

        if ($gatewayName === 'paystack') {
            $subRes = $this->gatewayRequest('paystack', $config, 'POST', '/subaccount', [
                'business_name' => $user['name'] ?: $user['email'],
                'settlement_bank' => $bankCode,
                'account_number' => $accountNumber,
                'percentage_charge' => 0,
                'description' => 'Vendor payout account',
                'primary_contact_email' => $user['email'],
                'primary_contact_name' => $user['name'] ?: $user['email'],
            ]);
            $subData = $subRes['data'] ?? [];
            $subaccountCode = $subData['subaccount_code'] ?? null;

            try {
                $recipientRes = $this->gatewayRequest('paystack', $config, 'POST', '/transferrecipient', [
                    'type' => 'nuban',
                    'name' => $accountName,
                    'account_number' => $accountNumber,
                    'bank_code' => $bankCode,
                    'currency' => $country['currency_code'] ?? 'NGN',
                ]);
                $transferRecipient = $recipientRes['data']['recipient_code'] ?? null;
            } catch (Exception $e) {
                // Recipient is optional for now; do not block saving if subaccount exists.
                $transferRecipient = null;
            }

            if (!$subaccountCode) {
                $this->sendError('Failed to create Paystack subaccount', 500);
            }
        } elseif ($gatewayName === 'flutterwave') {
            $recipient = PaymentGatewayFactory::create($gatewayName, $config)->createRecipient([
                'account_number' => $accountNumber,
                'bank_code' => $bankCode,
                'currency' => $country['currency_code'] ?? 'NGN',
            ]);
            $transferRecipient = $recipient['recipient_code'] ?? null;
            $subaccountCode = $transferRecipient ?: ('flw_' . $bankCode . '_' . $accountNumber);
        } else {
            $subaccountCode = 'manual_' . $bankCode . '_' . $accountNumber;
        }

        $hasCountryId = $this->checkColumnExists('subaccounts', 'country_id');
        $hasBankName = $this->checkColumnExists('subaccounts', 'bank_name');
        $existingQuery = $hasCountryId
            ? "SELECT id FROM subaccounts WHERE user_id = ? AND country_id = ? LIMIT 1"
            : "SELECT id FROM subaccounts WHERE user_id = ? LIMIT 1";
        $existingStmt = $this->db->prepare($existingQuery);
        $existingParams = $hasCountryId ? [$user['id'], $countryId] : [$user['id']];
        $existingStmt->execute($existingParams);
        $existing = $existingStmt->fetch();

        if ($existing) {
            $updateColumns = [
                'subaccount_code = ?',
                'account_number = ?',
                'account_name = ?',
                'bank_code = ?',
                'transfer_recipient = ?',
                'status = ?',
                'updated_at = NOW()',
            ];
            $updateParams = [
                $subaccountCode,
                $accountNumber,
                $accountName,
                $bankCode,
                $transferRecipient,
                'active',
            ];
            if ($hasBankName) {
                $updateColumns[] = 'bank_name = ?';
                $updateParams[] = $bankName;
            }
            if ($this->checkColumnExists('subaccounts', 'account_verified_at')) {
                $updateColumns[] = 'account_verified_at = NOW()';
            }
            if ($hasCountryId) {
                $updateColumns[] = 'country_id = ?';
                $updateParams[] = $countryId;
            }
            $updateParams[] = $existing['id'];
            $stmt = $this->db->prepare("UPDATE subaccounts SET " . implode(', ', $updateColumns) . " WHERE id = ?");
            $stmt->execute($updateParams);
            $recordId = $existing['id'];
        } else {
            $columns = ['id', 'user_id', 'subaccount_code', 'account_number', 'account_name', 'bank_code', 'transfer_recipient', 'status'];
            $placeholders = ['?', '?', '?', '?', '?', '?', '?', '?'];
            $insertParams = [
                $this->generateUUID(),
                $user['id'],
                $subaccountCode,
                $accountNumber,
                $accountName,
                $bankCode,
                $transferRecipient,
                'active',
            ];
            if ($hasCountryId) {
                $columns[] = 'country_id';
                $placeholders[] = '?';
                $insertParams[] = $countryId;
            }
            if ($hasBankName) {
                $columns[] = 'bank_name';
                $placeholders[] = '?';
                $insertParams[] = $bankName;
            }
            if ($this->checkColumnExists('subaccounts', 'account_verified_at')) {
                $columns[] = 'account_verified_at';
                $placeholders[] = 'NOW()';
            }
            $stmt = $this->db->prepare("INSERT INTO subaccounts (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")");
            $stmt->execute($insertParams);
            $recordId = $insertParams[0];
        }

        $this->sendResponse([
            'message' => 'Bank details saved successfully',
            'record_id' => $recordId,
            'subaccount_code' => $subaccountCode,
            'account_name' => $accountName,
            'account_number' => $accountNumber,
            'masked_account_number' => '****' . substr($accountNumber, -4),
            'bank_code' => $bankCode,
            'bank_name' => $bankName,
            'country_id' => $countryId,
            'gateway' => $gatewayName,
            'is_verified' => true,
            'verified_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function resolveAccountNameInternal(string $gatewayName, array $config, string $bankCode, string $accountNumber, string $countryId): array {
        if ($gatewayName === 'paystack') {
            $query = '/bank/resolve?account_number=' . urlencode($accountNumber) . '&bank_code=' . urlencode($bankCode);
            $response = $this->gatewayRequest($gatewayName, $config, 'GET', $query);
            return [
                'account_name' => $response['data']['account_name'] ?? '',
                'account_number' => $response['data']['account_number'] ?? $accountNumber,
                'bank_code' => $bankCode,
                'bank_name' => $response['data']['bank_name'] ?? null,
            ];
        }

        if ($gatewayName === 'flutterwave') {
            $response = $this->gatewayRequest($gatewayName, $config, 'POST', '/accounts/resolve', [
                'account_number' => $accountNumber,
                'account_bank' => $bankCode,
            ]);
            return [
                'account_name' => $response['data']['account_name'] ?? '',
                'account_number' => $response['data']['account_number'] ?? $accountNumber,
                'bank_code' => $bankCode,
                'bank_name' => $response['data']['bank_name'] ?? null,
            ];
        }

        return [
            'account_name' => '',
            'account_number' => $accountNumber,
            'bank_code' => $bankCode,
            'bank_name' => null,
        ];
    }

    private function gatewayRequest(string $gatewayName, array $config, string $method, string $endpoint, array $payload = []): array {
        $gatewayName = strtolower($gatewayName);
        if ($gatewayName === 'paystack') {
            $baseUrl = rtrim($config['base_url'] ?? 'https://api.paystack.co', '/');
            $secret = $config['secret_key'] ?? '';
            if ($secret === '') {
                throw new Exception('Paystack secret key is missing');
            }
        } elseif ($gatewayName === 'flutterwave') {
            $baseUrl = rtrim($config['base_url'] ?? 'https://api.flutterwave.com/v3', '/');
            $secret = $config['secret_key'] ?? '';
            if ($secret === '') {
                throw new Exception('Flutterwave secret key is missing');
            }
        } else {
            throw new Exception('Unsupported gateway for this operation');
        }

        $url = $baseUrl . $endpoint;
        $ch = curl_init($url);
        $headers = [
            'Authorization: Bearer ' . $secret,
            'Content-Type: application/json',
        ];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
        ];
        if (strtoupper($method) === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($payload);
        }
        curl_setopt_array($ch, $opts);
        $responseBody = curl_exec($ch);
        if ($responseBody === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new Exception('Gateway request failed: ' . $error);
        }
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode($responseBody, true) ?: [];
        if ($httpCode < 200 || $httpCode >= 300) {
            $message = $decoded['message'] ?? ('Gateway HTTP ' . $httpCode);
            throw new Exception($message);
        }
        return $decoded;
    }
}
