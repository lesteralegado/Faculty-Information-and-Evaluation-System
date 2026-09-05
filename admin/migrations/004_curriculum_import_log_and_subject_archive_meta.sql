-- Optional manual migration (also applied automatically on Subject Management page load).
-- Import audit log + subject archive metadata for batch rollovers.

CREATE TABLE IF NOT EXISTS `curriculum_import_log` (
  `log_id` INT NOT NULL AUTO_INCREMENT,
  `school_year` VARCHAR(30) NOT NULL,
  `semester` TINYINT(1) NOT NULL,
  `subjects_imported` INT NOT NULL DEFAULT 0,
  `subjects_archived` INT NOT NULL DEFAULT 0,
  `archive_batch_id` VARCHAR(36) NOT NULL,
  `file_name` VARCHAR(255) DEFAULT NULL,
  `imported_by_username` VARCHAR(150) DEFAULT NULL,
  `imported_by_user_id` INT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`log_id`),
  KEY `idx_curriculum_log_sy_sem` (`school_year`, `semester`),
  KEY `idx_curriculum_log_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add only if missing (safe to run repeatedly)
SET @dbname = DATABASE();
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'subjects' AND COLUMN_NAME = 'archived_at') > 0,
  'SELECT 1',
  'ALTER TABLE subjects ADD COLUMN archived_at DATETIME NULL DEFAULT NULL'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'subjects' AND COLUMN_NAME = 'archive_batch_id') > 0,
  'SELECT 1',
  'ALTER TABLE subjects ADD COLUMN archive_batch_id VARCHAR(36) NULL DEFAULT NULL'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;
