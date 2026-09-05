<?php
session_start();

require_once __DIR__ . '/../includes/student_access_control.php';
requireStudentAccess('full');

// Include database connection and evaluation status helper
include '../includes/db_connection.php';
include '../includes/evaluation_status_helper.php';

// Set timezone to UTC for consistent timestamp handling
date_default_timezone_set('UTC');
$conn->query("SET time_zone = '+00:00'");

// Get evaluation status using the helper function
$evaluation_status = getEvaluationStatus($conn);
$evaluation_phase = $evaluation_status['phase'];
$evaluation_start_date = $evaluation_status['start_date'];
$evaluation_end_date = $evaluation_status['end_date'];

// Redirect if evaluation is not ongoing
if ($evaluation_phase !== 'ongoing') {
    header("Location: student_dashboard.php?error=evaluation_not_available");
    exit();
}

// Get student information (robust to missing profile)
$student_username = $_SESSION['username'];

// 1) Fetch the current user
$user_stmt = $conn->prepare("SELECT user_id, role, first_name, last_name FROM users WHERE account_number = ? LIMIT 1");
$user_stmt->bind_param("s", $student_username);
$user_stmt->execute();
$user_res = $user_stmt->get_result();
if ($user_res->num_rows === 0) {
    die("User not found. Please contact administrator.");
}
$user_row = $user_res->fetch_assoc();
$current_user_id = (int)$user_row['user_id'];
$current_user_role = $user_row['role'];
$user_stmt->close();

// Resolve current active term using the same source used in admin evaluation management.
$current_school_year = !empty($evaluation_status['school_year']) ? (string)$evaluation_status['school_year'] : '2025-2026';
$current_semester = !empty($evaluation_status['semester']) ? (string)$evaluation_status['semester'] : '1st Semester';
$current_semester_num = (stripos($current_semester, '2') !== false) ? 2 : 1;

$current_school_year_norm = str_replace(["–", "—"], "-", $current_school_year);

