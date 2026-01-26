<?php
/**
 * Flutterwave Payment Gateway Implementation
 */

require_once __DIR__ . '/../PaymentGatewayInterface.php';

class FlutterwaveGateway implements PaymentGatewayInterface {
    private $secretKey;
    private $publicKey;
    private $webhookHash;
    private $baseUrl;
    
    public function __construct(array $config) {
        $this->secretKey = $config['secret_key'] ?? '';
        $this->publicKey = $config['public_key'] ?? '';
        $this->webhookHash = $config['webhook_hash'] ?? '';
        $this->baseUrl = $config['base_url'] ?? 'https://api.flutterwave.com/v3';
        
        if (empty($this->secretKey)) {
            throw new Exception('Flutterwave secret key is required');
        }
    }
    
    public function initializePayment(array $orderData): array {
        $payload = [
            'tx_ref' => $orderData['reference'],
            'amount' => $orderData['amount'],
            'currency' => $orderData['currency'] ?? 'KES',
            'redirect_url' => $orderData['callback_url'] ?? '',
            'customer' => [
                'email' => $orderData['customer_email'],
                'name' => $orderData['customer_name'] ?? '',
            ],
            'customizations' => [
                'title' => 'AbegEppMe Payment',
                'description' => 'Service Payment',
            ],
            'meta' => [
                'order_id' => $orderData['order_id'],
            ],
        ];
        
        $response = $this->makeRequest('POST', '/payments', $payload);
        
        if ($response['status'] !== 'success') {
            throw new Exception('Payment initialization failed: ' . ($response['message'] ?? 'Unknown error'));
        }
        
        return [
            'authorization_url' => $response['data']['link'],
            'reference' => $orderData['reference'],
        ];
    }
    
    public function verifyPayment(string $reference): array {
        $response = $this->makeRequest('GET', "/transactions/{$reference}/verify");
        
        if ($response['status'] !== 'success') {
            throw new Exception('Payment verification failed: ' . ($response['message'] ?? 'Unknown error'));
        }
        
        $data = $response['data'];
        return [
            'reference' => $data['tx_ref'] ?? '',
            'status' => strtolower($data['status'] ?? ''),
            'amount' => $data['amount'] ?? 0,
            'currency' => $data['currency'] ?? 'KES',
        ];
    }
    
    public function createTransfer(float $amount, string $recipient, string $reason): array {
        $payload = [
            'account_bank' => $recipient['bank_code'] ?? '',
            'account_number' => $recipient['account_number'] ?? '',
            'amount' => $amount,
            'narration' => $reason,
            'currency' => 'NGN',
            'reference' => 'ABEGEPPME_' . time(),
        ];
        
        $response = $this->makeRequest('POST', '/transfers', $payload);
        
        if ($response['status'] !== 'success') {
            throw new Exception('Transfer failed: ' . ($response['message'] ?? 'Unknown error'));
        }
        
        return [
            'reference' => $response['data']['reference'] ?? '',
            'status' => $response['data']['status'] ?? '',
            'amount' => $response['data']['amount'] ?? 0,
        ];
    }
    
    public function createBulkTransfer(array $transfers): array {
        $bulkData = [];
        foreach ($transfers as $transfer) {
            $bulkData[] = [
                'account_bank' => $transfer['bank_code'] ?? '',
                'account_number' => $transfer['account_number'] ?? '',
                'amount' => $transfer['amount'],
                'narration' => $transfer['reason'] ?? '',
                'currency' => 'NGN',
                'reference' => $transfer['reference'] ?? 'ABEGEPPME_' . time(),
            ];
        }
        
        $payload = [
            'title' => 'Bulk Transfer',
            'bulk_data' => $bulkData,
        ];
        
        $response = $this->makeRequest('POST', '/bulk-transfers', $payload);
        
        if ($response['status'] !== 'success') {
            throw new Exception('Bulk transfer failed: ' . ($response['message'] ?? 'Unknown error'));
        }
        
        return $response['data'];
    }
    
    public function createRecipient(array $bankData): array {
        $payload = [
            'account_number' => $bankData['account_number'],
            'account_bank' => $bankData['bank_code'],
            'currency' => $bankData['currency'] ?? 'NGN',
        ];
        
        $response = $this->makeRequest('POST', '/accounts/resolve', $payload);
        
        if ($response['status'] !== 'success') {
            throw new Exception('Recipient creation failed: ' . ($response['message'] ?? 'Unknown error'));
        }
        
        return [
            'account_number' => $response['data']['account_number'],
            'account_name' => $response['data']['account_name'],
        ];
    }
    
    public function handleWebhook(array $payload): array {
        $event = $payload['event'] ?? '';
        $data = $payload['data'] ?? [];
        
        return [
            'event' => $event,
            'reference' => $data['tx_ref'] ?? '',
            'status' => strtolower($data['status'] ?? ''),
            'amount' => $data['amount'] ?? 0,
            'currency' => $data['currency'] ?? 'KES',
        ];
    }
    
    public function verifyWebhookSignature(string $payload, string $signature): bool {
        $computedHash = hash_hmac('sha256', $payload, $this->webhookHash);
        return hash_equals($computedHash, $signature);
    }
    
    /**
     * Make HTTP request to Flutterwave API
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
            throw new Exception("Flutterwave API error: HTTP $httpCode");
        }
        
        return json_decode($response, true) ?: [];
    }
}
