<?php
/**
 * JWT Authentication Handler
 */

class JWTAuth {
    private $secret;
    private $algorithm = 'HS256';
    private $expiry;
    
    public function __construct() {
        $this->secret = getenv('JWT_SECRET') ?: 'your-secret-key';
        $this->expiry = intval(getenv('JWT_EXPIRY') ?: 86400); // 24 hours
    }
    
    /**
     * Generate JWT token
     */
    public function generateToken(array $payload): string {
        $header = [
            'typ' => 'JWT',
            'alg' => $this->algorithm,
        ];
        
        $payload['iat'] = time();
        $payload['exp'] = time() + $this->expiry;
        
        $headerEncoded = $this->base64UrlEncode(json_encode($header));
        $payloadEncoded = $this->base64UrlEncode(json_encode($payload));
        
        $signature = hash_hmac('sha256', "$headerEncoded.$payloadEncoded", $this->secret, true);
        $signatureEncoded = $this->base64UrlEncode($signature);
        
        return "$headerEncoded.$payloadEncoded.$signatureEncoded";
    }
    
    /**
     * Verify and decode JWT token
     */
    public function verifyToken(string $token): ?array {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        
        list($headerEncoded, $payloadEncoded, $signatureEncoded) = $parts;
        
        // Verify signature
        $signature = $this->base64UrlDecode($signatureEncoded);
        $expectedSignature = hash_hmac('sha256', "$headerEncoded.$payloadEncoded", $this->secret, true);
        
        if (!hash_equals($expectedSignature, $signature)) {
            return null;
        }
        
        $payload = json_decode($this->base64UrlDecode($payloadEncoded), true);
        
        // Check expiration
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            return null;
        }
        
        return $payload;
    }
    
    /**
     * Get user from token
     */
    public function getUserFromToken(string $token): ?array {
        $payload = $this->verifyToken($token);
        if (!$payload || !isset($payload['user_id'])) {
            return null;
        }
        
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$payload['user_id']]);
        return $stmt->fetch() ?: null;
    }
    
    private function base64UrlEncode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
    
    private function base64UrlDecode(string $data): string {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
