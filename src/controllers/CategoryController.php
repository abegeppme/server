<?php
/**
 * Category Controller
 * Handles category-related API endpoints
 */

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';

class CategoryController extends BaseController {
    private $auth;

    public function __construct() {
        parent::__construct();
        $this->auth = new AuthMiddleware();
    }
    
    /**
     * Get all categories (hierarchical structure)
     * GET /api/categories
     */
    public function index() {
        $parent_id = $_GET['parent_id'] ?? null;
        $is_active = isset($_GET['is_active']) ? (bool)$_GET['is_active'] : true;
        $include_counts = isset($_GET['include_counts']) ? (bool)$_GET['include_counts'] : false;
        
        // Check if is_vendor column exists
        $hasIsVendor = $this->checkColumnExists('users', 'is_vendor');
        
        if ($parent_id) {
            // Get subcategories for a specific parent
            $query = "
                SELECT sc.id, sc.name, sc.slug, sc.parent_id, sc.description, sc.icon, sc.sort_order, sc.is_active";
            
            if ($include_counts) {
                $query .= ",
                    COUNT(DISTINCT spc.vendor_id) as provider_count";
            }
            
            $query .= "
                FROM service_categories sc";
            
            if ($include_counts) {
                $query .= "
                LEFT JOIN service_provider_categories spc ON sc.id = spc.category_id
                LEFT JOIN users u ON spc.vendor_id = u.id AND u.status = 'ACTIVE'";
                
                if ($hasIsVendor) {
                    $query .= " AND u.is_vendor = 1";
                } else {
                    $query .= " AND u.role = 'VENDOR'";
                }
            }
            
            $query .= "
                WHERE sc.parent_id = ? AND sc.is_active = ?";
            
            if ($include_counts) {
                $query .= " GROUP BY sc.id";
            }
            
            $query .= " ORDER BY sc.sort_order ASC, sc.name ASC";
            
            $stmt = $this->db->prepare($query);
            $stmt->execute([$parent_id, $is_active ? 1 : 0]);
            $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $this->sendResponse($categories);
        } else {
            // Get all categories in hierarchical structure
            $query = "
                SELECT sc.id, sc.name, sc.slug, sc.parent_id, sc.description, sc.icon, sc.sort_order, sc.is_active";
            
            if ($include_counts) {
                $query .= ",
                    COUNT(DISTINCT spc.vendor_id) as provider_count";
            }
            
            $query .= "
                FROM service_categories sc";
            
            if ($include_counts) {
                $query .= "
                LEFT JOIN service_provider_categories spc ON sc.id = spc.category_id
                LEFT JOIN users u ON spc.vendor_id = u.id AND u.status = 'ACTIVE'";
                
                if ($hasIsVendor) {
                    $query .= " AND u.is_vendor = 1";
                } else {
                    $query .= " AND u.role = 'VENDOR'";
                }
            }
            
            $query .= "
                WHERE sc.is_active = ?";
            
            if ($include_counts) {
                $query .= " GROUP BY sc.id";
            }
            
            $query .= " ORDER BY sc.sort_order ASC, sc.name ASC";
            
            $stmt = $this->db->prepare($query);
            $stmt->execute([$is_active ? 1 : 0]);
            $allCategories = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Build hierarchical structure with counts
            $categories = $this->buildHierarchy($allCategories, $include_counts);
            
            $this->sendResponse($categories);
        }
    }
    
    /**
     * Get single category by ID
     * GET /api/categories/{id}
     */
    public function get($id) {
        $stmt = $this->db->prepare("
            SELECT id, name, slug, parent_id, description, icon, sort_order, is_active, created_at, updated_at
            FROM service_categories
            WHERE id = ?
        ");
        $stmt->execute([$id]);
        $category = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$category) {
            $this->sendError('Category not found', 404);
            return;
        }
        
        // Get subcategories if it's a parent category
        $subStmt = $this->db->prepare("
            SELECT id, name, slug, parent_id, description, icon, sort_order, is_active
            FROM service_categories
            WHERE parent_id = ? AND is_active = 1
            ORDER BY sort_order ASC, name ASC
        ");
        $subStmt->execute([$id]);
        $category['subcategories'] = $subStmt->fetchAll(PDO::FETCH_ASSOC);
        
        $this->sendResponse($category);
    }

    /**
     * Create category (admin only)
     * POST /api/categories
     */
    public function create() {
        $this->auth->requireAdmin();
        $data = $this->getRequestBody();

        $name = trim($data['name'] ?? '');
        if (empty($name)) {
            $this->sendError('Category name is required', 400);
        }

        $slug = trim($data['slug'] ?? '');
        if (empty($slug)) {
            $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name));
            $slug = trim($slug, '-');
        }

