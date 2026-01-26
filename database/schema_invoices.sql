-- Invoices Table
-- Add this to your database

CREATE TABLE IF NOT EXISTS `invoices` (
  `id` CHAR(36) NOT NULL PRIMARY KEY,
  `order_id` CHAR(36) NOT NULL,
  `invoice_number` VARCHAR(100) NOT NULL UNIQUE,
  `data` JSON NOT NULL COMMENT 'Complete invoice data',
  `status` ENUM('ACTIVE', 'CANCELLED', 'VOID') NOT NULL DEFAULT 'ACTIVE',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_order_id` (`order_id`),
  INDEX `idx_invoice_number` (`invoice_number`),
  INDEX `idx_status` (`status`),
  FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
