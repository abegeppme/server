<?php
/**
 * API Key Middleware
 * Protects API endpoints with API key authentication
 * Can be disabled by setting ENABLE_API_KEY_AUTH=false in .env
 */

class ApiKeyMiddleware {
    private $enabled;
    private $apiKey;
    
    public function __construct() {
        // Check if API key auth is enabled (default: true)
        $this->enabled = getenv('ENABLE_API_KEY_AUTH') !== 'false';
        $this->apiKey = getenv('API_KEY') ?: 'your-api-key-here-change-this';
    }
    
    /**
     * Check if request has valid API key
     */
    public function checkApiKey(): bool {
        // If disabled, allow all requests
        if (!$this->enabled) {
            return true;
        }
        
        // Get API key from header
        $headers = getallheaders();
        $providedKey = $headers['X-API-Key'] ?? $headers['x-api-key'] ?? null;
        
        // Also check Authorization header: Bearer {api_key}
        if (!$providedKey) {
            $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
            if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
                $providedKey = $matches[1];
            }
        }
        
        // Check query parameter (for testing, less secure)
        if (!$providedKey && isset($_GET['api_key'])) {
            $providedKey = $_GET['api_key'];
        }
        
        if (!$providedKey) {
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'error' => ['message' => 'API key required']
            ]);
            exit();
        }
        
        if ($providedKey !== $this->apiKey) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'error' => ['message' => 'Invalid API key']
            ]);
            exit();
        }
        
        return true;
    }
    
    /**
     * Get list of public endpoints that don't require API key
     */
    public function getPublicEndpoints(): array {
        return [
            '/auth',      // Authentication endpoints (router strips /api prefix)
            '/api/auth',  // Backward compatibility for environments that keep /api prefix
            '/ai',        // AI FAQ/glossary assistant endpoints
            '/service-providers', // Public marketplace/provider profile endpoints
            '/services',          // Public service listing/details
            '/categories',        // Public categories for browsing
            '/countries',         // Public country metadata
        ];
    }
    
    /**
     * Check if current endpoint is public
     */
    public function isPublicEndpoint(string $path): bool {
        $publicEndpoints = $this->getPublicEndpoints();
        foreach ($publicEndpoints as $publicPath) {
            if (strpos($path, $publicPath) === 0) {
                return true;
            }
        }
        return false;
    }
}
