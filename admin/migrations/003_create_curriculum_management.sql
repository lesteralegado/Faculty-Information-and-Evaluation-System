-- Migration: Create Curriculum Management Tables
-- Version: 1.0
-- Description: Implements comprehensive curriculum management system with version control

-- Main Curricula Table
CREATE TABLE IF NOT EXISTS `curricula` (
  `curriculum_id` INT(11) NOT NULL AUTO_INCREMENT,
  `curriculum_code` VARCHAR(50) NOT NULL UNIQUE,
  `title` VARCHAR(255) NOT NULL,
  `description` LONGTEXT,
  `subject_id` INT(11),
  `school_year` VARCHAR(9),
  `semester` TINYINT(1),
  `version` VARCHAR(10) NOT NULL DEFAULT '1.0',
  `status` ENUM('draft', 'active', 'archived') NOT NULL DEFAULT 'draft',
  `created_by` INT(11),
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `parent_curriculum_id` INT(11),
  PRIMARY KEY (`curriculum_id`),
  UNIQUE KEY `uniq_curriculum_code` (`curriculum_code`),
  KEY `idx_subject_id` (`subject_id`),
  KEY `idx_school_year_semester` (`school_year`, `semester`),
  KEY `idx_status` (`status`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_parent_curriculum_id` (`parent_curriculum_id`),
  CONSTRAINT `fk_curriculum_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`subject_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_curriculum_parent` FOREIGN KEY (`parent_curriculum_id`) REFERENCES `curricula` (`curriculum_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Main curriculum records with version control';

-- Learning Outcomes/Competencies Table
CREATE TABLE IF NOT EXISTS `curriculum_learning_outcomes` (
  `outcome_id` INT(11) NOT NULL AUTO_INCREMENT,
  `curriculum_id` INT(11) NOT NULL,
  `outcome_code` VARCHAR(50),
  `title` VARCHAR(255) NOT NULL,
  `description` LONGTEXT,
  `learning_domain` ENUM('cognitive', 'affective', 'psychomotor') DEFAULT 'cognitive',
  `bloom_level` ENUM('remember', 'understand', 'apply', 'analyze', 'evaluate', 'create') DEFAULT 'understand',
  `assessment_method` VARCHAR(255),
  `sequence_number` INT(11) DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`outcome_id`),
  KEY `idx_curriculum_id` (`curriculum_id`),
  KEY `idx_learning_domain` (`learning_domain`),
  KEY `idx_bloom_level` (`bloom_level`),
  CONSTRAINT `fk_learning_outcome_curriculum` FOREIGN KEY (`curriculum_id`) REFERENCES `curricula` (`curriculum_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Learning outcomes and competencies per curriculum';

-- Learning Units/Topics Table
CREATE TABLE IF NOT EXISTS `curriculum_units` (
  `unit_id` INT(11) NOT NULL AUTO_INCREMENT,
  `curriculum_id` INT(11) NOT NULL,
  `unit_code` VARCHAR(50),
  `title` VARCHAR(255) NOT NULL,
  `description` LONGTEXT,
  `duration_hours` DECIMAL(5, 2),
  `sequence_number` INT(11) DEFAULT 1,
  `status` ENUM('active', 'inactive') DEFAULT 'active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`unit_id`),
  KEY `idx_curriculum_id` (`curriculum_id`),
  KEY `idx_sequence_number` (`sequence_number`),
  CONSTRAINT `fk_unit_curriculum` FOREIGN KEY (`curriculum_id`) REFERENCES `curricula` (`curriculum_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Learning units and topics within curriculum';

-- Topics within Units
CREATE TABLE IF NOT EXISTS `curriculum_unit_topics` (
  `topic_id` INT(11) NOT NULL AUTO_INCREMENT,
  `unit_id` INT(11) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` LONGTEXT,
  `duration_hours` DECIMAL(5, 2),
  `sequence_number` INT(11) DEFAULT 1,
  `teaching_methods` VARCHAR(500),
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`topic_id`),
  KEY `idx_unit_id` (`unit_id`),
  KEY `idx_sequence_number` (`sequence_number`),
  CONSTRAINT `fk_topic_unit` FOREIGN KEY (`unit_id`) REFERENCES `curriculum_units` (`unit_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Individual topics within curriculum units';

-- Teaching Resources Table
CREATE TABLE IF NOT EXISTS `curriculum_resources` (
  `resource_id` INT(11) NOT NULL AUTO_INCREMENT,
  `curriculum_id` INT(11) NOT NULL,
  `resource_type` ENUM('textbook', 'reference', 'online_resource', 'video', 'worksheet', 'assessment_tool', 'other') DEFAULT 'reference',
  `title` VARCHAR(255) NOT NULL,
  `description` LONGTEXT,
  `author` VARCHAR(255),
  `publisher` VARCHAR(255),
  `url` VARCHAR(500),
  `isbn` VARCHAR(20),
  `publication_year` YEAR,
  `access_level` ENUM('free', 'licensed', 'restricted') DEFAULT 'free',
  `sequence_number` INT(11) DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`resource_id`),
  KEY `idx_curriculum_id` (`curriculum_id`),
  KEY `idx_resource_type` (`resource_type`),
  CONSTRAINT `fk_resource_curriculum` FOREIGN KEY (`curriculum_id`) REFERENCES `curricula` (`curriculum_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Teaching resources and references for curriculum';

-- Assessment Methods Table
CREATE TABLE IF NOT EXISTS `curriculum_assessments` (
  `assessment_id` INT(11) NOT NULL AUTO_INCREMENT,
  `curriculum_id` INT(11) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` LONGTEXT,
  `assessment_type` ENUM('formative', 'summative', 'diagnostic') DEFAULT 'formative',
  `percentage_weight` INT(11) DEFAULT 0,
  `sequence_number` INT(11) DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`assessment_id`),
  KEY `idx_curriculum_id` (`curriculum_id`),
  KEY `idx_assessment_type` (`assessment_type`),
  CONSTRAINT `fk_assessment_curriculum` FOREIGN KEY (`curriculum_id`) REFERENCES `curricula` (`curriculum_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Assessment methods and evaluation criteria';

-- Curriculum Mappings to Learning Outcomes
CREATE TABLE IF NOT EXISTS `curriculum_outcome_mappings` (
  `mapping_id` INT(11) NOT NULL AUTO_INCREMENT,
  `outcome_id` INT(11) NOT NULL,
  `unit_id` INT(11) NOT NULL,
  `assessment_id` INT(11),
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`mapping_id`),
  UNIQUE KEY `uniq_outcome_unit` (`outcome_id`, `unit_id`),
  KEY `idx_outcome_id` (`outcome_id`),
  KEY `idx_unit_id` (`unit_id`),
  KEY `idx_assessment_id` (`assessment_id`),
  CONSTRAINT `fk_mapping_outcome` FOREIGN KEY (`outcome_id`) REFERENCES `curriculum_learning_outcomes` (`outcome_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mapping_unit` FOREIGN KEY (`unit_id`) REFERENCES `curriculum_units` (`unit_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mapping_assessment` FOREIGN KEY (`assessment_id`) REFERENCES `curriculum_assessments` (`assessment_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Maps learning outcomes to units and assessments';

-- Curriculum Templates for reuse
CREATE TABLE IF NOT EXISTS `curriculum_templates` (
  `template_id` INT(11) NOT NULL AUTO_INCREMENT,
  `template_name` VARCHAR(255) NOT NULL,
  `template_code` VARCHAR(50) UNIQUE,
  `description` LONGTEXT,
  `subject_category` VARCHAR(100),
  `template_data` LONGTEXT,
  `is_default` BOOLEAN DEFAULT FALSE,
  `created_by` INT(11),
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`template_id`),
  KEY `idx_subject_category` (`subject_category`),
  KEY `idx_is_default` (`is_default`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Reusable curriculum templates';

-- Curriculum History/Audit Log
CREATE TABLE IF NOT EXISTS `curriculum_audit_log` (
  `log_id` INT(11) NOT NULL AUTO_INCREMENT,
  `curriculum_id` INT(11),
  `action` VARCHAR(50) NOT NULL,
  `details` LONGTEXT,
  `changed_by` INT(11),
  `changed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`log_id`),
  KEY `idx_curriculum_id` (`curriculum_id`),
  KEY `idx_action` (`action`),
  KEY `idx_changed_at` (`changed_at`),
  CONSTRAINT `fk_audit_log_curriculum` FOREIGN KEY (`curriculum_id`) REFERENCES `curricula` (`curriculum_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Audit log for curriculum changes and versions';
