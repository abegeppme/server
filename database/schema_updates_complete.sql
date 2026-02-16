-- ============================================
-- Complete Database Updates
-- Location + Address + Dual Role System
-- ============================================
-- This script safely adds columns only if they don't exist
-- Run this file to add all new features

-- ============================================
-- PART 1: LOCATION & ADDRESS COLUMNS
-- ============================================

-- Add latitude (only if it doesn't exist)
SET @dbname = DATABASE();
SET @tablename = 'users';
SET @columnname = 'latitude';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (COLUMN_NAME = @columnname)
  ) > 0,
  'SELECT "Column latitude already exists" AS message',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN `', @columnname, '` DECIMAL(10, 8) DEFAULT NULL COMMENT ''User latitude for location-based search''')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add longitude (only if it doesn't exist)
SET @columnname = 'longitude';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (COLUMN_NAME = @columnname)
  ) > 0,
  'SELECT "Column longitude already exists" AS message',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN `', @columnname, '` DECIMAL(11, 8) DEFAULT NULL COMMENT ''User longitude for location-based search''')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add address (only if it doesn't exist)
SET @columnname = 'address';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (COLUMN_NAME = @columnname)
  ) > 0,
  'SELECT "Column address already exists" AS message',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN `', @columnname, '` TEXT DEFAULT NULL COMMENT ''Full address string''')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add address_line1 (only if it doesn't exist)
SET @columnname = 'address_line1';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (COLUMN_NAME = @columnname)
  ) > 0,
  'SELECT "Column address_line1 already exists" AS message',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN `', @columnname, '` VARCHAR(255) DEFAULT NULL COMMENT ''Street address line 1''')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add address_line2 (only if it doesn't exist)
SET @columnname = 'address_line2';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (COLUMN_NAME = @columnname)
  ) > 0,
  'SELECT "Column address_line2 already exists" AS message',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN `', @columnname, '` VARCHAR(255) DEFAULT NULL COMMENT ''Street address line 2''')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add city (only if it doesn't exist)
SET @columnname = 'city';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (COLUMN_NAME = @columnname)
  ) > 0,
  'SELECT "Column city already exists" AS message',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN `', @columnname, '` VARCHAR(100) DEFAULT NULL COMMENT ''City name''')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add postal_code (only if it doesn't exist)
SET @columnname = 'postal_code';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (COLUMN_NAME = @columnname)
  ) > 0,
  'SELECT "Column postal_code already exists" AS message',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN `', @columnname, '` VARCHAR(20) DEFAULT NULL COMMENT ''Postal/ZIP code''')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add location index (only if it doesn't exist)
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (INDEX_NAME = 'idx_location')
  ) > 0,
  'SELECT "Index idx_location already exists" AS message',
  CONCAT('ALTER TABLE ', @tablename, ' ADD INDEX `idx_location` (`latitude`, `longitude`)')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- ============================================
-- PART 2: DUAL ROLE SYSTEM
-- ============================================

-- Add is_vendor (only if it doesn't exist)
SET @columnname = 'is_vendor';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (COLUMN_NAME = @columnname)
  ) > 0,
  'SELECT "Column is_vendor already exists" AS message',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN `', @columnname, '` BOOLEAN NOT NULL DEFAULT FALSE COMMENT ''User can provide services as vendor''')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add is_customer (only if it doesn't exist)
SET @columnname = 'is_customer';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (COLUMN_NAME = @columnname)
  ) > 0,
  'SELECT "Column is_customer already exists" AS message',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN `', @columnname, '` BOOLEAN NOT NULL DEFAULT TRUE COMMENT ''User can place orders as customer''')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add vendor_verified (only if it doesn't exist)
SET @columnname = 'vendor_verified';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (COLUMN_NAME = @columnname)
  ) > 0,
  'SELECT "Column vendor_verified already exists" AS message',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN `', @columnname, '` BOOLEAN NOT NULL DEFAULT FALSE COMMENT ''Vendor account verification status''')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add vendor_verified_at (only if it doesn't exist)
SET @columnname = 'vendor_verified_at';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (COLUMN_NAME = @columnname)
  ) > 0,
  'SELECT "Column vendor_verified_at already exists" AS message',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN `', @columnname, '` TIMESTAMP NULL DEFAULT NULL COMMENT ''Vendor verification timestamp''')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add business_name (only if it doesn't exist)
SET @columnname = 'business_name';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (COLUMN_NAME = @columnname)
  ) > 0,
  'SELECT "Column business_name already exists" AS message',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN `', @columnname, '` VARCHAR(255) DEFAULT NULL COMMENT ''Business name for vendors''')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add description (only if it doesn't exist)
SET @columnname = 'description';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (COLUMN_NAME = @columnname)
  ) > 0,
  'SELECT "Column description already exists" AS message',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN `', @columnname, '` TEXT DEFAULT NULL COMMENT ''Business description for vendors''')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- ============================================
-- PART 3: UPDATE EXISTING DATA
-- ============================================

-- Update existing vendors to have both flags (only if is_vendor column exists)
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (COLUMN_NAME = 'is_vendor')
  ) > 0,
  'UPDATE `users` SET `is_vendor` = TRUE, `is_customer` = TRUE WHERE `role` = ''VENDOR'' AND (`is_vendor` IS NULL OR `is_vendor` = FALSE)',
  'SELECT "Skipping vendor update - is_vendor column does not exist" AS message'
));
PREPARE updateIfExists FROM @preparedStatement;
EXECUTE updateIfExists;
DEALLOCATE PREPARE updateIfExists;

-- Update existing customers (only if is_customer column exists)
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (COLUMN_NAME = 'is_customer')
  ) > 0,
  'UPDATE `users` SET `is_customer` = TRUE WHERE `role` = ''CUSTOMER'' AND (`is_customer` IS NULL OR `is_customer` = FALSE)',
  'SELECT "Skipping customer update - is_customer column does not exist" AS message'
));
PREPARE updateIfExists FROM @preparedStatement;
EXECUTE updateIfExists;
DEALLOCATE PREPARE updateIfExists;

-- Update existing admins (only if is_vendor column exists)
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (COLUMN_NAME = 'is_vendor')
  ) > 0,
  'UPDATE `users` SET `is_vendor` = TRUE, `is_customer` = TRUE WHERE `role` = ''ADMIN'' AND (`is_vendor` IS NULL OR `is_vendor` = FALSE)',
  'SELECT "Skipping admin update - is_vendor column does not exist" AS message'
));
PREPARE updateIfExists FROM @preparedStatement;
EXECUTE updateIfExists;
DEALLOCATE PREPARE updateIfExists;

-- ============================================
-- PART 4: ADD INDEXES FOR PERFORMANCE
-- ============================================

-- Add idx_is_vendor index (only if it doesn't exist)
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (INDEX_NAME = 'idx_is_vendor')
  ) > 0,
  'SELECT "Index idx_is_vendor already exists" AS message',
  CONCAT('ALTER TABLE ', @tablename, ' ADD INDEX `idx_is_vendor` (`is_vendor`)')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add idx_is_customer index (only if it doesn't exist)
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (INDEX_NAME = 'idx_is_customer')
  ) > 0,
  'SELECT "Index idx_is_customer already exists" AS message',
  CONCAT('ALTER TABLE ', @tablename, ' ADD INDEX `idx_is_customer` (`is_customer`)')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add idx_vendor_verified index (only if it doesn't exist)
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (INDEX_NAME = 'idx_vendor_verified')
  ) > 0,
  'SELECT "Index idx_vendor_verified already exists" AS message',
  CONCAT('ALTER TABLE ', @tablename, ' ADD INDEX `idx_vendor_verified` (`vendor_verified`)')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- ============================================
-- COMPLETE!
-- ============================================
-- All updates applied successfully
-- Users can now be both vendors and customers
-- Address and location support added
-- The `role` column is kept for backward compatibility

SELECT 'Schema updates completed successfully!' AS message;
