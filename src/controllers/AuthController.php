<?php
/**
 * Authentication Controller
 */

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../auth/JWTAuth.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';

class AuthController extends BaseController {
    private $jwt;
    private $auth;
    
    public function __construct() {
        parent::__construct();
        $this->jwt = new JWTAuth();
        $this->auth = new AuthMiddleware();
    }
    
    public function index() {
        $this->sendResponse([
            'message' => 'Auth endpoints available',
            'endpoints' => [
                'POST /api/auth' => 'Sign up/Sign in (action: sign-up or sign-in)',
                'GET /api/auth/session' => 'Get current session',
            ]
        ]);
    }
    
    public function create() {
        $data = $this->getRequestBody();
        $action = $data['action'] ?? null;
        
        switch ($action) {
            case 'sign-up':
                $this->signUp($data);
                break;
            case 'sign-in':
                $this->signIn($data);
                break;
            default:
                $this->sendError('Invalid action. Use "sign-up" or "sign-in"', 400);
        }
    }
    
    private function signUp($data) {
        // Validate input
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';
        $name = $data['name'] ?? '';
        $country_id = $data['country_id'] ?? 'NG';
        
        if (empty($email) || empty($password)) {
            $this->sendError('Email and password are required', 400);
        }
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->sendError('Invalid email format', 400);
        }
        
        // Check if user exists
        $checkStmt = $this->db->prepare("SELECT id FROM users WHERE email = ?");
        $checkStmt->execute([$email]);
        if ($checkStmt->fetch()) {
            $this->sendError('Email already registered', 400);
        }
        
        // Hash password
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        
        // Create user
        $userId = $this->generateUUID();
        $insertStmt = $this->db->prepare("
            INSERT INTO users (id, email, name, password, role, status, country_id, preferred_currency)
            VALUES (?, ?, ?, ?, 'CUSTOMER', 'ACTIVE', ?, 'NGN')
        ");
        $insertStmt->execute([$userId, $email, $name, $hashedPassword, $country_id]);
        
        // Generate token
        $token = $this->jwt->generateToken([
            'user_id' => $userId,
            'email' => $email,
            'role' => 'CUSTOMER',
        ]);
        
        // Get created user
        $userStmt = $this->db->prepare("SELECT id, email, name, role, country_id FROM users WHERE id = ?");
        $userStmt->execute([$userId]);
        $user = $userStmt->fetch();
        
        $this->sendResponse([
            'token' => $token,
            'user' => $user,
        ]);
    }
    
    private function signIn($data) {
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';
        
        if (empty($email) || empty($password)) {
            $this->sendError('Email and password are required', 400);
        }
        
        // Get user
        $stmt = $this->db->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if (!$user) {
            $this->sendError('Invalid email or password', 401);
        }
        
        // Verify password (supports both WordPress and PHP password_hash)
        $passwordValid = false;
        
        // Try PHP password_verify first
        if (password_verify($password, $user['password'])) {
            $passwordValid = true;
        } else {
            // Try WordPress password check (if migrated from WP)
            // WordPress uses $P$ or $2y$ format
            if (strpos($user['password'], '$P$') === 0 || strpos($user['password'], '$2y$') === 0) {
                // WordPress password - use wp_check_password equivalent
                // For now, we'll need to update passwords on first login
                $passwordValid = false; // Will need to re-hash
            }
        }
        
        if (!$passwordValid) {
            $this->sendError('Invalid email or password', 401);
        }
        
        // Check user status
        if ($user['status'] !== 'ACTIVE') {
            $this->sendError('Account is not active', 403);
        }
        
        // Re-hash password if it's WordPress format (for security)
        if (strpos($user['password'], '$P$') === 0) {
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            $updateStmt = $this->db->prepare("UPDATE users SET password = ? WHERE id = ?");
            $updateStmt->execute([$newHash, $user['id']]);
        }
        
        // Generate token
        $token = $this->jwt->generateToken([
            'user_id' => $user['id'],
            'email' => $user['email'],
            'role' => $user['role'],
        ]);
        
        unset($user['password']); // Don't send password
        
        $this->sendResponse([
            'token' => $token,
            'user' => $user,
        ]);
    }
    
    public function get($id = null) {
        if ($id === 'session') {
            $user = $this->auth->getCurrentUser();
            if (!$user) {
                $this->sendError('Not authenticated', 401);
            }
            unset($user['password']);
            $this->sendResponse(['user' => $user]);
        } else {
            $this->sendError('Invalid endpoint', 404);
        }
    }
}
