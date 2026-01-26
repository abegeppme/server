<?php
/**
 * Review Controller - Complete Implementation
 */

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../services/NotificationService.php';

class ReviewController extends BaseController {
    private $auth;
    private $notificationService;
    
    public function __construct() {
        parent::__construct();
        $this->auth = new AuthMiddleware();
        $this->notificationService = new NotificationService();
    }
    
    public function index() {
        $vendorId = $_GET['vendor_id'] ?? null;
        $orderId = $_GET['order_id'] ?? null;
        
        if (!$vendorId && !$orderId) {
            $this->sendError('vendor_id or order_id is required', 400);
        }
        
        $query = "
            SELECT r.*, 
                   u.name as customer_name, u.avatar as customer_avatar,
                   o.order_number
            FROM reviews r
            INNER JOIN users u ON r.customer_id = u.id
            LEFT JOIN orders o ON r.order_id = o.id
            WHERE 1=1
        ";
        
        $params = [];
        
        if ($vendorId) {
            $query .= " AND r.vendor_id = ?";
            $params[] = $vendorId;
        }
        
        if ($orderId) {
            $query .= " AND r.order_id = ?";
            $params[] = $orderId;
        }
        
        $query .= " ORDER BY r.created_at DESC";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        $reviews = $stmt->fetchAll();
        
        $this->sendResponse($reviews);
    }
    
