<?php
/**
 * Media / Upload Controller
 * Supports uploads to backend uploads/new/* folders.
 */

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';

class MediaController extends BaseController {
    private $auth;
    
    public function __construct() {
        parent::__construct();
        $this->auth = new AuthMiddleware();
    }
    
    public function index() {
        $this->sendResponse([
            'message' => 'Media upload endpoint',
            'endpoint' => 'POST /api/media',
            'multipart_field' => 'file',
            'types' => ['avatar', 'service', 'verification-document', 'other']
        ]);
    }
    
    public function create() {
        $user = $this->auth->requireAuth();
        
        $contentType = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
        if (strpos($contentType, 'multipart/form-data') === false) {
            $this->sendError('Use multipart/form-data with a "file" field for uploads', 400);
        }
        
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $this->sendError('No file uploaded or upload error', 400);
        }
        
        $file = $_FILES['file'];
        $type = strtolower(trim($_POST['type'] ?? 'other'));
        
        // Validation
        $maxSize = 15 * 1024 * 1024; // 15MB for docs/images
        if (($file['size'] ?? 0) > $maxSize) {
            $this->sendError('File too large. Max 15MB.', 400);
        }
        
        $allowedMime = [
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/gif',
            'application/pdf',
        ];
        
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);
        
        if (!in_array($mimeType, $allowedMime, true)) {
            $this->sendError('Invalid file type. Allowed: JPG, PNG, WEBP, GIF, PDF.', 400);
        }
        
        // Determine folder path under uploads/new
        $subDir = 'new/other';
        if ($type === 'avatar') {
            $subDir = 'new/avatars';
        } elseif ($type === 'service') {
            $subDir = 'new/services';
        } elseif ($type === 'verification-document' || $type === 'verification' || $type === 'verification-documents') {
            $subDir = 'new/verification-documents';
        }
        
        $uploadsRoot = __DIR__ . '/../../uploads';
        if (!is_dir($uploadsRoot)) {
            if (!mkdir($uploadsRoot, 0775, true) && !is_dir($uploadsRoot)) {
                $this->sendError('Uploads directory not found and cannot be created', 500);
            }
        }
        
        $targetDir = $uploadsRoot . '/' . $subDir;
        if (!is_dir($targetDir)) {
            if (!mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
                $this->sendError('Failed to create upload directory', 500);
            }
        }
        
        // Generate unique file name
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext === '') {
            $ext = $mimeType === 'application/pdf' ? 'pdf' : 'jpg';
        }
        $filename = sprintf('%s_%s.%s', $user['id'], bin2hex(random_bytes(8)), $ext);
        $targetPath = $targetDir . '/' . $filename;
        
        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            $this->sendError('Failed to save uploaded file', 500);
        }
        
        // Build public URL: .../uploads/new/...
        $appUrl = rtrim(getenv('APP_URL') ?: '', '/');
        if ($appUrl === '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
            $appUrl = $scheme . '://' . $host . $scriptDir;
        }
        $publicBase = preg_replace('#/server/?$#', '', $appUrl);
        $publicUrl = $publicBase . '/uploads/' . $subDir . '/' . $filename;
        
        $this->sendResponse([
            'message' => 'File uploaded successfully',
            'url' => $publicUrl,
            'path' => 'uploads/' . $subDir . '/' . $filename,
            'type' => $type,
            'mime' => $mimeType,
            'size' => (int)$file['size'],
        ], 201);
    }
}
