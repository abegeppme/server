-- 48-Hour Hold Period Schema Updates (MySQL Compatible)
-- Run this file in phpMyAdmin or via MySQL command line

-- Add columns for 48-hour hold period
ALTER TABLE `orders`
ADD COLUMN `customer_confirmed_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'When customer confirmed service completion',
ADD COLUMN `payout_release_date` TIMESTAMP NULL DEFAULT NULL COMMENT 'When vendor balance should be released (48 hours after confirmation)',
ADD COLUMN `hold_period_completed` BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Whether 48-hour hold period has been completed',
ADD COLUMN `auto_released` BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Whether payment was auto-released after 48 hours';

-- Add columns for 7-day auto-release
ALTER TABLE `orders`
ADD COLUMN `vendor_completed_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'When vendor marked service as complete',
ADD COLUMN `auto_release_date` TIMESTAMP NULL DEFAULT NULL COMMENT 'When order should be auto-released if customer doesn't respond (7 days after vendor completion)',
ADD COLUMN `auto_release_triggered` BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Whether 7-day auto-release was triggered';

-- Create indexes for efficient querying
CREATE INDEX `idx_payout_release_date` ON `orders` (`payout_release_date`, `hold_period_completed`);
CREATE INDEX `idx_auto_release_date` ON `orders` (`auto_release_date`, `auto_release_triggered`, `status`);
