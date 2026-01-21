-- Manual SQL script to add user_id to bank_accounts table
-- Run this on your production database if you cannot run Laravel migrations

-- Step 1: Add user_id column as nullable
ALTER TABLE `bank_accounts` 
ADD COLUMN `user_id` BIGINT UNSIGNED NULL AFTER `id`,
ADD INDEX `bank_accounts_user_id_index` (`user_id`);

-- Step 2: Assign existing records to the first user (or delete if no users exist)
-- First, check if there are any users
SET @first_user_id = (SELECT id FROM users ORDER BY id LIMIT 1);

-- If users exist, assign bank accounts to first user
-- If no users exist, delete orphaned bank accounts
UPDATE `bank_accounts` SET `user_id` = @first_user_id WHERE `user_id` IS NULL;
-- OR if you want to delete orphaned accounts instead:
-- DELETE FROM `bank_accounts` WHERE `user_id` IS NULL;

-- Step 3: Make user_id non-nullable and add foreign key
ALTER TABLE `bank_accounts` 
MODIFY COLUMN `user_id` BIGINT UNSIGNED NOT NULL,
ADD CONSTRAINT `bank_accounts_user_id_foreign` 
FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

-- Step 4: Update unique constraint to be per user instead of global
-- First, drop the old unique constraint on account_number
ALTER TABLE `bank_accounts` DROP INDEX `bank_accounts_account_number_unique`;

-- Then add the new composite unique constraint
ALTER TABLE `bank_accounts` 
ADD UNIQUE KEY `bank_accounts_user_account_unique` (`user_id`, `account_number`);
