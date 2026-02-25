<?php
/**
 * Service Controller
 */

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../utils/CountryManager.php';

class ServiceController extends BaseController {
    private $auth;
    private $countryManager;
    
    public function __construct() {
        parent::__construct();
        $this->auth = new AuthMiddleware();
        $this->countryManager = new CountryManager();
    }
    
    public function index() {
        $pagination = $this->getPaginationParams();
        $country_id = $_GET['country_id'] ?? null;
        $category = $_GET['category'] ?? null;
        $status = $_GET['status'] ?? 'ACTIVE';
        $featured = isset($_GET['featured']) ? (bool)$_GET['featured'] : null;
        
        $query = "
            SELECT s.*, u.name as vendor_name, u.avatar as vendor_avatar,
                   c.name as country_name, cur.symbol as currency_symbol
            FROM services s
            INNER JOIN users u ON s.vendor_id = u.id
            LEFT JOIN countries c ON BINARY c.id = BINARY CAST(u.country_id AS CHAR(2))
            LEFT JOIN currencies cur ON c.currency_code = cur.code
            WHERE 1=1
        ";
        
        $params = [];
        
        if ($country_id) {
            $query .= " AND u.country_id = ?";
            $params[] = $country_id;
        }
        
        if ($category) {
            $query .= " AND s.category = ?";
            $params[] = $category;
        }
        
        if ($status) {
            $query .= " AND s.status = ?";
            $params[] = $status;
        }
        
        if ($featured !== null) {
            $query .= " AND s.featured = ?";
            $params[] = $featured ? 1 : 0;
        }
        
        $query .= " ORDER BY s.featured DESC, s.created_at DESC LIMIT ? OFFSET ?";
        $params[] = $pagination['limit'];
        $params[] = $pagination['offset'];
        
        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        $services = $stmt->fetchAll();
        
        // Get total count
        $countQuery = str_replace('SELECT s.*, u.name as vendor_name', 'SELECT COUNT(*) as total', $query);
        $countQuery = preg_replace('/ORDER BY.*$/', '', $countQuery);
        $countQuery = preg_replace('/LIMIT.*$/', '', $countQuery);
        $countStmt = $this->db->prepare($countQuery);
        $countStmt->execute(array_slice($params, 0, -2));
        $total = $countStmt->fetch()['total'];
        
        $this->sendResponse([
            'services' => $services,
            'pagination' => [
                'page' => $pagination['page'],
                'limit' => $pagination['limit'],
                'total' => $total,
                'pages' => ceil($total / $pagination['limit']),
            ],
        ]);
    }
    
