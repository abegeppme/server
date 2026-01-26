-- Update Services Table - Remove Required Price
-- Services are showcases, prices are negotiated per order

-- Make price optional (can be NULL for "Price on Request" or starting price)
ALTER TABLE `services` 
MODIFY COLUMN `price` DECIMAL(10,2) NULL COMMENT 'Optional starting price or price on request';

-- Add price_range field for services that want to show a range
ALTER TABLE `services` 
ADD COLUMN `price_range_min` DECIMAL(10,2) NULL COMMENT 'Minimum price in range',
ADD COLUMN `price_range_max` DECIMAL(10,2) NULL COMMENT 'Maximum price in range',
ADD COLUMN `price_type` ENUM('FIXED', 'RANGE', 'NEGOTIABLE', 'ON_REQUEST') DEFAULT 'NEGOTIABLE' COMMENT 'How pricing works for this service';

-- For multi-country schema, also update currency_code to be nullable (if column exists)
-- Check if currency_code exists before modifying
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'services' 
    AND COLUMN_NAME = 'currency_code');

SET @sql = IF(@col_exists > 0,
    'ALTER TABLE `services` MODIFY COLUMN `currency_code` CHAR(3) NULL COMMENT ''Service price currency (if price is set)'';',
    'SELECT ''currency_code column does not exist, skipping'' AS message;');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
