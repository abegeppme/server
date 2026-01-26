<?php
/**
 * Invoice Controller - Complete Implementation
 */

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../services/InvoiceService.php';
require_once __DIR__ . '/../services/EmailService.php';

class InvoiceController extends BaseController {
    private $auth;
    private $invoiceService;
    private $emailService;
    
    public function __construct() {
        parent::__construct();
        $this->auth = new AuthMiddleware();
        $this->invoiceService = new InvoiceService();
        $this->emailService = new EmailService();
    }
    
    public function index() {
        $user = $this->auth->requireAuth();
        $invoices = $this->invoiceService->getUserInvoices($user['id'], $user['role']);
        $this->sendResponse($invoices);
    }
    
    public function get($id) {
        $user = $this->auth->requireAuth();
        
        // Check if it's an order_id or invoice_id
        if (strlen($id) === 36 && strpos($id, '-') !== false) {
            // Likely an order ID (UUID format)
            $orderStmt = $this->db->prepare("
                SELECT * FROM orders 
                WHERE id = ? AND (customer_id = ? OR vendor_id = ?)
            ");
            $orderStmt->execute([$id, $user['id'], $user['id']]);
            $order = $orderStmt->fetch();
            
            if (!$order) {
                $this->sendError('Order not found', 404);
            }
            
            // Generate or get invoice
            $invoice = $this->invoiceService->getInvoice($id);
            if (!$invoice) {
                // Generate new invoice
                $invoice = $this->invoiceService->generateInvoice($id);
            } else {
                $invoice = $invoice['data'];
            }
            
            $this->sendResponse($invoice);
        } else {
            // Invoice number or ID
            $stmt = $this->db->prepare("
                SELECT i.*, o.customer_id, o.vendor_id
                FROM invoices i
                INNER JOIN orders o ON i.order_id = o.id
                WHERE i.invoice_number = ? OR i.id = ?
            ");
            $stmt->execute([$id, $id]);
            $invoice = $stmt->fetch();
            
            if (!$invoice) {
                $this->sendError('Invoice not found', 404);
            }
            
            // Check authorization
            if ($user['role'] !== 'ADMIN' && 
                $invoice['customer_id'] !== $user['id'] && 
                $invoice['vendor_id'] !== $user['id']) {
                $this->sendError('Unauthorized', 403);
            }
            
            $invoice['data'] = json_decode($invoice['data'], true);
            $this->sendResponse($invoice);
        }
    }
    
    public function create() {
        $user = $this->auth->requireAuth();
        $data = $this->getRequestBody();
        
        $orderId = $data['order_id'] ?? null;
        
        if (!$orderId) {
            $this->sendError('order_id is required', 400);
        }
        
        // Verify order belongs to user
        $orderStmt = $this->db->prepare("
            SELECT * FROM orders 
            WHERE id = ? AND (customer_id = ? OR vendor_id = ?)
        ");
        $orderStmt->execute([$orderId, $user['id'], $user['id']]);
        $order = $orderStmt->fetch();
        
        if (!$order) {
            $this->sendError('Order not found', 404);
        }
        
        // Generate invoice
        try {
            $invoice = $this->invoiceService->generateInvoice($orderId);
            
            // Send invoice email if requested
            if (isset($data['send_email']) && $data['send_email']) {
                $this->sendInvoiceEmail($orderId, $invoice);
            }
            
            $this->sendResponse($invoice, 201);
        } catch (Exception $e) {
            $this->sendError('Invoice generation failed: ' . $e->getMessage(), 500);
        }
    }
    
    public function postSend($id) {
        $user = $this->auth->requireAuth();
        
        // Get invoice
        $invoice = $this->invoiceService->getInvoice($id);
        if (!$invoice) {
            $this->sendError('Invoice not found', 404);
        }
        
        $invoiceData = json_decode($invoice['data'], true);
        
        // Send invoice email
        $this->sendInvoiceEmail($id, $invoiceData);
        
        $this->sendResponse(['message' => 'Invoice sent successfully']);
    }
    
    /**
     * Send invoice via email
     */
    private function sendInvoiceEmail(string $orderId, array $invoice) {
        $orderStmt = $this->db->prepare("
            SELECT o.*, c.email as customer_email, c.name as customer_name
            FROM orders o
            INNER JOIN users c ON o.customer_id = c.id
            WHERE o.id = ?
        ");
        $orderStmt->execute([$orderId]);
        $order = $orderStmt->fetch();
        
        if ($order) {
            $subject = "Invoice #{$invoice['invoice_number']} - Order #{$order['order_number']}";
            $body = $this->renderInvoiceEmail($invoice);
            $this->emailService->send($order['customer_email'], $subject, $body);
        }
    }
    
    /**
     * Render invoice email template
     */
    private function renderInvoiceEmail(array $invoice): string {
        $html = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; }
                .invoice { max-width: 800px; margin: 0 auto; padding: 20px; }
                .header { border-bottom: 2px solid #333; padding-bottom: 20px; margin-bottom: 20px; }
                .details { margin: 20px 0; }
                table { width: 100%; border-collapse: collapse; margin: 20px 0; }
                th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
                th { background-color: #f4f4f4; }
                .total { font-weight: bold; font-size: 1.2em; }
            </style>
        </head>
        <body>
            <div class='invoice'>
                <div class='header'>
                    <h1>Invoice #{$invoice['invoice_number']}</h1>
                    <p>Date: {$invoice['invoice_date']}</p>
                    <p>Due Date: {$invoice['due_date']}</p>
                </div>
                
                <div class='details'>
                    <h3>Bill To:</h3>
                    <p>{$invoice['customer']['name']}<br>
                    {$invoice['customer']['email']}<br>
                    {$invoice['customer']['phone']}</p>
                </div>
                
                <div class='details'>
                    <h3>Service Provider:</h3>
                    <p>{$invoice['vendor']['name']}<br>
                    {$invoice['vendor']['email']}</p>
                </div>
                
                <table>
                    <thead>
                        <tr>
                            <th>Service</th>
                            <th>Quantity</th>
                            <th>Price</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>";
        
        foreach ($invoice['items'] as $item) {
            $html .= "
                        <tr>
                            <td>{$item['service_title']}</td>
                            <td>{$item['quantity']}</td>
                            <td>{$invoice['currency']['symbol']}" . number_format($item['price'], 2) . "</td>
                            <td>{$invoice['currency']['symbol']}" . number_format($item['price'] * $item['quantity'], 2) . "</td>
                        </tr>";
        }
        
        $html .= "
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan='3'><strong>Subtotal:</strong></td>
                            <td>{$invoice['currency']['symbol']}" . number_format($invoice['totals']['subtotal'], 2) . "</td>
                        </tr>
                        <tr>
                            <td colspan='3'><strong>Service Charge:</strong></td>
                            <td>{$invoice['currency']['symbol']}" . number_format($invoice['totals']['service_charge'], 2) . "</td>
                        </tr>
                        <tr>
                            <td colspan='3'><strong>VAT:</strong></td>
                            <td>{$invoice['currency']['symbol']}" . number_format($invoice['totals']['vat_amount'], 2) . "</td>
                        </tr>
                        <tr class='total'>
                            <td colspan='3'><strong>Total:</strong></td>
                            <td>{$invoice['currency']['symbol']}" . number_format($invoice['totals']['total'], 2) . "</td>
                        </tr>
                    </tfoot>
                </table>
                
                <p>Order Number: {$invoice['order']['order_number']}</p>
                <p>Status: {$invoice['status']}</p>
            </div>
        </body>
        </html>";
        
        return $html;
    }
}
