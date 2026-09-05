<?php
/**
 * One-time migration script:
 * - Creates subject_teacher_assignments table (multi-teacher per subject, per SY/Sem)
 * - Backfills assignments from subjects.teacher_id for the CURRENT SY/Sem (best-effort)
 *
 * Run in browser: http://localhost/capstone/migrate_subject_teacher_assignments.php
 * Or CLI: php migrate_subject_teacher_assignments.php
 */
session_start();

// Allow CLI, or admin in browser
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

function normalize_sy(string $sy): string {
    return str_replace(["–", "—"], "-", trim($sy));
}

echo "== Subject Teacher Assignments Migration ==\n";

// Current SY/Sem
$current_sy = '';
$current_sem = 1;
$ctx_res = $conn->query("SELECT school_year, semester FROM currentschoolyearandsemester LIMIT 1");
if ($ctx_res && $ctx_res->num_rows > 0) {
    $ctx = $ctx_res->fetch_assoc();
    $current_sy = (string)($ctx['school_year'] ?? '');
    $current_sem = (int)($ctx['semester'] ?? 1);
}
$current_sy_norm = normalize_sy($current_sy);
$current_sem = ($current_sem === 2) ? 2 : 1;

echo "Current context: SY={$current_sy} (norm={$current_sy_norm}) | Sem={$current_sem}\n\n";

// 1) Create table
$create_sql = "
CREATE TABLE IF NOT EXISTS subject_teacher_assignments (
  assignment_id INT(11) NOT NULL AUTO_INCREMENT,
  subject_id INT(11) NOT NULL,
  teacher_id INT(11) NOT NULL,
  school_year VARCHAR(9) NOT NULL,
  semester TINYINT(1) NOT NULL,
  role ENUM('primary','assistant') NOT NULL DEFAULT 'primary',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (assignment_id),
  UNIQUE KEY uniq_subject_teacher_term (subject_id, teacher_id, school_year, semester),
  KEY idx_subject_term (subject_id, school_year, semester),
  KEY idx_teacher_term (teacher_id, school_year, semester)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
";

if ($conn->query($create_sql) === true) {
    echo "✓ Created/verified table: subject_teacher_assignments\n";
} else {
    echo "✗ Failed creating subject_teacher_assignments: {$conn->error}\n";
    exit(1);
}

// 2) Backfill from subjects.teacher_id (best-effort)
// subjects.teacher_id is inconsistent historically; we try:
// - If it matches teachers.teacher_id directly
// - Else if it matches teachers.user_id
echo "\nBackfilling assignments from subjects.teacher_id for current term...\n";

$subjects_res = $conn->query("SELECT subject_id, teacher_id FROM subjects WHERE teacher_id IS NOT NULL");
if (!$subjects_res) {
    echo "✗ Failed reading subjects: {$conn->error}\n";
    exit(1);
}

$insert_stmt = $conn->prepare(
    "INSERT IGNORE INTO subject_teacher_assignments (subject_id, teacher_id, school_year, semester, role)
     VALUES (?, ?, ?, ?, 'primary')"
);
if (!$insert_stmt) {
    echo "✗ Failed preparing insert: {$conn->error}\n";
    exit(1);
}

$resolved = 0;
$skipped = 0;
$inserted = 0;

while ($row = $subjects_res->fetch_assoc()) {
    $subject_id = (int)$row['subject_id'];
    $raw = (int)$row['teacher_id'];
    if ($raw <= 0) { $skipped++; continue; }

    // Try resolve to teachers.teacher_id for this current SY/Sem first
    $t_stmt = $conn->prepare(
        "SELECT t.teacher_id
           FROM teachers t
          WHERE t.teacher_id = ?
            AND REPLACE(REPLACE(t.school_year,'–','-'),'—','-') = ?
            AND t.semester = ?
          LIMIT 1"
    );
    $teacher_id = null;
    if ($t_stmt) {
        $t_stmt->bind_param("isi", $raw, $current_sy_norm, $current_sem);
        $t_stmt->execute();
        $trow = $t_stmt->get_result()->fetch_assoc();
        $t_stmt->close();
        if ($trow) {
            $teacher_id = (int)$trow['teacher_id'];
        }
    }

    // Else interpret raw as teachers.user_id
    if ($teacher_id === null) {
        $u_stmt = $conn->prepare(
            "SELECT t.teacher_id
               FROM teachers t
              WHERE t.user_id = ?
                AND REPLACE(REPLACE(t.school_year,'–','-'),'—','-') = ?
                AND t.semester = ?
              ORDER BY t.teacher_id DESC
              LIMIT 1"
        );
        if ($u_stmt) {
            $u_stmt->bind_param("isi", $raw, $current_sy_norm, $current_sem);
            $u_stmt->execute();
            $urow = $u_stmt->get_result()->fetch_assoc();
            $u_stmt->close();
            if ($urow) {
                $teacher_id = (int)$urow['teacher_id'];
            }
        }
    }

    if ($teacher_id === null || $teacher_id <= 0) {
        $skipped++;
        continue;
    }

    $resolved++;
    $insert_stmt->bind_param("iisi", $subject_id, $teacher_id, $current_sy, $current_sem);
    if ($insert_stmt->execute()) {
        // INSERT IGNORE: affected_rows may be 0 for duplicates
        if ($insert_stmt->affected_rows > 0) $inserted++;
    }
}

$insert_stmt->close();
echo "✓ Resolved: {$resolved}\n";
echo "✓ Inserted (new): {$inserted}\n";
echo "• Skipped/unresolved: {$skipped}\n";

echo "\nDone.\n";

