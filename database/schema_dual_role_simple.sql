-- Allow users to be both vendors and customers
-- Simple version - run each ALTER TABLE separately

-- Add dual role flags
ALTER TABLE `users`
ADD COLUMN `is_vendor` BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'User can provide services as vendor',
ADD COLUMN `is_customer` BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'User can place orders as customer',
ADD COLUMN `vendor_verified` BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Vendor account verification status',
ADD COLUMN `vendor_verified_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'Vendor verification timestamp';

-- Add business_name and description if they don't exist
ALTER TABLE `users`
ADD COLUMN `business_name` VARCHAR(255) DEFAULT NULL COMMENT 'Business name for vendors',
ADD COLUMN `description` TEXT DEFAULT NULL COMMENT 'Business description for vendors';

-- Update existing vendors
UPDATE `users` 
SET `is_vendor` = TRUE, `is_customer` = TRUE 
WHERE `role` = 'VENDOR';

-- Update existing customers
UPDATE `users` 
SET `is_customer` = TRUE 
WHERE `role` = 'CUSTOMER';

-- Update existing admins
UPDATE `users` 
SET `is_vendor` = TRUE, `is_customer` = TRUE 
WHERE `role` = 'ADMIN';

-- Add indexes for performance
ALTER TABLE `users`
ADD INDEX `idx_is_vendor` (`is_vendor`),
ADD INDEX `idx_is_customer` (`is_customer`),
ADD INDEX `idx_vendor_verified` (`vendor_verified`);

-- Note: If columns already exist, you'll get an error. That's okay - just skip those lines.
-- The `role` column is kept for backward compatibility and primary role indication.
