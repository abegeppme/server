import { Router } from 'express';

const router = Router();

// Get conversations for user
router.get('/conversations', async (req, res) => {
  res.json({ message: 'Get conversations - to be implemented' });
});

// Get messages for conversation
router.get('/conversations/:id/messages', async (req, res) => {
  res.json({ message: 'Get messages - to be implemented' });
});

// Send message (fallback if WebSocket fails)
router.post('/conversations/:id/messages', async (req, res) => {
  res.json({ message: 'Send message - to be implemented' });
});

export default router;