// Load student profile for the current term first to avoid mismatched section/teacher/subject cards.
$stmt = $conn->prepare("SELECT s.student_id as student_id, s.strand, s.year_level, s.section_id, s.section_name
                        FROM students s
                        WHERE s.user_id = ?
                          AND REPLACE(REPLACE(IFNULL(s.school_year, ''), '–', '-'), '—', '-') = ?
                          AND s.semester = ?
                        ORDER BY s.student_id DESC
                        LIMIT 1");
$stmt->bind_param("isi", $current_user_id, $current_school_year_norm, $current_semester_num);
$stmt->execute();
$result = $stmt->get_result();

// 3) If missing and role is student, auto-create a minimal student profile
if ($result->num_rows === 0 && $current_user_role === 'student') {
    // Get current school year and semester
    $sy_sem = $conn->query("SELECT school_year, semester FROM currentschoolyearandsemester LIMIT 1");
    $current_sy = '2025–2026';
    $current_sem = 1;
    if ($sy_sem && $sy_sem->num_rows > 0) {
        $row_sy = $sy_sem->fetch_assoc();
        $current_sy = $row_sy['school_year'];
        $current_sem = (int)$row_sy['semester'];
    }

    $strand_default = '';
    $year_level_default = '';
    $create_stmt = $conn->prepare("INSERT INTO students (user_id, strand, year_level, school_year, semester) VALUES (?, ?, ?, ?, ?)");
    $create_stmt->bind_param("isssi", $current_user_id, $strand_default, $year_level_default, $current_sy, $current_sem);
    if (!$create_stmt->execute()) {
        die("Failed to create student profile: " . $create_stmt->error);
    }
    $create_stmt->close();
    // Re-fetch
    $stmt = $conn->prepare("SELECT s.student_id as student_id, s.strand, s.year_level, s.section_id, s.section_name
                            FROM students s
                            WHERE s.user_id = ?
                            ORDER BY s.student_id DESC
                            LIMIT 1");
    $stmt->bind_param("i", $current_user_id);
    $stmt->execute();
    $result = $stmt->get_result();
}

// Final fallback for legacy rows that have missing term values.
if ($result->num_rows === 0) {
    $stmt = $conn->prepare("SELECT s.student_id as student_id, s.strand, s.year_level, s.section_id, s.section_name
                            FROM students s
                            WHERE s.user_id = ?
                            ORDER BY s.student_id DESC
                            LIMIT 1");
    $stmt->bind_param("i", $current_user_id);
    $stmt->execute();
    $result = $stmt->get_result();
}

if ($result->num_rows === 0) {
    die("Student not found. Please contact administrator.");
}

$student_data = $result->fetch_assoc();
$student_id = (int)$student_data['student_id'];
$student_strand = $student_data['strand'];
$student_year_level = $student_data['year_level'];
$student_section_id = isset($student_data['section_id']) ? $student_data['section_id'] : null;
$student_section_name = $student_data['section_name'] ?? null;
if (!empty($student_section_id)) {
    $sec_stmt = $conn->prepare("SELECT section_name FROM sections WHERE section_id = ? LIMIT 1");
    if ($sec_stmt) {
        $sec_stmt->bind_param("i", $student_section_id);
        $sec_stmt->execute();
        $sec_res = $sec_stmt->get_result();
        if ($sec_res && $sec_res->num_rows > 0) {
            $sec_row = $sec_res->fetch_assoc();
            $student_section_name = $sec_row['section_name'];
        }
        $sec_stmt->close();
    }
}

// Prefer evaluation targets (explicit per-term list). Falls back to subject table mapping if none are generated yet.
// Each card becomes (Subject × Teacher) so multi-teacher per subject is supported.
$sql = "SELECT
            DISTINCT
            et.evaluation_target_id AS target_id,
            s.subject_id AS subject_id,
            s.subject_name,
            t.teacher_id AS teacher_id,
            CONCAT(u.first_name, ' ', u.last_name) AS teacher_name,
            CASE
                WHEN EXISTS (
                    SELECT 1
                      FROM evaluations e
                     WHERE e.student_id = ?
                       AND e.subject_id = s.subject_id
                       AND e.teacher_id = t.teacher_id
                       AND REPLACE(REPLACE(e.school_year,'–','-'),'—','-') = ?
                       AND e.semester = ?
                )
                THEN 1 ELSE 0
            END AS is_evaluated
        FROM evaluation_targets et
        JOIN subjects s ON et.subject_id = s.subject_id
        JOIN teachers t ON et.teacher_id = t.teacher_id
        JOIN users u ON t.user_id = u.user_id AND u.role = 'teacher'
        WHERE et.is_active = 1
          AND REPLACE(REPLACE(et.school_year,'–','-'),'—','-') = ?
          AND et.semester = ?
          AND s.status = 'active'
          AND s.semester = ?
          AND s.year_level = ?
          AND s.strand = ?
          AND (et.section_id = ? OR et.section_id IS NULL)
        ORDER BY s.subject_name ASC, u.first_name ASC, u.last_name ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param(
    "isisiissi",
    $student_id,
    $current_school_year_norm,
    $current_semester_num,
    $current_school_year_norm,
    $current_semester_num,
    $current_semester_num,
    $student_year_level,
    $student_strand,
    $student_section_id
);
if (!$stmt->execute()) {
    die("Query Error: " . $stmt->error);
}
$result = $stmt->get_result();
$subjects = [];

if ($result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $teacher_name = $row['teacher_name'];
        // Clean up teacher name (trim spaces)
        $teacher_name = trim($teacher_name);
        
        $subjects[] = [
            'target_id' => (int)$row['target_id'],
            'id' => (int)$row['subject_id'],
            'teacher_id' => (int)$row['teacher_id'],
            'teacher' => $teacher_name,
            'subject' => $row['subject_name'],
            'is_evaluated' => (bool)$row['is_evaluated']
        ];
    }
}

// Fallback A: per-section teacher assignments (section_subject_teacher_assignments)
if (empty($subjects) && !empty($student_section_id) && (int)$student_section_id > 0
    && !empty($student_strand) && !empty($student_year_level)
) {
    $section_assign_sql = "SELECT DISTINCT
                        0 AS target_id,
                        s.subject_id,
                        s.subject_name,
                        ssta.teacher_id AS teacher_id,
                        CONCAT(u.first_name, ' ', u.last_name) AS teacher_name,
                        CASE
                            WHEN EXISTS (
                                SELECT 1
                                FROM evaluations e
                                WHERE e.student_id = ?
                                  AND e.subject_id = s.subject_id
                                  AND e.teacher_id = ssta.teacher_id
                                  AND REPLACE(REPLACE(e.school_year,'–','-'),'—','-') = ?
                                  AND e.semester = ?
                            ) THEN 1 ELSE 0
                        END AS is_evaluated
                    FROM section_subject_teacher_assignments ssta
                    INNER JOIN subjects s ON s.subject_id = ssta.subject_id AND s.status = 'active'
                    INNER JOIN teachers t ON t.teacher_id = ssta.teacher_id
                    INNER JOIN users u ON u.user_id = t.user_id AND u.role = 'teacher'
                    WHERE ssta.section_id = ?
                      AND REPLACE(REPLACE(ssta.school_year,'–','-'),'—','-') = ?
                      AND ssta.semester = ?
                      AND s.semester = ?
                      AND s.strand = ?
                      AND s.year_level = ?
                    ORDER BY s.subject_name ASC, u.first_name ASC, u.last_name ASC";

    $sec_assign_stmt = $conn->prepare($section_assign_sql);
    if ($sec_assign_stmt) {
        $sec_assign_section_id = (int)$student_section_id;
        $sec_assign_stmt->bind_param(
            "isisiisis",
            $student_id,
            $current_school_year_norm,
            $current_semester_num,
            $sec_assign_section_id,
            $current_school_year_norm,
            $current_semester_num,
            $current_semester_num,
            $student_strand,
            $student_year_level
        );
        if ($sec_assign_stmt->execute()) {
            $sar = $sec_assign_stmt->get_result();
            while ($row = $sar->fetch_assoc()) {
                $teacher_name = trim((string)($row['teacher_name'] ?? ''));
                if ($teacher_name === '') {
                    $teacher_name = 'TBA';
                }
                $subjects[] = [
                    'target_id' => (int)$row['target_id'],
                    'id' => (int)$row['subject_id'],
                    'teacher_id' => (int)$row['teacher_id'],
                    'teacher' => $teacher_name,
                    'subject' => $row['subject_name'],
                    'is_evaluated' => (bool)$row['is_evaluated'],
                ];
            }
        }
        $sec_assign_stmt->close();
    }
}

// Fallback B: if no evaluation_targets and no section rows, derive from subject_teacher_assignments / subjects.teacher_id
if (empty($subjects) && !empty($student_strand) && !empty($student_year_level)) {
    $fallback_sql = "SELECT
                        DISTINCT
                        0 AS target_id,
                        s.subject_id,
                        s.subject_name,
                        COALESCE(sta.teacher_id, s.teacher_id) AS teacher_id,
                        CONCAT(u.first_name, ' ', u.last_name) AS teacher_name,
                        CASE
                            WHEN EXISTS (
                                SELECT 1
                                FROM evaluations e
                                WHERE e.student_id = ?
                                  AND e.subject_id = s.subject_id
                                  AND e.teacher_id = COALESCE(sta.teacher_id, s.teacher_id)
                                  AND REPLACE(REPLACE(e.school_year,'–','-'),'—','-') = ?
                                  AND e.semester = ?
                            ) THEN 1 ELSE 0
                        END AS is_evaluated
                    FROM subjects s
                    LEFT JOIN subject_teacher_assignments sta
                        ON sta.subject_id = s.subject_id
                       AND REPLACE(REPLACE(sta.school_year,'–','-'),'—','-') = ?
                       AND sta.semester = ?
                       AND sta.role = 'primary'
                    LEFT JOIN teachers t ON t.teacher_id = COALESCE(sta.teacher_id, s.teacher_id)
                    LEFT JOIN users u ON u.user_id = t.user_id AND u.role = 'teacher'
                    WHERE s.status = 'active'
                      AND s.semester = ?
                      AND s.strand = ?
                      AND s.year_level = ?
                      AND COALESCE(sta.teacher_id, s.teacher_id) IS NOT NULL";
    
    $fallback_sql .= " ORDER BY s.subject_name ASC, u.first_name ASC, u.last_name ASC";

    $fallback_stmt = $conn->prepare($fallback_sql);
    if ($fallback_stmt) {
        $fallback_stmt->bind_param(
            "isisiiss",
            $student_id,
            $current_school_year_norm,
            $current_semester_num,
            $current_school_year_norm,
            $current_semester_num,
            $current_semester_num,
            $student_strand,
            $student_year_level
        );
        
        if ($fallback_stmt->execute()) {
            $fallback_result = $fallback_stmt->get_result();
            while ($row = $fallback_result->fetch_assoc()) {
                $teacher_name = trim((string)($row['teacher_name'] ?? ''));
                if ($teacher_name === '') {
                    $teacher_name = 'TBA';
                }

                $subjects[] = [
                    'target_id' => (int)$row['target_id'],
                    'id' => (int)$row['subject_id'],
                    'teacher_id' => (int)$row['teacher_id'],
                    'teacher' => $teacher_name,
                    'subject' => $row['subject_name'],
                    'is_evaluated' => (bool)$row['is_evaluated']
                ];
            }
        }
        $fallback_stmt->close();
    }
}

// Count pending evaluations for notification
$pending_count = 0;
foreach ($subjects as $subject) {
    if (!$subject['is_evaluated']) {
        $pending_count++;
    }
}

$form_feedback_success = isset($_GET['success']) && $_GET['success'] === '1';
$form_feedback_subject = isset($_GET['subject']) ? (string)$_GET['subject'] : '';
$form_feedback_rating = isset($_GET['rating']) ? (string)$_GET['rating'] : '';
$form_feedback_error = isset($_GET['error']) ? (string)$_GET['error'] : '';
$form_feedback_message = isset($_GET['message']) ? (string)$_GET['message'] : '';

// DYNAMIC: Fetch evaluation categories and questions from database based on current school year/semester
$evaluation_categories = [];
$all_questions_for_form = [];

// Get all active categories for current school year/semester, ordered by category name
$categories_query = "SELECT category_id, category_name, is_active, school_year, semester 
                     FROM evaluation_categories 
                     WHERE is_active = 1 
                       AND REPLACE(REPLACE(school_year,'–','-'),'—','-') = ? 
                       AND semester = ? 
                     ORDER BY category_name ASC";
$categories_stmt = $conn->prepare($categories_query);
if (!$categories_stmt) {
    die("Error preparing categories query: " . $conn->error);
}
$categories_stmt->bind_param("si", $current_school_year_norm, $current_semester_num);
if (!$categories_stmt->execute()) {
    die("Error executing categories query: " . $categories_stmt->error);
}
$categories_result = $categories_stmt->get_result();

// Build categories array with their questions
while ($category = $categories_result->fetch_assoc()) {
    $category_id = (int)$category['category_id'];
    $category_name = $category['category_name'];
    
    // Fetch questions for this category, ordered by insertion order
    $questions_query = "SELECT question_id, question, category_id, category_name, is_active, school_year, semester 
                        FROM evaluation_questions 
                        WHERE category_id = ? AND is_active = 1 
                          AND REPLACE(REPLACE(school_year,'–','-'),'—','-') = ? 
                          AND semester = ? 
                        ORDER BY question_id ASC";
    $questions_stmt = $conn->prepare($questions_query);
    if (!$questions_stmt) {
        die("Error preparing questions query: " . $conn->error);
    }
    $questions_stmt->bind_param("isi", $category_id, $current_school_year_norm, $current_semester_num);
    if (!$questions_stmt->execute()) {
        die("Error executing questions query: " . $questions_stmt->error);
    }
    $questions_result = $questions_stmt->get_result();
    
    $category_questions = [];
    $question_count = 0;
    while ($question = $questions_result->fetch_assoc()) {
        $question_count++;
        $category_questions[] = [
            'question_id' => (int)$question['question_id'],
            'question_text' => $question['question'],
            'category_id' => (int)$question['category_id'],
            'question_number' => $question_count
        ];
        
        // Add to global questions array (preserves order across all categories)
        $all_questions_for_form[] = [
            'question_id' => (int)$question['question_id'],
            'question_text' => $question['question'],
            'category_id' => (int)$question['category_id'],
            'category_name' => $question['category_name'],
            'question_number' => $question_count
        ];
    }
    $questions_stmt->close();
    
    // Add category with its questions to evaluation_categories array
    $evaluation_categories[] = [
        'category_id' => $category_id,
        'category_name' => $category_name,
        'question_count' => $question_count,
        'questions' => $category_questions
    ];
}
$categories_stmt->close();

// Rating options
$ratingEmojis = [
    5 => ['emoji' => '😍', 'label' => 'Strongly Agree', 'color' => '#28a745'],
    4 => ['emoji' => '😊', 'label' => 'Agree', 'color' => '#20c997'],
    3 => ['emoji' => '😐', 'label' => 'Uncertain', 'color' => '#ffc107'],
    2 => ['emoji' => '😞', 'label' => 'Disagree', 'color' => '#fd7e14'],
    1 => ['emoji' => '😡', 'label' => 'Strongly Disagree', 'color' => '#dc3545']
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $evaluation_target_id = $_POST['evaluation_target_id'] ?? '';
    $subject_id = $_POST['subject_id'] ?? '';
    $teacher_id = $_POST['teacher_id'] ?? null;
    $responses = [];
    
    // Collect all responses from form fields
    foreach ($all_questions_for_form as $index => $question) {
        $question_id = (int)$question['question_id'];
        $responses[$question_id] = isset($_POST['question_' . $question_id])
            ? trim((string)$_POST['question_' . $question_id])
            : '';
    }
    
    // Store responses in session for submit_evaluation.php
    $_SESSION['evaluation_data'] = [
        'evaluation_target_id' => $evaluation_target_id,
        'subject_id' => $subject_id,
        'teacher_id' => $teacher_id,
        'responses' => $responses,
        'comments' => $_POST['comments'] ?? ''
    ];
    
    // Redirect to submit_evaluation.php for processing
    header("Location: submit_evaluation.php");
    exit();
}

// Get user names for display
$firstName = strtoupper($_SESSION['first_name'] ?? $_SESSION['username'] ?? '');
$lastName = strtoupper($_SESSION['last_name'] ?? '');

    $stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="/capstone/images/school-logo.png" type="image/png">
    <title>Subject Evaluation Form</title>
    
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
        
        * {
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            margin: 0;
            padding: 0;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            overflow-x: hidden;
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
            font-weight: 900;
            margin-bottom: 15px;
            font-size: 2.8rem;
            position: relative;
            z-index: 2;
        }
        
        .academic-info {
            background: rgba(255, 255, 255, 0.15);
            padding: 25px;
            border-radius: 15px;
            margin-top: 25px;
            backdrop-filter: blur(10px);
            position: relative;
            z-index: 2;
        }
        
        .academic-info p {
            margin-bottom: 10px;
            font-size: 1.1rem;
        }
        
        /* Subject Cards Grid */
        .subjects-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
        }
        
        .subject-card {
            background: white;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            cursor: pointer;
            position: relative;
            overflow: hidden;
            border: 3px solid transparent;
        }
        
        .subject-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, var(--primary-color), var(--success-color));
        }
        
        .subject-card:hover:not(.evaluated) {
            transform: translateY(-10px) scale(1.02);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
            border-color: var(--primary-color);
        }
        
        .subject-card.evaluated {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            border-color: var(--success-color);
            cursor: default;
            opacity: 0.9;
        }
        
        .subject-card.evaluated::before {
            background: var(--success-color);
        }
        
        .subject-card.evaluated:hover {
            transform: none;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }
        
        .subject-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }
        
        .subject-title {
            font-size: 1.4rem;
            font-weight: 700;
            color: var(--text-dark);
            margin: 0;
        }
        
        .subject-status {
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .status-pending {
            background: #fff3cd;
            color: #856404;
        }
        
        .status-completed {
            background: #d4edda;
            color: #155724;
        }
        
        .subject-info {
            margin-bottom: 20px;
        }
        
        .subject-info p {
            margin-bottom: 8px;
            color: #666;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 10px;
            word-break: break-word;
            overflow-wrap: break-word;
        }
        
        .subject-info strong {
            flex-shrink: 0;
            white-space: nowrap;
            color: var(--text-dark);
        }
        
        .teacher-display {
            color: var(--primary-color);
            font-weight: 600;
            word-break: break-word;
            overflow-wrap: break-word;
        }
        
        /* Modal Styles */
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
        
        .modal-body {
            padding: 30px;
            max-height: 70vh;
            overflow-y: auto;
        }
        
        /* Progress Bar */
        .form-progress-container {
            margin-bottom: 25px;
        }
        
        .progress {
            height: 8px;
            border-radius: 10px;
            background-color: #e9ecef;
            overflow: hidden;
        }
        
        .progress-bar {
            background: linear-gradient(90deg, var(--primary-color), var(--primary-hover));
            transition: width 0.4s ease;
            box-shadow: 0 0 10px rgba(128, 0, 0, 0.2);
        }
        
        .progress-text {
            font-size: 0.9rem;
            color: var(--text-dark);
            margin-top: 8px;
            font-weight: 500;
        }
        
        /* Modern Category Headers - DYNAMIC RENDERING */
        .category-section {
            margin-bottom: 40px;
        }
        
        .category-header {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-hover));
            color: white;
            padding: 20px 30px;
            border-radius: 15px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 15px;
            box-shadow: 0 8px 25px rgba(128, 0, 0, 0.2);
            position: relative;
            overflow: hidden;
        }
        
        .category-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.6s;
        }
        
        .category-header:hover::before {
            left: 100%;
        }
        
        .category-icon {
            font-size: 1.8rem;
            background: rgba(255, 255, 255, 0.2);
            padding: 12px;
            border-radius: 12px;
        }
        
        .category-title {
            font-size: 1.3rem;
            font-weight: 600;
            margin: 0;
        }
        
        .category-count {
            margin-left: auto;
            background: rgba(255, 255, 255, 0.2);
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 500;
        }
        
        /* Question Cards */
        .question-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            border: 2px solid transparent;
            transition: all 0.3s ease;
        }
        
        .question-card:hover {
            border-color: var(--primary-color);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.12);
        }
        
        .question-text {
            font-weight: 600;
            margin-bottom: 20px;
            color: var(--text-dark);
            font-size: 1.1rem;
            line-height: 1.6;
        }
        
        /* Emoji Rating Options */
        .rating-options {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .rating-btn {
            flex: 1;
            min-width: 80px;
            padding: 15px;
            border: 3px solid #e0e0e0;
            background: white;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
            font-size: 2rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .rating-btn:hover {
            border-color: var(--primary-color);
            background: var(--primary-light);
            transform: scale(1.08);
        }
        
        .rating-btn.selected {
            border-color: var(--primary-color);
            background: var(--primary-light);
            box-shadow: 0 8px 20px rgba(128, 0, 0, 0.2);
            transform: scale(1.05);
        }
        
        .rating-label {
            font-size: 0.75rem;
            color: var(--text-dark);
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        /* Form Controls */
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-control, .form-select {
            border: 2px solid var(--border-color);
            border-radius: 12px;
            padding: 12px 18px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(128, 0, 0, 0.1);
            outline: none;
        }
        
        /* Buttons */
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            padding: 12px 30px;
            font-weight: 600;
            border-radius: 12px;
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover, .btn-primary:active, .btn-primary:focus {
            background-color: var(--primary-hover);
            border-color: var(--primary-hover);
            box-shadow: 0 8px 20px rgba(128, 0, 0, 0.2);
            transform: translateY(-2px);
        }
        
        .btn-secondary {
            background-color: #f0f0f0;
            border-color: var(--primary-color);
            border: 2px solid var(--primary-color);
            color: var(--primary-color);
            padding: 12px 30px;
            font-weight: 600;
            border-radius: 12px;
            transition: all 0.3s ease;
        }
        
        .btn-secondary:hover {
            background-color: rgba(128, 0, 0, 0.1);
            border-color: var(--primary-hover);
            color: var(--primary-hover);
        }

        /* Modern Alert Modal System */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            z-index: 1050;
            animation: fadeIn 0.3s ease;
        }

        .modal-overlay.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modern-modal-content {
            background: white;
            border-radius: 20px;
            padding: 40px 30px;
            max-width: 450px;
            width: 90%;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: slideUp 0.3s ease;
            position: relative;
            text-align: center;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .modal-icon {
            font-size: 3rem;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 80px;
            height: 80px;
            border-radius: 50%;
            margin-left: auto;
            margin-right: auto;
        }

        .modal-icon.success {
            background: rgba(40, 167, 69, 0.1);
            color: var(--success-color);
            animation: scaleIn 0.6s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }

        .modal-icon.error {
            background: rgba(220, 53, 69, 0.1);
            color: var(--danger-color);
            animation: shake 0.6s cubic-bezier(0.36, 0, 0.66, -0.56);
        }

        .close-modal {
            position: absolute;
            top: 15px;
            right: 15px;
            background: none;
            border: none;
            font-size: 1.5rem;
            color: #999;
            cursor: pointer;
            transition: color 0.3s ease;
        }

        .close-modal:hover {
            color: #333;
        }
        
        /* Comments Section */
        .comments-section {
            background: #f8f9fa;
            padding: 25px;
            border-radius: 15px;
            margin-top: 30px;
            border-left: 5px solid var(--primary-color);
        }
        
        .comments-section h5 {
            color: var(--text-dark);
            margin-bottom: 15px;
            font-weight: 600;
        }
        
        /* Success Modal Styles */
        .success-modal-content {
            text-align: center;
            padding: 40px 30px;
        }
        
        .success-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, var(--success-color), #20c997);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 25px;
            font-size: 2.5rem;
            color: white;
            box-shadow: 0 10px 30px rgba(40, 167, 69, 0.3);
            animation: scaleIn 0.6s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }
        
        @keyframes scaleIn {
            0% {
                transform: scale(0);
            }
            50% {
                transform: scale(1.1);
            }
            100% {
                transform: scale(1);
            }
        }
        
        .success-modal-title {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--success-color);
            margin-bottom: 12px;
        }
        
        .success-modal-message {
            font-size: 1rem;
            color: #666;
            margin-bottom: 20px;
            line-height: 1.6;
        }
        
        .success-details {
            background: #f0f9ff;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            border-left: 4px solid var(--info-color);
        }
        
        .success-details p {
            margin: 8px 0;
            font-size: 0.95rem;
            color: var(--text-dark);
        }
        
        /* Error Modal Styles */
        .error-modal-content {
            text-align: center;
            padding: 40px 30px;
        }
        
        .error-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, var(--danger-color), #fd7e14);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 25px;
            font-size: 2.5rem;
            color: white;
            box-shadow: 0 10px 30px rgba(220, 53, 69, 0.3);
            animation: shake 0.6s cubic-bezier(0.36, 0, 0.66, -0.56);
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-10px); }
            75% { transform: translateX(10px); }
        }
        
        .error-modal-title {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--danger-color);
            margin-bottom: 12px;
        }
        
        /* Validation Modal */
        .validation-warning {
            background: #fff3cd;
            border: 2px solid #ffc107;
            border-radius: 12px;
            padding: 20px;
            margin: 20px 0;
            color: #856404;
        }
        
        .validation-warning i {
            margin-right: 10px;
            color: var(--warning-color);
        }
        
        /* Responsive Adjustments */
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                margin-top: 0;
                padding: 15px;
            }
            
            .header-section {
                padding: 25px;
                margin-bottom: 25px;
            }
            
            .header-section h1 {
                font-size: 2rem;
            }
            
            .subjects-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .category-header {
                flex-wrap: wrap;
                padding: 15px;
            }
            
            .category-count {
                width: 100%;
                margin-left: 0;
                margin-top: 10px;
            }
            
            .rating-options {
                gap: 8px;
            }
            
            .rating-btn {
                min-width: 60px;
                padding: 10px;
                font-size: 1.5rem;
            }
        }

        /* Notification badge for pending evaluations */
        .pending-badge {
            display: inline-block;
            background: var(--danger-color);
            color: white;
            padding: 8px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            margin-left: 10px;
        }

        /* No data message */
        .no-data-message {
            background: #e7f3ff;
            border: 2px solid #b3d9ff;
            border-radius: 12px;
            padding: 30px;
            text-align: center;
            color: #004085;
            margin: 20px 0;
        }

        .no-data-message i {
            font-size: 3rem;
            margin-bottom: 15px;
            opacity: 0.5;
        }

        .no-data-message p {
            font-size: 1.1rem;
            margin: 0;
        }

        /* Alert spacing adjustments */
        .main-content > .alert {
            margin-top: 0;
            margin-bottom: 25px;
        }
    </style>
