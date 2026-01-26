-- Fix service_categories table to allow same name under different parents
-- Remove UNIQUE constraint on name, add UNIQUE on (name, parent_id)

-- Check and drop existing unique constraint on name
SET @dbname = DATABASE();
SET @tablename = 'service_categories';
SET @indexname = 'name';

-- Drop unique constraint on name if it exists
SET @sql = CONCAT('ALTER TABLE `', @tablename, '` DROP INDEX `', @indexname, '`');
SET @prepared = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
     WHERE table_schema = @dbname 
     AND table_name = @tablename 
     AND index_name = @indexname) > 0,
    @sql,
    'SELECT ''Index name does not exist, skipping'' AS message'
));
PREPARE stmt FROM @prepared;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add unique constraint on (name, parent_id) combination
-- This allows same name under different parents, but prevents duplicates within same parent
-- Check if constraint already exists first
SET @constraint_name = 'unique_name_parent';
SET @sql2 = CONCAT('ALTER TABLE `', @tablename, '` ADD UNIQUE KEY `', @constraint_name, '` (`name`, `parent_id`), ADD INDEX `idx_name_parent` (`name`, `parent_id`)');
SET @prepared2 = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
     WHERE table_schema = @dbname 
     AND table_name = @tablename 
     AND index_name = @constraint_name) = 0,
    @sql2,
    'SELECT ''Constraint unique_name_parent already exists, skipping'' AS message'
));
PREPARE stmt2 FROM @prepared2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;