        $parentId = $data['parent_id'] ?? null;
        $description = $data['description'] ?? null;
        $icon = $data['icon'] ?? null;
        $sortOrder = intval($data['sort_order'] ?? 0);
        $isActive = isset($data['is_active']) ? (intval((bool)$data['is_active'])) : 1;

        // Ensure slug is unique.
        $slugStmt = $this->db->prepare("SELECT id FROM service_categories WHERE slug = ? LIMIT 1");
        $slugStmt->execute([$slug]);
        if ($slugStmt->fetch()) {
            $this->sendError('Category slug already exists', 409);
        }

        $id = $this->generateUUID();
        $stmt = $this->db->prepare("
            INSERT INTO service_categories (id, name, slug, parent_id, description, icon, sort_order, is_active, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([$id, $name, $slug, $parentId, $description, $icon, $sortOrder, $isActive]);

        $this->sendResponse([
            'id' => $id,
            'message' => 'Category created successfully',
        ], 201);
    }

    /**
     * Update category (admin only)
     * PUT/PATCH /api/categories/{id}
     */
    public function update($id) {
        $this->auth->requireAdmin();
        $data = $this->getRequestBody();

        $checkStmt = $this->db->prepare("SELECT id FROM service_categories WHERE id = ? LIMIT 1");
        $checkStmt->execute([$id]);
        if (!$checkStmt->fetch()) {
            $this->sendError('Category not found', 404);
        }

        $allowedFields = ['name', 'slug', 'parent_id', 'description', 'icon', 'sort_order', 'is_active'];
        $updates = [];
        $params = [];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $updates[] = "{$field} = ?";
                if ($field === 'is_active') {
                    $params[] = intval((bool)$data[$field]);
                } elseif ($field === 'sort_order') {
                    $params[] = intval($data[$field]);
                } else {
                    $params[] = $data[$field];
                }
            }
        }

        if (empty($updates)) {
            $this->sendError('No fields to update', 400);
        }

        $updates[] = "updated_at = NOW()";
        $params[] = $id;

        $stmt = $this->db->prepare("
            UPDATE service_categories
            SET " . implode(', ', $updates) . "
            WHERE id = ?
        ");
        $stmt->execute($params);

        $this->sendResponse(['message' => 'Category updated successfully']);
    }

    /**
     * Delete/deactivate category (admin only)
     * DELETE /api/categories/{id}
     */
    public function delete($id) {
        $this->auth->requireAdmin();
        $hardDelete = isset($_GET['hard']) && $_GET['hard'] === 'true';

        $checkStmt = $this->db->prepare("SELECT id FROM service_categories WHERE id = ? LIMIT 1");
        $checkStmt->execute([$id]);
        if (!$checkStmt->fetch()) {
            $this->sendError('Category not found', 404);
        }

        if ($hardDelete) {
            $stmt = $this->db->prepare("DELETE FROM service_categories WHERE id = ?");
            $stmt->execute([$id]);
            $this->sendResponse(['message' => 'Category deleted successfully']);
            return;
        }

        $stmt = $this->db->prepare("
            UPDATE service_categories
            SET is_active = 0, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$id]);

        $this->sendResponse(['message' => 'Category deactivated successfully']);
    }
    
    /**
     * Build hierarchical category structure
     */
    private function buildHierarchy($categories, $include_counts = false) {
        $map = [];
        $roots = [];
        
        // Create map of all categories
        foreach ($categories as $category) {
            $map[$category['id']] = $category;
            $map[$category['id']]['subcategories'] = [];
            // Ensure provider_count is set
            if (!isset($map[$category['id']]['provider_count'])) {
                $map[$category['id']]['provider_count'] = 0;
            }
        }
        
        // Build tree structure
        foreach ($categories as $category) {
            if ($category['parent_id'] === null) {
                // Root category
                $roots[] = &$map[$category['id']];
            } else {
                // Subcategory
                if (isset($map[$category['parent_id']])) {
                    $map[$category['parent_id']]['subcategories'][] = &$map[$category['id']];
                    // Add subcategory count to parent
                    if ($include_counts && isset($category['provider_count'])) {
                        // Parent count should include all subcategory providers
                        // This is handled by the SQL query, but we can also sum here if needed
                    }
                }
            }
        }
        
        return $roots;
    }
}
