import { Router } from 'express';

const router = Router();

// Get all service providers
router.get('/', async (req, res) => {
  res.json({ message: 'Get service providers - to be implemented' });
});

// Get service provider by ID
router.get('/:id', async (req, res) => {
  res.json({ message: 'Get service provider by ID - to be implemented' });
});

// Register as service provider
router.post('/register', async (req, res) => {
  res.json({ message: 'Register service provider - to be implemented' });
});

// Update service provider profile
router.patch('/:id', async (req, res) => {
  res.json({ message: 'Update service provider profile - to be implemented' });
});

// Get service provider's services
router.get('/:id/services', async (req, res) => {
  res.json({ message: 'Get service provider services - to be implemented' });
});

export default router;
