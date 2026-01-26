<?php
/**
 * Payment Gateway Interface
 * All payment gateways must implement this interface
 */

interface PaymentGatewayInterface {
    /**
     * Initialize payment transaction
     * @param array $orderData Order data including amount, customer, etc.
     * @return array ['authorization_url' => string, 'reference' => string]
     */
    public function initializePayment(array $orderData): array;
    
    /**
     * Verify payment transaction
     * @param string $reference Transaction reference
     * @return array Transaction data
     */
    public function verifyPayment(string $reference): array;
    
    /**
     * Create transfer to recipient
     * @param float $amount Amount to transfer
     * @param string $recipient Recipient code
     * @param string $reason Transfer reason
     * @return array Transfer data with reference
     */
    public function createTransfer(float $amount, string $recipient, string $reason): array;
    
    /**
     * Create bulk transfer (multiple recipients)
     * @param array $transfers Array of ['amount' => float, 'recipient' => string, 'reason' => string]
     * @return array Bulk transfer data
     */
    public function createBulkTransfer(array $transfers): array;
    
    /**
     * Create transfer recipient
     * @param array $bankData Bank account details
     * @return array Recipient data with code
     */
    public function createRecipient(array $bankData): array;
    
    /**
     * Handle webhook payload
     * @param array $payload Webhook payload
     * @return array Processed webhook data
     */
    public function handleWebhook(array $payload): array;
    
    /**
     * Verify webhook signature
     * @param string $payload Raw webhook payload
     * @param string $signature Signature from headers
     * @return bool
     */
    public function verifyWebhookSignature(string $payload, string $signature): bool;
}
