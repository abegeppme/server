<?php
/**
 * User Controller - Complete Implementation
 */

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';

class UserController extends BaseController {
    private $auth;
    
    public function __construct() {
        parent::__construct();
        $this->auth = new AuthMiddleware();
    }
    
    public function index() {
        $user = $this->auth->requireAdmin();
        
        $pagination = $this->getPaginationParams();
        $role = $_GET['role'] ?? null;
        $status = $_GET['status'] ?? null;
        
        $query = "
            SELECT id, email, name, role, status, country_id, phone, avatar, created_at
            FROM users
            WHERE 1=1
        ";
        
        $params = [];
        
        if ($role) {
            $query .= " AND role = ?";
            $params[] = $role;
        }
        
        if ($status) {
            $query .= " AND status = ?";
            $params[] = $status;
        }
        
        $query .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
        $params[] = $pagination['limit'];
        $params[] = $pagination['offset'];
        
        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        $users = $stmt->fetchAll();
        
        // Get total count
        $countQuery = str_replace('SELECT id, email, name', 'SELECT COUNT(*) as total', $query);
        $countQuery = preg_replace('/ORDER BY.*$/', '', $countQuery);
        $countQuery = preg_replace('/LIMIT.*$/', '', $countQuery);
        $countStmt = $this->db->prepare($countQuery);
        $countStmt->execute(array_slice($params, 0, -2));
        $total = $countStmt->fetch()['total'];
        
        $this->sendResponse([
            'users' => $users,
            'pagination' => [
                'page' => $pagination['page'],
                'limit' => $pagination['limit'],
                'total' => $total,
                'pages' => ceil($total / $pagination['limit']),
            ],
        ]);
    }
    
