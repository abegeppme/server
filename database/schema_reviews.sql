-- ============================================
-- REVIEWS TABLE
-- ============================================
-- Customer reviews and ratings for vendors/services

CREATE TABLE IF NOT EXISTS `reviews` (
  `id` CHAR(36) NOT NULL PRIMARY KEY,
  `order_id` CHAR(36) DEFAULT NULL COMMENT 'Order this review is for',
  `customer_id` CHAR(36) NOT NULL COMMENT 'Customer who wrote the review',
  `vendor_id` CHAR(36) NOT NULL COMMENT 'Vendor being reviewed',
  `service_id` CHAR(36) DEFAULT NULL COMMENT 'Service being reviewed (optional)',
  `rating` INT NOT NULL COMMENT 'Rating from 1 to 5',
  `comment` TEXT DEFAULT NULL COMMENT 'Review text',
  `images` JSON DEFAULT NULL COMMENT 'Array of image URLs',
  `status` ENUM('PENDING', 'APPROVED', 'REJECTED', 'HIDDEN') NOT NULL DEFAULT 'APPROVED',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_order_id` (`order_id`),
  INDEX `idx_customer_id` (`customer_id`),
  INDEX `idx_vendor_id` (`vendor_id`),
  INDEX `idx_service_id` (`service_id`),
  INDEX `idx_rating` (`rating`),
  INDEX `idx_status` (`status`),
  INDEX `idx_created_at` (`created_at`),
  FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL,
  FOREIGN KEY (`customer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`vendor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
