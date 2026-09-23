<?php
session_start();

if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");  
    exit();
}

// Include database connection
include '../includes/db_connection.php';
include '../includes/evaluation_status_helper.php';
require_once __DIR__ . '/../includes/modern_alert_system.php';

// Teacher resolution removed - sections no longer have direct teacher assignments
// Teachers are assigned via section_subject_teacher_assignments table

// Check evaluation status
$evalStatus = getEvaluationStatus($conn);
$actions_disabled = $evalStatus['is_ongoing'];

function normalizeSchoolYear(string $sy): string {
    return str_replace(["–", "—"], "-", trim($sy));
}

/**
 * Same source of truth as Subject Management: term rows in subject_teacher_assignments,
 * or if none, subjects.teacher_id as legacy primary only.
 *
 * @return int[] Distinct teachers.teacher_id values qualified to teach the subject this term.
 */
function getQualifiedTeacherIdsForSubjectTerm(mysqli $conn, int $subject_id, string $school_year_norm, int $semester): array {
    $semester = ($semester === 2) ? 2 : 1;
    $stmt = $conn->prepare(
        "SELECT DISTINCT sta.teacher_id
           FROM subject_teacher_assignments sta
          WHERE sta.subject_id = ?
            AND REPLACE(REPLACE(sta.school_year,'–','-'),'—','-') = ?
            AND sta.semester = ?
          ORDER BY sta.teacher_id ASC"
    );
    $ids = [];
    if ($stmt) {
        $stmt->bind_param("isi", $subject_id, $school_year_norm, $semester);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $tid = (int)($row['teacher_id'] ?? 0);
            if ($tid > 0) {
                $ids[] = $tid;
            }
        }
        $stmt->close();
    }
    if ($ids !== []) {
        return array_values(array_unique($ids));
    }
    $fb = $conn->prepare("SELECT s.teacher_id FROM subjects s WHERE s.subject_id = ? LIMIT 1");
    if (!$fb) {
        return [];
    }
    $fb->bind_param("i", $subject_id);
    $fb->execute();
    $subRow = $fb->get_result()->fetch_assoc();
    $fb->close();
    $legacy = (int)($subRow['teacher_id'] ?? 0);
    return $legacy > 0 ? [$legacy] : [];
}

function isTeacherQualifiedForSubjectTerm(mysqli $conn, int $subject_id, int $teacher_id, string $school_year_norm, int $semester): bool {
    if ($teacher_id <= 0) {
        return false;
    }
    $allowed = getQualifiedTeacherIdsForSubjectTerm($conn, $subject_id, $school_year_norm, $semester);
    return in_array($teacher_id, $allowed, true);
}

/**
 * Role stored in Subject Management for this teacher on this subject/term (primary|assistant|null).
 */
function getTeacherRoleFromSubjectTeacherAssignments(mysqli $conn, int $subject_id, int $teacher_id, string $school_year_norm, int $semester): ?string {
    if ($teacher_id <= 0) {
        return null;
    }
    $semester = ($semester === 2) ? 2 : 1;
    $stmt = $conn->prepare(
        "SELECT sta.role
           FROM subject_teacher_assignments sta
          WHERE sta.subject_id = ?
            AND sta.teacher_id = ?
            AND REPLACE(REPLACE(sta.school_year,'–','-'),'—','-') = ?
            AND sta.semester = ?
          LIMIT 1"
    );
    if ($stmt) {
        $stmt->bind_param("iisi", $subject_id, $teacher_id, $school_year_norm, $semester);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $r = $row['role'] ?? null;
        if ($r === 'primary' || $r === 'assistant') {
            return $r;
        }
    }
    $legacy = null;
    $fb = $conn->prepare(
        "SELECT s.teacher_id FROM subjects s WHERE s.subject_id = ? LIMIT 1"
    );
    if ($fb) {
        $fb->bind_param("i", $subject_id);
        $fb->execute();
        $subRow = $fb->get_result()->fetch_assoc();
        $fb->close();
        if ((int)($subRow['teacher_id'] ?? 0) === $teacher_id) {
            $legacy = 'primary';
        }
    }
    return $legacy;
}

// Database schema is installed with deployment/schema-repair.sql, not during requests.

/** Build Location URL after POST (preserve assignments tab + selected section). */
function section_management_redirect_after_post(): string {
    $base = $_SERVER['PHP_SELF'] ?? 'section_management.php';
    $qs = [];
    if (!empty($_POST['redirect_sm_tab']) && $_POST['redirect_sm_tab'] === 'assignments') {
        $qs['sm_tab'] = 'assignments';
    }
    if (!empty($_POST['redirect_section_id'])) {
        $sid = (int)$_POST['redirect_section_id'];
        if ($sid > 0) {
            $qs['section_id'] = $sid;
        }
    }
    return $base . ($qs ? '?' . http_build_query($qs) : '');
}

// Database schema is installed with deployment/schema-repair.sql, not during requests.

