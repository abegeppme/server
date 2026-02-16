<?php
/**
 * Authentication Middleware
 */

require_once __DIR__ . '/../auth/JWTAuth.php';
require_once __DIR__ . '/../../config/database.php';

class AuthMiddleware {
    private $jwt;
    
    public function __construct() {
        $this->jwt = new JWTAuth();
    }
    
    /**
     * Get current authenticated user
     */
    public function getCurrentUser(): ?array {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        
        if (empty($authHeader) || !preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            return null;
        }
        
        $token = $matches[1];
        return $this->jwt->getUserFromToken($token);
    }
    
    /**
     * Require authentication
     */
    public function requireAuth(): array {
        $user = $this->getCurrentUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'error' => ['message' => 'Authentication required']
            ]);
            exit();
        }
        return $user;
    }
    
    /**
     * Require specific role
     */
    public function requireRole(string $role): array {
        $user = $this->requireAuth();
        if ($user['role'] !== $role) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'error' => ['message' => 'Insufficient permissions']
            ]);
            exit();
        }
        return $user;
    }
    
    /**
     * Require admin
     */
    public function requireAdmin(): array {
        return $this->requireRole('ADMIN');
    }
    
    /**
     * Require vendor (supports dual role system)
     */
    public function requireVendor(): array {
        $user = $this->requireAuth();
        
        // Check if is_vendor column exists
        $db = Database::getInstance()->getConnection();
        $hasIsVendor = false;
        try {
            $stmt = $db->query("SHOW COLUMNS FROM users LIKE 'is_vendor'");
            $hasIsVendor = $stmt->rowCount() > 0;
        } catch (Exception $e) {
            // Column doesn't exist, fall back to role
        }
        
        if ($hasIsVendor) {
            // Use is_vendor flag (allows users to be both customer and vendor)
            if (empty($user['is_vendor']) || $user['is_vendor'] == 0) {
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'error' => ['message' => 'Vendor access required. Please register as a service provider.']
                ]);
                exit();
            }
        } else {
            // Fall back to role check (backward compatibility)
            if ($user['role'] !== 'VENDOR') {
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'error' => ['message' => 'Vendor access required']
                ]);
                exit();
            }
        }
        
        return $user;
    }
    
    /**
     * Check if user is vendor (doesn't require, just checks)
     */
    public function isVendor(array $user): bool {
        $db = Database::getInstance()->getConnection();
        $hasIsVendor = false;
        try {
            $stmt = $db->query("SHOW COLUMNS FROM users LIKE 'is_vendor'");
            $hasIsVendor = $stmt->rowCount() > 0;
        } catch (Exception $e) {
            // Column doesn't exist
        }
        
        if ($hasIsVendor) {
            return !empty($user['is_vendor']) && $user['is_vendor'] == 1;
        } else {
            return $user['role'] === 'VENDOR';
        }
    }
    
    /**
     * Check if user is customer (doesn't require, just checks)
     */
    public function isCustomer(array $user): bool {
        $db = Database::getInstance()->getConnection();
        $hasIsCustomer = false;
        try {
            $stmt = $db->query("SHOW COLUMNS FROM users LIKE 'is_customer'");
            $hasIsCustomer = $stmt->rowCount() > 0;
        } catch (Exception $e) {
            // Column doesn't exist
        }
        
        if ($hasIsCustomer) {
            return !empty($user['is_customer']) && $user['is_customer'] == 1;
        } else {
            // Default: everyone is a customer unless they're admin/vendor only
            return $user['role'] === 'CUSTOMER' || $user['role'] === 'VENDOR' || $user['role'] === 'ADMIN';
        }
    }
}
