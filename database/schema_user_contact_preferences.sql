-- User contact preferences + mailing list consent storage
CREATE TABLE IF NOT EXISTS `user_contact_preferences` (
  `id` CHAR(36) NOT NULL PRIMARY KEY,
  `user_id` CHAR(36) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `name` VARCHAR(255) NULL,
  `username` VARCHAR(255) NULL,
  `terms_accepted_at` DATETIME NULL,
  `mailing_list_opt_in` TINYINT(1) NOT NULL DEFAULT 0,
  `source` VARCHAR(64) NOT NULL DEFAULT 'signup',
  `mailchimp_synced_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_ucp_user_id` (`user_id`),
  INDEX `idx_ucp_email` (`email`),
  INDEX `idx_ucp_mailing` (`mailing_list_opt_in`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
