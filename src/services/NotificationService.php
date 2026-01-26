<?php
/**
 * Notification Service
 * Creates and manages in-app notifications
 */

class NotificationService {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * Create notification
     */
    public function create(string $userId, string $type, string $title, string $message, array $data = []): string {
        $id = $this->generateUUID();
        
        $stmt = $this->db->prepare("
            INSERT INTO notifications (id, user_id, type, title, message, data)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $id,
            $userId,
            $type,
            $title,
            $message,
            json_encode($data)
        ]);
        
        return $id;
    }
    
    /**
     * Get user notifications
     */
    public function getUserNotifications(string $userId, bool $unreadOnly = false, int $limit = 50): array {
        $query = "
            SELECT * FROM notifications 
            WHERE user_id = ?
        ";
        
        if ($unreadOnly) {
            $query .= " AND read = 0";
        }
        
        $query .= " ORDER BY created_at DESC LIMIT ?";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute([$userId, $limit]);
        
        return $stmt->fetchAll();
    }
    
    /**
     * Mark notification as read
     */
    public function markAsRead(string $notificationId): bool {
        $stmt = $this->db->prepare("UPDATE notifications SET read = 1 WHERE id = ?");
        return $stmt->execute([$notificationId]);
    }
    
    /**
     * Mark all as read for user
     */
    public function markAllAsRead(string $userId): bool {
        $stmt = $this->db->prepare("UPDATE notifications SET read = 1 WHERE user_id = ? AND read = 0");
        return $stmt->execute([$userId]);
    }
    
    /**
     * Notify order created
     */
    public function notifyOrderCreated(array $order, array $vendor): string {
        return $this->create(
            $vendor['id'],
            'ORDER_CREATED',
            'New Order Received',
            "You have received a new order #{$order['order_number']}",
            ['order_id' => $order['id']]
        );
    }
    
    /**
     * Notify payment received
     */
    public function notifyPaymentReceived(array $payment, array $customer): string {
        return $this->create(
            $customer['id'],
            'PAYMENT_RECEIVED',
            'Payment Received',
            "Your payment of {$payment['amount']} has been received",
            ['payment_id' => $payment['id']]
        );
    }
    
    /**
     * Notify service complete
     */
    public function notifyServiceComplete(array $order, array $customer): string {
        return $this->create(
            $customer['id'],
            'SERVICE_COMPLETE',
            'Service Completed',
            "The service for order #{$order['order_number']} has been completed",
            ['order_id' => $order['id']]
        );
    }
    
    private function generateUUID(): string {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
