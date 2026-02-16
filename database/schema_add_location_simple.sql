-- Add location and address columns to users table
-- Simple version - run each ALTER TABLE separately

-- Add latitude (if not exists, check manually first)
ALTER TABLE `users`
ADD COLUMN `latitude` DECIMAL(10, 8) DEFAULT NULL COMMENT 'User latitude for location-based search';

-- Add longitude
ALTER TABLE `users`
ADD COLUMN `longitude` DECIMAL(11, 8) DEFAULT NULL COMMENT 'User longitude for location-based search';

-- Add address fields
ALTER TABLE `users`
ADD COLUMN `address` TEXT DEFAULT NULL COMMENT 'Full address string',
ADD COLUMN `address_line1` VARCHAR(255) DEFAULT NULL COMMENT 'Street address line 1',
ADD COLUMN `address_line2` VARCHAR(255) DEFAULT NULL COMMENT 'Street address line 2',
ADD COLUMN `city` VARCHAR(100) DEFAULT NULL COMMENT 'City name',
ADD COLUMN `postal_code` VARCHAR(20) DEFAULT NULL COMMENT 'Postal/ZIP code';

-- Add location index
ALTER TABLE `users`
ADD INDEX `idx_location` (`latitude`, `longitude`);

-- Note: If columns already exist, you'll get an error. That's okay - just skip those lines.
