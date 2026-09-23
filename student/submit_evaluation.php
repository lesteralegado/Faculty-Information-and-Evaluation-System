<?php
session_start();

require_once __DIR__ . '/../includes/student_access_control.php';
requireStudentAccess('full');

// Include database connection
include '../includes/db_connection.php';

// Check if evaluation data exists in session
if (!isset($_SESSION['evaluation_data'])) {
    header("Location: evaluation_form.php");
    exit();
}

/**
 * Normalize school year for comparisons (DB may use hyphen or en/em dash).
 */
function normalizeSchoolYearForDb(string $sy): string
{
    return str_replace(["\u{2013}", "\u{2014}"], "-", $sy);
}

// Get evaluation data from session
$evaluation_data = $_SESSION['evaluation_data'];
$evaluation_target_id = isset($evaluation_data['evaluation_target_id']) ? (int)$evaluation_data['evaluation_target_id'] : 0;
$subject_id = isset($evaluation_data['subject_id']) ? (int)$evaluation_data['subject_id'] : 0;
$teacher_id_post = isset($evaluation_data['teacher_id']) && $evaluation_data['teacher_id'] !== ''
    ? (int)$evaluation_data['teacher_id']
    : null;
$responses = isset($evaluation_data['responses']) && is_array($evaluation_data['responses'])
    ? $evaluation_data['responses']
    : [];

// Get student information (match evaluation_form.php: prefer profile row for current term)
$student_username = $_SESSION['username'];

// Determine current school year and semester from settings (same source as evaluation_form.php)
$ys_stmt = $conn->prepare("SELECT school_year, semester FROM currentschoolyearandsemester LIMIT 1");
$ys_stmt->execute();
$ys_row = $ys_stmt->get_result()->fetch_assoc();
$ys_stmt->close();

$current_school_year = $ys_row['school_year'] ?? '2025-2026';
$current_semester_num = isset($ys_row['semester']) ? (int)$ys_row['semester'] : 1;
$school_year = $current_school_year;

$school_year_norm = normalizeSchoolYearForDb($school_year);

$stu_stmt = $conn->prepare(
    "SELECT s.student_id, s.section_id FROM students s
     INNER JOIN users u ON s.user_id = u.user_id
     WHERE u.account_number = ?
       AND REPLACE(REPLACE(IFNULL(s.school_year, ''), '–', '-'), '—', '-') = ?
       AND s.semester = ?
     ORDER BY s.student_id DESC
     LIMIT 1"
);
$stu_stmt->bind_param("ssi", $student_username, $school_year_norm, $current_semester_num);
$stu_stmt->execute();
$stu_result = $stu_stmt->get_result();

if ($stu_result->num_rows === 0) {
    $stu_stmt->close();
    $stu_stmt = $conn->prepare(
        "SELECT s.student_id, s.section_id FROM students s
         INNER JOIN users u ON s.user_id = u.user_id
         WHERE u.account_number = ?
         ORDER BY s.student_id DESC
         LIMIT 1"
    );
    $stu_stmt->bind_param("s", $student_username);
    $stu_stmt->execute();
    $stu_result = $stu_stmt->get_result();
}

if ($stu_result->num_rows === 0) {
    $stu_stmt->close();
    die("Student not found");
}

$student_data = $stu_result->fetch_assoc();
$student_id = (int)$student_data['student_id'];
$section_id = isset($student_data['section_id']) ? (int)$student_data['section_id'] : 0;
$stu_stmt->close();
$dashEn = "\u{2013}";
$dashEm = "\u{2014}";

if ($subject_id <= 0) {
    header("Location: evaluation_form.php?error=submission_failed&message=" . urlencode("Invalid subject."));
    exit();
}