</head>
<body>

<!-- Modal System -->
<div id="alertModal" class="modal-overlay">
    <div class="modern-modal-content">
        <button class="close-modal" onclick="closeModal()">&times;</button>
        <div class="modal-icon" id="modalIcon"></div>
        <h2 id="modalTitle" style="margin: 0 0 15px 0; font-size: 1.5rem; font-weight: 700; color: #333;"></h2>
        <p id="modalMessage" style="margin: 0 0 20px 0; color: #666; line-height: 1.6;"></p>
        <div id="modalExtra" style="margin-bottom: 20px;"></div>
        <button class="btn btn-primary" onclick="closeModal()">OK</button>
    </div>
</div>

<?php include '../includes/side_bar.php'; ?>
<?php include '../includes/navbar.php'; ?>

<div class="main-content">
    <?php if ($form_feedback_success): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                showModal('success', 'Submitted Successfully', 
                    'Your evaluation for <?php echo addslashes(htmlspecialchars($form_feedback_subject !== '' ? $form_feedback_subject : 'the subject')); ?> was saved successfully.<?php if ($form_feedback_rating !== '' && is_numeric($form_feedback_rating)): ?> Average rating: <?php echo addslashes(htmlspecialchars(number_format((float)$form_feedback_rating, 2))); ?> / 5<?php endif; ?>', 
                    true);
            });
        </script>
    <?php elseif ($form_feedback_error !== ''): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                let errorMessage = '<?php if ($form_feedback_error === 'already_submitted'): ?>You have already submitted an evaluation for this subject this term.<?php else: ?><?php echo addslashes(htmlspecialchars($form_feedback_message !== '' ? $form_feedback_message : 'Please try again or contact support.')); ?><?php endif; ?>';
                showModal('error', 'Could not Submit', errorMessage, false);
            });
        </script>
    <?php endif; ?>

    <!-- Header Section -->
    <div class="header-section">
        <h1><i class="fas fa-clipboard-check"></i> Subject Evaluation Form</h1>
        <div class="academic-info">
            <p><strong>Student:</strong> <?php echo htmlspecialchars($firstName . ' ' . $lastName); ?></p>
            <p><strong>School Year:</strong> <?php echo htmlspecialchars($current_school_year); ?></p>
            <p><strong>Semester:</strong> <?php echo htmlspecialchars($current_semester); ?></p>
            <?php if ($pending_count > 0): ?>
                <p><strong>Pending Evaluations:</strong> <span class="pending-badge"><?php echo $pending_count; ?> subject<?php echo ($pending_count !== 1) ? 's' : ''; ?></span></p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Subject Cards Section -->
    <div class="subjects-grid">
        <?php if (empty($subjects)): ?>
            <div class="no-data-message" style="grid-column: 1/-1;">
                <i class="fas fa-inbox"></i>
                <p>No subjects available for evaluation at this time.</p>
            </div>
        <?php else: ?>
            <?php foreach ($subjects as $subject): ?>
                <div class="subject-card <?php echo $subject['is_evaluated'] ? 'evaluated' : ''; ?>" 
                     <?php echo $subject['is_evaluated'] ? '' : 'data-bs-toggle="modal" data-bs-target="#evaluationModal"'; ?>
                     data-target-id="<?php echo htmlspecialchars((string)($subject['target_id'] ?? 0)); ?>"
                     data-subject-id="<?php echo htmlspecialchars($subject['id']); ?>"
                     data-teacher-id="<?php echo htmlspecialchars($subject['teacher_id'] ?? ''); ?>"
                     data-subject-name="<?php echo htmlspecialchars($subject['subject']); ?>"
                     data-teacher-name="<?php echo htmlspecialchars($subject['teacher']); ?>"
                     data-is-evaluated="<?php echo $subject['is_evaluated'] ? '1' : '0'; ?>">
                    <div class="subject-header">
                        <h3 class="subject-title"><?php echo htmlspecialchars($subject['subject']); ?></h3>
                        <span class="subject-status <?php echo $subject['is_evaluated'] ? 'status-completed' : 'status-pending'; ?>">
                            <?php echo $subject['is_evaluated'] ? 'Already Submitted' : '⏱ Pending'; ?>
                        </span>
                    </div>
                    <div class="subject-info">
                        <p><strong>Teacher:</strong> <span class="teacher-display"><?php echo htmlspecialchars($subject['teacher']); ?></span></p>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Evaluation Modal - DYNAMICALLY RENDERED FROM DATABASE -->
    <div class="modal fade" id="evaluationModal" tabindex="-1" aria-labelledby="evaluationModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <div style="flex: 1;">
                        <h5 class="modal-title" id="evaluationModalLabel">Evaluation Form</h5>
                        <small id="subjectDisplayName" style="color: white; opacity: 0.85; display: block; margin-top: 5px; font-size: 0.95rem;"></small>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- Teacher Info Card -->
                    <div style="background: linear-gradient(135deg, var(--primary-light), rgba(128, 0, 0, 0.05)); padding: 20px; border-radius: 12px; margin-bottom: 25px; border-left: 4px solid var(--primary-color);">
                        <div style="display: flex; align-items: center; gap: 15px;">
                            <i class="fas fa-chalkboard-user" style="font-size: 1.8rem; color: var(--primary-color);"></i>
                            <div>
                                <p style="margin: 0; font-size: 0.9rem; color: #666; font-weight: 500;">Instructor:</p>
                                <p style="margin: 0; font-size: 1.1rem; color: var(--primary-color); font-weight: 700;" id="modalTeacherName">TBD</p>
                            </div>
                        </div>
                    </div>

                    <!-- Progress Bar -->
                    <div class="form-progress-container">
                        <div class="progress">
                            <div class="progress-bar" id="formProgressBar" role="progressbar" style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
                        </div>
                        <div class="progress-text">
                            <span id="answeredCount">0</span> of <span id="totalCount"><?php echo count($all_questions_for_form); ?></span> questions answered
                        </div>
                    </div>

                    <form id="evaluationForm" method="POST">
                        <input type="hidden" name="evaluation_target_id" id="evaluationTargetIdInput">
                        <input type="hidden" name="subject_id" id="subjectIdInput">
                        <input type="hidden" name="teacher_id" id="teacherIdInput">

                        <!-- DYNAMIC CATEGORIES AND QUESTIONS -->
                        <?php if (empty($evaluation_categories)): ?>
                            <div class="no-data-message">
                                <i class="fas fa-info-circle"></i>
                                <p>No evaluation categories and questions have been imported yet. Please contact your administrator.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($evaluation_categories as $index => $category): ?>
                                <div class="category-section">
                                    <div class="category-header">
                                        <div class="category-icon">
                                            <i class="fas fa-list-check"></i>
                                        </div>
                                        <h4 class="category-title"><?php echo htmlspecialchars($category['category_name']); ?></h4>
                                        <span class="category-count">
                                            <?php echo $category['question_count']; ?> question<?php echo ($category['question_count'] !== 1) ? 's' : ''; ?>
                                        </span>
                                    </div>

                                    <!-- Questions for this category -->
                                    <?php foreach ($category['questions'] as $question): ?>
                                        <div class="question-card">
                                            <div class="question-text">
                                                <span class="badge bg-secondary me-2"><?php echo $question['question_number']; ?></span>
                                                <?php echo htmlspecialchars($question['question_text']); ?>
                                            </div>
                                            <div class="rating-options">
                                                <input type="hidden" 
                                                       name="question_<?php echo htmlspecialchars($question['question_id']); ?>" 
                                                       class="question-response-<?php echo htmlspecialchars($question['question_id']); ?>" 
                                                       value="">
                                                <?php foreach ($ratingEmojis as $rating => $ratingData): ?>
                                                    <button type="button" 
                                                            class="rating-btn" 
                                                            data-rating="<?php echo $rating; ?>" 
                                                            data-question-id="<?php echo htmlspecialchars($question['question_id']); ?>"
                                                            title="<?php echo htmlspecialchars($ratingData['label']); ?>"
                                                            onclick="selectRating(this, event)">
                                                        <span><?php echo $ratingData['emoji']; ?></span>
                                                        <span class="rating-label"><?php echo htmlspecialchars($ratingData['label']); ?></span>
                                                    </button>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <!-- Comments Section -->
                        <div class="comments-section">
                            <h5>Additional Comments (Optional)</h5>
                            <textarea class="form-control" 
                                      name="comments" 
                                      rows="4" 
                                      placeholder="Share any additional feedback or comments about this subject/teacher..."></textarea>
                        </div>

                        <!-- Form Actions -->
                        <div class="mt-4 d-flex gap-2">
                            <button type="submit" class="btn btn-primary" id="submitBtn">
                                <i class="fas fa-paper-plane"></i> Submit Evaluation
                            </button>
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                <i class="fas fa-times"></i> Cancel
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Success Confirmation Modal -->
    <div class="modal fade" id="successModal" tabindex="-1" aria-labelledby="successModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-body success-modal-content">
                    <div class="success-icon">
                        <i class="fas fa-check"></i>
                    </div>
                    <h3 class="success-modal-title">Evaluation Submitted!</h3>
                    <p class="success-modal-message">Your evaluation has been successfully recorded.</p>
                    <div class="success-details">
                        <p><strong id="successSubject">Subject:</strong> <span id="successSubjectValue"></span></p>
                        <p><strong>Teacher:</strong> <span id="successTeacherValue"></span></p>
                        <p><strong>Status:</strong> <span style="color: var(--success-color); font-weight: 600;">✓ Completed</span></p>
                    </div>
                    <button type="button" class="btn btn-primary" id="closeSuccessBtn" data-bs-dismiss="modal">
                        <i class="fas fa-arrow-left"></i> Return to Evaluations
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Validation Error Modal -->
    <div class="modal fade" id="validationModal" tabindex="-1" aria-labelledby="validationModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title" id="validationModalLabel" style="color: white;">
                        <i class="fas fa-exclamation-triangle"></i> Incomplete Form
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" style="padding: 30px;">
                    <div class="validation-warning">
                        <i class="fas fa-info-circle"></i>
                        <strong>Please answer all questions</strong>
                        <p style="margin-top: 8px; margin-bottom: 0;">All questions must be answered before submitting the evaluation. You currently have <span id="unansweredCount">0</span> unanswered question(s).</p>
                    </div>
                    <button type="button" class="btn btn-warning w-100" data-bs-dismiss="modal">
                        <i class="fas fa-pencil"></i> Continue Filling Form
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Confirm Submission Modal -->
    <div class="modal fade" id="confirmModal" tabindex="-1" aria-labelledby="confirmModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, var(--primary-color), var(--primary-hover)); color: white; border: none;">
                    <h5 class="modal-title" id="confirmModalLabel">
                        <i class="fas fa-check-circle"></i> Confirm Submission
                    </h5>
                    <button type="button" class="btn-close" style="filter: invert(1);" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" style="padding: 30px; text-align: center;">
                    <div style="margin-bottom: 25px;">
                        <i class="fas fa-question-circle" style="font-size: 2.5rem; color: var(--primary-color); margin-bottom: 15px;"></i>
                        <p style="font-size: 1.1rem; color: var(--text-dark); margin: 0;">
                            <strong>Are you ready to submit?</strong>
                        </p>
                        <p style="color: #666; font-size: 0.95rem; margin-top: 10px;">
                            Once submitted, you <strong>cannot change</strong> your answers for this subject.
                        </p>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-secondary flex-grow-1" data-bs-dismiss="modal">
                            <i class="fas fa-arrow-left"></i> Keep Editing
                        </button>
                        <button type="button" class="btn btn-primary flex-grow-1" id="confirmSubmitBtn">
                            <i class="fas fa-check"></i> Submit Now
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
    const evaluationQuestionCount = <?php echo (int) count($all_questions_for_form); ?>;

    let selectedRatings = {};
    let currentForm = null;
    let successModalBS = null;
    let validationModalBS = null;
    let confirmModalBS = null;

    // Initialize modal instances
    document.addEventListener('DOMContentLoaded', function() {
        successModalBS = new bootstrap.Modal(document.getElementById('successModal'));
        validationModalBS = new bootstrap.Modal(document.getElementById('validationModal'));
        confirmModalBS = new bootstrap.Modal(document.getElementById('confirmModal'));
    });

    function selectRating(button, event) {
        event.preventDefault();
        
        const questionId = button.getAttribute('data-question-id');
        const rating = button.getAttribute('data-rating');
        
        // Remove selected class from all rating buttons for this question
        const questionCard = button.closest('.question-card');
        const allButtons = questionCard.querySelectorAll('.rating-btn');
        allButtons.forEach(btn => btn.classList.remove('selected'));
        
        // Add selected class to clicked button
        button.classList.add('selected');
        
        // Store rating value in hidden input
        const hiddenInput = document.querySelector(`.question-response-${questionId}`);
        if (hiddenInput) {
            hiddenInput.value = rating;
        }
        
        // Track in selectedRatings object
        selectedRatings[questionId] = rating;
        
        // Update progress bar
        updateFormProgress();
    }

    function updateFormProgress() {
        let answered = 0;
        document.querySelectorAll('input[name^="question_"]').forEach(function (input) {
            if (input.value !== '' && input.value !== null) {
                answered++;
            }
        });

        const percentage = (answered / evaluationQuestionCount) * 100;
        const progressBar = document.getElementById('formProgressBar');
        if (progressBar) {
            progressBar.style.width = percentage + '%';
            progressBar.setAttribute('aria-valuenow', Math.round(percentage));
        }

        document.getElementById('answeredCount').textContent = answered;
        document.getElementById('totalCount').textContent = evaluationQuestionCount;
    }

    // Handle modal opening - populate form with subject data
    const evaluationModal = document.getElementById('evaluationModal');
    if (evaluationModal) {
        evaluationModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            if (!button) return;

            const isEvaluated = button.getAttribute('data-is-evaluated');
            if (isEvaluated === '1') {
                event.preventDefault();
                return;
            }
            const subjectId = button.getAttribute('data-subject-id');
            const teacherId = button.getAttribute('data-teacher-id');
            const targetId = button.getAttribute('data-target-id');
            const subjectName = button.getAttribute('data-subject-name');
            const teacherName = button.getAttribute('data-teacher-name');
            
            // Clear previous selections
            document.querySelectorAll('.rating-btn').forEach(btn => btn.classList.remove('selected'));
            document.querySelectorAll('input[name^="question_"]').forEach(input => input.value = '');
            selectedRatings = {};
            
            // Update modal with subject information
            document.getElementById('evaluationTargetIdInput').value = targetId || '';
            document.getElementById('subjectIdInput').value = subjectId;
            document.getElementById('teacherIdInput').value = teacherId || '';
            document.getElementById('subjectDisplayName').textContent = `${subjectName} - Instructor: ${teacherName}`;
            document.getElementById('modalTeacherName').textContent = teacherName;
            document.getElementById('evaluationModalLabel').textContent = `Evaluate: ${subjectName}`;
            
            // Store subject info for success modal
            document.getElementById('successSubjectValue').textContent = subjectName;
            document.getElementById('successTeacherValue').textContent = teacherName;
            
            // Reset progress bar
            updateFormProgress();
        });
    }

    // Form validation before submission
    document.getElementById('evaluationForm').addEventListener('submit', function(event) {
        event.preventDefault();

        if (evaluationQuestionCount === 0) {
            alert('No evaluation questions are available.');
            return false;
        }

        let answered = 0;
        document.querySelectorAll('input[name^="question_"]').forEach(function (input) {
            if (input.value !== '' && input.value !== null) {
                answered++;
            }
        });

        if (answered < evaluationQuestionCount) {
            document.getElementById('unansweredCount').textContent = evaluationQuestionCount - answered;
            validationModalBS.show();
            return false;
        }

        // Show confirmation modal
        currentForm = this;
        confirmModalBS.show();
    });

    // Handle confirm submission button
    document.getElementById('confirmSubmitBtn').addEventListener('click', function() {
        confirmModalBS.hide();
        
        // Submit the form
        if (currentForm) {
            currentForm.submit();
        }
    });

    // Add event listener to progress updates
    document.addEventListener('input', function(event) {
        if (event.target.matches('input[name^="question_"]')) {
            updateFormProgress();
        }
    });

    // Update progress when rating buttons are clicked
    document.addEventListener('click', function(event) {
        if (event.target.closest('.rating-btn')) {
            setTimeout(updateFormProgress, 100);
        }
    });

    // Modern Modal System Functions
    function showModal(type, title, message, autoClose = false) {
        const modal = document.getElementById('alertModal');
        const modalIcon = document.getElementById('modalIcon');
        const modalTitle = document.getElementById('modalTitle');
        const modalMessage = document.getElementById('modalMessage');

        // Set icon based on type
        if (type === 'success') {
            modalIcon.className = 'modal-icon success';
            modalIcon.innerHTML = '<i class="fas fa-check-circle"></i>';
        } else if (type === 'error') {
            modalIcon.className = 'modal-icon error';
            modalIcon.innerHTML = '<i class="fas fa-exclamation-circle"></i>';
        }

        modalTitle.textContent = title;
        modalMessage.textContent = message;
        modal.classList.add('show');

        // Auto-close after 3 seconds for success messages
        if (autoClose) {
            setTimeout(() => {
                closeModal();
            }, 3000);
        }
    }

    function closeModal() {
        const modal = document.getElementById('alertModal');
        modal.classList.remove('show');
    }

    // Close modal on escape key
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeModal();
        }
    });

    // Close modal when clicking outside
    const alertModal = document.getElementById('alertModal');
    if (alertModal) {
        alertModal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
    }
</script>

</body>
</html>
