<?php
/**
 * Dispute Controller
 * Handles dispute-related API endpoints
 */

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';

class DisputeController extends BaseController {
    private $auth;
    
    public function __construct() {
        parent::__construct();
        $this->auth = new AuthMiddleware();
    }
    
    public function index() {
        $user = $this->auth->requireAuth();
        $pagination = $this->getPaginationParams();
        $status = $_GET['status'] ?? null;
        $order_id = $_GET['order_id'] ?? null;
        
        $query = "
            SELECT d.*, 
                   o.order_number,
                   o.customer_id,
                   o.vendor_id,
                   c.name as customer_name,
                   v.name as vendor_name,
                   u.name as raised_by_name
            FROM disputes d
            INNER JOIN orders o ON d.order_id = o.id
            INNER JOIN users c ON o.customer_id = c.id
            INNER JOIN users v ON o.vendor_id = v.id
            LEFT JOIN users u ON d.raised_by = u.id
            WHERE 1=1
        ";
        
        $params = [];
        
        // Filter by user role
        if ($user['role'] === 'CUSTOMER') {
            $query .= " AND o.customer_id = ?";
            $params[] = $user['id'];
        } elseif ($user['role'] === 'VENDOR') {
            $query .= " AND o.vendor_id = ?";
            $params[] = $user['id'];
        }
        // Admin can see all
        
        if ($status) {
            $query .= " AND d.status = ?";
            $params[] = $status;
        }
        
        if ($order_id) {
            $query .= " AND d.order_id = ?";
            $params[] = $order_id;
        }
        
        $query .= " ORDER BY d.created_at DESC LIMIT ? OFFSET ?";
        $params[] = $pagination['limit'];
        $params[] = $pagination['offset'];
        
        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        $disputes = $stmt->fetchAll();
        
        // Get total count
        $countQuery = str_replace('SELECT d.*,', 'SELECT COUNT(*) as total', $query);
        $countQuery = preg_replace('/ORDER BY.*$/', '', $countQuery);
        $countQuery = preg_replace('/LIMIT.*$/', '', $countQuery);
        $countStmt = $this->db->prepare($countQuery);
        $countStmt->execute(array_slice($params, 0, -2));
        $total = $countStmt->fetch()['total'];
        
        $this->sendResponse([
            'disputes' => $disputes,
            'pagination' => [
                'page' => $pagination['page'],
                'limit' => $pagination['limit'],
                'total' => $total,
                'pages' => ceil($total / $pagination['limit']),
            ],
        ]);
    }
    
    public function get($id) {
        $user = $this->auth->requireAuth();
        
        $query = "
            SELECT d.*, 
                   o.*,
                   c.name as customer_name,
                   c.email as customer_email,
                   v.name as vendor_name,
                   v.email as vendor_email,
                   u.name as raised_by_name
            FROM disputes d
            INNER JOIN orders o ON d.order_id = o.id
            INNER JOIN users c ON o.customer_id = c.id
            INNER JOIN users v ON o.vendor_id = v.id
            LEFT JOIN users u ON d.raised_by = u.id
            WHERE d.id = ?
        ";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute([$id]);
        $dispute = $stmt->fetch();
        
        if (!$dispute) {
            $this->sendError('Dispute not found', 404);
        }
        
        // Check access permissions
        if ($user['role'] === 'CUSTOMER' && $dispute['customer_id'] !== $user['id']) {
            $this->sendError('Access denied', 403);
        }
        if ($user['role'] === 'VENDOR' && $dispute['vendor_id'] !== $user['id']) {
            $this->sendError('Access denied', 403);
        }
        
        $this->sendResponse($dispute);
    }
    
