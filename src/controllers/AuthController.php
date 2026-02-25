<?php
/**
 * Authentication Controller
 */

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../auth/JWTAuth.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../utils/WordPressPassword.php';

class AuthController extends BaseController {
    private $jwt;
    private $auth;
    
    public function __construct() {
        parent::__construct();
        $this->jwt = new JWTAuth();
        $this->auth = new AuthMiddleware();
        $this->ensurePasswordResetTable();
        $this->ensureUserConsentTable();
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
            case 'sign-out':
                $this->sendResponse(['message' => 'Signed out']);
                break;
            case 'forgot-password':
                $this->forgotPassword($data);
                break;
            case 'reset-password':
                $this->resetPassword($data);
                break;
            case 'change-password':
                $this->changePassword($data);
                break;
            case 'debug-auth':
                $this->debugAuth($data);
                break;
            default:
                $this->sendError('Invalid action', 400);
        }
    }
    
    private function signUp($data) {
        // Validate input
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';
        $name = $data['name'] ?? '';
        $username = trim($data['username'] ?? '');
        $country_id = $data['country_id'] ?? 'NG';
        $termsAccepted = !empty($data['terms_accepted']);
        $mailingListOptIn = !empty($data['mailing_list_opt_in']);
        
        if (empty($email) || empty($password) || empty($name) || empty($username)) {
            $this->sendError('Username, name, email, and password are required', 400);
        }
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->sendError('Invalid email format', 400);
        }

        if (!$termsAccepted) {
            $this->sendError('You must accept the terms and conditions', 400);
        }

        if (!$mailingListOptIn) {
            $this->sendError('You must agree to join the mailing list', 400);
        }

        if (!$this->isStrongPassword($password)) {
            $this->sendError(
                'Password must be at least 8 characters and include one uppercase letter, one number, and one special character',
                400
            );
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

        // Store consent and marketing preference for contact list usage.
        $consentStmt = $this->db->prepare("
            INSERT INTO user_contact_preferences (
                id, user_id, email, name, username, terms_accepted_at, mailing_list_opt_in, source, created_at, updated_at
            )
            VALUES (?, ?, ?, ?, ?, NOW(), ?, 'signup', NOW(), NOW())
        ");
        $consentStmt->execute([
            $this->generateUUID(),
            $userId,
            $email,
            $name,
            $username,
            $mailingListOptIn ? 1 : 0,
        ]);
        
        // Generate token
        $token = $this->jwt->generateToken([
            'user_id' => $userId,
            'email' => $email,
            'role' => 'CUSTOMER',
        ]);
        
        // Get created user
        $userStmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
        $userStmt->execute([$userId]);
        $user = $userStmt->fetch();
        unset($user['password']);
        
        $this->sendResponse([
            'token' => $token,
            'user' => $user,
        ]);
    }
    
    private function signIn($data) {
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';
        
        if (empty($email) || empty($password)) {
            $this->logAuthEvent('SIGN_IN_MISSING_FIELDS', $email);
            $this->sendError('Email and password are required', 400);
        }
        
        // Get user
        $stmt = $this->db->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if (!$user) {
            $this->logAuthEvent('SIGN_IN_USER_NOT_FOUND', $email);
            $this->sendError('Invalid email or password', 401);
        }
        
        // Verify password (supports both WordPress and PHP password_hash)
        $passwordValid = WordPressPassword::checkPassword($password, $user['password']);
        
        if (!$passwordValid) {
            $this->logAuthEvent('SIGN_IN_PASSWORD_INVALID', $email, [
                'user_id' => $user['id'] ?? null,
                'hash_type' => $this->classifyPasswordHash($user['password'] ?? ''),
            ]);
            $this->sendError('Invalid email or password', 401);
        }
        
        // Check user status
        if ($user['status'] !== 'ACTIVE') {
            $this->logAuthEvent('SIGN_IN_USER_INACTIVE', $email, [
                'user_id' => $user['id'] ?? null,
                'status' => $user['status'] ?? null,
            ]);
            $this->sendError('Account is not active', 403);
        }

        // Logged-in but forced reset flow for migrated WordPress hashes.
        $mustResetPassword = $this->requiresWordPressPasswordReset($user);
        $resetToken = null;
        if ($mustResetPassword) {
            $resetToken = $this->createResetToken($user['id']);
            $this->logAuthEvent('SIGN_IN_REQUIRES_PASSWORD_RESET', $email, [
                'user_id' => $user['id'] ?? null,
                'hash_type' => $this->classifyPasswordHash($user['password'] ?? ''),
            ]);
        }
        
        // Generate token
        $token = $this->jwt->generateToken([
            'user_id' => $user['id'],
            'email' => $user['email'],
            'role' => $user['role'],
        ]);
        
        unset($user['password']); // Don't send password
        $user['requires_password_reset'] = $mustResetPassword;
        
        $this->sendResponse([
            'token' => $token,
            'user' => $user,
            'requires_password_reset' => $mustResetPassword,
            // Safe to return here since user has already authenticated.
            'reset_token' => $resetToken,
            'reset_link' => $mustResetPassword
                ? '/auth/reset-password?token=' . urlencode($resetToken)
                : null,
        ]);
    }

    private function debugAuth(array $data): void {
        // Keep this endpoint dev-only and admin-only.
        if (APP_ENV !== 'development') {
            $this->sendError('debug-auth is only available in development', 403);
        }
        $this->auth->requireAdmin();

        $emails = [];
        if (!empty($data['email']) && is_string($data['email'])) {
            $emails[] = trim($data['email']);
        }
        if (!empty($data['emails']) && is_array($data['emails'])) {
            foreach ($data['emails'] as $email) {
                if (is_string($email) && trim($email) !== '') {
                    $emails[] = trim($email);
                }
            }
        }
        $emails = array_values(array_unique(array_filter($emails, function ($email) {
            return filter_var($email, FILTER_VALIDATE_EMAIL);
        })));

        if (count($emails) === 0) {
            $this->sendError('Provide email or emails[] with valid email values', 400);
        }

        $testPassword = isset($data['test_password']) && is_string($data['test_password'])
            ? $data['test_password']
            : null;

        $stmt = $this->db->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $results = [];

        foreach ($emails as $email) {
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if (!$user) {
                $results[] = [
                    'email' => $email,
                    'exists' => false,
                    'can_sign_in' => false,
                    'reasons' => ['USER_NOT_FOUND'],
                ];
                continue;
            }

            $status = strtoupper((string)($user['status'] ?? ''));
            $hash = (string)($user['password'] ?? '');
            $hashType = $this->classifyPasswordHash($hash);
            $requiresReset = $this->requiresWordPressPasswordReset($user);
            $passwordMatches = $testPassword !== null ? WordPressPassword::checkPassword($testPassword, $hash) : null;

            $reasons = [];
            if ($status !== 'ACTIVE') {
                $reasons[] = 'USER_NOT_ACTIVE';
            }
            if ($testPassword !== null && $passwordMatches !== true) {
                $reasons[] = 'PASSWORD_MISMATCH';
            }
            if ($requiresReset) {
                $reasons[] = 'PASSWORD_RESET_REQUIRED';
            }

            $results[] = [
                'email' => $email,
                'exists' => true,
                'user_id' => $user['id'] ?? null,
                'name' => $user['name'] ?? null,
                'status' => $status,
                'role' => $user['role'] ?? null,
                'flags' => [
                    'is_vendor' => isset($user['is_vendor']) ? (string)$user['is_vendor'] : null,
                    'is_customer' => isset($user['is_customer']) ? (string)$user['is_customer'] : null,
                    'is_admin' => isset($user['is_admin']) ? (string)$user['is_admin'] : null,
                    'is_manager' => isset($user['is_manager']) ? (string)$user['is_manager'] : null,
                ],
                'password_hash_type' => $hashType,
                'requires_password_reset' => $requiresReset,
                'password_matches' => $passwordMatches,
                'can_sign_in' => count(array_filter($reasons, function ($reason) {
                    return $reason !== 'PASSWORD_RESET_REQUIRED';
                })) === 0,
                'reasons' => $reasons,
            ];
        }

        $this->sendResponse([
            'generated_at' => date('c'),
            'count' => count($results),
            'results' => $results,
        ]);
    }
    
    public function get($id = null) {
        if ($id !== null && $id !== 'session') {
            $this->sendError('Invalid endpoint', 404);
        }

        $user = $this->auth->getCurrentUser();
        if (!$user) {
            $this->sendError('Not authenticated', 401);
        }
        $mustResetPassword = $this->requiresWordPressPasswordReset($user);
        unset($user['password']);
        $user['requires_password_reset'] = $mustResetPassword;
        $this->sendResponse($user);
    }

    private function forgotPassword($data) {
        $email = trim($data['email'] ?? '');
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->sendError('Valid email is required', 400);
        }

        $stmt = $this->db->prepare("SELECT id, email FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // Always return generic success to avoid account enumeration.
        if (!$user) {
            $this->sendResponse([
                'message' => 'If this email exists, a reset link has been generated.',
            ]);
        }

        $token = $this->createResetToken($user['id']);

        $payload = [
            'message' => 'If this email exists, a reset link has been generated.',
        ];
        if (APP_ENV === 'development') {
            $payload['dev_token'] = $token;
            $payload['reset_link'] = '/auth/reset-password?token=' . urlencode($token);
        }

        $this->sendResponse($payload);
    }

    private function resetPassword($data) {
        $token = trim($data['token'] ?? '');
        $password = $data['password'] ?? '';

        if (empty($token) || empty($password)) {
            $this->sendError('Token and password are required', 400);
        }

        if (!$this->isStrongPassword($password)) {
            $this->sendError(
                'Password must be at least 8 characters and include one uppercase letter, one number, and one special character',
                400
            );
        }

        $tokenHash = hash('sha256', $token);
        $stmt = $this->db->prepare("
            SELECT id, user_id
            FROM password_reset_tokens
            WHERE token_hash = ?
              AND used_at IS NULL
              AND expires_at >= NOW()
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$tokenHash]);
        $reset = $stmt->fetch();

        if (!$reset) {
            $this->sendError('Invalid or expired reset token', 400);
        }

        $newHash = password_hash($password, PASSWORD_DEFAULT);

        $this->db->beginTransaction();
        try {
            $updateUserStmt = $this->db->prepare("UPDATE users SET password = ? WHERE id = ?");
            $updateUserStmt->execute([$newHash, $reset['user_id']]);

            $markUsedStmt = $this->db->prepare("UPDATE password_reset_tokens SET used_at = NOW() WHERE id = ?");
            $markUsedStmt->execute([$reset['id']]);

            // Invalidate older active tokens for this user.
            $invalidateStmt = $this->db->prepare("
                UPDATE password_reset_tokens
                SET used_at = NOW()
                WHERE user_id = ?
                  AND used_at IS NULL
            ");
            $invalidateStmt->execute([$reset['user_id']]);

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }

        $this->sendResponse(['message' => 'Password reset successful']);
    }

    private function changePassword($data) {
        $currentUser = $this->auth->requireAuth();
        $currentPassword = $data['current_password'] ?? '';
        $newPassword = $data['new_password'] ?? '';

        if (empty($currentPassword) || empty($newPassword)) {
            $this->sendError('Current password and new password are required', 400);
        }

        if (!$this->isStrongPassword($newPassword)) {
            $this->sendError(
                'New password must be at least 8 characters and include one uppercase letter, one number, and one special character',
                400
            );
        }

        $stmt = $this->db->prepare("SELECT id, password FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$currentUser['id']]);
        $user = $stmt->fetch();
        if (!$user) {
            $this->sendError('User not found', 404);
        }

        $passwordValid = WordPressPassword::checkPassword($currentPassword, $user['password']);
        if (!$passwordValid) {
            $this->sendError('Current password is incorrect', 400);
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $updateStmt = $this->db->prepare("UPDATE users SET password = ? WHERE id = ?");
        $updateStmt->execute([$newHash, $currentUser['id']]);

        $this->sendResponse(['message' => 'Password updated successfully']);
    }

    private function isStrongPassword($password): bool {
        if (strlen($password) < 8) {
            return false;
        }
        if (!preg_match('/[A-Z]/', $password)) {
            return false;
        }
        if (!preg_match('/\d/', $password)) {
            return false;
        }
        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            return false;
        }
        return true;
    }

    private function createResetToken(string $userId): string {
        $token = bin2hex(random_bytes(24));
        $tokenHash = hash('sha256', $token);
        $stmt = $this->db->prepare("
            INSERT INTO password_reset_tokens (id, user_id, token_hash, expires_at, created_at)
            VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 60 MINUTE), NOW())
        ");
        $stmt->execute([
            $this->generateUUID(),
            $userId,
            $tokenHash,
        ]);

        return $token;
    }

    private function ensurePasswordResetTable(): void {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS password_reset_tokens (
                id CHAR(36) NOT NULL PRIMARY KEY,
                user_id CHAR(36) NOT NULL,
                token_hash VARCHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_prt_user_id (user_id),
                UNIQUE KEY uk_prt_token_hash (token_hash)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    private function ensureUserConsentTable(): void {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS user_contact_preferences (
                id CHAR(36) NOT NULL PRIMARY KEY,
                user_id CHAR(36) NOT NULL,
                email VARCHAR(255) NOT NULL,
                name VARCHAR(255) NULL,
                username VARCHAR(255) NULL,
                terms_accepted_at DATETIME NULL,
                mailing_list_opt_in TINYINT(1) NOT NULL DEFAULT 0,
                source VARCHAR(64) NOT NULL DEFAULT 'signup',
                mailchimp_synced_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_ucp_user_id (user_id),
                INDEX idx_ucp_email (email),
                INDEX idx_ucp_mailing (mailing_list_opt_in)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    private function sendJson(array $payload, int $statusCode): void {
        http_response_code($statusCode);
        echo json_encode($payload);
        exit();
    }

    private function requiresWordPressPasswordReset(array $user): bool {
        $envValue = getenv('REQUIRE_WORDPRESS_PASSWORD_RESET');
        $requireReset = $envValue === false ? true : strtolower((string) $envValue) === 'true';
        if (!$requireReset) {
            return false;
        }
        $passwordHash = $user['password'] ?? '';
        if (!is_string($passwordHash) || $passwordHash === '') {
            return false;
        }
        return WordPressPassword::isWordPressHash($passwordHash);
    }

    private function classifyPasswordHash(string $hash): string {
        if ($hash === '') return 'EMPTY';
        if (preg_match('/^[a-f0-9]{32}$/i', $hash)) return 'MD5_LEGACY';
        if (strpos($hash, '$wp$2a$') === 0 || strpos($hash, '$wp$2y$') === 0) return 'WP_PREFIXED_BCRYPT';
        if (strpos($hash, '$P$') === 0 || strpos($hash, '$H$') === 0) return 'WP_PORTABLE';
        if (strpos($hash, '$2a$') === 0 || strpos($hash, '$2y$') === 0 || strpos($hash, '$2b$') === 0) return 'PHP_BCRYPT';
        if (strpos($hash, '$argon2') === 0) return 'PHP_ARGON2';
        return 'UNKNOWN';
    }

    private function logAuthEvent(string $event, string $email, array $meta = []): void {
        if (APP_ENV !== 'development') {
            return;
        }
        $payload = [
            'event' => $event,
            'email' => strtolower(trim($email)),
            'meta' => $meta,
            'at' => date('c'),
        ];
        error_log('[AUTH_DEBUG] ' . json_encode($payload));
    }
}
