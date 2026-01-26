<?php
/**
 * Invoice Service
 * Generates and manages invoices for orders
 */

class InvoiceService {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * Generate invoice for order
     */
    public function generateInvoice(string $orderId): array {
        // Get order with all details
        $orderStmt = $this->db->prepare("
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
        $orderStmt->execute([$orderId]);
        $order = $orderStmt->fetch();
        
        if (!$order) {
            throw new Exception('Order not found');
        }
        
        // Get order items
        $itemsStmt = $this->db->prepare("
            SELECT oi.*, s.title as service_title, s.description as service_description
            FROM order_items oi
            INNER JOIN services s ON oi.service_id = s.id
            WHERE oi.order_id = ?
        ");
        $itemsStmt->execute([$orderId]);
        $items = $itemsStmt->fetchAll();
        
        // Get payment info
        $paymentStmt = $this->db->prepare("SELECT * FROM payments WHERE order_id = ?");
        $paymentStmt->execute([$orderId]);
        $payment = $paymentStmt->fetch();
        
        // Generate invoice number
        $invoiceNumber = 'INV-' . $order['order_number'] . '-' . date('Ymd');
        
        // Build invoice data
        $invoice = [
            'invoice_number' => $invoiceNumber,
            'invoice_date' => date('Y-m-d'),
            'due_date' => date('Y-m-d', strtotime('+7 days')),
            'order' => [
                'id' => $order['id'],
                'order_number' => $order['order_number'],
                'created_at' => $order['created_at'],
            ],
            'customer' => [
                'name' => $order['customer_name'],
                'email' => $order['customer_email'],
                'phone' => $order['customer_phone'],
            ],
            'vendor' => [
                'name' => $order['vendor_name'],
                'email' => $order['vendor_email'],
                'phone' => $order['vendor_phone'],
            ],
            'items' => $items,
            'totals' => [
                'subtotal' => floatval($order['subtotal']),
                'service_charge' => floatval($order['service_charge']),
                'vat_amount' => floatval($order['vat_amount']),
                'total' => floatval($order['total']),
            ],
            'payment_breakdown' => $order['vendor_initial_amount'] ? [
                'vendor_initial' => floatval($order['vendor_initial_amount']),
                'insurance' => floatval($order['insurance_amount']),
                'commission' => floatval($order['commission_amount']),
                'balance' => floatval($order['vendor_balance_amount']),
            ] : null,
            'payment' => $payment ? [
                'status' => $payment['status'],
                'reference' => $payment['paystack_ref'],
                'paid_at' => $payment['paid_at'],
            ] : null,
            'currency' => [
                'code' => $order['currency_code'],
                'symbol' => $order['currency_symbol'],
            ],
            'status' => $order['status'],
        ];
        
        // Save invoice record
        $this->saveInvoice($orderId, $invoiceNumber, $invoice);
        
        return $invoice;
    }
    
    /**
     * Get invoice by order ID
     */
    public function getInvoice(string $orderId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM invoices WHERE order_id = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$orderId]);
        $invoice = $stmt->fetch();
        
        if (!$invoice) {
            return null;
        }
        
        $invoice['data'] = json_decode($invoice['data'], true);
        return $invoice;
    }
    
    /**
     * Get invoices for user
     */
    public function getUserInvoices(string $userId, string $role): array {
        if ($role === 'CUSTOMER') {
            $query = "
                SELECT i.*, o.order_number, o.status as order_status
                FROM invoices i
                INNER JOIN orders o ON i.order_id = o.id
                WHERE o.customer_id = ?
                ORDER BY i.created_at DESC
            ";
        } else {
            $query = "
                SELECT i.*, o.order_number, o.status as order_status
                FROM invoices i
                INNER JOIN orders o ON i.order_id = o.id
                WHERE o.vendor_id = ?
                ORDER BY i.created_at DESC
            ";
        }
        
        $stmt = $this->db->prepare($query);
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Save invoice to database
     */
    private function saveInvoice(string $orderId, string $invoiceNumber, array $invoiceData): void {
        $invoiceId = $this->generateUUID();
        
        $stmt = $this->db->prepare("
            INSERT INTO invoices (id, order_id, invoice_number, data, status)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                invoice_number = VALUES(invoice_number),
                data = VALUES(data),
                updated_at = NOW()
        ");
        
        $stmt->execute([
            $invoiceId,
            $orderId,
            $invoiceNumber,
            json_encode($invoiceData),
            'ACTIVE',
        ]);
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
