-- Simple fix for service_categories - Run this if the complex version fails
-- Allows same category name under different parents

-- Step 1: Drop the unique constraint on name (if it exists)
-- Run this manually if needed:
-- ALTER TABLE `service_categories` DROP INDEX `name`;

-- Step 2: Add unique constraint on (name, parent_id)
ALTER TABLE `service_categories` 
ADD UNIQUE KEY `unique_name_parent` (`name`, `parent_id`),
ADD INDEX `idx_name_parent` (`name`, `parent_id`);