// Check if student has already submitted evaluation for this subject+teacher in the active period
$dup_stmt = $conn->prepare(
    "SELECT evaluation_id FROM evaluations
     WHERE student_id = ? AND subject_id = ? AND teacher_id = ?
       AND REPLACE(REPLACE(school_year,'{$dashEn}','-'),'{$dashEm}','-') = ? AND semester = ? LIMIT 1"
);
$teacher_id_for_dup = (int)($teacher_id_post ?? 0);
$dup_stmt->bind_param("iiisi", $student_id, $subject_id, $teacher_id_for_dup, $school_year_norm, $current_semester_num);
$dup_stmt->execute();
$existing_result = $dup_stmt->get_result();

if ($existing_result->num_rows > 0) {
    $dup_stmt->close();
    unset($_SESSION['evaluation_data']);
    header("Location: evaluation_form.php?error=already_submitted");
    exit();
}
$dup_stmt->close();

// Teacher is required and must match an active evaluation target for this student/section/term
$teacher_id = (int)($teacher_id_post ?? 0);
if ($teacher_id <= 0) {
    header("Location: evaluation_form.php?error=submission_failed&message=" . urlencode("Invalid teacher."));
    exit();
}

// Database schema is installed with deployment/schema-repair.sql, not during requests.

// Validate evaluation target: Check if target exists in evaluation_targets table
// For target_id = 0 (fallback mode), verify teacher exists for the subject instead
$is_valid_target = false;

if ($evaluation_target_id > 0) {
    // Explicit evaluation target mode: validate the target exists and matches all criteria
    $target_stmt = $conn->prepare(
        "SELECT evaluation_target_id FROM evaluation_targets
          WHERE is_active = 1
            AND evaluation_target_id = ?
            AND section_id = ?
            AND subject_id = ?
            AND teacher_id = ?
            AND REPLACE(REPLACE(school_year,'{$dashEn}','-'),'{$dashEm}','-') = ?
            AND semester = ?
          LIMIT 1"
    );
    if (!$target_stmt) {
        header("Location: evaluation_form.php?error=submission_failed&message=" . urlencode("Server error validating evaluation target."));
        exit();
    }
    $target_stmt->bind_param("iiiisi", $evaluation_target_id, $section_id, $subject_id, $teacher_id, $school_year_norm, $current_semester_num);
    $target_stmt->execute();
    $target_row = $target_stmt->get_result()->fetch_assoc();
    $target_stmt->close();
    
    if ($target_row) {
        $is_valid_target = true;
    }
} else {
    // Fallback mode (target_id = 0): validate section–subject–teacher assignment, else subject-level teachers
    if ($section_id > 0) {
        $sec_assign_stmt = $conn->prepare(
            "SELECT 1 AS ok FROM section_subject_teacher_assignments ssta
             WHERE ssta.section_id = ?
               AND ssta.subject_id = ?
               AND ssta.teacher_id = ?
               AND REPLACE(REPLACE(ssta.school_year,'{$dashEn}','-'),'{$dashEm}','-') = ?
               AND ssta.semester = ?
             LIMIT 1"
        );
        if ($sec_assign_stmt) {
            $sec_assign_stmt->bind_param("iiisi", $section_id, $subject_id, $teacher_id, $school_year_norm, $current_semester_num);
            $sec_assign_stmt->execute();
            $sec_assign_row = $sec_assign_stmt->get_result()->fetch_assoc();
            $sec_assign_stmt->close();
            if ($sec_assign_row) {
                $is_valid_target = true;
            }
        }
    }
    if (!$is_valid_target) {
        $fallback_check = $conn->prepare(
            "SELECT t.teacher_id FROM teachers t
              JOIN subjects s ON t.teacher_id = s.teacher_id OR EXISTS (
                  SELECT 1 FROM subject_teacher_assignments sta
                  WHERE sta.subject_id = s.subject_id AND sta.teacher_id = t.teacher_id
                    AND REPLACE(REPLACE(sta.school_year,'{$dashEn}','-'),'{$dashEm}','-') = ?
                    AND sta.semester = ?
              )
              WHERE t.teacher_id = ? AND s.subject_id = ? AND s.status = 'active'
              LIMIT 1"
        );
        if ($fallback_check) {
            $fallback_check->bind_param("siii", $school_year_norm, $current_semester_num, $teacher_id, $subject_id);
            $fallback_check->execute();
            $fallback_row = $fallback_check->get_result()->fetch_assoc();
            $fallback_check->close();

            if ($fallback_row) {
                $is_valid_target = true;
            }
        }
    }
}

