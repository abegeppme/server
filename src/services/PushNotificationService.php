<?php
/**
 * Push Notification Service
 * Handles Firebase Cloud Messaging (FCM) push notifications
 */

class PushNotificationService {
    private $fcmServerKey;
    private $fcmUrl = 'https://fcm.googleapis.com/fcm/send';
    
    public function __construct() {
        $this->fcmServerKey = getenv('FCM_SERVER_KEY') ?: '';
    }
    
    /**
     * Send push notification to device
     */
    public function sendToDevice(string $deviceToken, string $title, string $body, array $data = []): bool {
        $payload = [
            'to' => $deviceToken,
            'notification' => [
                'title' => $title,
                'body' => $body,
                'sound' => 'default',
            ],
            'data' => $data,
        ];
        
        return $this->sendRequest($payload);
    }
    
    /**
     * Send push notification to multiple devices
     */
    public function sendToDevices(array $deviceTokens, string $title, string $body, array $data = []): array {
        $results = [];
        foreach ($deviceTokens as $token) {
            $results[$token] = $this->sendToDevice($token, $title, $body, $data);
        }
        return $results;
    }
    
    /**
     * Send to user (all their devices)
     */
    public function sendToUser(string $userId, string $title, string $body, array $data = []): bool {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT device_token FROM push_notification_subscriptions 
            WHERE user_id = ? AND active = 1
        ");
        $stmt->execute([$userId]);
        $tokens = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($tokens)) {
            return false;
        }
        
        $success = 0;
        foreach ($tokens as $token) {
            if ($this->sendToDevice($token, $title, $body, $data)) {
                $success++;
            }
        }
        
        return $success > 0;
    }
    
    /**
     * Send order notification
     */
    public function sendOrderNotification(string $userId, array $order): bool {
        return $this->sendToUser($userId, 
            "New Order #{$order['order_number']}",
            "You have a new order",
            ['type' => 'order', 'order_id' => $order['id']]
        );
    }
    
    /**
     * Send payment notification
     */
    public function sendPaymentNotification(string $userId, array $payment): bool {
        return $this->sendToUser($userId,
            "Payment Received",
            "Payment of {$payment['amount']} received",
            ['type' => 'payment', 'payment_id' => $payment['id']]
        );
    }
    
    /**
     * Send chat message notification
     */
    public function sendChatNotification(string $userId, string $senderName, string $message): bool {
        return $this->sendToUser($userId,
            "New message from {$senderName}",
            $message,
            ['type' => 'chat']
        );
    }
    
    /**
     * Make FCM request
     */
    private function sendRequest(array $payload): bool {
        if (empty($this->fcmServerKey)) {
            return false; // FCM not configured
        }
        
        $ch = curl_init($this->fcmUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: key=' . $this->fcmServerKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $result = json_decode($response, true);
            return isset($result['success']) && $result['success'] > 0;
        }
        
        return false;
    }
}
