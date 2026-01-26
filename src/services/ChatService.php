<?php
/**
 * Chat Service
 * Handles chat/messaging functionality
 */

class ChatService {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * Get or create conversation between two users
     */
    public function getOrCreateConversation(string $user1Id, string $user2Id): array {
        // Ensure user1 < user2 for consistency
        if ($user1Id > $user2Id) {
            list($user1Id, $user2Id) = [$user2Id, $user1Id];
        }
        
        $stmt = $this->db->prepare("
            SELECT * FROM conversations 
            WHERE user1_id = ? AND user2_id = ?
        ");
        $stmt->execute([$user1Id, $user2Id]);
        $conversation = $stmt->fetch();
        
        if (!$conversation) {
            $conversationId = $this->generateUUID();
            $insertStmt = $this->db->prepare("
                INSERT INTO conversations (id, user1_id, user2_id)
                VALUES (?, ?, ?)
            ");
            $insertStmt->execute([$conversationId, $user1Id, $user2Id]);
            
            return [
                'id' => $conversationId,
                'user1_id' => $user1Id,
                'user2_id' => $user2Id,
            ];
        }
        
        return $conversation;
    }
    
    /**
     * Send message
     */
    public function sendMessage(string $conversationId, string $fromId, string $content, string $kind = 'MESSAGE', ?string $fileUrl = null): array {
        $messageId = $this->generateUUID();
        
        $stmt = $this->db->prepare("
            INSERT INTO messages (id, conversation_id, from_id, kind, content, file_url)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$messageId, $conversationId, $fromId, $kind, $content, $fileUrl]);
        
        // Update conversation last message time
        $updateStmt = $this->db->prepare("
            UPDATE conversations 
            SET last_message_at = NOW(), updated_at = NOW()
            WHERE id = ?
        ");
        $updateStmt->execute([$conversationId]);
        
        // Get created message
        $getStmt = $this->db->prepare("SELECT * FROM messages WHERE id = ?");
        $getStmt->execute([$messageId]);
        
        return $getStmt->fetch();
    }
    
    /**
     * Get messages for conversation
     */
    public function getMessages(string $conversationId, int $limit = 50, ?string $before = null): array {
        $query = "
            SELECT m.*, u.name as from_name, u.avatar as from_avatar
            FROM messages m
            INNER JOIN users u ON m.from_id = u.id
            WHERE m.conversation_id = ?
        ";
        
        $params = [$conversationId];
        
        if ($before) {
            $query .= " AND m.created_at < ?";
            $params[] = $before;
        }
        
        $query .= " ORDER BY m.created_at DESC LIMIT ?";
        $params[] = $limit;
        
        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        
        $messages = $stmt->fetchAll();
        return array_reverse($messages); // Return in chronological order
    }
    
    /**
     * Get conversations for user
     */
    public function getConversations(string $userId): array {
        $stmt = $this->db->prepare("
            SELECT c.*, 
                   u1.name as user1_name, u1.avatar as user1_avatar,
                   u2.name as user2_name, u2.avatar as user2_avatar,
                   (SELECT content FROM messages 
                    WHERE conversation_id = c.id 
                    ORDER BY created_at DESC LIMIT 1) as last_message
            FROM conversations c
            INNER JOIN users u1 ON c.user1_id = u1.id
            INNER JOIN users u2 ON c.user2_id = u2.id
            WHERE c.user1_id = ? OR c.user2_id = ?
            ORDER BY c.last_message_at DESC
        ");
        $stmt->execute([$userId, $userId]);
        
        return $stmt->fetchAll();
    }
    
    /**
     * Mark messages as read
     */
    public function markAsRead(string $conversationId, string $userId): bool {
        $stmt = $this->db->prepare("
            UPDATE messages 
            SET read = 1 
            WHERE conversation_id = ? AND from_id != ? AND read = 0
        ");
        return $stmt->execute([$conversationId, $userId]);
    }
    
    private function generateUUID(): string {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
