<?php
/**
 * Paystack Payment Gateway Implementation
 */

require_once __DIR__ . '/../PaymentGatewayInterface.php';

class PaystackGateway implements PaymentGatewayInterface {
    private $secretKey;
    private $publicKey;
    private $webhookSecret;
    private $baseUrl;
    
    public function __construct(array $config) {
        $this->secretKey = $config['secret_key'] ?? '';
        $this->publicKey = $config['public_key'] ?? '';
        $this->webhookSecret = $config['webhook_secret'] ?? $this->secretKey;
        $this->baseUrl = $config['base_url'] ?? 'https://api.paystack.co';
        
        if (empty($this->secretKey)) {
            throw new Exception('Paystack secret key is required');
        }
    }
    
    public function initializePayment(array $orderData): array {
        $payload = [
            'email' => $orderData['customer_email'],
            'amount' => intval($orderData['amount'] * 100), // Convert to kobo
            'reference' => $orderData['reference'],
            'currency' => $orderData['currency'] ?? 'NGN',
            'callback_url' => $orderData['callback_url'] ?? '',
            'metadata' => [
                'order_id' => $orderData['order_id'],
                'customer' => $orderData['customer'] ?? [],
                'vendor' => $orderData['vendor'] ?? [],
            ],
        ];
        
        // Add split configuration if provided
        if (!empty($orderData['split'])) {
            $payload['split'] = $orderData['split'];
        }
        
        $response = $this->makeRequest('POST', '/transaction/initialize', $payload);
        
        if (!$response['status']) {
            throw new Exception('Payment initialization failed: ' . ($response['message'] ?? 'Unknown error'));
        }
        
        return [
            'authorization_url' => $response['data']['authorization_url'],
            'reference' => $response['data']['reference'],
            'access_code' => $response['data']['access_code'],
        ];
    }
    
    public function verifyPayment(string $reference): array {
        $response = $this->makeRequest('GET', "/transaction/verify/{$reference}");
        
        if (!$response['status']) {
            throw new Exception('Payment verification failed: ' . ($response['message'] ?? 'Unknown error'));
        }
        
        return $response['data'];
    }
    
    public function createTransfer(float $amount, string $recipient, string $reason): array {
        $payload = [
            'source' => 'balance',
            'amount' => intval($amount * 100), // Convert to kobo
            'recipient' => $recipient,
            'reason' => $reason,
        ];
        
        $response = $this->makeRequest('POST', '/transfer', $payload);
        
        if (!$response['status']) {
            throw new Exception('Transfer failed: ' . ($response['message'] ?? 'Unknown error'));
        }
        
        return [
            'reference' => $response['data']['transfer_code'],
            'status' => $response['data']['status'],
            'amount' => $response['data']['amount'] / 100, // Convert from kobo
        ];
    }
    
    public function createBulkTransfer(array $transfers): array {
        $transferData = [];
        foreach ($transfers as $transfer) {
            $transferData[] = [
                'amount' => intval($transfer['amount'] * 100),
                'recipient' => $transfer['recipient'],
                'reference' => $transfer['reference'] ?? generateUUID(),
            ];
        }
        
        $payload = [
            'source' => 'balance',
            'transfers' => $transferData,
        ];
        
        $response = $this->makeRequest('POST', '/transfer/bulk', $payload);
        
        if (!$response['status']) {
            throw new Exception('Bulk transfer failed: ' . ($response['message'] ?? 'Unknown error'));
        }
        
        return $response['data'];
    }
    
    public function createRecipient(array $bankData): array {
        $payload = [
            'type' => 'nuban',
            'name' => $bankData['account_name'],
            'account_number' => $bankData['account_number'],
            'bank_code' => $bankData['bank_code'],
            'currency' => $bankData['currency'] ?? 'NGN',
        ];
        
        $response = $this->makeRequest('POST', '/transferrecipient', $payload);
        
        if (!$response['status']) {
            throw new Exception('Recipient creation failed: ' . ($response['message'] ?? 'Unknown error'));
        }
        
        return [
            'recipient_code' => $response['data']['recipient_code'],
            'account_number' => $response['data']['details']['account_number'],
            'account_name' => $response['data']['details']['account_name'],
        ];
    }
    
    public function handleWebhook(array $payload): array {
        $event = $payload['event'] ?? '';
        $data = $payload['data'] ?? [];
        
        return [
            'event' => $event,
            'reference' => $data['reference'] ?? '',
            'status' => $data['status'] ?? '',
            'amount' => isset($data['amount']) ? $data['amount'] / 100 : 0,
            'currency' => $data['currency'] ?? 'NGN',
            'metadata' => $data['metadata'] ?? [],
        ];
    }
    
    public function verifyWebhookSignature(string $payload, string $signature): bool {
        if (empty($this->webhookSecret) || empty($signature)) {
            return false;
        }
        $computedSignature = hash_hmac('sha512', $payload, $this->webhookSecret);
        return hash_equals($computedSignature, $signature);
    }
    
    /**
     * Make HTTP request to Paystack API
     */
    private function makeRequest(string $method, string $endpoint, array $data = []): array {
        $url = $this->baseUrl . $endpoint;
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->secretKey,
                'Content-Type: application/json',
            ],
        ]);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception("Paystack API error: HTTP $httpCode");
        }
        
        return json_decode($response, true) ?: [];
    }
}

function generateUUID() {
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}
