<?php
session_start();

if (!isset($_SESSION['username']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: ../index.php");
    exit();
}

include '../includes/db_connection.php';
include '../includes/evaluation_status_helper.php';

function normalize_sy(string $sy): string {
    return str_replace(["–", "—"], "-", trim($sy));
}

// Ensure table exists (safe if migration script wasn't run yet)
$conn->query(
    "CREATE TABLE IF NOT EXISTS evaluation_targets (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
);

// Fetch current SY/Sem
$ctx_stmt = $conn->prepare("SELECT school_year, semester FROM currentschoolyearandsemester LIMIT 1");
$ctx_stmt->execute();
$ctx = $ctx_stmt->get_result()->fetch_assoc() ?: ['school_year' => '', 'semester' => 1];
$ctx_stmt->close();
$school_year = (string)($ctx['school_year'] ?? '');
$semester = ((int)($ctx['semester'] ?? 1) === 2) ? 2 : 1;
$school_year_norm = normalize_sy($school_year);

$evalStatus = getEvaluationStatus($conn);
$actions_disabled = $evalStatus['is_ongoing'];

// Handle generate action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate') {
    if ($actions_disabled) {
        $_SESSION['error'] = "Cannot generate evaluation targets while evaluation is ongoing.";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    $conn->begin_transaction();
    try {
        $migr = __DIR__ . '/migrations/001_create_section_subject_teacher_assignments.sql';
        if (is_readable($migr)) {
            $migr_sql = file_get_contents($migr);
            if ($migr_sql !== false && $conn->multi_query($migr_sql)) {
                while ($conn->more_results() && $conn->next_result()) {
                }
            }
        }

        // Clear existing targets for this term (regenerate deterministically)
        $del = $conn->prepare("DELETE FROM evaluation_targets WHERE school_year = ? AND semester = ?");
        $del->bind_param("si", $school_year, $semester);
        $del->execute();
        $del->close();

        // Build targets:
        // 1) Prefer section_subject_teacher_assignments (per section + subject + term).
        // 2) For any (section, subject) with no target yet, fall back to subject_teacher_assignments (legacy).
        $sqlA = "
            INSERT IGNORE INTO evaluation_targets (school_year, semester, section_id, subject_id, teacher_id, is_active)
            SELECT DISTINCT
                ? AS school_year,
                ? AS semester,
                sec.section_id,
                ssta.subject_id,
                ssta.teacher_id,
                1 AS is_active
            FROM section_subject_teacher_assignments ssta
            INNER JOIN sections sec ON sec.section_id = ssta.section_id
            INNER JOIN subjects sub ON sub.subject_id = ssta.subject_id
            WHERE sec.status = 'active'
              AND sub.status = 'active'
              AND REPLACE(REPLACE(sec.school_year,'–','-'),'—','-') = ?
              AND sec.semester = ?
              AND REPLACE(REPLACE(ssta.school_year,'–','-'),'—','-') = ?
              AND ssta.semester = ?
              AND sub.year_level = sec.year_level
              AND sub.strand = sec.strand
              AND sub.semester = sec.semester
        ";
        $insA = $conn->prepare($sqlA);
        if (!$insA) {
            throw new Exception("Prepare section targets failed: " . $conn->error);
        }
        $insA->bind_param("sisisi", $school_year, $semester, $school_year_norm, $semester, $school_year_norm, $semester);
        if (!$insA->execute()) {
            throw new Exception("Insert (section assignments) failed: " . $insA->error);
        }
        $created = $insA->affected_rows;
        $insA->close();

        $sqlB = "
            INSERT IGNORE INTO evaluation_targets (school_year, semester, section_id, subject_id, teacher_id, is_active)
            SELECT DISTINCT
                ? AS school_year,
                ? AS semester,
                sec.section_id,
                subj.subject_id,
                sta.teacher_id,
                1 AS is_active
            FROM sections sec
            INNER JOIN subjects subj
              ON subj.status = 'active'
             AND subj.year_level = sec.year_level
             AND subj.strand = sec.strand
             AND subj.semester = sec.semester
            INNER JOIN subject_teacher_assignments sta
              ON sta.subject_id = subj.subject_id
             AND sta.school_year = ?
             AND sta.semester = ?
            WHERE sec.status = 'active'
              AND REPLACE(REPLACE(sec.school_year,'–','-'),'—','-') = ?
              AND sec.semester = ?
              AND NOT EXISTS (
                SELECT 1 FROM evaluation_targets et
                WHERE et.school_year = ?
                  AND et.semester = ?
                  AND et.section_id = sec.section_id
                  AND et.subject_id = subj.subject_id
              )
        ";
        $insB = $conn->prepare($sqlB);
        if (!$insB) {
            throw new Exception("Prepare fallback targets failed: " . $conn->error);
        }
        $insB->bind_param("sisisisi", $school_year, $semester, $school_year, $semester, $school_year_norm, $semester, $school_year, $semester);
        if (!$insB->execute()) {
            throw new Exception("Insert (subject-teacher fallback) failed: " . $insB->error);
        }
        $created += $insB->affected_rows;
        $insB->close();

        $conn->commit();
        $_SESSION['success'] = "Evaluation targets generated for $school_year / " . ($semester === 1 ? '1st' : '2nd') . " Semester. Rows created: $created";
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = "Generation failed: " . $e->getMessage();
    }

    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Stats: counts + missing (subjects with no assigned teachers)
$stats = [
    'sections' => 0,
    'subjects' => 0,
    'assigned_subjects' => 0,
    'targets' => 0,
    'missing_subject_teacher' => 0,
];

// Active sections for current term
$sec_stmt = $conn->prepare("SELECT COUNT(*) AS c FROM sections WHERE status='active' AND REPLACE(REPLACE(school_year,'–','-'),'—','-') = ? AND semester = ?");
$sec_stmt->bind_param("si", $school_year_norm, $semester);
$sec_stmt->execute();
$stats['sections'] = (int)($sec_stmt->get_result()->fetch_assoc()['c'] ?? 0);
$sec_stmt->close();

// Active subjects (global, filtered by strands/years doesn't matter here)
$subj_res = $conn->query("SELECT COUNT(*) AS c FROM subjects WHERE status='active'");
$stats['subjects'] = (int)($subj_res->fetch_assoc()['c'] ?? 0);

// Subjects with at least one assignment this term
$as_stmt = $conn->prepare("SELECT COUNT(DISTINCT subject_id) AS c FROM subject_teacher_assignments WHERE school_year = ? AND semester = ?");
$as_stmt->bind_param("si", $school_year, $semester);
$as_stmt->execute();
$stats['assigned_subjects'] = (int)($as_stmt->get_result()->fetch_assoc()['c'] ?? 0);
$as_stmt->close();
$stats['missing_subject_teacher'] = max(0, $stats['subjects'] - $stats['assigned_subjects']);

// Targets count
$t_stmt = $conn->prepare("SELECT COUNT(*) AS c FROM evaluation_targets WHERE school_year = ? AND semester = ?");
$t_stmt->bind_param("si", $school_year, $semester);
$t_stmt->execute();
$stats['targets'] = (int)($t_stmt->get_result()->fetch_assoc()['c'] ?? 0);
$t_stmt->close();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="/capstone/images/school-logo.png" type="image/png">
    <title>Evaluation Targets</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --primary:#800000; --primary2:#a00000; }
        body { font-family:'Poppins',sans-serif; background: linear-gradient(135deg,#f5f7fa 0%,#c3cfe2 100%); min-height:100vh; }
        .main-content { margin-left:250px; margin-top:60px; padding:30px; }
        .header { background: linear-gradient(135deg,var(--primary),var(--primary2)); color:#fff; padding:28px; border-radius:18px; box-shadow:0 12px 30px rgba(128,0,0,.2); }
        .cardx { background:#fff; border-radius:16px; padding:22px; box-shadow:0 6px 18px rgba(0,0,0,.08); border:1px solid #e9ecef; }
        .stat { background:#f8f9fa; border-radius:14px; padding:16px; border-left:4px solid var(--primary); }
        .stat .n { font-weight:800; font-size:1.8rem; color:var(--primary); }
        .btn-primary-custom { background: linear-gradient(135deg,var(--primary),var(--primary2)); border:none; font-weight:700; }
        .btn-primary-custom:disabled { opacity:.65; cursor:not-allowed; }
    </style>
</head>
<body>
<?php include '../includes/side_bar.php'; ?>
<?php include '../includes/navbar.php'; ?>

<div class="main-content">
    <div class="header mb-4">
        <h2 class="m-0"><i class="fas fa-bullseye me-2"></i>Evaluation Targets</h2>
        <div class="mt-2 opacity-75">
            Current: <strong><?php echo htmlspecialchars($school_year); ?></strong> / <strong><?php echo $semester === 1 ? '1st Semester' : '2nd Semester'; ?></strong>
        </div>
    </div>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm" role="alert">
            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($_SESSION['success']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <?php if ($actions_disabled): ?>
        <div class="alert alert-warning border-0 shadow-sm">
            <i class="fas fa-lock me-2"></i><strong>Locked:</strong> target generation is disabled while evaluation is ongoing.
        </div>
    <?php endif; ?>

    <div class="cardx mb-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div>
                <div class="fw-bold" style="color:var(--primary);">Generate targets for the current term</div>
                <div class="text-muted small">Creates one target per (Section × Subject × Assigned Teacher). Unassigned subjects will be skipped.</div>
            </div>
            <form method="POST" class="m-0">
                <input type="hidden" name="action" value="generate">
                <button type="submit" class="btn btn-primary-custom text-white px-4 py-2"
                        <?php echo $actions_disabled ? 'disabled' : ''; ?>
                        onclick="return confirm('Regenerate evaluation targets for this term? This will replace the existing target list for the current School Year/Semester.');">
                    <i class="fas fa-wand-magic-sparkles me-2"></i>Generate / Regenerate
                </button>
            </form>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-md-3"><div class="stat"><div class="text-muted small">Active Sections (term)</div><div class="n"><?php echo (int)$stats['sections']; ?></div></div></div>
        <div class="col-md-3"><div class="stat"><div class="text-muted small">Active Subjects</div><div class="n"><?php echo (int)$stats['subjects']; ?></div></div></div>
        <div class="col-md-3"><div class="stat"><div class="text-muted small">Subjects w/ Teachers (term)</div><div class="n"><?php echo (int)$stats['assigned_subjects']; ?></div></div></div>
        <div class="col-md-3"><div class="stat"><div class="text-muted small">Targets (term)</div><div class="n"><?php echo (int)$stats['targets']; ?></div></div></div>
    </div>

    <?php if ($stats['missing_subject_teacher'] > 0): ?>
        <div class="alert alert-info mt-4 border-0 shadow-sm">
            <i class="fas fa-info-circle me-2"></i>
            <strong><?php echo (int)$stats['missing_subject_teacher']; ?></strong> active subject(s) have no assigned teachers for this term yet. They will not appear in the student evaluation list until assigned in Subject Management.
        </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