// Active term from system settings — Subject Management saves subject_teacher_assignments ONLY for this (school_year + semester).
$current_sy_result = $conn->query("SELECT school_year, semester FROM currentschoolyearandsemester LIMIT 1");
$current_sy = ($current_sy_result && ($rowEarly = $current_sy_result->fetch_assoc())) ? $rowEarly : ['school_year' => '', 'semester' => 1];
$current_school_year_norm = normalizeSchoolYear((string)($current_sy['school_year'] ?? ''));
$current_semester = ((int)($current_sy['semester'] ?? 1) === 2) ? 2 : 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Re-check evaluation status on POST to prevent bypassing UI-disabled controls.
    $post_is_evaluation_ongoing = isEvaluationOngoing($conn);
    if ($post_is_evaluation_ongoing) {
        $blocked_actions = ['add', 'update', 'archive', 'restore', 'add_assignment', 'remove_assignment'];
        if (isset($_POST['action']) && in_array($_POST['action'], $blocked_actions, true)) {
            $_SESSION['error'] = "This action is not allowed while an evaluation is ongoing. Please wait until the evaluation period ends.";
            header("Location: " . section_management_redirect_after_post());
            exit();
        }
    }

    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                $section_name = trim($_POST['section_name'] ?? '');
                $year_level = trim($_POST['year_level'] ?? '');
                $strand = trim($_POST['strand'] ?? '');
                $school_year = trim($_POST['school_year'] ?? '');
                $semester = (int)($_POST['semester'] ?? 0);

                $dup_stmt = $conn->prepare("SELECT section_id FROM sections WHERE section_name = ? AND year_level = ? AND strand = ? AND school_year = ? AND semester = ? LIMIT 1");
                $dup_stmt->bind_param("ssssi", $section_name, $year_level, $strand, $school_year, $semester);
                $dup_stmt->execute();
                $duplicate = $dup_stmt->get_result()->fetch_assoc();
                $dup_stmt->close();

                if ($duplicate) {
                    $_SESSION['error'] = "A section with the same name, year level, strand, school year, and semester already exists.";
                    break;
                }
                
                $stmt = $conn->prepare("INSERT INTO sections (section_name, year_level, strand, school_year, semester, status) VALUES (?, ?, ?, ?, ?, 'active')");
                $stmt->bind_param("ssssi", $section_name, $year_level, $strand, $school_year, $semester);
                
                if ($stmt->execute()) {
                    $_SESSION['success'] = "Section added successfully!";
                } else {
                    $_SESSION['error'] = "Error adding section: " . $conn->error;
                }
                break;
                
            case 'update':
                $id = (int)($_POST['section_id'] ?? 0);
                $section_name = trim($_POST['section_name'] ?? '');
                $year_level = trim($_POST['year_level'] ?? '');
                $strand = trim($_POST['strand'] ?? '');
                $school_year = normalizeSchoolYear(trim($_POST['school_year'] ?? ''));
                $semester = (int)($_POST['semester'] ?? 0);

                $dup_stmt = $conn->prepare(
                    "SELECT section_id FROM sections WHERE section_name = ? AND year_level = ? AND strand = ? AND REPLACE(REPLACE(school_year,'–','-'),'—','-') = ? AND semester = ? AND section_id <> ? LIMIT 1"
                );
                $dup_stmt->bind_param("ssssii", $section_name, $year_level, $strand, $school_year, $semester, $id);
                $dup_stmt->execute();
                $duplicate = $dup_stmt->get_result()->fetch_assoc();
                $dup_stmt->close();

                if ($duplicate) {
                    $_SESSION['error'] = "Update blocked: another section with the same name, year level, strand, school year, and semester already exists.";
                    break;
                }
                
                $stmt = $conn->prepare("UPDATE sections SET section_name=?, year_level=?, strand=? WHERE section_id=?");
                $stmt->bind_param("sssi", $section_name, $year_level, $strand, $id);
                
                if ($stmt->execute()) {
                    $_SESSION['success'] = "Section updated successfully!";
                } else {
                    $_SESSION['error'] = "Error updating section: " . $conn->error;
                }
                break;
                
            case 'archive':
                $id = $_POST['section_id'];
                $stmt = $conn->prepare("UPDATE sections SET status = 'archived' WHERE section_id = ?");
                $stmt->bind_param("i", $id);
                
                if ($stmt->execute()) {
                    $_SESSION['success'] = "Section archived successfully!";
                } else {
                    $_SESSION['error'] = "Error archiving section: " . $conn->error;
                }
                break;
                
            case 'restore':
                $id = $_POST['section_id'];
                $stmt = $conn->prepare("UPDATE sections SET status = 'active' WHERE section_id = ?");
                $stmt->bind_param("i", $id);
                
                if ($stmt->execute()) {
                    $_SESSION['success'] = "Section restored successfully!";
                } else {
                    $_SESSION['error'] = "Error restoring section: " . $conn->error;
                }
                break;

            case 'add_assignment':
                $section_id = (int)($_POST['section_id'] ?? 0);
                $subject_id = (int)($_POST['subject_id'] ?? 0);
                $teacher_id = (int)($_POST['teacher_id'] ?? 0);

                if ($section_id <= 0 || $subject_id <= 0 || $teacher_id <= 0) {
                    $_SESSION['error'] = "Invalid section, subject, or teacher selected.";
                    break;
                }

                $sec_stmt = $conn->prepare(
                    "SELECT section_id, year_level, strand, school_year, semester, status FROM sections WHERE section_id = ? LIMIT 1"
                );
                $sec_stmt->bind_param("i", $section_id);
                $sec_stmt->execute();
                $sec_row = $sec_stmt->get_result()->fetch_assoc();
                $sec_stmt->close();

                if (!$sec_row || ($sec_row['status'] ?? '') !== 'active') {
                    $_SESSION['error'] = "Section not found or not active.";
                    break;
                }

                $school_year = normalizeSchoolYear((string)($sec_row['school_year'] ?? ''));
                $semester = ((int)($sec_row['semester'] ?? 1) === 2) ? 2 : 1;

                if ($school_year !== $current_school_year_norm || $semester !== $current_semester) {
                    $_SESSION['error'] = 'This section is not tied to the active school year and semester in system settings; teacher eligibility is based on that active term.';
                    break;
                }

                $sub_stmt = $conn->prepare(
                    "SELECT subject_id, year_level, strand, semester FROM subjects WHERE subject_id = ? AND status = 'active' LIMIT 1"
                );
                $sub_stmt->bind_param("i", $subject_id);
                $sub_stmt->execute();
                $sub_row = $sub_stmt->get_result()->fetch_assoc();
                $sub_stmt->close();

                if (!$sub_row) {
                    $_SESSION['error'] = "Subject not found.";
                    break;
                }

                $sub_sem = ((int)($sub_row['semester'] ?? 1) === 2) ? 2 : 1;
                if (($sub_row['year_level'] ?? '') !== ($sec_row['year_level'] ?? '')
                    || ($sub_row['strand'] ?? '') !== ($sec_row['strand'] ?? '')
                    || $sub_sem !== $semester
                ) {
                    $_SESSION['error'] = "This subject does not match the section's year level, strand, and semester.";
                    break;
                }

                $teach_check = $conn->prepare("SELECT teacher_id FROM teachers WHERE teacher_id = ? LIMIT 1");
                $teach_check->bind_param("i", $teacher_id);
                $teach_check->execute();
                if (!$teach_check->get_result()->fetch_assoc()) {
                    $_SESSION['error'] = "Teacher not found.";
                    $teach_check->close();
                    break;
                }
                $teach_check->close();

                // Must match Subject Management rows in subject_teacher_assignments for the ACTIVE term (all roles: primary + assistant).
                if (!isTeacherQualifiedForSubjectTerm($conn, $subject_id, $teacher_id, $current_school_year_norm, $current_semester)) {
                    $_SESSION['error'] = 'This teacher is not listed for the selected subject in Subject Management for this school year and semester. Add or update the subject’s assigned teachers there first.';
                    break;
                }

                $role = getTeacherRoleFromSubjectTeacherAssignments($conn, $subject_id, $teacher_id, $current_school_year_norm, $current_semester);
                if ($role !== 'primary' && $role !== 'assistant') {
                    $role = 'primary';
                }

                $dup_check = $conn->prepare(
                    "SELECT assignment_id, teacher_id FROM section_subject_teacher_assignments
                     WHERE section_id = ? AND subject_id = ?
                     AND REPLACE(REPLACE(school_year,'–','-'),'—','-') = ? AND semester = ? LIMIT 1"
                );
                $dup_check->bind_param("iisi", $section_id, $subject_id, $school_year, $semester);
                $dup_check->execute();
                $existing_assignment = $dup_check->get_result()->fetch_assoc();
                if ($existing_assignment) {
                    if ((int)$existing_assignment['teacher_id'] === $teacher_id) {
                        $_SESSION['error'] = "This assignment already exists for this term.";
                    } else {
                        $_SESSION['error'] = "This section is already assigned to another teacher for the selected subject and term.";
                    }
                    $dup_check->close();
                    break;
                }
                $dup_check->close();

                $stmt = $conn->prepare(
                    "INSERT INTO section_subject_teacher_assignments
                     (section_id, subject_id, teacher_id, school_year, semester, role)
                     VALUES (?, ?, ?, ?, ?, ?)"
                );
                $stmt->bind_param("iiisis", $section_id, $subject_id, $teacher_id, $school_year, $semester, $role);

                if ($stmt->execute()) {
                    $_SESSION['success'] = "Teacher assigned to subject for this section.";
                } else {
                    $_SESSION['error'] = "Error assigning teacher: " . $conn->error;
                }
                break;

            case 'update_assignment':
                $assignment_id = (int)($_POST['assignment_id'] ?? 0);
                $new_teacher_id = (int)($_POST['teacher_id'] ?? 0);

                if ($assignment_id <= 0 || $new_teacher_id <= 0) {
                    $_SESSION['error'] = "Invalid assignment or teacher selected.";
                    break;
                }

                // Fetch the current assignment to get section_id and subject_id
                $current_assign = $conn->prepare(
                    "SELECT section_id, subject_id, school_year, semester FROM section_subject_teacher_assignments
                     WHERE assignment_id = ? LIMIT 1"
                );
                $current_assign->bind_param("i", $assignment_id);
                $current_assign->execute();
                $assign_row = $current_assign->get_result()->fetch_assoc();
                $current_assign->close();

                if (!$assign_row) {
                    $_SESSION['error'] = "Assignment not found.";
                    break;
                }

                $sec_id = (int)$assign_row['section_id'];
                $subj_id = (int)$assign_row['subject_id'];
                $assign_sy = (string)($assign_row['school_year'] ?? '');
                $assign_sem = (int)($assign_row['semester'] ?? 1);

                // Verify the new teacher exists
                $teach_verify = $conn->prepare("SELECT teacher_id FROM teachers WHERE teacher_id = ? LIMIT 1");
                $teach_verify->bind_param("i", $new_teacher_id);
                $teach_verify->execute();
                if (!$teach_verify->get_result()->fetch_assoc()) {
                    $_SESSION['error'] = "Selected teacher not found.";
                    $teach_verify->close();
                    break;
                }
                $teach_verify->close();

                // Verify the new teacher is qualified for this subject in this term
                $assign_sy_norm = normalizeSchoolYear($assign_sy);
                if (!isTeacherQualifiedForSubjectTerm($conn, $subj_id, $new_teacher_id, $assign_sy_norm, $assign_sem)) {
                    $_SESSION['error'] = 'The selected teacher is not listed for this subject in Subject Management for this term.';
                    break;
                }

                // Get the role for the new teacher
                $role = getTeacherRoleFromSubjectTeacherAssignments($conn, $subj_id, $new_teacher_id, $assign_sy_norm, $assign_sem);
                if ($role !== 'primary' && $role !== 'assistant') {
                    $role = 'primary';
                }

                // Update the assignment
                $update_stmt = $conn->prepare(
                    "UPDATE section_subject_teacher_assignments SET teacher_id = ?, role = ? WHERE assignment_id = ?"
                );
                $update_stmt->bind_param("isi", $new_teacher_id, $role, $assignment_id);

                if ($update_stmt->execute()) {
                    $_SESSION['success'] = "Assignment updated successfully.";
                } else {
                    $_SESSION['error'] = "Error updating assignment: " . $conn->error;
                }
                break;

            case 'remove_assignment':
                $assignment_id = (int)($_POST['assignment_id'] ?? 0);
                if ($assignment_id <= 0) {
                    $_SESSION['error'] = "Invalid assignment.";
                    break;
                }
                $stmt = $conn->prepare("DELETE FROM section_subject_teacher_assignments WHERE assignment_id = ?");
                $stmt->bind_param("i", $assignment_id);
                if ($stmt->execute()) {
                    $_SESSION['success'] = "Assignment removed.";
                } else {
                    $_SESSION['error'] = "Error removing assignment: " . $conn->error;
                }
                break;

        }
        header("Location: " . section_management_redirect_after_post());
        exit();
    }
}

// Fetch active sections (no teacher join - teachers assigned separately)
$active_sql = "SELECT s.* FROM sections s WHERE s.status = 'active' ORDER BY s.school_year DESC, s.semester DESC, s.year_level, s.section_name";
$active_result = $conn->query($active_sql);
$active_sections = $active_result->fetch_all(MYSQLI_ASSOC);

// Fetch archived sections (no teacher join - teachers assigned separately)
$archived_sql = "SELECT s.* FROM sections s WHERE s.status = 'archived' ORDER BY s.school_year DESC, s.semester DESC, s.year_level, s.section_name";
$archived_result = $conn->query($archived_sql);
$archived_sections = $archived_result->fetch_all(MYSQLI_ASSOC);

// Fetch all distinct school years from sections table
$school_years_sql = "SELECT DISTINCT school_year FROM sections WHERE school_year IS NOT NULL AND school_year != '' ORDER BY school_year DESC";
$school_years_result = $conn->query($school_years_sql);
$school_years = $school_years_result ? $school_years_result->fetch_all(MYSQLI_ASSOC) : [];

$stats = [
    'grade_11_count' => count(array_filter($active_sections, static fn($s) => ($s['year_level'] ?? '') === '11')),
    'grade_12_count' => count(array_filter($active_sections, static fn($s) => ($s['year_level'] ?? '') === '12')),
    'archived_count' => count($archived_sections),
];

$sm_tab = (isset($_GET['sm_tab']) && $_GET['sm_tab'] === 'assignments') ? 'assignments' : 'sections';
$selected_section_id = isset($_GET['section_id']) ? (int)$_GET['section_id'] : 0;
$selected_section = null;
$section_subjects = [];
$section_assignments = [];
$assignments_by_subject = [];
$teachers_for_assign = [];
$subject_qualified_teacher_ids = [];
$teacher_id_to_qualifying_subject_ids = [];
$assignment_ui_config = null;
$sections_with_assignment_gaps = [];
$assignment_gap_section_ids = [];