    public function get($id) {
        $stmt = $this->db->prepare("
            SELECT r.*, 
                   u.name as customer_name, u.avatar as customer_avatar,
                   v.name as vendor_name,
                   o.order_number
            FROM reviews r
            INNER JOIN users u ON r.customer_id = u.id
            INNER JOIN users v ON r.vendor_id = v.id
            LEFT JOIN orders o ON r.order_id = o.id
            WHERE r.id = ?
        ");
        $stmt->execute([$id]);
        $review = $stmt->fetch();
        
        if (!$review) {
            $this->sendError('Review not found', 404);
        }
        
        $this->sendResponse($review);
    }
    
    public function create() {
        $user = $this->auth->requireAuth();
        $data = $this->getRequestBody();
        
        $orderId = $data['order_id'] ?? null;
        $vendorId = $data['vendor_id'] ?? null;
        $rating = intval($data['rating'] ?? 0);
        $comment = $data['comment'] ?? '';
        
        if (!$orderId || !$vendorId) {
            $this->sendError('order_id and vendor_id are required', 400);
        }
        
        if ($rating < 1 || $rating > 5) {
            $this->sendError('Rating must be between 1 and 5', 400);
        }
        
        // Verify order belongs to customer
        $orderStmt = $this->db->prepare("
            SELECT * FROM orders 
            WHERE id = ? AND customer_id = ? AND vendor_id = ? AND status = 'COMPLETED'
        ");
        $orderStmt->execute([$orderId, $user['id'], $vendorId]);
        $order = $orderStmt->fetch();
        
        if (!$order) {
            $this->sendError('Order not found or not completed', 404);
        }
        
        // Check if review already exists
        $checkStmt = $this->db->prepare("SELECT * FROM reviews WHERE order_id = ? AND customer_id = ?");
        $checkStmt->execute([$orderId, $user['id']]);
        if ($checkStmt->fetch()) {
            $this->sendError('Review already exists for this order', 400);
        }
        
        // Create review
        $reviewId = $this->generateUUID();
        $insertStmt = $this->db->prepare("
            INSERT INTO reviews (id, order_id, customer_id, vendor_id, rating, comment)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $insertStmt->execute([$reviewId, $orderId, $user['id'], $vendorId, $rating, $comment]);
        
        // Update vendor average rating
        $this->updateVendorRating($vendorId);
        
        // Update service ratings if order has services
        $itemsStmt = $this->db->prepare("SELECT service_id FROM order_items WHERE order_id = ?");
        $itemsStmt->execute([$orderId]);
        $items = $itemsStmt->fetchAll();
        
        foreach ($items as $item) {
            $this->updateServiceRating($item['service_id']);
        }
        
        // Notify vendor
        $vendorStmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
        $vendorStmt->execute([$vendorId]);
        $vendor = $vendorStmt->fetch();
        
        if ($vendor) {
            $this->notificationService->create(
                $vendorId,
                'NEW_REVIEW',
                'New Review Received',
                "You received a {$rating}-star review",
                ['review_id' => $reviewId, 'order_id' => $orderId]
            );
        }
        
        // Get created review
        $getStmt = $this->db->prepare("SELECT * FROM reviews WHERE id = ?");
        $getStmt->execute([$reviewId]);
        $review = $getStmt->fetch();
        
        $this->sendResponse($review, 201);
    }
    
    public function update($id) {
        $user = $this->auth->requireAuth();
        $data = $this->getRequestBody();
        
        // Get review
        $reviewStmt = $this->db->prepare("SELECT * FROM reviews WHERE id = ?");
        $reviewStmt->execute([$id]);
        $review = $reviewStmt->fetch();
        
        if (!$review) {
            $this->sendError('Review not found', 404);
        }
        
        // Check ownership
        if ($review['customer_id'] !== $user['id'] && $user['role'] !== 'ADMIN') {
            $this->sendError('Unauthorized', 403);
        }
        
        // Build update
        $updates = [];
        $params = [];
        
        if (isset($data['rating'])) {
            $rating = intval($data['rating']);
            if ($rating < 1 || $rating > 5) {
                $this->sendError('Rating must be between 1 and 5', 400);
            }
            $updates[] = "rating = ?";
            $params[] = $rating;
        }
        
        if (isset($data['comment'])) {
            $updates[] = "comment = ?";
            $params[] = $data['comment'];
        }
        
        if (empty($updates)) {
            $this->sendError('No fields to update', 400);
        }
        
        $params[] = $id;
        $query = "UPDATE reviews SET " . implode(', ', $updates) . " WHERE id = ?";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        
        // Update vendor rating
        $this->updateVendorRating($review['vendor_id']);
        
        // Get updated review
        $getStmt = $this->db->prepare("SELECT * FROM reviews WHERE id = ?");
        $getStmt->execute([$id]);
        $updated = $getStmt->fetch();
        
        $this->sendResponse($updated);
    }
    
    public function delete($id) {
        $user = $this->auth->requireAuth();
        
        // Get review
        $reviewStmt = $this->db->prepare("SELECT * FROM reviews WHERE id = ?");
        $reviewStmt->execute([$id]);
        $review = $reviewStmt->fetch();
        
        if (!$review) {
            $this->sendError('Review not found', 404);
        }
        
        // Check ownership
        if ($review['customer_id'] !== $user['id'] && $user['role'] !== 'ADMIN') {
            $this->sendError('Unauthorized', 403);
        }
        
        $vendorId = $review['vendor_id'];
        
        // Delete review
        $deleteStmt = $this->db->prepare("DELETE FROM reviews WHERE id = ?");
        $deleteStmt->execute([$id]);
        
        // Update vendor rating
        $this->updateVendorRating($vendorId);
        
        $this->sendResponse(['message' => 'Review deleted'], 200);
    }
    
    /**
     * Update vendor average rating
     */
    private function updateVendorRating(string $vendorId) {
        $ratingStmt = $this->db->prepare("
            SELECT AVG(rating) as avg_rating, COUNT(*) as review_count
            FROM reviews
            WHERE vendor_id = ?
        ");
        $ratingStmt->execute([$vendorId]);
        $stats = $ratingStmt->fetch();
        
        $avgRating = floatval($stats['avg_rating'] ?? 0);
        $reviewCount = intval($stats['review_count'] ?? 0);
        
        // Update user table if we have a rating field, or create a vendor_stats table
        // For now, we'll update services that belong to this vendor
        $updateStmt = $this->db->prepare("
            UPDATE services 
            SET rating = ?, review_count = ?
            WHERE vendor_id = ?
        ");
        $updateStmt->execute([$avgRating, $reviewCount, $vendorId]);
    }
    
    /**
     * Update service average rating
     */
    private function updateServiceRating(string $serviceId) {
        $ratingStmt = $this->db->prepare("
            SELECT AVG(r.rating) as avg_rating, COUNT(*) as review_count
            FROM reviews r
            INNER JOIN orders o ON r.order_id = o.id
            INNER JOIN order_items oi ON o.id = oi.order_id
            WHERE oi.service_id = ?
        ");
        $ratingStmt->execute([$serviceId]);
        $stats = $ratingStmt->fetch();
        
        $avgRating = floatval($stats['avg_rating'] ?? 0);
        $reviewCount = intval($stats['review_count'] ?? 0);
        
        $updateStmt = $this->db->prepare("
            UPDATE services 
            SET rating = ?, review_count = ?
            WHERE id = ?
        ");
        $updateStmt->execute([$avgRating, $reviewCount, $serviceId]);
    }
}
