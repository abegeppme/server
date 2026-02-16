<?php
/**
 * PalmPay Payment Gateway Implementation
 * Note: endpoint paths can differ by PalmPay merchant account type.
 */

require_once __DIR__ . '/../PaymentGatewayInterface.php';

class PalmpayGateway implements PaymentGatewayInterface {
    private $merchantId;
    private $secretKey;
    private $webhookSecret;
    private $baseUrl;

    public function __construct(array $config) {
        $this->merchantId = $config['merchant_id'] ?? '';
        $this->secretKey = $config['secret_key'] ?? '';
        $this->webhookSecret = $config['webhook_secret'] ?? '';
        $this->baseUrl = $config['base_url'] ?? 'https://openapi.palmpay.com';

        if (empty($this->merchantId) || empty($this->secretKey)) {
            throw new Exception('PalmPay merchant_id and secret_key are required');
        }
    }

    public function initializePayment(array $orderData): array {
        $payload = [
            'merchantId' => $this->merchantId,
            'outTradeNo' => $orderData['reference'],
            'amount' => $orderData['amount'],
            'currency' => $orderData['currency'] ?? 'NGN',
            'notifyUrl' => $orderData['webhook_url'] ?? '',
            'returnUrl' => $orderData['callback_url'] ?? '',
            'subject' => 'AbegEppMe Service Payment',
            'body' => 'Order ' . ($orderData['order_id'] ?? ''),
            'customerEmail' => $orderData['customer_email'] ?? '',
            'metadata' => [
                'order_id' => $orderData['order_id'] ?? '',
                'customer_id' => $orderData['customer_id'] ?? '',
            ],
        ];

        $response = $this->makeRequest('POST', '/api/v1/payments/initialize', $payload);

        if (!($response['success'] ?? false)) {
            throw new Exception('PalmPay payment initialization failed');
        }

        $payUrl = $response['data']['paymentUrl'] ?? null;
        if (!$payUrl) {
            throw new Exception('PalmPay did not return an authorization URL');
        }

        return [
            'authorization_url' => $payUrl,
            'reference' => $orderData['reference'],
        ];
    }

    public function verifyPayment(string $reference): array {
        $response = $this->makeRequest('GET', '/api/v1/payments/verify?outTradeNo=' . urlencode($reference));
        if (!($response['success'] ?? false)) {
            throw new Exception('PalmPay payment verification failed');
        }

        $data = $response['data'] ?? [];
        return [
            'reference' => $data['outTradeNo'] ?? $reference,
            'status' => strtolower($data['status'] ?? 'unknown'),
            'amount' => floatval($data['amount'] ?? 0),
            'currency' => $data['currency'] ?? 'NGN',
        ];
    }

    public function createTransfer(float $amount, string $recipient, string $reason): array {
        $payload = [
            'merchantId' => $this->merchantId,
            'amount' => $amount,
            'currency' => 'NGN',
            'recipientCode' => $recipient,
            'reason' => $reason,
            'reference' => 'PALMPAY_TRF_' . time(),
        ];

        $response = $this->makeRequest('POST', '/api/v1/transfers/create', $payload);
        if (!($response['success'] ?? false)) {
            throw new Exception('PalmPay transfer failed');
        }

        $data = $response['data'] ?? [];
        return [
            'reference' => $data['reference'] ?? ($payload['reference']),
            'status' => $data['status'] ?? 'PENDING',
            'amount' => floatval($data['amount'] ?? $amount),
        ];
    }

    public function createBulkTransfer(array $transfers): array {
        $payload = [
            'merchantId' => $this->merchantId,
            'transfers' => $transfers,
        ];

        $response = $this->makeRequest('POST', '/api/v1/transfers/bulk', $payload);
        if (!($response['success'] ?? false)) {
            throw new Exception('PalmPay bulk transfer failed');
        }

        return $response['data'] ?? [];
    }

    public function createRecipient(array $bankData): array {
        $payload = [
            'merchantId' => $this->merchantId,
            'accountNumber' => $bankData['account_number'] ?? '',
            'bankCode' => $bankData['bank_code'] ?? '',
            'accountName' => $bankData['account_name'] ?? '',
        ];

        $response = $this->makeRequest('POST', '/api/v1/transfers/recipients', $payload);
        if (!($response['success'] ?? false)) {
            throw new Exception('PalmPay recipient creation failed');
        }

        $data = $response['data'] ?? [];
        return [
            'recipient_code' => $data['recipientCode'] ?? '',
            'account_number' => $data['accountNumber'] ?? ($bankData['account_number'] ?? ''),
            'account_name' => $data['accountName'] ?? ($bankData['account_name'] ?? ''),
        ];
    }

    public function handleWebhook(array $payload): array {
        return [
            'event' => $payload['event'] ?? '',
            'reference' => $payload['outTradeNo'] ?? ($payload['reference'] ?? ''),
            'status' => strtolower($payload['status'] ?? ''),
            'amount' => floatval($payload['amount'] ?? 0),
            'currency' => $payload['currency'] ?? 'NGN',
            'metadata' => $payload['metadata'] ?? [],
        ];
    }

    public function verifyWebhookSignature(string $payload, string $signature): bool {
        if (empty($this->webhookSecret) || empty($signature)) {
            return false;
        }
        $computed = hash_hmac('sha256', $payload, $this->webhookSecret);
        return hash_equals($computed, $signature);
    }

    private function makeRequest(string $method, string $endpoint, array $data = []): array {
        $url = rtrim($this->baseUrl, '/') . $endpoint;
        $ch = curl_init($url);

        $headers = [
            'Content-Type: application/json',
            'X-Merchant-Id: ' . $this->merchantId,
            'X-Api-Key: ' . $this->secretKey,
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => 30,
        ]);

        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode < 200 || $httpCode >= 300) {
            throw new Exception("PalmPay API error: HTTP $httpCode");
        }

        return json_decode($response, true) ?: [];
    }
}
