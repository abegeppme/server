import { Server } from 'socket.io';
import { logger } from '../utils/logger.js';

export const setupWebSocket = (io: Server): void => {
  io.on('connection', (socket) => {
    logger.info(`Client connected: ${socket.id}`);

    // Join user's personal room
    socket.on('join', (userId: string) => {
      socket.join(`user:${userId}`);
      logger.info(`User ${userId} joined their room`);
    });

    // Join conversation room
    socket.on('join-conversation', (conversationId: string) => {
      socket.join(`conversation:${conversationId}`);
      logger.info(`Socket ${socket.id} joined conversation ${conversationId}`);
    });

    // Leave conversation room
    socket.on('leave-conversation', (conversationId: string) => {
      socket.leave(`conversation:${conversationId}`);
      logger.info(`Socket ${socket.id} left conversation ${conversationId}`);
    });

    // Handle new message
    socket.on('NEW_MESSAGE', (data: any) => {
      // Broadcast to conversation room
      socket.to(`conversation:${data.conversationId}`).emit('NEW_MESSAGE', data);
      logger.debug('Message broadcasted', data);
    });

    // Handle typing indicator
    socket.on('TYPING', (data: any) => {
      socket.to(`conversation:${data.conversationId}`).emit('TYPING', data);
    });

    // Handle call signaling
    socket.on('CALL', (data: any) => {
      socket.to(`user:${data.to}`).emit('CALL', data);
    });

    socket.on('OFFER', (data: any) => {
      socket.to(`user:${data.to}`).emit('OFFER', data);
    });

    socket.on('ANSWER', (data: any) => {
      socket.to(`user:${data.to}`).emit('ANSWER', data);
    });

    socket.on('ICE_CANDIDATE', (data: any) => {
      socket.to(`user:${data.to}`).emit('ICE_CANDIDATE', data);
    });

    socket.on('CALL_END', (data: any) => {
      socket.to(`user:${data.to}`).emit('CALL_END', data);
    });

    socket.on('CALL_REJECT', (data: any) => {
      socket.to(`user:${data.to}`).emit('CALL_REJECT', data);
    });

    // Handle presence
    socket.on('PRESENCE_UPDATE', (data: any) => {
      socket.broadcast.emit('PRESENCE_UPDATE', data);
    });

    // Handle disconnect
    socket.on('disconnect', () => {
      logger.info(`Client disconnected: ${socket.id}`);
    });
  });

  logger.info('WebSocket server initialized');
};
