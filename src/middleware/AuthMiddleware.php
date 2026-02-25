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
        $normalizedExpected = $this->normalizeRole($role);
        $normalizedActual = $this->normalizeRole($user['role'] ?? '');
        if ($normalizedActual !== $normalizedExpected) {
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
        $user = $this->requireAuth();
        if (!$this->isAdmin($user)) {
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
            $isVendorFlag = isset($user['is_vendor']) && (string)$user['is_vendor'] !== '0' && (string)$user['is_vendor'] !== '';
            if (!$isVendorFlag) {
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'error' => ['message' => 'Vendor access required. Please register as a service provider.']
                ]);
                exit();
            }
        } else {
            // Fall back to role check (backward compatibility)
            $normalizedRole = $this->normalizeRole($user['role'] ?? '');
            if ($normalizedRole !== 'VENDOR' && $normalizedRole !== 'SERVICE_PROVIDER' && $normalizedRole !== 'PROVIDER') {
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
            return isset($user['is_vendor']) && (string)$user['is_vendor'] !== '0' && (string)$user['is_vendor'] !== '';
        } else {
            $normalizedRole = $this->normalizeRole($user['role'] ?? '');
            return $normalizedRole === 'VENDOR' || $normalizedRole === 'SERVICE_PROVIDER' || $normalizedRole === 'PROVIDER';
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
            return isset($user['is_customer']) && (string)$user['is_customer'] !== '0' && (string)$user['is_customer'] !== '';
        } else {
            // Default: everyone is a customer unless they're admin/vendor only
            $normalizedRole = $this->normalizeRole($user['role'] ?? '');
            return $normalizedRole === 'CUSTOMER' || $normalizedRole === 'VENDOR' || $normalizedRole === 'ADMIN';
        }
    }

    private function normalizeRole(string $role): string {
        $upper = strtoupper(trim($role));
        return str_replace([' ', '-'], '_', $upper);
    }

    private function isAdmin(array $user): bool {
        $normalizedRole = $this->normalizeRole($user['role'] ?? '');
        $isAdminFlag = isset($user['is_admin']) && (string)$user['is_admin'] !== '0' && (string)$user['is_admin'] !== '';
        $isManagerFlag = isset($user['is_manager']) && (string)$user['is_manager'] !== '0' && (string)$user['is_manager'] !== '';
        $email = strtolower(trim((string)($user['email'] ?? '')));

        return
            $normalizedRole === 'ADMIN' ||
            $normalizedRole === 'ADMINISTRATOR' ||
            $normalizedRole === 'SUPER_ADMIN' ||
            $normalizedRole === 'MANAGER' ||
            $isAdminFlag ||
            $isManagerFlag ||
            $email === 'admin@abegeppme.com' ||
            $email === 'admin@abegeppme';
    }
}