if ($sm_tab === 'assignments') {
    $sections_for_assign_sql = "SELECT s.section_id, s.section_name, s.year_level, s.strand, s.school_year, s.semester
                 FROM sections s
                 WHERE s.status = 'active'
                 AND REPLACE(REPLACE(s.school_year,'–','-'),'—','-') = ?
                 AND s.semester = ?
                 ORDER BY s.year_level, s.section_name";
    $sections_assign_stmt = $conn->prepare($sections_for_assign_sql);
    $sections_assign_stmt->bind_param('si', $current_school_year_norm, $current_semester);
    $sections_assign_stmt->execute();
    $sections_for_assign = $sections_assign_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $sections_assign_stmt->close();

    // Cross-section summary: sections that have at least one subject with no section-level teacher assignment this term.
    $sectionSubjectsGapCheckSql = "SELECT s.subject_id, s.subject_name
                             FROM subjects s
                             WHERE s.status = 'active'
                             AND s.strand = ?
                             AND s.year_level = ?
                             AND s.semester = ?
                             ORDER BY s.subject_name";
    $sectionIdListForGaps = [];
    foreach ($sections_for_assign as $__secRow) {
        $__id = (int)($__secRow['section_id'] ?? 0);
        if ($__id > 0) {
            $sectionIdListForGaps[$__id] = true;
        }
    }
    $sectionIdsForGaps = array_keys($sectionIdListForGaps);
    $assignedSubjectIdsBySection = [];
    foreach ($sectionIdsForGaps as $__sid) {
        $assignedSubjectIdsBySection[$__sid] = [];
    }
    if ($sectionIdsForGaps !== []) {
        $inPh = implode(',', array_fill(0, count($sectionIdsForGaps), '?'));
        $batchGapSql = "SELECT DISTINCT a.section_id, a.subject_id
                FROM section_subject_teacher_assignments a
                INNER JOIN sections sec ON sec.section_id = a.section_id AND sec.status = 'active'
                WHERE REPLACE(REPLACE(sec.school_year,'–','-'),'—','-') = ?
                  AND sec.semester = ?
                  AND a.section_id IN ($inPh)
                  AND REPLACE(REPLACE(a.school_year,'–','-'),'—','-') = REPLACE(REPLACE(sec.school_year,'–','-'),'—','-')
                  AND a.semester = sec.semester";
        $stmtBatchGap = $conn->prepare($batchGapSql);
        if ($stmtBatchGap) {
            $gapBindParams = array_merge([$current_school_year_norm, $current_semester], $sectionIdsForGaps);
            $gapTypes = 'si' . str_repeat('i', count($sectionIdsForGaps));
            $gapBindArgs = [$gapTypes];
            for ($gi = 0; $gi < count($gapBindParams); $gi++) {
                $gapBindArgs[] = &$gapBindParams[$gi];
            }
            call_user_func_array([$stmtBatchGap, 'bind_param'], $gapBindArgs);
            $stmtBatchGap->execute();
            $gapRes = $stmtBatchGap->get_result();
            while ($grow = $gapRes->fetch_assoc()) {
                $gSec = (int)($grow['section_id'] ?? 0);
                $gSub = (int)($grow['subject_id'] ?? 0);
                if ($gSec > 0 && $gSub > 0) {
                    $assignedSubjectIdsBySection[$gSec][$gSub] = true;
                }
            }
            $stmtBatchGap->close();
        }
        $subjGapStmt = $conn->prepare($sectionSubjectsGapCheckSql);
        if ($subjGapStmt) {
            foreach ($sections_for_assign as $gapSecRow) {
                $gapSecId = (int)($gapSecRow['section_id'] ?? 0);
                if ($gapSecId <= 0) {
                    continue;
                }
                $gapSelSem = ((int)($gapSecRow['semester'] ?? 1) === 2) ? 2 : 1;
                $gapYl = (string)($gapSecRow['year_level'] ?? '');
                $subjGapStmt->bind_param('ssi', $gapSecRow['strand'], $gapYl, $gapSelSem);
                $subjGapStmt->execute();
                $gapSubjects = $subjGapStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                if ($gapSubjects === []) {
                    continue;
                }
                $assignedSet = $assignedSubjectIdsBySection[$gapSecId] ?? [];
                $unNames = [];
                foreach ($gapSubjects as $gs) {
                    $gsid = (int)($gs['subject_id'] ?? 0);
                    if ($gsid > 0 && empty($assignedSet[$gsid])) {
                        $unNames[] = (string)($gs['subject_name'] ?? '');
                    }
                }
                if ($unNames !== []) {
                    $sections_with_assignment_gaps[] = [
                        'section_id' => $gapSecId,
                        'section_name' => (string)($gapSecRow['section_name'] ?? ''),
                        'year_level' => (string)($gapSecRow['year_level'] ?? ''),
                        'total_subjects' => count($gapSubjects),
                        'unassigned_count' => count($unNames),
                        'unassigned_names' => $unNames,
                    ];
                }
            }
            $subjGapStmt->close();
        }
        usort($sections_with_assignment_gaps, static function ($a, $b) {
            return strcasecmp($a['section_name'] ?? '', $b['section_name'] ?? '');
        });
        foreach ($sections_with_assignment_gaps as $gapEntry) {
            $assignment_gap_section_ids[(int)$gapEntry['section_id']] = true;
        }
    }

    $teachers_sql = "SELECT DISTINCT t.teacher_id, u.first_name, u.last_name, t.strand
                 FROM teachers t
                 INNER JOIN users u ON t.user_id = u.user_id
                 WHERE u.role = 'teacher' AND u.status = 'active'
                 AND REPLACE(REPLACE(t.school_year,'–','-'),'—','-') = ?
                 AND t.semester = ?
                 ORDER BY u.first_name, u.last_name";
    $teachers_stmt = $conn->prepare($teachers_sql);
    $teachers_stmt->bind_param('si', $current_school_year_norm, $current_semester);
    $teachers_stmt->execute();
    $teachers_for_assign = $teachers_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $teachers_stmt->close();

    if ($selected_section_id > 0) {
        $section_stmt = $conn->prepare("SELECT * FROM sections WHERE section_id = ? AND status = 'active' LIMIT 1");
        $section_stmt->bind_param('i', $selected_section_id);
        $section_stmt->execute();
        $selected_section = $section_stmt->get_result()->fetch_assoc();
        $section_stmt->close();

        if ($selected_section) {
            $sel_sy = normalizeSchoolYear((string)($selected_section['school_year'] ?? ''));
            $sel_sem = ((int)($selected_section['semester'] ?? 1) === 2) ? 2 : 1;

            $section_subj_sql = "SELECT s.subject_id, s.subject_name, s.strand, s.year_level, s.semester
                             FROM subjects s
                             WHERE s.status = 'active'
                             AND s.strand = ?
                             AND s.year_level = ?
                             AND s.semester = ?
                             ORDER BY s.subject_name";
            $section_subj_stmt = $conn->prepare($section_subj_sql);
            $yl = (string)($selected_section['year_level'] ?? '');
            $section_subj_stmt->bind_param('ssi', $selected_section['strand'], $yl, $sel_sem);
            $section_subj_stmt->execute();
            $section_subjects = $section_subj_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $section_subj_stmt->close();

            foreach ($section_subjects as $srow) {
                $subjId = (int)$srow['subject_id'];
                // subject_teacher_assignments is keyed to currentschoolyearandsemester (not sections.*), include every STA teacher for this term.
                $allowedTeachers = getQualifiedTeacherIdsForSubjectTerm($conn, $subjId, $current_school_year_norm, $current_semester);
                $subject_qualified_teacher_ids[$subjId] = $allowedTeachers;
                foreach ($allowedTeachers as $tid) {
                    if (!isset($teacher_id_to_qualifying_subject_ids[$tid])) {
                        $teacher_id_to_qualifying_subject_ids[$tid] = [];
                    }
                    $teacher_id_to_qualifying_subject_ids[$tid][] = $subjId;
                }
            }
            foreach ($teacher_id_to_qualifying_subject_ids as $tidKey => $subjIds) {
                $teacher_id_to_qualifying_subject_ids[$tidKey] = array_values(array_unique($subjIds));
            }

            $assignment_ui_config = [
                'subjectTeachers' => [],
                'teacherSubjects' => [],
                'teachers' => [],
                'subjects' => [],
            ];
            foreach ($subject_qualified_teacher_ids as $sk => $tids) {
                $assignment_ui_config['subjectTeachers'][(string)(int)$sk] = array_values(array_map('intval', $tids));
            }
            foreach ($teacher_id_to_qualifying_subject_ids as $tk => $sids) {
                $assignment_ui_config['teacherSubjects'][(string)(int)$tk] = array_values(array_map('intval', $sids));
            }
            foreach ($teachers_for_assign as $trow) {
                $assignment_ui_config['teachers'][] = [
                    'id' => (int)$trow['teacher_id'],
                    'label' => trim(($trow['first_name'] ?? '') . ' ' . ($trow['last_name'] ?? '')),
                ];
            }
            foreach ($section_subjects as $srow) {
                $assignment_ui_config['subjects'][] = [
                    'id' => (int)$srow['subject_id'],
                    'name' => (string)($srow['subject_name'] ?? ''),
                ];
            }

            $assign_sql = "SELECT a.assignment_id, a.subject_id, a.teacher_id, a.role AS section_role,
                              s.subject_name, CONCAT(u.first_name, ' ', u.last_name) AS teacher_name,
                              sta.role AS sta_role,
                              COALESCE(sta.role, a.role) AS display_role
                       FROM section_subject_teacher_assignments a
                       INNER JOIN subjects s ON a.subject_id = s.subject_id
                       INNER JOIN teachers t ON a.teacher_id = t.teacher_id
                       INNER JOIN users u ON t.user_id = u.user_id
                       LEFT JOIN subject_teacher_assignments sta
                         ON sta.subject_id = a.subject_id
                        AND sta.teacher_id = a.teacher_id
                        AND REPLACE(REPLACE(sta.school_year,'–','-'),'—','-') = ?
                        AND sta.semester = ?
                       WHERE a.section_id = ?
                       AND REPLACE(REPLACE(a.school_year,'–','-'),'—','-') = ?
                       AND a.semester = ?
                       ORDER BY s.subject_name,
                                CASE WHEN COALESCE(sta.role, a.role) = 'primary' THEN 0 ELSE 1 END,
                                u.first_name, u.last_name";
            $assign_stmt = $conn->prepare($assign_sql);
            $assign_stmt->bind_param('sisii', $current_school_year_norm, $current_semester, $selected_section_id, $sel_sy, $sel_sem);
            $assign_stmt->execute();
            $section_assignments = $assign_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $assign_stmt->close();

            foreach ($section_assignments as $assignment) {
                $subject_id = (int)$assignment['subject_id'];
                if (!isset($assignments_by_subject[$subject_id])) {
                    $assignments_by_subject[$subject_id] = [
                        'subject_name' => $assignment['subject_name'],
                        'teachers' => [],
                    ];
                }
                $assignments_by_subject[$subject_id]['teachers'][] = $assignment;
            }
        }
    }
} else {
    $sections_for_assign = [];
    $sections_with_assignment_gaps = [];
    $assignment_gap_section_ids = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="/images/school-logo.png" type="image/png">
    <title>Section Management</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --primary-color: #800000;
            --primary-hover: #a00000;
            --primary-light: rgba(128, 0, 0, 0.1);
            --text-dark: #2c3e50;
            --border-color: #dee2e6;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --danger-color: #dc3545;
            --info-color: #17a2b8;
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            margin: 0;
            padding: 0;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
        }
        
        .main-content {
            margin-left: 250px;
            margin-top: 60px;
            padding: 30px;
            min-height: calc(100vh - 60px);
        }
        
        .header-section {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-hover));
            color: white;
            padding: 40px;
            border-radius: 20px;
            margin-bottom: 40px;
            box-shadow: 0 15px 35px rgba(128, 0, 0, 0.2);
            position: relative;
            overflow: hidden;
        }
        
        .header-section h1 {
            font-weight: 700;
            margin-bottom: 15px;
            font-size: 2.8rem;
            position: relative;
            z-index: 2;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 25px;
            position: relative;
            z-index: 2;
        }
        
        .stat-card {
            background: rgba(255, 255, 255, 0.15);
            padding: 25px;
            border-radius: 15px;
            text-align: center;
            backdrop-filter: blur(10px);
            transition: transform 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 10px;
        }
        
        .stat-label {
            font-size: 1rem;
            opacity: 0.9;
        }
        
        .dashboard-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid #e9ecef;
            margin-bottom: 25px;
            transition: all 0.3s ease;
        }

        .dashboard-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.12);
        }

        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f8f9fa;
            flex-wrap: wrap;
        }

        .card-header h3 {
            margin: 0;
            font-size: 1.4rem;
            font-weight: 600;
            color: var(--primary-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-header i {
            font-size: 1.5rem;
            color: var(--primary-color);
            background: rgba(128, 0, 0, 0.1);
            padding: 10px;
            border-radius: 10px;
        }
        
        .tab-navigation {
            display: flex;
            gap: 10px;
            margin-bottom: 25px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .tab-button {
            padding: 12px 24px;
            background: transparent;
            border: none;
            cursor: pointer;
            font-weight: 600;
            font-size: 1rem;
            color: #6c757d;
            transition: all 0.3s ease;
            border-bottom: 3px solid transparent;
            margin-bottom: -2px;
            position: relative;
            top: 2px;
        }
        
        .tab-button.active {
            color: var(--primary-color);
            border-bottom-color: var(--primary-color);
        }
        
        .tab-button:hover {
            color: var(--primary-color);
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }

        .main-sm-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 28px;
            flex-wrap: wrap;
        }

        .main-sm-tab {
            flex: 1;
            min-width: 220px;
            text-align: center;
            padding: 14px 22px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 1rem;
            color: #6c757d;
            text-decoration: none;
            transition: all 0.25s ease;
            border: 2px solid #e9ecef;
            background: white;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .main-sm-tab:hover {
            color: var(--primary-color);
            border-color: var(--primary-color);
            background: var(--primary-light);
        }

        .main-sm-tab.active {
            color: white;
            border-color: transparent;
            background: linear-gradient(135deg, var(--primary-color), var(--primary-hover));
            box-shadow: 0 6px 18px rgba(128, 0, 0, 0.28);
        }

        .main-sm-tab i {
            margin-right: 8px;
        }

        .assignment-meta {
            font-size: 0.9rem;
            color: #6c757d;
        }

        #assignment-section-toolbar {
            scroll-margin-top: 88px;
        }

        #assignments-gap-banner {
            border-left: 5px solid #e65100;
            background: linear-gradient(90deg, #fff3e0 0%, #fffaf5 100%);
        }

        #assignments-gap-banner .assignments-gap-section-list li:last-child {
            border-bottom: 0 !important;
            margin-bottom: 0 !important;
            padding-bottom: 0 !important;
        }

        .subject-assign-block {
            border: 1px solid #e9ecef;
            border-radius: 12px;
            margin-bottom: 18px;
            overflow: hidden;
        }

        .subject-assign-head {
            background: #f8f9fa;
            padding: 14px 18px;
            font-weight: 600;
            color: var(--primary-color);
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }

        .subject-assign-body {
            padding: 12px 18px;
        }

        .assign-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding: 10px 0;
            border-bottom: 1px solid #f0f0f0;
            flex-wrap: wrap;
        }

        .assign-row:last-child {
            border-bottom: none;
        }

        .role-badge-sm {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-left: 8px;
        }

        .role-badge-sm.primary {
            background: #d4edda;
            color: #155724;
        }

        .role-badge-sm.assistant {
            background: #cfe2ff;
            color: #084298;
        }
        
        .btn-primary-custom {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-hover));
            border: none;
            color: white !important;
            padding: 10px 20px;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 5px 15px rgba(128, 0, 0, 0.3);
        }
        
        .btn-primary-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(128, 0, 0, 0.4);
            background: linear-gradient(135deg, var(--primary-hover), var(--primary-color));
            color: white !important;
        }
        
        .table-custom {
            margin-bottom: 0;
        }
        
        .table-custom thead th {
            background: #f8f9fa;
            border: none;
            font-weight: 600;
            color: var(--text-dark);
            padding: 20px 15px;
            font-size: 0.95rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .table-custom tbody td {
            padding: 20px 15px;
            border-top: 1px solid #f0f0f0;
            vertical-align: middle;
        }
        
        .table-custom tbody tr {
            transition: all 0.3s ease;
        }
        
        .table-custom tbody tr:hover {
            background: #f8f9fa;
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
            justify-content: center;
        }
        
        .btn-action {
            width: 35px;
            height: 35px;
            border-radius: 8px;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            font-size: 0.9rem;
        }
        
        .btn-edit {
            background: #e3f2fd;
            color: #1976d2;
        }
        
        .btn-edit:hover {
            background: #1976d2;
            color: white;
            transform: scale(1.1);
        }
        
        .btn-archive {
            background: #fff3cd;
            color: #856404;
        }
        
        .btn-archive:hover {
            background: #ffc107;
            color: white;
            transform: scale(1.1);
        }
        
        .btn-restore {
            background: #d4edda;
            color: #155724;
        }
        
        .btn-restore:hover {
            background: #28a745;
            color: white;
            transform: scale(1.1);
        }
        
        .btn-action:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        .btn-primary-custom:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        .modal-content {
            border-radius: 20px;
            border: none;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
        }
        
        .modal-header {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-hover));
            color: white;
            border-radius: 20px 20px 0 0;
            padding: 25px 30px;
            border-bottom: none;
        }
        
        .modal-title {
            font-weight: 700;
            font-size: 1.5rem;
        }
        
        .btn-close {
            filter: invert(1);
        }
        
        .form-control-custom {
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 12px 15px;
            font-size: 1rem;
            transition: all 0.3s ease;
            accent-color: var(--primary-color);
        }

        /* Stronger focus styles to override browser default blue outlines
           Apply to inputs, selects, and textareas using the custom class */
        .form-control-custom:focus,
        input.form-control-custom:focus,
        select.form-control-custom:focus,
        textarea.form-control-custom:focus {
            outline: none !important;
            border-color: var(--primary-color) !important;
            box-shadow: 0 0 0 0.25rem rgba(128, 0, 0, 0.25) !important;
        }
        
        .alert-custom {
            border-radius: 15px;
            border: none;
            padding: 15px 20px;
            margin-bottom: 25px;
            font-weight: 500;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
        }
        
        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        .year-badge {
            background: var(--primary-light);
            padding: 5px 12px;
            border-radius: 20px;
            font-weight: 600;
            color: var(--primary-color);
            display: inline-block;
            font-size: 0.85rem;
            white-space: nowrap;
        }
        
        .teacher-name {
            color: var(--primary-color);
            font-weight: 600;
        }
        
        .teacher-tbd {
            color: #dc3545;
            font-style: italic;
            font-weight: 600;
        }
        
        .teacher-cell {
            max-width: 200px;
            word-wrap: break-word;
            overflow-wrap: break-word;
            word-break: break-word;
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 20px;
            }
            
            .header-section h1 {
                font-size: 2.2rem;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .card-header {
                flex-direction: column;
                align-items: stretch;
            }
            
            .tab-button {
                padding: 10px 16px;
                font-size: 0.9rem;
            }
        }
    </style>