if (!$is_valid_target) {
    header("Location: evaluation_form.php?error=submission_failed&message=" . urlencode("This evaluation item is not assigned to you for the current term. Please refresh or contact the administrator."));
    exit();
}

// Get all active questions for this evaluation period
$questions_sql = "SELECT question_id, question, category_id, category_name 
                  FROM evaluation_questions 
                  WHERE is_active = 1 
                    AND REPLACE(REPLACE(school_year,'{$dashEn}','-'),'{$dashEm}','-') = ? 
                    AND semester = ?
                  ORDER BY category_id ASC, question_id ASC";
$questions_stmt = $conn->prepare($questions_sql);
$questions_stmt->bind_param("si", $school_year_norm, $current_semester_num);
$questions_stmt->execute();
$questions_result = $questions_stmt->get_result();

$questions_list = [];
$question_index = 0;
while ($q_row = $questions_result->fetch_assoc()) {
    $question_index++;
    $q_row['_display_order'] = $question_index;
    $questions_list[(int)$q_row['question_id']] = $q_row;
}
$questions_stmt->close();

if (count($questions_list) === 0) {
    header("Location: evaluation_form.php?error=submission_failed&message=" . urlencode("No evaluation questions are configured for this period."));
    exit();
}

// Require an answer for every active question (1-5)
$answered_question_ids = [];
$validation_error = '';
foreach ($questions_list as $qid => $qinfo) {
    $raw = $responses[$qid] ?? $responses[(string)$qid] ?? '';
    if ($raw === '' || $raw === null) {
        $validation_error = 'Please answer all questions before submitting.';
        break;
    }
    $rating = (int)$raw;
    if ($rating < 1 || $rating > 5) {
        $validation_error = 'Invalid rating submitted.';
        break;
    }
    $answered_question_ids[$qid] = $rating;
}

if ($validation_error !== '') {
    header("Location: evaluation_form.php?error=submission_failed&message=" . urlencode($validation_error));
    exit();
}

$total_rating = array_sum($answered_question_ids);
$overall_rating = round($total_rating / count($answered_question_ids), 2);

// Get subject name for success message
$subject_stmt = $conn->prepare("SELECT subject_name FROM subjects WHERE subject_id = ?");
$subject_stmt->bind_param("i", $subject_id);
$subject_stmt->execute();
$subject_result = $subject_stmt->get_result();
$subject_row = $subject_result->fetch_assoc();
$subject_name = $subject_row['subject_name'] ?? 'Unknown Subject';
$subject_stmt->close();

require_once __DIR__ . '/../includes/evaluation_submission.php';
try {
    app_save_evaluation($conn, $student_id, $teacher_id, $subject_id, $school_year_norm,
        $current_semester_num, $answered_question_ids, (string)($evaluation_data['comments'] ?? ''));
    unset($_SESSION['evaluation_data']);
    header("Location: evaluation_form.php?success=1&subject=" . urlencode($subject_name) . "&rating=" . urlencode((string)$overall_rating));
    exit();
} catch (InvalidArgumentException $error) {
    $message = $error->getMessage();
} catch (Throwable $error) {
    error_log('Evaluation submission failed: ' . $error->getMessage());
    $message = 'Your evaluation could not be saved. Please try again or contact the administrator.';
}
header("Location: evaluation_form.php?error=submission_failed&message=" . urlencode($message));
exit();
