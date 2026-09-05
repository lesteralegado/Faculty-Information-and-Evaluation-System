<?php
/**
 * One-time migration script:
 * - Creates evaluation_targets table for explicit per-term evaluation assignment
 *
 * Run in browser (admin): http://localhost/capstone/migrate_evaluation_targets.php
 * Or CLI: php migrate_evaluation_targets.php
 */
session_start();

$is_cli = (PHP_SAPI === 'cli');
if (!$is_cli) {
    if (!isset($_SESSION['username']) || ($_SESSION['role'] ?? '') !== 'admin') {
        header("HTTP/1.1 403 Forbidden");
        echo "Forbidden: admin only.";
        exit();
    }
    header('Content-Type: text/plain; charset=utf-8');
}

require_once __DIR__ . '/includes/db_connection.php';

echo "== Evaluation Targets Migration ==\n\n";

$create_sql = "
CREATE TABLE IF NOT EXISTS evaluation_targets (
  evaluation_target_id INT(11) NOT NULL AUTO_INCREMENT,
  school_year VARCHAR(9) NOT NULL,
  semester TINYINT(1) NOT NULL,
  section_id INT(11) NOT NULL,
  subject_id INT(11) NOT NULL,
  teacher_id INT(11) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (evaluation_target_id),
  UNIQUE KEY uniq_target (school_year, semester, section_id, subject_id, teacher_id),
  KEY idx_term_section (school_year, semester, section_id),
  KEY idx_term_teacher (school_year, semester, teacher_id),
  KEY idx_term_subject (school_year, semester, subject_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
";

if ($conn->query($create_sql) === true) {
    echo "✓ Created/verified table: evaluation_targets\n";
} else {
    echo "✗ Failed creating evaluation_targets: {$conn->error}\n";
    exit(1);
}

echo "\nDone.\n";

