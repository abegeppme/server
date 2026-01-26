<?php
/**
 * Authentication Middleware
 */

require_once __DIR__ . '/../auth/JWTAuth.php';

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
     * Require vendor
     */
    public function requireVendor(): array {
        return $this->requireRole('VENDOR');
    }
}