    public function get($id) {
        $currentUser = $this->auth->requireAuth();
        
        if ($id === 'profile' || $id === 'me') {
            // Get current user profile
            $stmt = $this->db->prepare("
                SELECT u.*, c.name as country_name, cur.symbol as currency_symbol
                FROM users u
                LEFT JOIN countries c ON u.country_id = c.id
                LEFT JOIN currencies cur ON u.preferred_currency = cur.code
                WHERE u.id = ?
            ");
            $stmt->execute([$currentUser['id']]);
            $user = $stmt->fetch();
            
            if (!$user) {
                $this->sendError('User not found', 404);
            }
            
            unset($user['password']);
            
            // Get user stats
            $stats = $this->getUserStats($currentUser['id'], $currentUser['role']);
            $user['stats'] = $stats;
            
            $this->sendResponse($user);
        } elseif ($id === 'search') {
            $this->searchUsers($currentUser);
        } else {
            // Get user by ID (admin only or self)
            if ($currentUser['role'] !== 'ADMIN' && $currentUser['id'] !== $id) {
                $this->sendError('Unauthorized', 403);
            }
            
            $stmt = $this->db->prepare("
                SELECT id, email, name, role, status, country_id, phone, avatar, created_at
                FROM users
                WHERE id = ?
            ");
            $stmt->execute([$id]);
            $user = $stmt->fetch();
            
            if (!$user) {
                $this->sendError('User not found', 404);
            }
            
            // Get user stats if admin
            if ($currentUser['role'] === 'ADMIN') {
                $stats = $this->getUserStats($id, $user['role']);
                $user['stats'] = $stats;
            }
            
            $this->sendResponse($user);
        }
    }
    
    public function update($id) {
        $currentUser = $this->auth->requireAuth();
        $data = $this->getRequestBody();
        
        if ($id === 'profile' || $id === 'me') {
            // Update current user profile
            $userId = $currentUser['id'];
        } else {
            // Update user by ID (admin only)
            if ($currentUser['role'] !== 'ADMIN') {
                $this->sendError('Unauthorized', 403);
            }
            $userId = $id;
        }
        
        // Check user exists
        $checkStmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
        $checkStmt->execute([$userId]);
        $user = $checkStmt->fetch();
        
        if (!$user) {
            $this->sendError('User not found', 404);
        }
        
        // Build update query
        $updates = [];
        $params = [];
        
        $allowedFields = ['name', 'phone', 'avatar', 'country_id', 'preferred_currency'];
        
        // Admin can update more fields
        if ($currentUser['role'] === 'ADMIN') {
            $allowedFields = array_merge($allowedFields, ['role', 'status', 'email']);
        }
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updates[] = "$field = ?";
                $params[] = $data[$field];
            }
        }
        
        // Handle password update
        if (isset($data['password']) && !empty($data['password'])) {
            $updates[] = "password = ?";
            $params[] = password_hash($data['password'], PASSWORD_DEFAULT);
        }
        
        if (empty($updates)) {
            $this->sendError('No fields to update', 400);
        }
        
        $params[] = $userId;
        $query = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        
        // Get updated user
        $getStmt = $this->db->prepare("
            SELECT id, email, name, role, status, country_id, phone, avatar, created_at
            FROM users WHERE id = ?
        ");
        $getStmt->execute([$userId]);
        $updated = $getStmt->fetch();
        
        $this->sendResponse($updated);
    }
    
    /**
     * Get user statistics
     */
    private function getUserStats(string $userId, string $role): array {
        $stats = [];
        
        if ($role === 'CUSTOMER') {
            // Customer stats
            $ordersStmt = $this->db->prepare("
                SELECT 
                    COUNT(*) as total_orders,
                    SUM(total) as total_spent
                FROM orders 
                WHERE customer_id = ?
            ");
            $ordersStmt->execute([$userId]);
            $orderStats = $ordersStmt->fetch();
            
            $stats = [
                'total_orders' => intval($orderStats['total_orders'] ?? 0),
                'total_spent' => floatval($orderStats['total_spent'] ?? 0),
            ];
        } elseif ($role === 'VENDOR') {
            // Vendor stats
            $ordersStmt = $this->db->prepare("
                SELECT 
                    COUNT(*) as total_orders,
                    SUM(total) as total_revenue
                FROM orders 
                WHERE vendor_id = ?
            ");
            $ordersStmt->execute([$userId]);
            $orderStats = $ordersStmt->fetch();
            
            $balanceStmt = $this->db->prepare("
                SELECT SUM(pb.vendor_balance_amount) as total_balance
                FROM payment_breakdowns pb
                INNER JOIN orders o ON pb.order_id = o.id
                WHERE o.vendor_id = ? AND o.status = 'COMPLETED' AND pb.balance_paid = 0
            ");
            $balanceStmt->execute([$userId]);
            $balance = $balanceStmt->fetch();
            
            $servicesStmt = $this->db->prepare("
                SELECT COUNT(*) as total_services
                FROM services
                WHERE vendor_id = ?
            ");
            $servicesStmt->execute([$userId]);
            $services = $servicesStmt->fetch();
            
            $stats = [
                'total_orders' => intval($orderStats['total_orders'] ?? 0),
                'total_revenue' => floatval($orderStats['total_revenue'] ?? 0),
                'available_balance' => floatval($balance['total_balance'] ?? 0),
                'total_services' => intval($services['total_services'] ?? 0),
            ];
        }
        
        return $stats;
    }

    /**
     * Search users for chat/mentions
     */
    private function searchUsers(array $currentUser): void {
        $q = trim($_GET['q'] ?? '');
        $vendorId = trim($_GET['vendor_id'] ?? '');
        $limit = min(50, max(1, intval($_GET['limit'] ?? 20)));

        if ($q === '' && $vendorId === '') {
            $this->sendResponse([]);
        }

        $conditions = [];
        $params = [];

        if ($q !== '') {
            $conditions[] = "(name LIKE ? OR email LIKE ?)";
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
        }

        if ($vendorId !== '') {
            $conditions[] = "id = ?";
            $params[] = $vendorId;
        }

        $query = "
            SELECT id, email, name, role, status, avatar
            FROM users
            WHERE status = 'ACTIVE'
              AND id <> ?
              AND (" . implode(' OR ', $conditions) . ")
            ORDER BY name ASC
            LIMIT ?
        ";
        array_unshift($params, $currentUser['id']);
        $params[] = $limit;

        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        $users = $stmt->fetchAll();

        $this->sendResponse($users);
    }
}
