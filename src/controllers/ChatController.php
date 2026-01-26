<?php
/**
 * Chat Controller
 */

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../services/ChatService.php';
require_once __DIR__ . '/../services/PushNotificationService.php';

class ChatController extends BaseController {
    private $auth;
    private $chatService;
    private $pushService;
    
    public function __construct() {
        parent::__construct();
        $this->auth = new AuthMiddleware();
        $this->chatService = new ChatService();
        $this->pushService = new PushNotificationService();
    }
    
    public function index() {
        $user = $this->auth->requireAuth();
        $conversations = $this->chatService->getConversations($user['id']);
        $this->sendResponse($conversations);
    }
    
    public function get($id) {
        $user = $this->auth->requireAuth();
        
        // Check if it's a conversation ID or "messages" endpoint
        if (strpos($id, '/') !== false) {
            list($conversationId, $action) = explode('/', $id, 2);
            if ($action === 'messages') {
                $limit = intval($_GET['limit'] ?? 50);
                $before = $_GET['before'] ?? null;
                $messages = $this->chatService->getMessages($conversationId, $limit, $before);
                
                // Mark as read
                $this->chatService->markAsRead($conversationId, $user['id']);
                
                $this->sendResponse($messages);
                return;
            }
        }
        
        // Get conversation details
        $stmt = $this->db->prepare("
            SELECT c.*, 
                   u1.name as user1_name, u1.avatar as user1_avatar,
                   u2.name as user2_name, u2.avatar as user2_avatar
            FROM conversations c
            INNER JOIN users u1 ON c.user1_id = u1.id
            INNER JOIN users u2 ON c.user2_id = u2.id
            WHERE c.id = ? AND (c.user1_id = ? OR c.user2_id = ?)
        ");
        $stmt->execute([$id, $user['id'], $user['id']]);
        $conversation = $stmt->fetch();
        
        if (!$conversation) {
            $this->sendError('Conversation not found', 404);
        }
        
        $this->sendResponse($conversation);
    }
    
    public function create() {
        $user = $this->auth->requireAuth();
        $data = $this->getRequestBody();
        
        $conversationId = $data['conversation_id'] ?? null;
        $toUserId = $data['to_user_id'] ?? null;
        $content = $data['content'] ?? '';
        $kind = $data['kind'] ?? 'MESSAGE';
        $fileUrl = $data['file_url'] ?? null;
        
        if (empty($content) && $kind === 'MESSAGE') {
            $this->sendError('Message content is required', 400);
        }
        
        // Get or create conversation
        if ($conversationId) {
            // Verify user is part of conversation
            $checkStmt = $this->db->prepare("
                SELECT * FROM conversations 
                WHERE id = ? AND (user1_id = ? OR user2_id = ?)
            ");
            $checkStmt->execute([$conversationId, $user['id'], $user['id']]);
            if (!$checkStmt->fetch()) {
                $this->sendError('Conversation not found', 404);
            }
        } else {
            if (!$toUserId) {
                $this->sendError('to_user_id or conversation_id is required', 400);
            }
            $conversation = $this->chatService->getOrCreateConversation($user['id'], $toUserId);
            $conversationId = $conversation['id'];
        }
        
        // Send message
        $message = $this->chatService->sendMessage($conversationId, $user['id'], $content, $kind, $fileUrl);
        
        // Get recipient
        $convStmt = $this->db->prepare("SELECT user1_id, user2_id FROM conversations WHERE id = ?");
        $convStmt->execute([$conversationId]);
        $conv = $convStmt->fetch();
        $recipientId = $conv['user1_id'] === $user['id'] ? $conv['user2_id'] : $conv['user1_id'];
        
        // Send push notification
        $recipientStmt = $this->db->prepare("SELECT name FROM users WHERE id = ?");
        $recipientStmt->execute([$recipientId]);
        $recipient = $recipientStmt->fetch();
        
        if ($recipient) {
            $this->pushService->sendChatNotification($recipientId, $user['name'], $content);
        }
        
        $this->sendResponse($message, 201);
    }
}
