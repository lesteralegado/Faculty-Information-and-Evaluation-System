-- Migration: Create section_subject_teacher_assignments table
-- This table handles the assignment of teachers to specific subjects within sections
-- Supports multiple teachers per subject (primary + assistants)

CREATE TABLE IF NOT EXISTS `section_subject_teacher_assignments` (
  `assignment_id` int(11) NOT NULL AUTO_INCREMENT,
  `section_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `school_year` varchar(9) NOT NULL,
  `semester` tinyint(1) NOT NULL,
  `role` enum('primary','assistant') NOT NULL DEFAULT 'primary',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`assignment_id`),
  UNIQUE KEY `uniq_section_subject_teacher` (`section_id`,`subject_id`,`teacher_id`,`school_year`,`semester`),
  KEY `idx_section_term` (`section_id`,`school_year`,`semester`),
  KEY `idx_subject` (`subject_id`),
  KEY `idx_teacher` (`teacher_id`),
  KEY `idx_school_year_semester` (`school_year`,`semester`),
  KEY `idx_role_primary` (`role`,`school_year`,`semester`),
  CONSTRAINT `fk_section_subject_teacher_section` FOREIGN KEY (`section_id`) REFERENCES `sections` (`section_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_section_subject_teacher_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`subject_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_section_subject_teacher_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`teacher_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Manages assignment of teachers to subjects within sections per school year and semester';
