<?php
/**
 * AbegEppMe API Entry Point
 */

// Set headers
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . (getenv('CORS_ORIGIN') ?: '*'));
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Load configuration
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

// Autoloader
spl_autoload_register(function ($class) {
    $paths = [
        __DIR__ . '/src/',
        __DIR__ . '/src/controllers/',
        __DIR__ . '/src/models/',
        __DIR__ . '/src/middleware/',
        __DIR__ . '/src/utils/',
    ];
    
    foreach ($paths as $path) {
        $file = $path . $class . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

// Error handler
set_error_handler(function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }
});

// Exception handler
set_exception_handler(function ($exception) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => [
            'message' => APP_ENV === 'development' ? $exception->getMessage() : 'Internal server error',
            'file' => APP_ENV === 'development' ? $exception->getFile() : null,
            'line' => APP_ENV === 'development' ? $exception->getLine() : null,
        ]
    ]);
});

// Router
$request_uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$request_method = $_SERVER['REQUEST_METHOD'];

// Remove the base path (server directory)
$base_paths = [
    '/abegeppme-v4/server',
    '/server',
];
foreach ($base_paths as $base_path) {
    if (strpos($request_uri, $base_path) === 0) {
        $request_uri = substr($request_uri, strlen($base_path));
        break;
    }
}

// Remove /api prefix if present
if (strpos($request_uri, '/api') === 0) {
    $request_uri = substr($request_uri, 4);
}

$request_uri = rtrim($request_uri, '/') ?: '/';
$segments = explode('/', trim($request_uri, '/'));

// Route handling
try {
    $controller = null;
    $action = null;
    $id = null;
    
    if (empty($segments[0]) || $segments[0] === '') {
        // Root API endpoint
        echo json_encode([
            'success' => true,
            'message' => 'AbegEppMe API v' . APP_VERSION,
            'endpoints' => [
                'auth' => '/api/auth',
                'users' => '/api/users',
                'services' => '/api/services',
                'orders' => '/api/orders',
                'service-providers' => '/api/service-providers',
                'chat' => '/api/chat',
                'admin' => '/api/admin',
                'payments' => '/api/payments',
                'countries' => '/api/countries',
                'reviews' => '/api/reviews',
                'invoices' => '/api/invoices',
                'migration' => '/api/migration',
            ]
        ]);
        exit();
    }
    
    $resource = $segments[0];
    $id = $segments[1] ?? null;
    $sub_resource = $segments[2] ?? null;
    
    // Route to appropriate controller
    switch ($resource) {
        case 'auth':
            require_once __DIR__ . '/src/controllers/AuthController.php';
            $controller = new AuthController();
            break;
            
        case 'users':
            require_once __DIR__ . '/src/controllers/UserController.php';
            $controller = new UserController();
            break;
            
        case 'services':
            require_once __DIR__ . '/src/controllers/ServiceController.php';
            $controller = new ServiceController();
            break;
            
        case 'orders':
            require_once __DIR__ . '/src/controllers/OrderController.php';
            $controller = new OrderController();
            break;
            
        case 'service-providers':
            require_once __DIR__ . '/src/controllers/ServiceProviderController.php';
            $controller = new ServiceProviderController();
            break;
            
        case 'chat':
            require_once __DIR__ . '/src/controllers/ChatController.php';
            $controller = new ChatController();
            break;
            
        case 'admin':
            require_once __DIR__ . '/src/controllers/AdminController.php';
            $controller = new AdminController();
            break;
            
        case 'payments':
            require_once __DIR__ . '/src/controllers/PaymentController.php';
            $controller = new PaymentController();
            break;
            
        case 'countries':
            require_once __DIR__ . '/src/controllers/CountryController.php';
            $controller = new CountryController();
            break;
            
        case 'reviews':
            require_once __DIR__ . '/src/controllers/ReviewController.php';
            $controller = new ReviewController();
            break;
            
        case 'invoices':
            require_once __DIR__ . '/src/controllers/InvoiceController.php';
            $controller = new InvoiceController();
            break;
            
        case 'migration':
            require_once __DIR__ . '/src/controllers/MigrationController.php';
            $controller = new MigrationController();
            break;
            
        default:
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'error' => ['message' => 'Endpoint not found']
            ]);
            exit();
    }
    
    // Handle request
    $controller->handleRequest($request_method, $id, $sub_resource);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => [
            'message' => APP_ENV === 'development' ? $e->getMessage() : 'Internal server error'
        ]
    ]);
}
