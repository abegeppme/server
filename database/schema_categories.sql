-- Service Provider Categories
-- Supports multiple categories per service provider

CREATE TABLE IF NOT EXISTS `service_categories` (
  `id` CHAR(36) NOT NULL PRIMARY KEY,
  `name` VARCHAR(255) NOT NULL COMMENT 'Category name (e.g., "Plumbing", "Photography")',
  `slug` VARCHAR(255) NOT NULL UNIQUE COMMENT 'URL-friendly slug',
  `parent_id` CHAR(36) DEFAULT NULL COMMENT 'Parent category for subcategories',
  `description` TEXT DEFAULT NULL,
  `icon` VARCHAR(255) DEFAULT NULL COMMENT 'Icon name or URL',
  `sort_order` INT DEFAULT 0 COMMENT 'Display order',
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_slug` (`slug`),
  INDEX `idx_parent_id` (`parent_id`),
  INDEX `idx_is_active` (`is_active`),
  INDEX `idx_sort_order` (`sort_order`),
  INDEX `idx_name_parent` (`name`, `parent_id`),
  UNIQUE KEY `unique_name_parent` (`name`, `parent_id`),
  FOREIGN KEY (`parent_id`) REFERENCES `service_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Many-to-many: Service Providers can have multiple categories
CREATE TABLE IF NOT EXISTS `service_provider_categories` (
  `id` CHAR(36) NOT NULL PRIMARY KEY,
  `vendor_id` CHAR(36) NOT NULL COMMENT 'Service provider user ID',
  `category_id` CHAR(36) NOT NULL,
  `is_primary` BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Primary category for this provider',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_vendor_id` (`vendor_id`),
  INDEX `idx_category_id` (`category_id`),
  INDEX `idx_is_primary` (`is_primary`),
  UNIQUE KEY `unique_vendor_category` (`vendor_id`, `category_id`),
  FOREIGN KEY (`vendor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`category_id`) REFERENCES `service_categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Update services table to reference category (optional, for backward compatibility)
-- Services can still have a simple category string, but providers use the categories table
ALTER TABLE `services` 
MODIFY COLUMN `category` VARCHAR(100) DEFAULT NULL COMMENT 'Legacy category field, use service_provider_categories for providers';
