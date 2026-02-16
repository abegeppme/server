<?php

class ChatController {
    private $db;
    private $user_id;

    public function __construct() {
        $this->db = (new Database())->getConnection();
        $this->user_id = $this->getAuthenticatedUserId();
    }

    /**
     * Placeholder for getting the current user's ID from a token or session.
     * IMPORTANT: You must replace this with your actual authentication logic.
     */
    private function getAuthenticatedUserId() {
        // Example: If you were using sessions
        // if (isset($_SESSION['user_id'])) {
        //     return $_SESSION['user_id'];
        // }

        // For demonstration, we'll use a hardcoded ID.
        // Replace this with the real, authenticated user's ID.
        return '7c98754f-da93-4679-8eb9-32fc2913dbc7'; // Example: The admin user from your sample data
    }

    public function handleRequest($method, $id, $sub_resource) {
        if ($this->user_id === null) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => ['message' => 'Unauthorized']]);
            return;
        }

        if ($method === 'POST') {
            if ($id === 'typing' || $sub_resource === 'typing') {
                $this->handleTypingStatus();
            } else {
                $this->sendMessage();
            }
        } elseif ($method === 'GET') {
            $this->getMessages();
        } else {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => ['message' => 'Method Not Allowed']]);
        }
    }

    private function getMessages() {
        $recipient_id = $_GET['conversation_with'] ?? null;
        $last_timestamp = $_GET['since'] ?? '1970-01-01 00:00:00';

        if (!$recipient_id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => ['message' => 'Recipient ID is required.']]);
            return;
        }

        $sender_id = $this->user_id;

        $stmt = $this->db->prepare("
            SELECT id, sender_id, recipient_id, message, created_at FROM messages 
            WHERE ((sender_id = :sender_id AND recipient_id = :recipient_id) OR (sender_id = :recipient_id AND recipient_id = :sender_id))
            AND created_at > :last_timestamp
            ORDER BY created_at ASC
        ");
        $stmt->execute([
            ':sender_id' => $sender_id,
            ':recipient_id' => $recipient_id,
            ':last_timestamp' => $last_timestamp
        ]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $is_typing = $this->getTypingStatus($recipient_id);

        echo json_encode([
            'success' => true, 
            'data' => [
                'messages' => $messages,
                'is_typing' => $is_typing
            ]
        ]);
    }

    private function getTypingStatus($recipient_id) {
        // This is a placeholder. A real implementation would use a cache like Redis or a temporary table.
        return false; // For now, we will implement the frontend part first.
    }

    private function sendMessage() {
        $data = json_decode(file_get_contents('php://input'), true);
        $recipient_id = $data['recipient_id'] ?? null;
        $message_text = trim($data['message'] ?? '');

        if (!$recipient_id || empty($message_text)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => ['message' => 'Recipient and message are required.']]);
            return;
        }

        $sender_id = $this->user_id;

        $stmt = $this->db->prepare("
            INSERT INTO messages (id, sender_id, recipient_id, message, created_at) 
            VALUES (:id, :sender_id, :recipient_id, :message, NOW())
        ");
        
        $uuid = UUID::v4();

        if ($stmt->execute([
            ':id' => $uuid,
            ':sender_id' => $sender_id,
            ':recipient_id' => $recipient_id,
            ':message' => $message_text
        ])) {
            http_response_code(201);
            echo json_encode(['success' => true, 'data' => ['id' => $uuid, 'message' => 'Message sent.']]);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => ['message' => 'Failed to send message.']]);
        }
    }

    private function handleTypingStatus() {
        $data = json_decode(file_get_contents('php://input'), true);
        $recipient_id = $data['recipient_id'] ?? null;

        if (!$recipient_id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => ['message' => 'Recipient ID is required.']]);
            return;
        }

        // In a real application, you would store this status in a fast cache (like Redis or Memcached)
        // with an expiration time of a few seconds.
        // For this example, we'll just acknowledge the request.
        // A file-based cache could also work for simple cases.
        // e.g., file_put_contents(__DIR__ . '/../../cache/typing_' . $this->user_id . '.tmp', time());

        http_response_code(200);
        echo json_encode(['success' => true]);
    }
}

// A simple UUID generator if you don't have one
class UUID {
    public static function v4() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
    }
}

?>