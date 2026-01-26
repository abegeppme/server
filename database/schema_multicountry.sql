-- AbegEppMe Service Marketplace Database Schema
-- Multi-Country, Multi-Currency Support
-- MySQL/MariaDB Compatible
-- Version: 2.0.0

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- ============================================
-- COUNTRIES & CURRENCIES
-- ============================================

CREATE TABLE IF NOT EXISTS `countries` (
  `id` CHAR(2) NOT NULL PRIMARY KEY COMMENT 'ISO 3166-1 Alpha-2 code',
  `name` VARCHAR(100) NOT NULL,
  `iso3` CHAR(3) DEFAULT NULL COMMENT 'ISO 3166-1 Alpha-3 code',
  `numeric_code` CHAR(3) DEFAULT NULL,
  `currency_code` CHAR(3) NOT NULL COMMENT 'Default currency ISO 4217 code',
  `phone_code` VARCHAR(5) DEFAULT NULL COMMENT 'International dialing code',
  `timezone` VARCHAR(50) DEFAULT NULL,
  `status` ENUM('ACTIVE', 'INACTIVE', 'COMING_SOON') NOT NULL DEFAULT 'INACTIVE',
  `payment_gateway` VARCHAR(50) DEFAULT NULL COMMENT 'Paystack, Flutterwave, etc.',
  `payment_gateway_config` JSON DEFAULT NULL COMMENT 'Gateway-specific configuration',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_status` (`status`),
  INDEX `idx_currency_code` (`currency_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `currencies` (
  `code` CHAR(3) NOT NULL PRIMARY KEY COMMENT 'ISO 4217 code',
  `name` VARCHAR(100) NOT NULL,
  `symbol` VARCHAR(10) NOT NULL,
  `symbol_native` VARCHAR(10) DEFAULT NULL,
  `decimal_digits` TINYINT NOT NULL DEFAULT 2,
  `rounding` DECIMAL(10,2) DEFAULT 0.00,
  `status` ENUM('ACTIVE', 'INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `currency_rates` (
  `id` CHAR(36) NOT NULL PRIMARY KEY,
  `from_currency` CHAR(3) NOT NULL,
  `to_currency` CHAR(3) NOT NULL,
  `rate` DECIMAL(15,6) NOT NULL COMMENT 'Exchange rate',
  `source` VARCHAR(50) DEFAULT NULL COMMENT 'API source name',
  `effective_date` DATE NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_rate` (`from_currency`, `to_currency`, `effective_date`),
  INDEX `idx_from_currency` (`from_currency`),
  INDEX `idx_to_currency` (`to_currency`),
  INDEX `idx_effective_date` (`effective_date`),
  FOREIGN KEY (`from_currency`) REFERENCES `currencies` (`code`) ON DELETE CASCADE,
  FOREIGN KEY (`to_currency`) REFERENCES `currencies` (`code`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- LOCATIONS (States/Cities)
-- ============================================

CREATE TABLE IF NOT EXISTS `states` (
  `id` CHAR(36) NOT NULL PRIMARY KEY,
  `country_id` CHAR(2) NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `code` VARCHAR(10) DEFAULT NULL COMMENT 'State/Province code',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_country_id` (`country_id`),
  FOREIGN KEY (`country_id`) REFERENCES `countries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cities` (
  `id` CHAR(36) NOT NULL PRIMARY KEY,
  `state_id` CHAR(36) DEFAULT NULL,
  `country_id` CHAR(2) NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `latitude` DECIMAL(10,8) DEFAULT NULL,
  `longitude` DECIMAL(11,8) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_state_id` (`state_id`),
  INDEX `idx_country_id` (`country_id`),
  FOREIGN KEY (`state_id`) REFERENCES `states` (`id`) ON DELETE SET NULL,
  FOREIGN KEY (`country_id`) REFERENCES `countries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- USER MANAGEMENT (Updated with Country)
-- ============================================

CREATE TABLE IF NOT EXISTS `users` (
  `id` CHAR(36) NOT NULL PRIMARY KEY,
  `email` VARCHAR(255) NOT NULL UNIQUE,
  `name` VARCHAR(255) DEFAULT NULL,
  `password` VARCHAR(255) NOT NULL COMMENT 'Hashed password',
  `role` ENUM('CUSTOMER', 'VENDOR', 'ADMIN') NOT NULL DEFAULT 'CUSTOMER',
  `status` ENUM('ACTIVE', 'INACTIVE', 'SUSPENDED', 'PENDING_VERIFICATION') NOT NULL DEFAULT 'PENDING_VERIFICATION',
  `phone` VARCHAR(20) DEFAULT NULL,
  `avatar` VARCHAR(500) DEFAULT NULL,
  `country_id` CHAR(2) DEFAULT NULL COMMENT 'User country',
  `city_id` CHAR(36) DEFAULT NULL COMMENT 'User city',
  `address` TEXT DEFAULT NULL,
  `preferred_currency` CHAR(3) DEFAULT NULL COMMENT 'User preferred currency',
  `language` VARCHAR(10) DEFAULT 'en' COMMENT 'Preferred language code',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_email` (`email`),
  INDEX `idx_role` (`role`),
  INDEX `idx_status` (`status`),
  INDEX `idx_country_id` (`country_id`),
  INDEX `idx_city_id` (`city_id`),
  FOREIGN KEY (`country_id`) REFERENCES `countries` (`id`) ON DELETE SET NULL,
  FOREIGN KEY (`city_id`) REFERENCES `cities` (`id`) ON DELETE SET NULL,
  FOREIGN KEY (`preferred_currency`) REFERENCES `currencies` (`code`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- SERVICE PROVIDER / VENDOR (Updated)
-- ============================================

CREATE TABLE IF NOT EXISTS `subaccounts` (
  `id` CHAR(36) NOT NULL PRIMARY KEY,
  `user_id` CHAR(36) NOT NULL UNIQUE,
  `country_id` CHAR(2) NOT NULL COMMENT 'Country for this subaccount',
  `subaccount_code` VARCHAR(100) NOT NULL COMMENT 'Payment gateway subaccount code',
  `account_number` VARCHAR(20) DEFAULT NULL,
  `account_name` VARCHAR(255) DEFAULT NULL,
  `bank_code` VARCHAR(10) DEFAULT NULL,
  `bank_name` VARCHAR(100) DEFAULT NULL,
  `transfer_recipient` VARCHAR(100) DEFAULT NULL COMMENT 'Payment gateway transfer recipient code',
  `status` VARCHAR(20) NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_country_id` (`country_id`),
  INDEX `idx_subaccount_code` (`subaccount_code`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`country_id`) REFERENCES `countries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- SERVICES (Updated with Location)
-- ============================================

CREATE TABLE IF NOT EXISTS `services` (
  `id` CHAR(36) NOT NULL PRIMARY KEY,
  `vendor_id` CHAR(36) NOT NULL,
  `country_id` CHAR(2) NOT NULL COMMENT 'Service available in country',
  `city_id` CHAR(36) DEFAULT NULL COMMENT 'Service available in city',
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `price` DECIMAL(10,2) NOT NULL,
  `currency_code` CHAR(3) NOT NULL COMMENT 'Service price currency',
  `category` VARCHAR(100) DEFAULT NULL,
  `images` JSON DEFAULT NULL COMMENT 'Array of image URLs',
  `gallery` JSON DEFAULT NULL COMMENT 'Additional gallery images',
  `status` ENUM('DRAFT', 'ACTIVE', 'INACTIVE', 'ARCHIVED') NOT NULL DEFAULT 'DRAFT',
  `featured` BOOLEAN NOT NULL DEFAULT FALSE,
  `rating` DECIMAL(3,2) DEFAULT NULL,
  `review_count` INT NOT NULL DEFAULT 0,
  `service_area` JSON DEFAULT NULL COMMENT 'Service coverage area (cities/states)',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_vendor_id` (`vendor_id`),
  INDEX `idx_country_id` (`country_id`),
  INDEX `idx_city_id` (`city_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_category` (`category`),
  INDEX `idx_featured` (`featured`),
  INDEX `idx_currency_code` (`currency_code`),
  FOREIGN KEY (`vendor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`country_id`) REFERENCES `countries` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`city_id`) REFERENCES `cities` (`id`) ON DELETE SET NULL,
  FOREIGN KEY (`currency_code`) REFERENCES `currencies` (`code`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- ORDERS (Updated with Currency)
-- ============================================

CREATE TABLE IF NOT EXISTS `orders` (
  `id` CHAR(36) NOT NULL PRIMARY KEY,
  `order_number` VARCHAR(50) NOT NULL UNIQUE COMMENT 'Human-readable order number',
  `customer_id` CHAR(36) NOT NULL,
  `vendor_id` CHAR(36) NOT NULL,
  `country_id` CHAR(2) NOT NULL COMMENT 'Order country',
  `currency_code` CHAR(3) NOT NULL COMMENT 'Order currency',
  `subtotal` DECIMAL(10,2) NOT NULL,
  `service_charge` DECIMAL(10,2) NOT NULL,
  `vat_amount` DECIMAL(10,2) NOT NULL,
  `total` DECIMAL(10,2) NOT NULL,
  `status` ENUM('PENDING', 'PROCESSING', 'IN_SERVICE', 'AWAITING_CONFIRMATION', 'COMPLETED', 'CANCELLED', 'REFUNDED', 'IN_DISPUTE') NOT NULL DEFAULT 'PENDING',
  `vendor_complete` BOOLEAN NOT NULL DEFAULT FALSE,
  `customer_confirmed` BOOLEAN NOT NULL DEFAULT FALSE,
  `completion_documents` JSON DEFAULT NULL COMMENT 'Array of document URLs',
  `payment_method_type` VARCHAR(20) DEFAULT NULL COMMENT 'split or individual',
  `payment_method_set_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `completed_at` TIMESTAMP NULL DEFAULT NULL,
  INDEX `idx_customer_id` (`customer_id`),
  INDEX `idx_vendor_id` (`vendor_id`),
  INDEX `idx_country_id` (`country_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_order_number` (`order_number`),
  INDEX `idx_created_at` (`created_at`),
  FOREIGN KEY (`customer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`vendor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`country_id`) REFERENCES `countries` (`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`currency_code`) REFERENCES `currencies` (`code`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- (Keep existing order_items table as is)
CREATE TABLE IF NOT EXISTS `order_items` (
  `id` CHAR(36) NOT NULL PRIMARY KEY,
  `order_id` CHAR(36) NOT NULL,
  `service_id` CHAR(36) NOT NULL,
  `quantity` INT NOT NULL DEFAULT 1,
  `price` DECIMAL(10,2) NOT NULL,
  `currency_code` CHAR(3) NOT NULL COMMENT 'Item price currency',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_order_id` (`order_id`),
  INDEX `idx_service_id` (`service_id`),
  FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`currency_code`) REFERENCES `currencies` (`code`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- PAYMENTS (Updated with Currency)
-- ============================================

CREATE TABLE IF NOT EXISTS `payments` (
  `id` CHAR(36) NOT NULL PRIMARY KEY,
  `order_id` CHAR(36) NOT NULL UNIQUE,
  `country_id` CHAR(2) NOT NULL COMMENT 'Payment country',
  `payment_gateway` VARCHAR(50) NOT NULL COMMENT 'Paystack, Flutterwave, etc.',
  `paystack_ref` VARCHAR(100) DEFAULT NULL UNIQUE COMMENT 'Payment gateway transaction reference',
  `paystack_auth_url` VARCHAR(500) DEFAULT NULL,
  `amount` DECIMAL(10,2) NOT NULL COMMENT 'Total in smallest currency unit',
  `currency_code` VARCHAR(3) NOT NULL DEFAULT 'NGN',
  `status` ENUM('PENDING', 'INITIALIZED', 'PAID', 'FAILED', 'REFUNDED') NOT NULL DEFAULT 'PENDING',
  `customer_email` VARCHAR(255) NOT NULL,
  `customer_name` VARCHAR(255) DEFAULT NULL,
  `vendor_email` VARCHAR(255) DEFAULT NULL,
  `vendor_name` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `paid_at` TIMESTAMP NULL DEFAULT NULL,
  INDEX `idx_order_id` (`order_id`),
  INDEX `idx_country_id` (`country_id`),
  INDEX `idx_paystack_ref` (`paystack_ref`),
  INDEX `idx_status` (`status`),
  FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`country_id`) REFERENCES `countries` (`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`currency_code`) REFERENCES `currencies` (`code`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- (Keep payment_breakdowns table but add currency support)
CREATE TABLE IF NOT EXISTS `payment_breakdowns` (
  `id` CHAR(36) NOT NULL PRIMARY KEY,
  `order_id` CHAR(36) NOT NULL UNIQUE,
  `currency_code` CHAR(3) NOT NULL,
  `total` DECIMAL(10,2) NOT NULL COMMENT 'in smallest currency unit',
  `subtotal` DECIMAL(10,2) NOT NULL COMMENT 'in smallest currency unit',
  `service_charge` DECIMAL(10,2) NOT NULL COMMENT 'in smallest currency unit',
  `vat_amount` DECIMAL(10,2) NOT NULL COMMENT 'in smallest currency unit',
  `vendor_initial_pct` DECIMAL(5,2) NOT NULL,
  `insurance_pct` DECIMAL(5,2) NOT NULL,
  `commission_pct` DECIMAL(5,2) NOT NULL,
  `vat_pct` DECIMAL(5,2) NOT NULL,
  `vendor_initial_amount` DECIMAL(10,2) NOT NULL COMMENT 'in smallest currency unit',
  `insurance_amount` DECIMAL(10,2) NOT NULL COMMENT 'in smallest currency unit',
  `commission_amount` DECIMAL(10,2) NOT NULL COMMENT 'in smallest currency unit',
  `vendor_balance_amount` DECIMAL(10,2) NOT NULL COMMENT 'Escrow balance in smallest currency unit',
  `payment_method_type` VARCHAR(20) NOT NULL COMMENT 'split or individual',
  `vendor_initial_paid` BOOLEAN NOT NULL DEFAULT FALSE,
  `insurance_paid` BOOLEAN NOT NULL DEFAULT FALSE,
  `balance_paid` BOOLEAN NOT NULL DEFAULT FALSE,
  `individual_transfers_processed` BOOLEAN NOT NULL DEFAULT FALSE,
  `individual_transfer_method` VARCHAR(20) DEFAULT NULL COMMENT 'single or bulk',
  `individual_transfer_refs` JSON DEFAULT NULL COMMENT 'Store transfer references',
  `insurance_subaccount` VARCHAR(100) DEFAULT NULL,
  `snapshot` JSON DEFAULT NULL COMMENT 'Complete breakdown snapshot',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_order_id` (`order_id`),
  INDEX `idx_currency_code` (`currency_code`),
  FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`currency_code`) REFERENCES `currencies` (`code`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- INSERT DEFAULT DATA
-- ============================================

-- Insert African Countries (Common ones)
INSERT INTO `countries` (`id`, `name`, `iso3`, `currency_code`, `phone_code`, `status`, `payment_gateway`) VALUES
('NG', 'Nigeria', 'NGA', 'NGN', '+234', 'ACTIVE', 'Paystack'),
('GH', 'Ghana', 'GHA', 'GHS', '+233', 'INACTIVE', 'Paystack'),
('KE', 'Kenya', 'KEN', 'KES', '+254', 'INACTIVE', 'Flutterwave'),
('ZA', 'South Africa', 'ZAF', 'ZAR', '+27', 'INACTIVE', 'Paystack'),
('EG', 'Egypt', 'EGY', 'EGP', '+20', 'INACTIVE', 'Paystack'),
('TZ', 'Tanzania', 'TZA', 'TZS', '+255', 'INACTIVE', NULL),
('UG', 'Uganda', 'UGA', 'UGX', '+256', 'INACTIVE', NULL),
('RW', 'Rwanda', 'RWA', 'RWF', '+250', 'INACTIVE', NULL)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- Insert Currencies
INSERT INTO `currencies` (`code`, `name`, `symbol`, `symbol_native`, `decimal_digits`) VALUES
('NGN', 'Nigerian Naira', '₦', '₦', 2),
('GHS', 'Ghanaian Cedi', 'GH₵', 'GH₵', 2),
('KES', 'Kenyan Shilling', 'KSh', 'KSh', 2),
('ZAR', 'South African Rand', 'R', 'R', 2),
('EGP', 'Egyptian Pound', 'E£', 'ج.م', 2),
('TZS', 'Tanzanian Shilling', 'TSh', 'TSh', 2),
('UGX', 'Ugandan Shilling', 'USh', 'USh', 0),
('RWF', 'Rwandan Franc', 'RF', 'RF', 0),
('USD', 'US Dollar', '$', '$', 2),
('EUR', 'Euro', '€', '€', 2)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- Insert Nigeria States (Sample)
INSERT INTO `states` (`id`, `country_id`, `name`, `code`) VALUES
(UUID(), 'NG', 'Lagos', 'LA'),
(UUID(), 'NG', 'Abuja', 'FCT'),
(UUID(), 'NG', 'Kano', 'KN'),
(UUID(), 'NG', 'Rivers', 'RI'),
(UUID(), 'NG', 'Ogun', 'OG')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- Insert Lagos Cities (Sample)
INSERT INTO `cities` (`id`, `state_id`, `country_id`, `name`, `latitude`, `longitude`)
SELECT 
    UUID(),
    s.id,
    'NG',
    city_name,
    lat,
    lng
FROM (
    SELECT 'Lagos Island' as city_name, 6.4541 as lat, 3.3947 as lng
    UNION SELECT 'Victoria Island', 6.4281, 3.4219
    UNION SELECT 'Ikeja', 6.5244, 3.3792
    UNION SELECT 'Surulere', 6.5010, 3.3581
    UNION SELECT 'Ajao Estate', 6.5361, 3.3169
) cities
CROSS JOIN (SELECT id FROM states WHERE country_id = 'NG' AND name = 'Lagos' LIMIT 1) s;

SET FOREIGN_KEY_CHECKS = 1;