    public function get($id) {
        $stmt = $this->db->prepare("
            SELECT s.*, u.name as vendor_name, u.avatar as vendor_avatar, u.email as vendor_email,
                   c.name as country_name, cur.symbol as currency_symbol
            FROM services s
            INNER JOIN users u ON s.vendor_id = u.id
            LEFT JOIN countries c ON BINARY c.id = BINARY CAST(u.country_id AS CHAR(2))
            LEFT JOIN currencies cur ON c.currency_code = cur.code
            WHERE s.id = ?
        ");
        $stmt->execute([$id]);
        $service = $stmt->fetch();
        
        if (!$service) {
            $this->sendError('Service not found', 404);
        }
        
        // Parse JSON fields
        if ($service['images']) {
            $service['images'] = json_decode($service['images'], true) ?: [];
        }
        if ($service['gallery']) {
            $service['gallery'] = json_decode($service['gallery'], true) ?: [];
        }
        if ($service['service_area']) {
            $service['service_area'] = json_decode($service['service_area'], true) ?: [];
        }
        
        $this->sendResponse($service);
    }
    
    public function create() {
        $user = $this->auth->requireVendor();
        $data = $this->getRequestBody();
        
        // Validate required fields
        $title = $data['title'] ?? '';
        $country_id = $data['country_id'] ?? $user['country_id'] ?? 'NG';
        
        if (empty($title)) {
            $this->sendError('Title is required', 400);
        }
        
        // Price is optional - can be negotiated per order
        $price = isset($data['price']) ? floatval($data['price']) : null;
        $priceType = $data['price_type'] ?? ($price ? 'FIXED' : 'NEGOTIABLE');
        $priceRangeMin = isset($data['price_range_min']) ? floatval($data['price_range_min']) : null;
        $priceRangeMax = isset($data['price_range_max']) ? floatval($data['price_range_max']) : null;
        
        // Get country currency
        $country = $this->countryManager->getCountry($country_id);
        if (!$country) {
            $this->sendError('Country not found', 404);
        }
        
        // Currency code only needed if price is set and column exists
        $currency_code = null;
        if ($price !== null) {
            // Check if currency_code column exists
            $currencyCheck = $this->db->query("SHOW COLUMNS FROM services LIKE 'currency_code'");
            $hasCurrency = $currencyCheck->rowCount() > 0;
            
            if ($hasCurrency) {
                $currency_code = $data['currency_code'] ?? $country['currency_code'];
            }
        }
        
        $serviceId = $this->generateUUID();
        
        // Build dynamic INSERT based on what fields are provided
        // Check if country_id column exists in services table before trying to insert
        $hasCountryCol = $this->checkColumnExists('services', 'country_id');

        $columns = ['id', 'vendor_id', 'title', 'status'];
        $placeholders = ['?', '?', '?', '?'];
        $values = [$serviceId, $user['id'], $title, $data['status'] ?? 'DRAFT'];

        if ($hasCountryCol) {
            $columns[] = 'country_id';
            $placeholders[] = '?';
            $values[] = $country_id;
        }
        
        // Optional fields
        if (isset($data['city_id'])) {
            $columns[] = 'city_id';
            $placeholders[] = '?';
            $values[] = $data['city_id'];
        }
        
        if (isset($data['description'])) {
            $columns[] = 'description';
            $placeholders[] = '?';
            $values[] = $data['description'];
        }
        
        if ($price !== null) {
            $columns[] = 'price';
            $placeholders[] = '?';
            $values[] = $price;
            
            if ($currency_code) {
                $columns[] = 'currency_code';
                $placeholders[] = '?';
                $values[] = $currency_code;
            }
        }
        
        if (isset($data['category'])) {
            $columns[] = 'category';
            $placeholders[] = '?';
            $values[] = $data['category'];
        }
        
        if (isset($data['images'])) {
            $columns[] = 'images';
            $placeholders[] = '?';
            $values[] = json_encode($data['images']);
        }
        
        if (isset($data['gallery'])) {
            $columns[] = 'gallery';
            $placeholders[] = '?';
            $values[] = json_encode($data['gallery']);
        }
        
        if (isset($data['featured'])) {
            $columns[] = 'featured';
            $placeholders[] = '?';
            $values[] = (int)$data['featured'];
        }
        
        if (isset($data['service_area'])) {
            $columns[] = 'service_area';
            $placeholders[] = '?';
            $values[] = json_encode($data['service_area']);
        }
        
        $columnsStr = implode(', ', $columns);
        $placeholdersStr = implode(', ', $placeholders);
        
        $stmt = $this->db->prepare("
            INSERT INTO services ({$columnsStr})
            VALUES ({$placeholdersStr})
        ");
        $stmt->execute($values);
        
        // Get created service
        $getStmt = $this->db->prepare("SELECT * FROM services WHERE id = ?");
        $getStmt->execute([$serviceId]);
        $service = $getStmt->fetch();
        
        $this->sendResponse($service, 201);
    }
    
    public function update($id) {
        $user = $this->auth->requireAuth();
        $data = $this->getRequestBody();
        
        // Check ownership
        $checkStmt = $this->db->prepare("SELECT vendor_id FROM services WHERE id = ?");
        $checkStmt->execute([$id]);
        $service = $checkStmt->fetch();
        
        if (!$service) {
            $this->sendError('Service not found', 404);
        }
        
        if ($service['vendor_id'] !== $user['id'] && $user['role'] !== 'ADMIN') {
            $this->sendError('Unauthorized', 403);
        }
        
        // Build update query
        $updates = [];
        $params = [];
        
        $allowedFields = ['title', 'description', 'price', 'category', 'status', 'featured', 'images', 'gallery', 'service_area', 'city_id'];
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                if (in_array($field, ['images', 'gallery', 'service_area'])) {
                    $updates[] = "$field = ?";
                    $params[] = json_encode($data[$field]);
                } else {
                    $updates[] = "$field = ?";
                    $params[] = $data[$field];
                }
            }
        }
        
        if (empty($updates)) {
            $this->sendError('No fields to update', 400);
        }
        
        $params[] = $id;
        $query = "UPDATE services SET " . implode(', ', $updates) . " WHERE id = ?";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        
        // Get updated service
        $getStmt = $this->db->prepare("SELECT * FROM services WHERE id = ?");
        $getStmt->execute([$id]);
        $updated = $getStmt->fetch();
        
        $this->sendResponse($updated);
    }
    
    public function delete($id) {
        $user = $this->auth->requireAuth();
        
        // Check ownership
        $checkStmt = $this->db->prepare("SELECT vendor_id FROM services WHERE id = ?");
        $checkStmt->execute([$id]);
        $service = $checkStmt->fetch();
        
        if (!$service) {
            $this->sendError('Service not found', 404);
        }
        
        if ($service['vendor_id'] !== $user['id'] && $user['role'] !== 'ADMIN') {
            $this->sendError('Unauthorized', 403);
        }
        
        // Soft delete (set status to ARCHIVED) or hard delete
        $deleteStmt = $this->db->prepare("DELETE FROM services WHERE id = ?");
        $deleteStmt->execute([$id]);
        
        $this->sendResponse(['message' => 'Service deleted'], 200);
    }
}
