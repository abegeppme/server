<?php
/**
 * Base Controller Class
 */

class BaseController {
    protected $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * Handle HTTP request
     */
    public function handleRequest($method, $id = null, $sub_resource = null) {
        switch ($method) {
            case 'GET':
                if ($sub_resource) {
                    $this->handleSubResource('get', $id, $sub_resource);
                } elseif ($id) {
                    $this->get($id);
                } else {
                    $this->index();
                }
                break;
                
            case 'POST':
                if ($sub_resource) {
                    $this->handleSubResource('post', $id, $sub_resource);
                } else {
                    $this->create();
                }
                break;
                
            case 'PATCH':
            case 'PUT':
                if ($id) {
                    $this->update($id);
                } else {
                    $this->sendError('ID required', 400);
                }
                break;
                
            case 'DELETE':
                if ($id) {
                    $this->delete($id);
                } else {
                    $this->sendError('ID required', 400);
                }
                break;
                
            default:
                $this->sendError('Method not allowed', 405);
        }
    }
    
    /**
     * Handle sub-resources (e.g., /orders/:id/complete)
     */
    protected function handleSubResource($method, $id, $sub_resource) {
        $method_name = $method . ucfirst($sub_resource);
        if (method_exists($this, $method_name)) {
            $this->$method_name($id);
        } else {
            $this->sendError('Sub-resource not found', 404);
        }
    }
    
    /**
     * Get request body
     */
    protected function getRequestBody() {
        $input = file_get_contents('php://input');
        return json_decode($input, true) ?: [];
    }
    
    /**
     * Send JSON response
     */
    protected function sendResponse($data, $status_code = 200) {
        http_response_code($status_code);
        echo json_encode([
            'success' => true,
            'data' => $data
        ]);
        exit();
    }
    
    /**
     * Send error response
     */
    protected function sendError($message, $status_code = 400) {
        http_response_code($status_code);
        echo json_encode([
            'success' => false,
            'error' => ['message' => $message]
        ]);
        exit();
    }
    
    /**
     * Get pagination parameters
     */
    protected function getPaginationParams() {
        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $limit = isset($_GET['limit']) ? min(100, max(1, intval($_GET['limit']))) : 20;
        $offset = ($page - 1) * $limit;
        
        return [
            'page' => $page,
            'limit' => $limit,
            'offset' => $offset
        ];
    }
    
    /**
     * Generate UUID
     */
    protected function generateUUID() {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
    
    /**
     * Check if a column exists in a table
     */
    protected function checkColumnExists($table, $column) {
        try {
            $stmt = $this->db->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Check if a table exists
     */
    protected function checkTableExists($table) {
        try {
            $stmt = $this->db->query("SHOW TABLES LIKE '{$table}'");
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            return false;
        }
    }
}
