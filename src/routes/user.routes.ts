import { Router } from 'express';

const router = Router();

// User profile routes
router.get('/profile', async (req, res) => {
  res.json({ message: 'Get user profile - to be implemented' });
});

router.patch('/profile', async (req, res) => {
  res.json({ message: 'Update user profile - to be implemented' });
});

export default router;
