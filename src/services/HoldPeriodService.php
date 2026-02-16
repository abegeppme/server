<?php
/**
 * Hold Period Service
 * Handles 48-hour hold period and 7-day auto-release
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../payment/PaymentGatewayFactory.php';
require_once __DIR__ . '/NotificationService.php';
require_once __DIR__ . '/EmailService.php';

class HoldPeriodService {
    private $db;
    private $notificationService;
    private $emailService;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->notificationService = new NotificationService();
        $this->emailService = new EmailService();
    }
    
    /**
     * Process orders that have completed 48-hour hold period
     * Should be called by cron job every hour
     */
    public function process48HourHoldReleases() {
        // Find orders where payout_release_date has passed and hold_period_completed is false
        $query = "
            SELECT o.*, pb.vendor_balance_amount, pb.currency_code
            FROM orders o
            INNER JOIN payment_breakdowns pb ON o.id = pb.order_id
            WHERE o.payout_release_date IS NOT NULL
            AND o.payout_release_date <= NOW()
            AND o.hold_period_completed = 0
            AND o.status = 'AWAITING_PAYOUT'
            AND o.customer_confirmed = 1
            AND pb.balance_paid = 0
        ";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        $orders = $stmt->fetchAll();
        
        $processed = 0;
        $failed = 0;
        
        foreach ($orders as $order) {
            // Check if dispute exists
            $disputeStmt = $this->db->prepare("
                SELECT id FROM disputes 
                WHERE order_id = ? AND status IN ('PENDING', 'UNDER_REVIEW')
            ");
            $disputeStmt->execute([$order['id']]);
            if ($disputeStmt->fetch()) {
                // Dispute exists - skip payment release
                continue;
            }
            
            try {
                // Release vendor balance payment
                $this->releaseVendorBalance($order);
                
                // Mark hold period as completed
                $updateStmt = $this->db->prepare("
                    UPDATE orders 
                    SET hold_period_completed = 1,
                        status = 'COMPLETED',
                        completed_at = NOW(),
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $updateStmt->execute([$order['id']]);
                
                // Notify both parties
                $this->notifyHoldPeriodCompleted($order);
                
                $processed++;
            } catch (Exception $e) {
                error_log("Failed to release payment for order {$order['id']}: " . $e->getMessage());
                $failed++;
            }
        }
        
        return [
            'processed' => $processed,
            'failed' => $failed,
            'total' => count($orders),
        ];
    }
    
    /**
     * Process 7-day auto-release for vendor protection
     * Should be called by cron job daily
     */
    public function process7DayAutoRelease() {
        // Find orders where vendor completed but customer hasn't confirmed/disputed after 7 days
        $query = "
            SELECT o.*
            FROM orders o
            WHERE o.vendor_completed_at IS NOT NULL
            AND o.auto_release_date IS NOT NULL
            AND o.auto_release_date <= NOW()
            AND o.auto_release_triggered = 0
            AND o.status = 'AWAITING_CONFIRMATION'
            AND o.customer_confirmed = 0
            AND NOT EXISTS (
                SELECT 1 FROM disputes d 
                WHERE d.order_id = o.id 
                AND d.status IN ('PENDING', 'UNDER_REVIEW')
            )
        ";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        $orders = $stmt->fetchAll();
        
        $processed = 0;
        
        foreach ($orders as $order) {
            try {
                // Auto-confirm on behalf of customer
                $payoutReleaseDate = date('Y-m-d H:i:s', strtotime('+48 hours'));
                $updateStmt = $this->db->prepare("
                    UPDATE orders 
                    SET customer_confirmed = 1,
                        customer_confirmed_at = NOW(),
                        payout_release_date = ?,
                        auto_release_triggered = 1,
                        status = 'AWAITING_PAYOUT',
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $updateStmt->execute([$payoutReleaseDate, $order['id']]);
                
                // Notify both parties
                $customerStmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
                $customerStmt->execute([$order['customer_id']]);
                $customer = $customerStmt->fetch();
                
                $vendorStmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
                $vendorStmt->execute([$order['vendor_id']]);
                $vendor = $vendorStmt->fetch();
                
                if ($customer) {
                    $this->notificationService->create(
                        $customer['id'],
                        'ORDER_AUTO_CONFIRMED',
                        'Order Auto-Confirmed',
                        "Order #{$order['order_number']} has been auto-confirmed after 7 days. You can still raise a dispute within 48 hours.",
                        ['order_id' => $order['id']]
                    );
                }
                
                if ($vendor) {
                    $this->notificationService->create(
                        $vendor['id'],
                        'ORDER_AUTO_CONFIRMED',
                        'Order Auto-Confirmed',
                        "Order #{$order['order_number']} has been auto-confirmed. Payment will be released in 48 hours if no dispute is raised.",
                        ['order_id' => $order['id']]
                    );
                }
                
                $processed++;
            } catch (Exception $e) {
                error_log("Failed to auto-release order {$order['id']}: " . $e->getMessage());
            }
        }
        
        return [
            'processed' => $processed,
            'total' => count($orders),
        ];
    }
    
    /**
     * Release vendor balance payment
     */
    private function releaseVendorBalance($order) {
        // Get payment breakdown
        $breakdownStmt = $this->db->prepare("
            SELECT pb.*, o.vendor_id, o.country_id
            FROM payment_breakdowns pb
            INNER JOIN orders o ON pb.order_id = o.id
            WHERE pb.order_id = ? AND pb.balance_paid = 0
        ");
        $breakdownStmt->execute([$order['id']]);
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
            // No subaccount - mark for manual payout
            return;
        }
        
        // Process payment via gateway
        $gateway = PaymentGatewayFactory::getGatewayForCountry($breakdown['country_id']);
        
        $result = $gateway->createTransfer(
            $breakdown['vendor_balance_amount'],
            $subaccount['subaccount_code'],
            'Order completion balance payment (48-hour hold completed)'
        );
        
        // Mark as paid
        $updateStmt = $this->db->prepare("
            UPDATE payment_breakdowns 
            SET balance_paid = 1 
            WHERE order_id = ?
        ");
        $updateStmt->execute([$order['id']]);
        
        // Create transfer record
        $transferId = $this->generateUUID();
        $transferStmt = $this->db->prepare("
            INSERT INTO transfers (id, order_id, vendor_id, amount, currency_code, transfer_reference, status, country_id)
            VALUES (?, ?, ?, ?, ?, ?, 'SUCCESS', ?)
        ");
        $transferStmt->execute([
            $transferId,
            $order['id'],
            $breakdown['vendor_id'],
            $breakdown['vendor_balance_amount'],
            $breakdown['currency_code'],
            $result['reference'] ?? '',
            $breakdown['country_id'],
        ]);
    }
    
    /**
     * Notify parties that 48-hour hold period completed
     */
    private function notifyHoldPeriodCompleted($order) {
        $customerStmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
        $customerStmt->execute([$order['customer_id']]);
        $customer = $customerStmt->fetch();
        
        $vendorStmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
        $vendorStmt->execute([$order['vendor_id']]);
        $vendor = $vendorStmt->fetch();
        
        if ($customer) {
            $this->notificationService->create(
                $customer['id'],
                'PAYMENT_RELEASED',
                'Payment Released',
                "The 48-hour hold period for order #{$order['order_number']} has completed. Vendor balance has been released.",
                ['order_id' => $order['id']]
            );
        }
        
        if ($vendor) {
            $this->notificationService->create(
                $vendor['id'],
                'PAYMENT_RECEIVED',
                'Balance Payment Received',
                "Your balance payment for order #{$order['order_number']} has been released and transferred to your account.",
                ['order_id' => $order['id']]
            );
        }
    }
    
    /**
     * Generate UUID
     */
    private function generateUUID() {
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