</head>
<body>
    <?php include '../includes/side_bar.php'; ?>
    <?php include '../includes/navbar.php'; ?>
    <?php renderModernAlertSystem(); ?>

    <div class="main-content">
        <!-- Header Section -->
        <div class="header-section">
            <h1><i class="fas fa-layer-group me-3"></i>Section Management</h1>
            
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo count($active_sections); ?></div>
                    <div class="stat-label">Active Sections</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['grade_11_count'] ?? 0; ?></div>
                    <div class="stat-label">Grade 11 Sections</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['grade_12_count'] ?? 0; ?></div>
                    <div class="stat-label">Grade 12 Sections</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['archived_count'] ?? 0; ?></div>
                    <div class="stat-label">Archived Sections</div>
                </div>
            </div>
        </div>

        <nav class="main-sm-tabs" aria-label="Section module views">
            <a href="section_management.php?sm_tab=sections"
               class="main-sm-tab <?php echo $sm_tab === 'sections' ? 'active' : ''; ?>">
                <i class="fas fa-layer-group"></i>Sections
            </a>
            <a href="section_management.php?sm_tab=assignments<?php echo $selected_section_id > 0 ? '&section_id=' . (int)$selected_section_id : ''; ?>"
               class="main-sm-tab <?php echo $sm_tab === 'assignments' ? 'active' : ''; ?>">
                <i class="fas fa-chalkboard-user"></i>Teacher assignments
            </a>
        </nav>

        <?php if ($sm_tab === 'sections'): ?>
        <!-- Main Content Card -->
        <div class="dashboard-card">
        <div class="card-header">
    <div style="display: flex; align-items: center; gap: 15px;">
        <i class="fas fa-th-list"></i>
        <h3>Section Directory</h3>
    </div>

    <div style="display: flex; gap: 10px;">

        

        <!-- Add Section Button -->
        <button type="button"
            class="btn btn-primary-custom d-flex align-items-center justify-content-center"
            data-bs-toggle="modal"
            data-bs-target="#addSectionModal"
            style="height: 40px; min-width: 140px; font-size: 1rem; padding: 0 18px;"
            <?php echo $actions_disabled ? 'disabled' : ''; ?>>
            <i class="fas fa-plus me-2" style="color: #fff; font-size: 1.1rem;"></i>Add Section
        </button>

    </div>
