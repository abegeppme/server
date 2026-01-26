import { Router } from 'express';
import { logger } from '../utils/logger.js';

const router = Router();

// Placeholder routes - Better Auth will handle these
// These will be implemented when Better Auth is configured

router.post('/sign-up', async (req, res) => {
  logger.info('Sign up endpoint called');
  res.json({ message: 'Sign up endpoint - to be implemented with Better Auth' });
});

router.post('/sign-in', async (req, res) => {
  logger.info('Sign in endpoint called');
  res.json({ message: 'Sign in endpoint - to be implemented with Better Auth' });
});

router.post('/sign-out', async (req, res) => {
  logger.info('Sign out endpoint called');
  res.json({ message: 'Sign out endpoint - to be implemented with Better Auth' });
});

router.get('/session', async (req, res) => {
  logger.info('Session endpoint called');
  res.json({ message: 'Session endpoint - to be implemented with Better Auth' });
});

export default router;
