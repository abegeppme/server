-- AbegEppMe Service Marketplace Database Schema
-- MySQL/MariaDB Compatible
-- Version: 1.0.0

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- ============================================
-- DATABASE SETUP
-- ============================================

CREATE DATABASE IF NOT EXISTS `abegeppme` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `abegeppme`;

-- ============================================
-- USER MANAGEMENT
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
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_email` (`email`),
  INDEX `idx_role` (`role`),
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- SERVICE PROVIDER / VENDOR
-- ============================================

CREATE TABLE IF NOT EXISTS `subaccounts` (
  `id` CHAR(36) NOT NULL PRIMARY KEY,
  `user_id` CHAR(36) NOT NULL UNIQUE,
  `subaccount_code` VARCHAR(100) NOT NULL UNIQUE COMMENT 'Paystack subaccount code',
  `account_number` VARCHAR(20) DEFAULT NULL,
  `account_name` VARCHAR(255) DEFAULT NULL,
  `bank_code` VARCHAR(10) DEFAULT NULL,
  `transfer_recipient` VARCHAR(100) DEFAULT NULL COMMENT 'Paystack transfer recipient code',
  `status` VARCHAR(20) NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_subaccount_code` (`subaccount_code`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- SERVICES
-- ============================================

CREATE TABLE IF NOT EXISTS `services` (
  `id` CHAR(36) NOT NULL PRIMARY KEY,
  `vendor_id` CHAR(36) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `price` DECIMAL(10,2) NOT NULL,
  `category` VARCHAR(100) DEFAULT NULL,
  `images` JSON DEFAULT NULL COMMENT 'Array of image URLs',
  `gallery` JSON DEFAULT NULL COMMENT 'Additional gallery images',
  `status` ENUM('DRAFT', 'ACTIVE', 'INACTIVE', 'ARCHIVED') NOT NULL DEFAULT 'DRAFT',
  `featured` BOOLEAN NOT NULL DEFAULT FALSE,
  `rating` DECIMAL(3,2) DEFAULT NULL,
  `review_count` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_vendor_id` (`vendor_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_category` (`category`),
  INDEX `idx_featured` (`featured`),
  FOREIGN KEY (`vendor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- ORDERS
-- ============================================

CREATE TABLE IF NOT EXISTS `orders` (
  `id` CHAR(36) NOT NULL PRIMARY KEY,
  `order_number` VARCHAR(50) NOT NULL UNIQUE COMMENT 'Human-readable order number',
  `customer_id` CHAR(36) NOT NULL,
  `vendor_id` CHAR(36) NOT NULL,
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
  INDEX `idx_status` (`status`),
  INDEX `idx_order_number` (`order_number`),
  INDEX `idx_created_at` (`created_at`),
  FOREIGN KEY (`customer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`vendor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `order_items` (
  `id` CHAR(36) NOT NULL PRIMARY KEY,
  `order_id` CHAR(36) NOT NULL,
  `service_id` CHAR(36) NOT NULL,
  `quantity` INT NOT NULL DEFAULT 1,
  `price` DECIMAL(10,2) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_order_id` (`order_id`),
  INDEX `idx_service_id` (`service_id`),
  FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- PAYMENTS
-- ============================================

CREATE TABLE IF NOT EXISTS `payments` (
  `id` CHAR(36) NOT NULL PRIMARY KEY,
  `order_id` CHAR(36) NOT NULL UNIQUE,
  `paystack_ref` VARCHAR(100) NOT NULL UNIQUE COMMENT 'Paystack transaction reference',
  `paystack_auth_url` VARCHAR(500) DEFAULT NULL,
  `amount` DECIMAL(10,2) NOT NULL COMMENT 'Total in kobo (stored as decimal)',
  `currency` VARCHAR(3) NOT NULL DEFAULT 'NGN',
  `status` ENUM('PENDING', 'INITIALIZED', 'PAID', 'FAILED', 'REFUNDED') NOT NULL DEFAULT 'PENDING',
  `customer_email` VARCHAR(255) NOT NULL,
  `customer_name` VARCHAR(255) DEFAULT NULL,
  `vendor_email` VARCHAR(255) DEFAULT NULL,
  `vendor_name` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `paid_at` TIMESTAMP NULL DEFAULT NULL,
  INDEX `idx_order_id` (`order_id`),
  INDEX `idx_paystack_ref` (`paystack_ref`),
  INDEX `idx_status` (`status`),
  FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payment_breakdowns` (
  `id` CHAR(36) NOT NULL PRIMARY KEY,
  `order_id` CHAR(36) NOT NULL UNIQUE,
  `total` DECIMAL(10,2) NOT NULL COMMENT 'in kobo',
  `subtotal` DECIMAL(10,2) NOT NULL COMMENT 'in kobo',
  `service_charge` DECIMAL(10,2) NOT NULL COMMENT 'in kobo',
  `vat_amount` DECIMAL(10,2) NOT NULL COMMENT 'in kobo',
  `vendor_initial_pct` DECIMAL(5,2) NOT NULL,
  `insurance_pct` DECIMAL(5,2) NOT NULL,
  `commission_pct` DECIMAL(5,2) NOT NULL,
  `vat_pct` DECIMAL(5,2) NOT NULL,
  `vendor_initial_amount` DECIMAL(10,2) NOT NULL COMMENT 'in kobo',
  `insurance_amount` DECIMAL(10,2) NOT NULL COMMENT 'in kobo',
  `commission_amount` DECIMAL(10,2) NOT NULL COMMENT 'in kobo',
  `vendor_balance_amount` DECIMAL(10,2) NOT NULL COMMENT 'Escrow balance in kobo',
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
  FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TRANSFERS
-- ============================================

CREATE TABLE IF NOT EXISTS `transfers` (
  `id` CHAR(36) NOT NULL PRIMARY KEY,
  `order_id` CHAR(36) NOT NULL,
  `type` ENUM('VENDOR_INITIAL', 'INSURANCE', 'VENDOR_BALANCE', 'REFUND') NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL COMMENT 'in kobo',
  `recipient` VARCHAR(100) NOT NULL COMMENT 'Paystack recipient code or subaccount',
  `paystack_ref` VARCHAR(100) DEFAULT NULL UNIQUE COMMENT 'Paystack transfer reference',
  `paystack_response` JSON DEFAULT NULL COMMENT 'Full Paystack response',
  `status` ENUM('PENDING', 'PROCESSING', 'SUCCESS', 'FAILED') NOT NULL DEFAULT 'PENDING',
  `reason` VARCHAR(500) DEFAULT NULL,
  `failure_reason` VARCHAR(500) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `processed_at` TIMESTAMP NULL DEFAULT NULL,
  INDEX `idx_order_id` (`order_id`),
  INDEX `idx_paystack_ref` (`paystack_ref`),
  INDEX `idx_status` (`status`),
  INDEX `idx_type` (`type`),
  FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- CHAT / MESSAGING
-- ============================================

CREATE TABLE IF NOT EXISTS `conversations` (
  `id` CHAR(36) NOT NULL PRIMARY KEY,
  `user1_id` CHAR(36) NOT NULL,
  `user2_id` CHAR(36) NOT NULL,
  `last_message_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_conversation` (`user1_id`, `user2_id`),
  INDEX `idx_user1_id` (`user1_id`),
  INDEX `idx_user2_id` (`user2_id`),
  INDEX `idx_last_message_at` (`last_message_at`),
  FOREIGN KEY (`user1_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user2_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `messages` (
  `id` CHAR(36) NOT NULL PRIMARY KEY,
  `conversation_id` CHAR(36) NOT NULL,
  `from_id` CHAR(36) NOT NULL,
  `kind` ENUM('MESSAGE', 'PHOTO', 'FILE') NOT NULL DEFAULT 'MESSAGE',
  `content` TEXT NOT NULL,
  `file_url` VARCHAR(500) DEFAULT NULL COMMENT 'For photo/file messages',
  `delivered` BOOLEAN NOT NULL DEFAULT FALSE,
  `read` BOOLEAN NOT NULL DEFAULT FALSE,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_conversation_id` (`conversation_id`),
  INDEX `idx_from_id` (`from_id`),
  INDEX `idx_created_at` (`created_at`),
  FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`from_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- DISPUTES
-- ============================================

CREATE TABLE IF NOT EXISTS `disputes` (
  `id` CHAR(36) NOT NULL PRIMARY KEY,
  `order_id` CHAR(36) NOT NULL UNIQUE,
  `customer_id` CHAR(36) NOT NULL,
  `vendor_id` CHAR(36) NOT NULL,
  `reason` TEXT NOT NULL,
  `description` TEXT DEFAULT NULL,
  `evidence` JSON DEFAULT NULL COMMENT 'Array of evidence URLs',
  `status` ENUM('PENDING', 'UNDER_REVIEW', 'RESOLVED', 'DISMISSED') NOT NULL DEFAULT 'PENDING',
  `resolution` ENUM('REFUND_CUSTOMER', 'PAY_VENDOR', 'PARTIAL_SETTLEMENT', 'NO_ACTION') DEFAULT NULL,
  `resolution_note` TEXT DEFAULT NULL,
  `resolved_by` CHAR(36) DEFAULT NULL COMMENT 'Admin user ID',
  `resolved_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_order_id` (`order_id`),
  INDEX `idx_customer_id` (`customer_id`),
  INDEX `idx_vendor_id` (`vendor_id`),
  INDEX `idx_status` (`status`),
  FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`customer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`vendor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- NOTIFICATIONS
-- ============================================

CREATE TABLE IF NOT EXISTS `notifications` (
  `id` CHAR(36) NOT NULL PRIMARY KEY,
  `user_id` CHAR(36) NOT NULL,
  `type` ENUM('ORDER_CREATED', 'ORDER_UPDATED', 'PAYMENT_RECEIVED', 'SERVICE_COMPLETE', 'DISPUTE_RAISED', 'DISPUTE_RESOLVED', 'MESSAGE_RECEIVED', 'TRANSFER_SUCCESS', 'TRANSFER_FAILED') NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `message` TEXT NOT NULL,
  `data` JSON DEFAULT NULL COMMENT 'Additional data',
  `read` BOOLEAN NOT NULL DEFAULT FALSE,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_read` (`read`),
  INDEX `idx_created_at` (`created_at`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TRANSACTION LOGS
-- ============================================

CREATE TABLE IF NOT EXISTS `transaction_logs` (
  `id` CHAR(36) NOT NULL PRIMARY KEY,
  `order_id` CHAR(36) DEFAULT NULL,
  `user_id` CHAR(36) DEFAULT NULL,
  `transaction_ref` VARCHAR(100) DEFAULT NULL COMMENT 'Paystack or other transaction reference',
  `event_type` VARCHAR(50) NOT NULL COMMENT 'payment_success, transfer_initiated, etc.',
  `request_payload` JSON DEFAULT NULL COMMENT 'Request data',
  `response_payload` JSON DEFAULT NULL COMMENT 'Response data',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_order_id` (`order_id`),
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_transaction_ref` (`transaction_ref`),
  INDEX `idx_event_type` (`event_type`),
  INDEX `idx_created_at` (`created_at`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- SECURITY & MONITORING
-- ============================================

CREATE TABLE IF NOT EXISTS `suspicious_activity` (
  `id` CHAR(36) NOT NULL PRIMARY KEY,
  `customer_id` CHAR(36) DEFAULT NULL,
  `vendor_id` CHAR(36) DEFAULT NULL,
  `reason` ENUM('MULTIPLE_TRANSACTIONS', 'EXCESSIVE_CANCELLATIONS', 'UNUSUAL_PATTERNS') NOT NULL,
  `count` INT NOT NULL DEFAULT 1,
  `details` JSON DEFAULT NULL COMMENT 'Additional details',
  `status` ENUM('PENDING_REVIEW', 'REVIEWED', 'DISMISSED') NOT NULL DEFAULT 'PENDING_REVIEW',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `reviewed_at` TIMESTAMP NULL DEFAULT NULL,
  `reviewed_by` CHAR(36) DEFAULT NULL COMMENT 'Admin user ID',
  INDEX `idx_customer_id` (`customer_id`),
  INDEX `idx_vendor_id` (`vendor_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_created_at` (`created_at`),
  FOREIGN KEY (`customer_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  FOREIGN KEY (`vendor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
