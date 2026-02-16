-- Allow users to be both vendors and customers
-- This changes the role system to support multiple roles

-- Step 1: Add is_vendor flag (allows users to be both customer and vendor)
ALTER TABLE `users`
ADD COLUMN IF NOT EXISTS `is_vendor` BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'User can provide services as vendor',
ADD COLUMN IF NOT EXISTS `is_customer` BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'User can place orders as customer',
ADD COLUMN IF NOT EXISTS `vendor_verified` BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Vendor account verification status',
ADD COLUMN IF NOT EXISTS `vendor_verified_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'Vendor verification timestamp';

-- Step 2: Update existing vendors
UPDATE `users` 
SET `is_vendor` = TRUE, `is_customer` = TRUE 
WHERE `role` = 'VENDOR';

-- Step 3: Update existing customers (ensure they're marked as customers)
UPDATE `users` 
SET `is_customer` = TRUE 
WHERE `role` = 'CUSTOMER';

-- Step 4: Update existing admins (they can be both)
UPDATE `users` 
SET `is_vendor` = TRUE, `is_customer` = TRUE 
WHERE `role` = 'ADMIN';

-- Step 5: Add indexes for performance
ALTER TABLE `users`
ADD INDEX IF NOT EXISTS `idx_is_vendor` (`is_vendor`),
ADD INDEX IF NOT EXISTS `idx_is_customer` (`is_customer`),
ADD INDEX IF NOT EXISTS `idx_vendor_verified` (`vendor_verified`);

-- Note: The `role` column is kept for backward compatibility and primary role indication
-- But the system now uses `is_vendor` and `is_customer` flags for capabilities