</div>
 
            <!-- Tab navigation for Active and Archived sections -->
            <div class="tab-navigation">
                <button type="button" class="tab-button active" onclick="switchTab('active', this)">
                    <i class="fas fa-check-circle me-2"></i>Active Sections
                </button>
                <button type="button" class="tab-button" onclick="switchTab('archived', this)">
                    <i class="fas fa-archive me-2"></i>Archived Sections
                </button>
            </div>

            <!-- Added search bar and filter section (moved below tab navigation) -->
            <div style="display: flex; gap: 15px; margin-bottom: 20px; flex-wrap: wrap; align-items: center;">
                <!-- Updated search bar UI to match user_management.php with search icon inside input -->
                <div style="flex: 1; min-width: 250px;">
                    <div style="position: relative;">
                        <i class="fas fa-search" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #999;"></i>
                        <input 
                            type="text" 
                            id="searchSection" 
                            class="form-control form-control-custom" 
                            placeholder="Search by section name..." 
                            style="height: 40px; padding-left: 38px;"
                            oninput="filterSections()">
                    </div>
                </div>
                <select 
                    id="filterYearLevel" 
                    class="form-control form-control-custom" 
                    style="max-width: 180px; height: 40px; padding: 10px 15px;"
                    onchange="filterSections()">
                    <option value="">All Grade Levels</option>
                    <option value="11">Grade 11</option>
                    <option value="12">Grade 12</option>
                </select>
                <select 
                    id="filterStrand" 
                    class="form-control form-control-custom" 
                    style="max-width: 200px; height: 40px; padding: 10px 15px;"
                    onchange="filterSections()">
                    <option value="">All Strands</option>
                    <option value="Accountancy, Business, and Management">ABM</option>
                    <option value="Humanities and Social Sciences">HUMSS</option>
                    <option value="Science, Technology, Engineering, Mathematics">STEM</option>
                    <option value="Information and Communication Technology">ICT</option>
                </select>
            </div>
            
            <!-- Active Sections Tab -->
            <div id="active" class="tab-content active">
                <?php if (count($active_sections) > 0): ?>
                    <table class="table table-custom" id="activeSectionsTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Section Name</th>
                                <th>Year Level</th>
                                <th>Strand</th>
                                <th>School Year</th>
                                <th>Semester</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($active_sections as $section): ?>
                                <tr class="section-row" data-year-level="<?php echo htmlspecialchars($section['year_level']); ?>" data-strand="<?php echo htmlspecialchars($section['strand']); ?>" data-section-name="<?php echo htmlspecialchars(strtolower($section['section_name'])); ?>">
                                    <td>
                                        <strong>#<?php echo str_pad($section['section_id'], 3, '0', STR_PAD_LEFT); ?></strong>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($section['section_name']); ?></strong>
                                    </td>
                                    <td>
                                        <span class="year-badge">Grade <?php echo htmlspecialchars($section['year_level']); ?></span>
                                    </td>
                                    <td>
                                        <small><?php echo htmlspecialchars(substr($section['strand'], 0, 25)) . (strlen($section['strand']) > 25 ? '...' : ''); ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary"><?php echo htmlspecialchars($section['school_year']); ?></span>
                                    </td>
                                    <td>
                                        <span class="badge bg-info">Sem <?php echo htmlspecialchars($section['semester']); ?></span>
                                    </td>
                                    <td class="text-center">
                                        <div class="action-buttons">
                                            <button type="button" class="btn btn-action btn-edit" 
                                                    onclick="editSection(<?php echo htmlspecialchars(json_encode($section)); ?>)"
                                                    data-bs-toggle="modal" data-bs-target="#editSectionModal"
                                                    title="Edit"
                                                    <?php echo $actions_disabled ? 'disabled' : ''; ?>>
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button type="button" class="btn btn-action btn-archive" 
                                                    onclick="archiveSection(<?php echo $section['section_id']; ?>, '<?php echo htmlspecialchars($section['section_name']); ?>')"
                                                    title="Archive"
                                                    <?php echo $actions_disabled ? 'disabled' : ''; ?>>
                                                <i class="fas fa-box"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <h4>No active sections found</h4>
                        <p>Add a new section to get started.</p>
                    </div>
                <?php endif; ?>
                <div id="activeNoResults" class="empty-state" style="display: none;">
                    <i class="fas fa-search"></i>
                    <h4>No sections match your search</h4>
                    <p>Try adjusting your search or filter criteria.</p>
                </div>
            </div>
            
            <!-- Archived Sections Tab -->
            <div id="archived" class="tab-content">
                <?php if (count($archived_sections) > 0): ?>
                    <table class="table table-custom" id="archivedSectionsTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Section Name</th>
                                <th>Year Level</th>
                                <th>Strand</th>
                                <th>School Year</th>
                                <th>Semester</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($archived_sections as $section): ?>
                                <tr class="section-row" data-year-level="<?php echo htmlspecialchars($section['year_level']); ?>" data-strand="<?php echo htmlspecialchars($section['strand']); ?>" data-section-name="<?php echo htmlspecialchars(strtolower($section['section_name'])); ?>">
                                    <td>
                                        <strong>#<?php echo str_pad($section['section_id'], 3, '0', STR_PAD_LEFT); ?></strong>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($section['section_name']); ?></strong>
                                    </td>
                                    <td>
                                        <span class="year-badge">Grade <?php echo htmlspecialchars($section['year_level']); ?></span>
                                    </td>
                                    <td>
                                        <small><?php echo htmlspecialchars(substr($section['strand'], 0, 25)) . (strlen($section['strand']) > 25 ? '...' : ''); ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary"><?php echo htmlspecialchars($section['school_year']); ?></span>
                                    </td>
                                    <td>
                                        <span class="badge bg-info">Sem <?php echo htmlspecialchars($section['semester']); ?></span>
                                    </td>
                                    <td class="text-center">
                                        <div class="action-buttons">
                                            <button type="button" class="btn btn-action btn-restore" 
                                                    onclick="restoreSection(<?php echo $section['section_id']; ?>, '<?php echo htmlspecialchars($section['section_name']); ?>')"
                                                    title="Restore"
                                                    <?php echo $actions_disabled ? 'disabled' : ''; ?>>
                                                <i class="fas fa-undo"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-archive"></i>
                        <h4>No archived sections</h4>
                        <p>Archived sections will appear here.</p>
                    </div>
                <?php endif; ?>

            </div>
        </div>

        <?php else: ?>

        <div class="dashboard-card assignment-card">
            <div class="card-header">
                <div style="display: flex; align-items: center; gap: 15px;">
                    <i class="fas fa-sitemap"></i>
                    <div>
                        <h3 style="margin:0;">Assign teachers to subjects</h3>
                        <p class="assignment-meta mb-0 mt-1">
                            Link each section’s subjects to teachers for
                            <strong><?php echo htmlspecialchars($current_sy['school_year'] ?? '—'); ?></strong>,
                            <?php echo ((int)($current_sy['semester'] ?? 1) === 2) ? '2nd' : '1st'; ?> semester.
                            Subjects match the section’s grade level, strand, and semester.
                            Only teachers listed under each subject in <strong>Subject Management</strong> (for this term) can be assigned here.
                        </p>
                    </div>
                </div>
            </div>

            <?php if (!empty($sections_with_assignment_gaps)): ?>
                <?php
                $first_gap_section_id = (int)($sections_with_assignment_gaps[0]['section_id'] ?? 0);
                $gap_section_count = count($sections_with_assignment_gaps);
                ?>
                <div id="assignments-gap-banner" class="alert alert-warning shadow-sm mb-4 py-3 px-3 px-md-4" role="alert">
                    <div class="d-flex flex-wrap align-items-start gap-3">
                        <div class="fs-3 text-warning flex-shrink-0 lh-1 pt-1">
                            <i class="fas fa-user-clock" aria-hidden="true"></i>
                        </div>
                        <div class="flex-grow-1 min-w-0">
                            <h4 class="h5 mb-2 fw-bold text-dark">
                                Sections with unassigned subjects
                            </h4>
                            <p class="mb-2 small text-dark">
                                <strong><?php echo (int)$gap_section_count; ?></strong>
                                section<?php echo $gap_section_count === 1 ? '' : 's'; ?> still <?php echo $gap_section_count === 1 ? 'has' : 'have'; ?>
                                one or more subjects <strong>without a teacher assigned</strong> for this term.
                                Assignments below update this list after you save changes.
                            </p>
                            <ul class="assignments-gap-section-list list-unstyled mb-3 small">
                                <?php foreach ($sections_with_assignment_gaps as $gapRow): ?>
                                    <?php
                                    $gSid = (int)$gapRow['section_id'];
                                    $gTotal = (int)$gapRow['total_subjects'];
                                    $gUn = (int)$gapRow['unassigned_count'];
                                    $gNames = $gapRow['unassigned_names'] ?? [];
                                    $gShow = array_slice($gNames, 0, 6);
                                    $gRest = count($gNames) - count($gShow);
                                    $gapHref = '?sm_tab=assignments&section_id=' . $gSid . '#assignment-section-toolbar';
                                    ?>
                                    <li class="mb-2 pb-2 border-bottom border-warning-subtle">
                                        <div class="d-flex flex-wrap align-items-baseline gap-2">
                                            <a class="fw-semibold text-decoration-none" style="color: var(--primary-color);" href="<?php echo htmlspecialchars($gapHref); ?>">
                                                <?php echo htmlspecialchars($gapRow['section_name']); ?>
                                                <span class="text-muted fw-normal">· Grade <?php echo htmlspecialchars($gapRow['year_level']); ?></span>
                                            </a>
                                            <span class="badge rounded-pill bg-warning text-dark">
                                                <?php echo $gUn; ?> / <?php echo $gTotal; ?> unassigned
                                            </span>
                                        </div>
                                        <div class="text-muted mt-1" style="font-size: 0.85rem;">
                                            <?php if ($gUn === $gTotal): ?>
                                                All subjects in this section need a teacher.
                                            <?php else: ?>
                                                Some subjects still need a teacher (<?php echo (int)$gUn; ?> of <?php echo (int)$gTotal; ?>).
                                            <?php endif; ?>
                                            <?php if ($gShow !== []): ?>
                                                <span class="d-block mt-1">
                                                    <span class="fw-semibold text-secondary">Subjects:</span>
                                                    <?php echo htmlspecialchars(implode(', ', $gShow)); ?>
                                                    <?php if ($gRest > 0): ?>
                                                        <span class="text-nowrap"> — and <?php echo (int)$gRest; ?> more</span>
                                                    <?php endif; ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <div class="d-flex flex-wrap gap-2 align-items-center">
                                <a class="btn btn-sm btn-outline-dark" href="#assignment-section-toolbar">
                                    <i class="fas fa-list-ul me-1"></i>Jump to section picker
                                </a>
                                <?php if ($first_gap_section_id > 0): ?>
                                    <a class="btn btn-sm btn-primary-custom" href="?sm_tab=assignments&section_id=<?php echo $first_gap_section_id; ?>#assignment-section-toolbar">
                                        <i class="fas fa-location-arrow me-1"></i>Open first section with gaps
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div id="assignment-section-toolbar" class="row align-items-end mb-4">
                <div class="col-md-8 mb-3 mb-md-0">
                    <label class="form-label fw-semibold" for="assignmentSectionSelect">Section</label>
                    <form method="get" id="assignmentSectionForm">
                        <input type="hidden" name="sm_tab" value="assignments">
                        <select class="form-control form-control-custom" name="section_id" id="assignmentSectionSelect"
                                onchange="document.getElementById('assignmentSectionForm').submit();">
                            <option value="">Select a section</option>
                            <?php foreach ($sections_for_assign as $section): ?>
                                <?php
                                $optSecId = (int)$section['section_id'];
                                $optHasGap = !empty($assignment_gap_section_ids[$optSecId]);
                                $optLabel = $section['section_name'] . ' · Grade ' . $section['year_level'];
                                if ($optHasGap) {
                                    $optLabel .= ' — needs teacher(s)';
                                }
                                ?>
                                <option value="<?php echo $optSecId; ?>"
                                    data-assignment-gap="<?php echo $optHasGap ? '1' : '0'; ?>"
                                    <?php echo ($optSecId === $selected_section_id) ? 'selected' : ''; ?>
                                    <?php if ($optHasGap): ?> class="fw-semibold" style="color: #b45309;"<?php endif; ?>>
                                    <?php echo htmlspecialchars($optLabel); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
                <div class="col-md-4">
                    <p class="assignment-meta mb-0">
                        <?php if (empty($sections_for_assign)): ?>
                            No active sections match the current school year and semester. Add or restore sections in the <strong>Sections</strong> tab.
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <?php if ($selected_section): ?>
                <?php
                $selected_has_assignment_gap = !empty($assignment_gap_section_ids[(int)$selected_section['section_id']]);
                $summaryCardStyle = 'box-shadow:none;' . ($selected_has_assignment_gap
                    ? 'background:#fffbf5;border:2px solid #ff9800;'
                    : 'border:1px solid #e9ecef;');
                ?>
                <div class="dashboard-card" style="<?php echo $summaryCardStyle; ?>">
                    <div class="row text-center text-md-start">
                        <div class="col-md-3 mb-2">
                            <small class="text-muted d-block">Section</small>
                            <strong><?php echo htmlspecialchars($selected_section['section_name']); ?></strong>
                            <?php if ($selected_has_assignment_gap): ?>
                                <span class="badge bg-warning text-dark ms-1 align-middle">Unassigned subject(s)</span>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-3 mb-2">
                            <small class="text-muted d-block">Year level</small>
                            <strong>Grade <?php echo htmlspecialchars($selected_section['year_level']); ?></strong>
                        </div>
                        <div class="col-md-6 mb-2">
                            <small class="text-muted d-block">Strand</small>
                            <strong><?php echo htmlspecialchars($selected_section['strand']); ?></strong>
                        </div>
                    </div>
                </div>

                <?php
                $subjects_without_qualified_teacher = 0;
                foreach ($section_subjects as $gqSub) {
                    $gqId = (int)$gqSub['subject_id'];
                    if (empty($subject_qualified_teacher_ids[$gqId])) {
                        $subjects_without_qualified_teacher++;
                    }
                }
                ?>
                <?php if (count($section_subjects) > 0 && count($teachers_for_assign) > 0): ?>
                    <?php if ($subjects_without_qualified_teacher > 0): ?>
                        <div class="alert alert-warning mt-3 mb-0 py-2" role="alert">
                            <small>
                                <?php echo (int)$subjects_without_qualified_teacher; ?> subject(s) have no teachers listed in Subject Management for this term—assign qualified teachers there before they can be selected below.
                            </small>
                        </div>
                    <?php endif; ?>
                    <div class="p-3 mt-3 rounded" style="background:#f8f9fa;">
                        <h5 class="mb-3"><i class="fas fa-plus-circle me-2"></i>Add assignment</h5>
                        <form method="post" id="addAssignmentForm">
                            <input type="hidden" name="action" value="add_assignment">
                            <input type="hidden" name="section_id" value="<?php echo (int)$selected_section_id; ?>">
                            <input type="hidden" name="redirect_sm_tab" value="assignments">
                            <input type="hidden" name="redirect_section_id" value="<?php echo (int)$selected_section_id; ?>">
                            <div class="row g-3 align-items-end">
                                <div class="col-md-5">
                                    <label class="form-label">Subject</label>
                                    <select class="form-control form-control-custom" name="subject_id" id="assignmentSubjectSelect" required>
                                        <option value="">Select subject</option>
                                        <?php foreach ($section_subjects as $sub): ?>
                                            <?php $sidOpt = (int)$sub['subject_id']; ?>
                                            <option value="<?php echo $sidOpt; ?>">
                                                <?php echo htmlspecialchars($sub['subject_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label">Teacher</label>
                                    <select class="form-control form-control-custom" name="teacher_id" id="assignmentTeacherSelect" required>
                                        <option value="">Select teacher</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label d-none d-md-block">&nbsp;</label>
                                    <button type="submit" class="btn btn-primary-custom w-100" <?php echo $actions_disabled ? 'disabled' : ''; ?>>
                                        <i class="fas fa-plus me-1"></i>Add
                                    </button>
                                </div>
                            </div>
                            <small class="text-muted d-block mt-2">
                                Roles (Primary vs Assistant) follow Subject Management for this teacher and subject—they are filled in automatically when you assign.
                            </small>
                        </form>
                        <?php if (!empty($assignment_ui_config)): ?>
                        <script>
                        window.__assignmentUi = <?php echo json_encode($assignment_ui_config, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
                        </script>
                        <?php endif; ?>
                    </div>
                <?php elseif (count($section_subjects) === 0): ?>
                    <div class="empty-state py-4">
                        <i class="fas fa-book"></i>
                        <h4>No subjects for this section</h4>
                        <p class="mb-0">Ensure subjects exist in Subject Management for this strand, grade level, and semester.</p>
                    </div>
                <?php else: ?>
                    <div class="empty-state py-4">
                        <i class="fas fa-user-slash"></i>
                        <h4>No teachers for this term</h4>
                        <p class="mb-0">No active teachers are registered for the current school year and semester.</p>
                    </div>
                <?php endif; ?>

                <?php if (count($section_subjects) > 0): ?>
                    <!-- Unassigned Subjects Notification -->
                    <?php
                    $unassigned_subjects = [];
                    foreach ($section_subjects as $sub):
                        $sid = (int)$sub['subject_id'];
                        if (empty($assignments_by_subject[$sid]['teachers'])) {
                            $unassigned_subjects[] = [
                                'id' => $sid,
                                'name' => htmlspecialchars($sub['subject_name'])
                            ];
                        }
                    endforeach;
                    ?>
                    
                    <?php if (!empty($unassigned_subjects)): ?>
                    <div class="alert alert-warning" role="alert" style="border-left: 5px solid #ff9800; background: #fff8e1; margin-top: 25px; margin-bottom: 25px;">
                        <div style="display: flex; align-items: flex-start; gap: 15px;">
                            <div style="font-size: 1.5rem; color: #ff9800; flex-shrink: 0;">
                                <i class="fas fa-exclamation-triangle"></i>
                            </div>
                            <div style="flex: 1;">
                                <h5 style="margin: 0 0 12px 0; color: #f57c00; font-weight: 600;">
                                    <i class="fas fa-tasks me-2"></i>Unassigned Teaching Loads
                                </h5>
                                <p style="margin: 0 0 12px 0; color: #666; line-height: 1.5;">
                                    The following <?php echo count($unassigned_subjects); ?> subject(s) in this section do <strong>not have a teacher assigned</strong>. Please assign qualified teachers to ensure complete coverage.
                                </p>
                                <div style="background: white; padding: 12px 15px; border-radius: 8px; margin-bottom: 0;">
                                    <?php foreach ($unassigned_subjects as $idx => $unsub): ?>
                                        <div style="display: flex; align-items: center; gap: 10px; padding: 8px 0; <?php echo $idx < count($unassigned_subjects) - 1 ? 'border-bottom: 1px solid #ffe0b2;' : ''; ?>">
                                            <span style="display: inline-block; width: 24px; height: 24px; background: #ffcccc; border-radius: 50%; text-align: center; line-height: 24px; font-size: 0.85rem; color: #c62828; font-weight: 600;">
                                                <?php echo $idx + 1; ?>
                                            </span>
                                            <span style="color: #333; font-weight: 500;"><?php echo $unsub['name']; ?></span>
                                            <span style="margin-left: auto; font-size: 0.85rem; color: #f57c00; background: #ffe0b2; padding: 4px 10px; border-radius: 20px; font-weight: 600;">
                                                No teacher assigned
                                            </span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <h5 class="mt-4 mb-3"><i class="fas fa-list me-2"></i>Current assignments by subject</h5>
                    <?php
                    $has_any_assign = false;
                    foreach ($section_subjects as $sub):
                        $sid = (int)$sub['subject_id'];
                        if (empty($assignments_by_subject[$sid]['teachers'])) {
                            continue;
                        }
                        $has_any_assign = true;
                        ?>
                        <div class="subject-assign-block">
                            <div class="subject-assign-head">
                                <span><?php echo htmlspecialchars($sub['subject_name']); ?></span>
                                <span class="badge bg-primary"><?php echo count($assignments_by_subject[$sid]['teachers']); ?> teacher(s)</span>
                            </div>
                            <div class="subject-assign-body">
                                <?php foreach ($assignments_by_subject[$sid]['teachers'] as $as): ?>
                                    <div class="assign-row">
                                        <div>
                                            <span class="fw-semibold"><?php echo htmlspecialchars($as['teacher_name']); ?></span>
                                            <?php
                                                $lblRole = strtolower((string)($as['display_role'] ?? $as['role'] ?? 'primary'));
                                                $lblRole = ($lblRole === 'assistant') ? 'assistant' : 'primary';
                                            ?>
                                            <span class="role-badge-sm <?php echo $lblRole === 'assistant' ? 'assistant' : 'primary'; ?>">
                                                <?php echo $lblRole === 'assistant' ? 'Assistant' : 'Primary'; ?>
                                            </span>
                                        </div>
                                        <div class="d-flex gap-2 align-items-center flex-wrap">
                                            <?php if (count($assignments_by_subject[$sid]['teachers']) > 0): ?>
                                            <button type="button" class="btn btn-sm btn-outline-primary edit-assignment-btn" title="Edit"
                                                    data-bs-toggle="modal" data-bs-target="#editAssignmentModal"
                                                    data-assignment-id="<?php echo (int)$as['assignment_id']; ?>"
                                                    data-subject-id="<?php echo (int)$sid; ?>"
                                                    data-subject-name="<?php echo htmlspecialchars($sub['subject_name'], ENT_QUOTES); ?>"
                                                    data-teacher-id="<?php echo (int)$as['teacher_id']; ?>"
                                                    data-teacher-name="<?php echo htmlspecialchars($as['teacher_name'], ENT_QUOTES); ?>"
                                                    <?php echo $actions_disabled ? 'disabled' : ''; ?>>
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <?php endif; ?>
                                            <form method="post" onsubmit="return confirm('Remove this assignment?');">
                                                <input type="hidden" name="action" value="remove_assignment">
                                                <input type="hidden" name="assignment_id" value="<?php echo (int)$as['assignment_id']; ?>">
                                                <input type="hidden" name="redirect_sm_tab" value="assignments">
                                                <input type="hidden" name="redirect_section_id" value="<?php echo (int)$selected_section_id; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Remove"
                                                        <?php echo $actions_disabled ? 'disabled' : ''; ?>>
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    
                    <!-- All Assignments Complete Message -->
                    <?php if ($has_any_assign && empty($unassigned_subjects)): ?>
                    <div class="alert alert-success" role="alert" style="border-left: 5px solid #28a745; background: #f0f7f4; margin-top: 25px; margin-bottom: 0;">
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <div style="font-size: 1.3rem; color: #28a745; flex-shrink: 0;">
                                <i class="fas fa-check-circle"></i>
                            </div>
                            <div>
                                <strong style="color: #155724;">All subjects have teachers assigned</strong>
                                <p style="margin: 4px 0 0 0; color: #666; font-size: 0.9rem;">This section's teaching load is complete. All <?php echo count($section_subjects); ?> subject(s) have qualified teachers.</p>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!$has_any_assign && count($section_subjects) > 0): ?>
                        <div class="empty-state py-3">
                            <p class="mb-0 text-muted">No teacher assignments yet. Use the form above to assign teachers to each subject.</p>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

            <?php else: ?>
                <div class="empty-state py-5">
                    <i class="fas fa-hand-pointer"></i>
                    <h4>Select a section</h4>
                    <p class="mb-0">Choose a section to view subjects and manage teacher assignments.</p>
                </div>
            <?php endif; ?>
        </div>

        <?php endif; ?>
    </div>

    <!-- Add Section Modal -->
    <div class="modal fade" id="addSectionModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-layer-group me-2"></i>Add New Section
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" id="addSectionForm">
                        <input type="hidden" name="action" value="add">
                        <div class="mb-3">
                            <label for="add_section_name" class="form-label">Section Name *</label>
                            <input type="text" class="form-control form-control-custom" id="add_section_name" 
                                   name="section_name" placeholder="e.g., ABM 11-A" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="add_year_level" class="form-label">Year Level *</label>
                                <select class="form-control form-control-custom" id="add_year_level" 
                                        name="year_level" required>
                                    <option value="">Select Year Level</option>
                                    <option value="11">Grade 11</option>
                                    <option value="12">Grade 12</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="add_strand" class="form-label">Strand *</label>
                                <select class="form-control form-control-custom" id="add_strand" 
                                        name="strand" required>
                                    <option value="">Select Strand</option>
                                    <option value="Accountancy, Business, and Management">Accountancy, Business, and Management</option>
                                    <option value="Humanities and Social Sciences">Humanities and Social Sciences</option>
                                    <option value="Science, Technology, Engineering, Mathematics">Science, Technology, Engineering, Mathematics</option>
                                    <option value="Information and Communication Technology">Information and Communication Technology</option>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="add_school_year" class="form-label">School Year *</label>
                                <input type="text" class="form-control form-control-custom" id="add_school_year" 
                                       value="<?php echo htmlspecialchars($current_sy['school_year'] ?? ''); ?>" disabled>
                                <input type="hidden" name="school_year" id="add_school_year_hidden" value="<?php echo htmlspecialchars($current_sy['school_year'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="add_semester" class="form-label">Semester *</label>
                                <select class="form-control form-control-custom" id="add_semester" disabled>
                                    <option value="1" <?php echo ($current_sy['semester'] == 1) ? 'selected' : ''; ?>>1st Semester</option>
                                    <option value="2" <?php echo ($current_sy['semester'] == 2) ? 'selected' : ''; ?>>2nd Semester</option>
                                </select>
                                <input type="hidden" name="semester" id="add_semester_hidden" value="<?php echo htmlspecialchars($current_sy['semester'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="text-end">
                            <button type="button" class="btn btn-secondary me-2" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary-custom">
                                <i class="fas fa-save me-2"></i>Add Section
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Section Modal -->
    <div class="modal fade" id="editSectionModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-edit me-2"></i>Edit Section
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" id="editSectionForm">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="section_id" id="edit_section_id">
                        <div class="mb-3">
                            <label for="edit_section_name" class="form-label">Section Name *</label>
                            <input type="text" class="form-control form-control-custom" id="edit_section_name" 
                                   name="section_name" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="edit_year_level" class="form-label">Year Level *</label>
                                <select class="form-control form-control-custom" id="edit_year_level" 
                                        name="year_level" required>
                                    <option value="11">Grade 11</option>
                                    <option value="12">Grade 12</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="edit_strand" class="form-label">Strand *</label>
                                <select class="form-control form-control-custom" id="edit_strand" 
                                        name="strand" required>
                                    <option value="Accountancy, Business, and Management">Accountancy, Business, and Management</option>
                                    <option value="Humanities and Social Sciences">Humanities and Social Sciences</option>
                                    <option value="Science, Technology, Engineering, Mathematics">Science, Technology, Engineering, Mathematics</option>
                                    <option value="Information and Communication Technology">Information and Communication Technology</option>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="edit_school_year" class="form-label">School Year *</label>
                                <input type="text" class="form-control form-control-custom" id="edit_school_year_display" 
                                       value="<?php echo htmlspecialchars($current_sy['school_year'] ?? ''); ?>" disabled>
                                <input type="hidden" name="school_year" id="edit_school_year_hidden" value="<?php echo htmlspecialchars($current_sy['school_year'] ?? ''); ?>">
                                <small class="text-muted d-block mt-1">School year is set to the current system setting</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="edit_semester" class="form-label">Semester *</label>
                                <input type="text" class="form-control form-control-custom" id="edit_semester_display"
                                       value="<?php echo ($current_sy['semester'] == 1) ? '1st Semester' : '2nd Semester'; ?>" disabled>
                                <input type="hidden" name="semester" id="edit_semester_hidden" value="<?php echo htmlspecialchars($current_sy['semester'] ?? ''); ?>">
                                <small class="text-muted d-block mt-1">Semester is set to the current system setting</small>
                            </div>
                        </div>
                        <div class="text-end">
                            <button type="button" class="btn btn-secondary me-2" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary-custom">
                                <i class="fas fa-save me-2"></i>Update Section
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Assignment Modal -->
    <div class="modal fade" id="editAssignmentModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-edit me-2"></i>Edit Teacher Assignment
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" id="editAssignmentForm">
                        <input type="hidden" name="action" value="update_assignment">
                        <input type="hidden" name="assignment_id" id="edit_assignment_id">
                        <input type="hidden" name="redirect_sm_tab" value="assignments">
                        <input type="hidden" name="redirect_section_id" id="edit_assignment_section_id">
                        
                        <div class="mb-3">
                            <label class="form-label">Subject</label>
                            <input type="text" class="form-control form-control-custom" id="edit_assignment_subject" disabled>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Current Teacher</label>
                            <input type="text" class="form-control form-control-custom" id="edit_assignment_current_teacher" disabled>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_assignment_teacher" class="form-label">Replace with Teacher *</label>
                            <select class="form-control form-control-custom" name="teacher_id" id="edit_assignment_teacher" required>
                                <option value="">Select teacher</option>
                            </select>
                            <small class="text-muted d-block mt-1">Only teachers qualified for this subject are shown.</small>
                        </div>
                        
                        <div class="text-end">
                            <button type="button" class="btn btn-secondary me-2" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary-custom">
                                <i class="fas fa-save me-2"></i>Update Assignment
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <form method="POST" id="archiveSectionForm" style="display: none;">
        <input type="hidden" name="action" value="archive">
        <input type="hidden" name="section_id" id="archive_section_id">
    </form>

    <form method="POST" id="restoreSectionForm" style="display: none;">
        <input type="hidden" name="action" value="restore">
        <input type="hidden" name="section_id" id="restore_section_id">
    </form>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        <?php if (isset($_SESSION['success'])): ?>
            document.addEventListener('DOMContentLoaded', function() {
                showModernAlert('success', 'Success', <?php echo json_encode($_SESSION['success']); ?>, { autoCloseMs: 3000 });
            });
        <?php unset($_SESSION['success']); endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            document.addEventListener('DOMContentLoaded', function() {
                showModernAlert('error', 'Error', <?php echo json_encode($_SESSION['error']); ?>);
            });
        <?php unset($_SESSION['error']); endif; ?>

        <?php if ($actions_disabled): ?>
            document.addEventListener('DOMContentLoaded', function() {
                showModernAlert(
                    'warning',
                    'Section Management Disabled',
                    'Section management and teacher–subject assignments are temporarily disabled during the active evaluation period. Please try again after the evaluation ends.'
                );
            });
        <?php endif; ?>

        function filterSections() {
            const searchInput = document.getElementById('searchSection');
            if (!searchInput) return;
            const searchTerm = searchInput.value.toLowerCase();
            const yearLevelFilter = document.getElementById('filterYearLevel').value;
            const strandFilter = document.getElementById('filterStrand').value;
            
            // Get active tab to filter the correct table
            const activeTab = document.querySelector('.tab-content.active');
            if (!activeTab) return;
            const rows = activeTab.querySelectorAll('.section-row');
            
            let visibleCount = 0;
            
            rows.forEach(row => {
                const sectionName = row.getAttribute('data-section-name');
                const yearLevel = row.getAttribute('data-year-level');
                const strand = row.getAttribute('data-strand');
                
                // Check if row matches search term
                const matchesSearch = sectionName.includes(searchTerm);
                
                // Check if row matches year level filter
                const matchesYearLevel = !yearLevelFilter || yearLevel === yearLevelFilter;
                
                // Check if row matches strand filter
                const matchesStrand = !strandFilter || strand === strandFilter;
                
                // Show row if all conditions match
                if (matchesSearch && matchesYearLevel && matchesStrand) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });
            
            // Show/hide no results message
            const activeTabId = activeTab.id;
            const noResultsElement = document.getElementById(activeTabId + 'NoResults');
            const tableElement = document.getElementById(activeTabId + 'SectionsTable');
            
            if (visibleCount === 0) {
                if (noResultsElement) noResultsElement.style.display = 'block';
                if (tableElement) tableElement.style.display = 'none';
            } else {
                if (noResultsElement) noResultsElement.style.display = 'none';
                if (tableElement) tableElement.style.display = '';
            }
        }
        
        function switchTab(tabName, btn) {
            const tabContents = document.querySelectorAll('.tab-content');
            tabContents.forEach(content => {
                content.classList.remove('active');
            });
            
            const tabButtons = document.querySelectorAll('.tab-button');
            tabButtons.forEach(button => {
                button.classList.remove('active');
            });
            
            const panel = document.getElementById(tabName);
            if (panel) panel.classList.add('active');
            if (btn) btn.classList.add('active');
            
            filterSections();
        }
        
        // Initialize Add Section Modal with default values and event listeners
        const addSectionModal = document.getElementById('addSectionModal');
        if (addSectionModal) {
            addSectionModal.addEventListener('show.bs.modal', function () {
                // Keep School Year and Semester fields disabled (read-only) and visible
                const schoolYearField = document.getElementById('add_school_year');
                const semesterField = document.getElementById('add_semester');
                
                // Ensure fields are disabled (read-only) but visible
                if (schoolYearField) {
                    schoolYearField.disabled = true;
                    schoolYearField.style.display = '';
                }
                if (semesterField) {
                    semesterField.disabled = true;
                    semesterField.style.display = '';
                }
            });
        }
        
        function editSection(section) {
            document.getElementById('edit_section_id').value = section.section_id;
            document.getElementById('edit_section_name').value = section.section_name;
            document.getElementById('edit_year_level').value = section.year_level;
            document.getElementById('edit_strand').value = section.strand;
            document.getElementById('edit_school_year_hidden').value = section.school_year;
            document.getElementById('edit_semester_hidden').value = section.semester;
            const syDisp = document.getElementById('edit_school_year_display');
            if (syDisp) syDisp.value = section.school_year || '';
            const semDisp = document.getElementById('edit_semester_display');
            if (semDisp) semDisp.value = String(section.semester) === '2' ? '2nd Semester' : '1st Semester';
        }
        
        function editAssignment(assignment) {
            // Populate assignment ID and section ID for redirect
            document.getElementById('edit_assignment_id').value = assignment.assignment_id;
            document.getElementById('edit_assignment_section_id').value = document.querySelector('select[name="section_id"]')?.value || '';
            
            // Display subject and current teacher (read-only)
            document.getElementById('edit_assignment_subject').value = assignment.subject_name;
            document.getElementById('edit_assignment_current_teacher').value = assignment.teacher_name;
            
            // Populate teacher dropdown with qualified teachers, excluding current teacher
            const teacherSelect = document.getElementById('edit_assignment_teacher');
            teacherSelect.innerHTML = '<option value="">Select teacher</option>';
            
            const cfg = window.__assignmentUi;
            if (!cfg) return;
            
            // Get qualified teachers for this subject
            const subject_id_str = String(assignment.subject_id);
            const allowedTeacherIds = (cfg.subjectTeachers || {})[subject_id_str];
            if (!Array.isArray(allowedTeacherIds)) return;
            
            const currentTeacherId = assignment.teacher_id;
            const teachers = cfg.teachers || [];
            
            // Add only teachers qualified for this subject, excluding the current one
            allowedTeacherIds.forEach(tid => {
                if (tid === currentTeacherId) return; // Skip current teacher
                
                const teacherMeta = teachers.find(t => t.id === tid);
                if (!teacherMeta) return;
                
                const option = document.createElement('option');
                option.value = tid;
                option.textContent = teacherMeta.label;
                teacherSelect.appendChild(option);
            });
        }
        
        // Initialize Edit Assignment Modal - read data from button attributes
        const editAssignmentModal = document.getElementById('editAssignmentModal');
        if (editAssignmentModal) {
            editAssignmentModal.addEventListener('show.bs.modal', function (event) {
                const button = event.relatedTarget;
                if (!button) return;
                
                const assignment = {
                    assignment_id: parseInt(button.getAttribute('data-assignment-id'), 10),
                    subject_id: parseInt(button.getAttribute('data-subject-id'), 10),
                    subject_name: button.getAttribute('data-subject-name'),
                    teacher_id: parseInt(button.getAttribute('data-teacher-id'), 10),
                    teacher_name: button.getAttribute('data-teacher-name')
                };
                
                editAssignment(assignment);
            });
        }
        
        // Initialize Edit Section Modal
        const editSectionModal = document.getElementById('editSectionModal');
        if (editSectionModal) {
            editSectionModal.addEventListener('show.bs.modal', function () {
                const schoolYearField = document.getElementById('edit_school_year_display');
                const semesterField = document.getElementById('edit_semester_display');
                
                if (schoolYearField) schoolYearField.disabled = true;
                if (semesterField) semesterField.disabled = true;
            });
        }
        
        function archiveSection(sectionId, sectionName) {
            document.getElementById('archive_section_id').value = sectionId;
            showModernConfirm(
                'Archive Section',
                `Archive "${sectionName}"? You can restore it later from archived sections.`,
                function() {
                    document.getElementById('archiveSectionForm').submit();
                },
                { confirmText: 'Archive' }
            );
        }
        
        function restoreSection(sectionId, sectionName) {
            document.getElementById('restore_section_id').value = sectionId;
            showModernConfirm(
                'Restore Section',
                `Restore "${sectionName}" back to active sections?`,
                function() {
                    document.getElementById('restoreSectionForm').submit();
                },
                { confirmText: 'Restore' }
            );
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            const tableRows = document.querySelectorAll('.table-custom tbody tr');
            tableRows.forEach((row, index) => {
                row.style.opacity = '0';
                row.style.transform = 'translateY(20px)';
                
                setTimeout(() => {
                    row.style.transition = 'all 0.6s ease';
                    row.style.opacity = '1';
                    row.style.transform = 'translateY(0)';
                }, index * 50);
            });
            if (typeof assignmentInitPairingSelectors === 'function') {
                assignmentInitPairingSelectors();
            }
        });

        /**
         * Rebuild subject/teacher dropdowns from subject_teacher_assignments maps (no disabled <option> quirks).
         */
        function assignmentInitPairingSelectors() {
            const subjectSel = document.getElementById('assignmentSubjectSelect');
            const teacherSel = document.getElementById('assignmentTeacherSelect');
            const cfg = window.__assignmentUi;
            if (!subjectSel || !teacherSel || !cfg) return;

            function allowedTeacherIds(sidStr) {
                const arr = (cfg.subjectTeachers || {})[sidStr];
                return Array.isArray(arr) ? arr.map(function (x) { return parseInt(x, 10); }).filter(function (n) { return n > 0; }) : [];
            }
            function allowedSubjectIdsTeacher(tidStr) {
                const arr = (cfg.teacherSubjects || {})[tidStr];
                return Array.isArray(arr) ? arr.map(function (x) { return parseInt(x, 10); }).filter(function (n) { return n > 0; }) : [];
            }
            function selHas(selectEl, val) {
                if (val === null || val === undefined || String(val) === '') return false;
                return Array.from(selectEl.options).some(function (o) { return o.value === String(val); });
            }

            function rebuildSubjectsKeepTeacherHint() {
                const tidStr = teacherSel.value;
                const prevSub = subjectSel.value;
                subjectSel.innerHTML = '<option value="">Select subject</option>';
                (cfg.subjects || []).forEach(function (s) {
                    const aids = allowedTeacherIds(String(s.id));
                    if (aids.length === 0) return;
                    if (tidStr) {
                        var ok = aids.some(function (id) { return String(id) === String(tidStr); });
                        if (!ok) return;
                    }
                    var o = document.createElement('option');
                    o.value = String(s.id);
                    o.textContent = s.name;
                    subjectSel.appendChild(o);
                });
                if (selHas(subjectSel, prevSub)) subjectSel.value = prevSub;
                else subjectSel.value = '';
            }

            function rebuildTeachersList() {
                const sidStr = subjectSel.value;
                const prevT = teacherSel.value;
                teacherSel.innerHTML = '<option value="">Select teacher</option>';
                var teachersMeta = cfg.teachers || [];
                if (sidStr) {
                    allowedTeacherIds(String(sidStr)).forEach(function (tid) {
                        var meta = teachersMeta.find(function (x) { return String(x.id) === String(tid); });
                        if (!meta) return;
                        var to = document.createElement('option');
                        to.value = String(tid);
                        to.textContent = meta.label;
                        teacherSel.appendChild(to);
                    });
                } else {
                    teachersMeta.forEach(function (t) {
                        if (allowedSubjectIdsTeacher(String(t.id)).length === 0) return;
                        var to = document.createElement('option');
                        to.value = String(t.id);
                        to.textContent = t.label;
                        teacherSel.appendChild(to);
                    });
                }
                if (selHas(teacherSel, prevT)) teacherSel.value = prevT;
                else teacherSel.value = '';
            }

            subjectSel.addEventListener('change', function () {
                rebuildTeachersList();
                var tidStr = teacherSel.value;
                var sidStr = subjectSel.value;
                if (tidStr && sidStr) {
                    var ok = allowedTeacherIds(String(sidStr)).some(function (id) { return String(id) === String(tidStr); });
                    if (!ok) teacherSel.value = '';
                    rebuildTeachersList();
                }
            });
            teacherSel.addEventListener('change', function () {
                rebuildSubjectsKeepTeacherHint();
                var sidStr = subjectSel.value;
                var tidStr = teacherSel.value;
                if (sidStr && tidStr) {
                    var ok = allowedTeacherIds(String(sidStr)).some(function (id) { return String(id) === String(tidStr); });
                    if (!ok) subjectSel.value = '';
                    rebuildTeachersList();
                    rebuildSubjectsKeepTeacherHint();
                } else if (!tidStr) {
                    rebuildTeachersList();
                }
            });

            rebuildSubjectsKeepTeacherHint();
            rebuildTeachersList();
        }

        
        const addSectionFormEl = document.getElementById('addSectionForm');
        if (addSectionFormEl) {
            addSectionFormEl.addEventListener('submit', function(e) {
                const name = document.getElementById('add_section_name').value.trim();
                const yearLevel = document.getElementById('add_year_level').value;
                const strand = document.getElementById('add_strand').value;
                const schoolYear = document.getElementById('add_school_year_hidden').value;
                const semester = document.getElementById('add_semester_hidden').value;
                
                if (!name || !yearLevel || !strand || !schoolYear || !semester) {
                    e.preventDefault();
                    showModernAlert('warning', 'Validation Required', 'Section Name, Year Level, Strand, School Year, and Semester are required.');
                    return false;
                }
            });
        }
    </script>
</body>
</html>
