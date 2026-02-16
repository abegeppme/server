-- 48-Hour Hold Period Schema Updates
-- Adds columns to track customer confirmation and 48-hour hold period

ALTER TABLE `orders`
ADD COLUMN IF NOT EXISTS `customer_confirmed_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'When customer confirmed service completion',
ADD COLUMN IF NOT EXISTS `payout_release_date` TIMESTAMP NULL DEFAULT NULL COMMENT 'When vendor balance should be released (48 hours after confirmation)',
ADD COLUMN IF NOT EXISTS `hold_period_completed` BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Whether 48-hour hold period has been completed',
ADD COLUMN IF NOT EXISTS `auto_released` BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Whether payment was auto-released after 48 hours';

-- Index for efficient querying of orders awaiting release
CREATE INDEX IF NOT EXISTS `idx_payout_release_date` ON `orders` (`payout_release_date`, `hold_period_completed`);

-- 7-Day Auto-Release for vendor protection
ALTER TABLE `orders`
ADD COLUMN IF NOT EXISTS `vendor_completed_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'When vendor marked service as complete',
ADD COLUMN IF NOT EXISTS `auto_release_date` TIMESTAMP NULL DEFAULT NULL COMMENT 'When order should be auto-released if customer doesn't respond (7 days after vendor completion)',
ADD COLUMN IF NOT EXISTS `auto_release_triggered` BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Whether 7-day auto-release was triggered';

-- Index for 7-day auto-release queries
CREATE INDEX IF NOT EXISTS `idx_auto_release_date` ON `orders` (`auto_release_date`, `auto_release_triggered`, `status`);