    public function create() {
        $user = $this->auth->requireAuth();
        $data = $this->getRequestBody();
        
        $order_id = $data['order_id'] ?? null;
        $reason = $data['reason'] ?? '';
        $description = $data['description'] ?? '';
        $evidence = $data['evidence'] ?? [];
        
        if (!$order_id || empty($reason)) {
            $this->sendError('order_id and reason are required', 400);
        }
        
        // Get order
        $orderStmt = $this->db->prepare("
            SELECT * FROM orders 
            WHERE id = ? AND (customer_id = ? OR vendor_id = ?)
        ");
        $orderStmt->execute([$order_id, $user['id'], $user['id']]);
        $order = $orderStmt->fetch();
        
        if (!$order) {
            $this->sendError('Order not found', 404);
        }
        
        // Check if dispute already exists
        $checkStmt = $this->db->prepare("SELECT id FROM disputes WHERE order_id = ?");
        $checkStmt->execute([$order_id]);
        if ($checkStmt->fetch()) {
            $this->sendError('Dispute already exists for this order', 400);
        }
        
        // Create dispute
        $disputeId = $this->generateUUID();
        $insertStmt = $this->db->prepare("
            INSERT INTO disputes (
                id, order_id, customer_id, vendor_id, raised_by, 
                reason, description, evidence, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'PENDING')
        ");
        $insertStmt->execute([
            $disputeId,
            $order_id,
            $order['customer_id'],
            $order['vendor_id'],
            $user['id'],
            $reason,
            $description,
            json_encode($evidence),
        ]);
        
        // Update order status - Cancel 48-hour hold if in progress
        $orderUpdateStmt = $this->db->prepare("
            UPDATE orders 
            SET status = 'ON_HOLD',
                payout_release_date = NULL  -- Cancel 48-hour hold if in progress
            WHERE id = ?
        ");
        $orderUpdateStmt->execute([$order_id]);
        
        // Notify admin
        require_once __DIR__ . '/../services/NotificationService.php';
        $notificationService = new NotificationService();
        
        $adminStmt = $this->db->prepare("SELECT id FROM users WHERE role = 'ADMIN' LIMIT 1");
        $adminStmt->execute();
        $admin = $adminStmt->fetch();
        
        if ($admin) {
            $notificationService->create(
                $admin['id'],
                'DISPUTE_RAISED',
                'New Dispute Raised',
                "A dispute has been raised for order #{$order['order_number']}",
                ['order_id' => $order_id, 'dispute_id' => $disputeId]
            );
        }
        
        $this->sendResponse([
            'message' => 'Dispute raised successfully',
            'dispute_id' => $disputeId,
        ], 201);
    }
    
    public function postResolve($id) {
        $user = $this->auth->requireAdmin();
        $data = $this->getRequestBody();
        
        $resolution = $data['resolution'] ?? '';
        $resolution_note = $data['resolution_note'] ?? '';
        
        if (empty($resolution)) {
            $this->sendError('resolution is required', 400);
        }
        
        // Get dispute
        $disputeStmt = $this->db->prepare("
            SELECT d.*, o.*
            FROM disputes d
            INNER JOIN orders o ON d.order_id = o.id
            WHERE d.id = ?
        ");
        $disputeStmt->execute([$id]);
        $dispute = $disputeStmt->fetch();
        
        if (!$dispute) {
            $this->sendError('Dispute not found', 404);
        }
        
        if ($dispute['status'] !== 'PENDING') {
            $this->sendError('Dispute already resolved', 400);
        }
        
        // Update dispute
        $updateStmt = $this->db->prepare("
            UPDATE disputes 
            SET status = 'RESOLVED',
                resolved_by = ?,
                resolution = ?,
                resolution_note = ?,
                resolved_at = NOW()
            WHERE id = ?
        ");
        $updateStmt->execute([$user['id'], $resolution, $resolution_note, $id]);
        
        // Handle resolution based on type
        require_once __DIR__ . '/../services/NotificationService.php';
        $notificationService = new NotificationService();
        
        if ($resolution === 'REFUND_CUSTOMER') {
            // Process refund (would integrate with payment gateway)
            $orderUpdateStmt = $this->db->prepare("UPDATE orders SET status = 'REFUNDED' WHERE id = ?");
            $orderUpdateStmt->execute([$dispute['order_id']]);
        } elseif ($resolution === 'PAY_VENDOR') {
            // Pay vendor balance
            $orderUpdateStmt = $this->db->prepare("UPDATE orders SET status = 'COMPLETED' WHERE id = ?");
            $orderUpdateStmt->execute([$dispute['order_id']]);
            // Trigger vendor balance payment
            require_once __DIR__ . '/OrderController.php';
            $orderController = new OrderController();
            $orderController->postPayout($dispute['order_id']);
        } elseif ($resolution === 'PARTIAL_SETTLEMENT') {
            // Handle partial settlement
            $orderUpdateStmt = $this->db->prepare("UPDATE orders SET status = 'COMPLETED' WHERE id = ?");
            $orderUpdateStmt->execute([$dispute['order_id']]);
        } else {
            // NO_ACTION - Return order to previous state
            $orderUpdateStmt = $this->db->prepare("UPDATE orders SET status = 'AWAITING_CONFIRMATION' WHERE id = ?");
            $orderUpdateStmt->execute([$dispute['order_id']]);
        }
        
        // Notify both parties
        $notificationService->create(
            $dispute['customer_id'],
            'DISPUTE_RESOLVED',
            'Dispute Resolved',
            "Your dispute for order #{$dispute['order_number']} has been resolved",
            ['dispute_id' => $id, 'order_id' => $dispute['order_id']]
        );
        
        $notificationService->create(
            $dispute['vendor_id'],
            'DISPUTE_RESOLVED',
            'Dispute Resolved',
            "The dispute for order #{$dispute['order_number']} has been resolved",
            ['dispute_id' => $id, 'order_id' => $dispute['order_id']]
        );
        
        $this->sendResponse([
            'message' => 'Dispute resolved successfully',
            'dispute_id' => $id,
        ]);
    }
}
