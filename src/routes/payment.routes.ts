import { Router } from 'express';

const router = Router();

// Initialize payment
router.post('/initialize', async (req, res) => {
  res.json({ message: 'Initialize payment - to be implemented with Paystack' });
});

// Verify payment
router.post('/verify', async (req, res) => {
  res.json({ message: 'Verify payment - to be implemented with Paystack' });
});

// Paystack webhook
router.post('/webhooks/paystack', async (req, res) => {
  res.json({ message: 'Paystack webhook - to be implemented' });
});

export default router;
