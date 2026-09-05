<?php
session_start();

// Check if user is logged in and has appropriate privileges
if (!isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    header("Location: index.php");
    exit();
}

// Include database connection
include 'includes/db_connection.php';

// Get current school year and semester from database
$sy_sem_stmt = $conn->prepare("SELECT school_year, semester FROM currentschoolyearandsemester LIMIT 1");
$sy_sem_stmt->execute();
$sy_sem_result = $sy_sem_stmt->get_result();
$default_school_year = '2025-2026';
$default_semester = '1st Semester';
if ($sy_sem_result && $sy_sem_result->num_rows > 0) {
    $sy_sem_row = $sy_sem_result->fetch_assoc();
    // Normalize school year to use hyphen instead of en-dash/em-dash
    $default_school_year = str_replace(array("–", "—"), "-", $sy_sem_row['school_year']);
    $default_semester = ((int)$sy_sem_row['semester'] == 1) ? '1st Semester' : '2nd Semester';
}
$sy_sem_stmt->close();

$teacher_filter = isset($_GET['teacher_filter']) ? $_GET['teacher_filter'] : '';
$school_year = isset($_GET['school_year']) ? $_GET['school_year'] : $default_school_year;
$semester = isset($_GET['semester']) ? $_GET['semester'] : $default_semester;

// Normalize school year to use a hyphen instead of en-dash/em-dash (DB may contain mixed dash characters)
$school_year = str_replace(array("–", "—"), "-", $school_year);

// evaluations.semester is tinyint (1 or 2) — allow "all" too
$semester_lc = strtolower(trim((string)$semester));
$semester_num = null;
if (!in_array($semester_lc, ['all', 'all semesters'], true)) {
    $semester_num = (stripos((string)$semester, '2') !== false) ? 2 : 1;
    $semester = ($semester_num === 2) ? '2nd Semester' : '1st Semester';
} else {
    $semester = 'All Semesters';
}

// Get available school years from database (dynamically from evaluation data)
$school_year_options = [];
$sy_query = "
    SELECT DISTINCT REPLACE(REPLACE(e.school_year, '–', '-'), '—', '-') as school_year
    FROM evaluations e
    WHERE e.school_year IS NOT NULL AND TRIM(e.school_year) <> ''
    ORDER BY school_year DESC
";
$sy_result = $conn->query($sy_query);
if ($sy_result) {
    while ($sy_row = $sy_result->fetch_assoc()) {
        $school_year_options[] = $sy_row['school_year'];
    }
}

$school_year_options = array_values(array_unique(array_map(function ($sy) {
    return str_replace(array("–", "—"), "-", trim((string)$sy));
}, $school_year_options)));
rsort($school_year_options);

// Validate selected school year is in available options
if (!in_array($school_year, $school_year_options, true)) {
    $school_year = in_array($default_school_year, $school_year_options, true)
        ? $default_school_year
        : (count($school_year_options) > 0 ? $school_year_options[0] : '2025-2026');
}

// Security: Prevent teachers from filtering by other teachers via URL manipulation
if (isset($_SESSION['role']) && $_SESSION['role'] === 'teacher' && !empty($teacher_filter)) {
    $teacher_filter = '';
}

// Determine user role and get teacher_id if applicable
$user_role = $_SESSION['role'];
$user_username = $_SESSION['username'];
$restricted_strand = null;
$logged_in_teacher_id = null;

// For teacher role: Get the logged-in teacher's ID to filter their evaluations only
if ($user_role === 'teacher') {
    $stmt_tid = $conn->prepare("SELECT t.teacher_id 
                                FROM teachers t 
                                JOIN users u ON t.user_id = u.user_id 
                                WHERE u.account_number = ? 
                                LIMIT 1");
    $stmt_tid->bind_param("s", $user_username);
    if ($stmt_tid->execute()) {
        $res_tid = $stmt_tid->get_result();
        if ($res_tid && $res_tid->num_rows > 0) {
            $row_tid = $res_tid->fetch_assoc();
            $logged_in_teacher_id = (int)$row_tid['teacher_id'];
        } else {
            header("Location: index.php");
            exit();
        }
    }
    $stmt_tid->close();
}

// Distinct subject–teacher pairs in current school year / semester (and strand) for cascading filters
$filter_edges = [];
$teachers = [];
$subjects = [];
if ($user_role !== 'teacher') {
    $edge_where = ["REPLACE(REPLACE(e.school_year, '–', '-'), '—', '-') = ?"];
    $edge_params = [$school_year];
    $edge_types = "s";
    if ($semester_num !== null) {
        $edge_where[] = "e.semester = ?";
        $edge_params[] = $semester_num;
        $edge_types .= "i";
    }
    if (!empty($restricted_strand)) {
        $edge_where[] = "s.strand = ?";
        $edge_params[] = $restricted_strand;
        $edge_types .= "s";
    }
    $edge_sql = "SELECT DISTINCT e.subject_id,
                        s.subject_name,
                        e.teacher_id,
                        COALESCE(NULLIF(TRIM(CONCAT_WS(' ', NULLIF(u.first_name, ''), NULLIF(u.last_name, ''))), ''), 'TBD') AS teacher_name
                 FROM evaluations e
                 INNER JOIN subjects s ON e.subject_id = s.subject_id
                 INNER JOIN teachers t ON e.teacher_id = t.teacher_id
                 INNER JOIN users u ON t.user_id = u.user_id
                 WHERE " . implode(" AND ", $edge_where) . "
                 ORDER BY s.subject_name, teacher_name";
    $edge_stmt = $conn->prepare($edge_sql);
    if ($edge_stmt) {
        if (!empty($edge_params)) {
            $edge_stmt->bind_param($edge_types, ...$edge_params);
        }
        $edge_stmt->execute();
        $edge_rows = $edge_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $edge_stmt->close();
        $subjects_by_id = [];
        $teachers_by_id = [];
        foreach ($edge_rows as $r) {
            $sid = (int)$r['subject_id'];
            $tid = (int)$r['teacher_id'];
            $filter_edges[] = [
                'subject_id' => $sid,
                'subject_name' => $r['subject_name'],
                'teacher_id' => $tid,
                'teacher_name' => $r['teacher_name'],
            ];
            $subjects_by_id[$sid] = ['subject_id' => $sid, 'subject_name' => $r['subject_name']];
            $teachers_by_id[$tid] = ['teacher_id' => $tid, 'name' => $r['teacher_name']];
        }
        uasort($subjects_by_id, function ($a, $b) {
            return strcasecmp($a['subject_name'], $b['subject_name']);
        });
        uasort($teachers_by_id, function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });
        $subjects = array_values($subjects_by_id);
        $teachers = array_values($teachers_by_id);
    }

    $subject_ids_allowed = array_column($subjects, 'subject_id');
    $teacher_ids_allowed = array_column($teachers, 'teacher_id');
    if ($teacher_filter !== '' && $teacher_filter !== null && !in_array((int)$teacher_filter, array_map('intval', $teacher_ids_allowed), true)) {
        $teacher_filter = '';
    }
}

// Build WHERE clause for filters (evaluations parent row per submission)
$where_conditions = ["REPLACE(REPLACE(e.school_year, '–', '-'), '—', '-') = ?"];
$params = [$school_year];
$types = "s";
if ($semester_num !== null) {
    $where_conditions[] = "e.semester = ?";
    $params[] = $semester_num;
    $types .= "i";
}

$strand_condition_sql = '';

if (!empty($teacher_filter)) {
    $where_conditions[] = "e.teacher_id = ?";
    $params[] = $teacher_filter;
    $types .= "i";
}

// CRITICAL: Force scope to the logged-in teacher for teacher role
if ($user_role === 'teacher' && !empty($logged_in_teacher_id)) {
    $where_conditions[] = "e.teacher_id = ?";
    $params[] = $logged_in_teacher_id;
    $types .= "i";
}

$where_clause = implode(" AND ", $where_conditions);

// Get subjects teaching for the logged-in teacher
$subjects_teaching = [];
if ($user_role === 'teacher' && !empty($logged_in_teacher_id)) {
    $subjects_sql = "SELECT DISTINCT s.subject_name
                     FROM evaluations e
                     JOIN subjects s ON e.subject_id = s.subject_id
                     WHERE e.teacher_id = ? AND REPLACE(REPLACE(e.school_year, '–', '-'), '—', '-') = ?";
    if ($semester_num !== null) {
        $subjects_sql .= " AND e.semester = ?";
    }
    $subjects_sql .= " ORDER BY s.subject_name";
    $subj_stmt = $conn->prepare($subjects_sql);
    if ($semester_num !== null) {
        $subj_stmt->bind_param("isi", $logged_in_teacher_id, $school_year, $semester_num);
    } else {
        $subj_stmt->bind_param("is", $logged_in_teacher_id, $school_year);
    }
    $subj_stmt->execute();
    $subjects_teaching = $subj_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $subj_stmt->close();
}

// Get overall statistics (one DB row per question; count distinct evaluation sessions)
// Count unique student-subject combinations to accurately reflect evaluations submitted per subject
$stats_sql = "SELECT 
    COUNT(DISTINCT CONCAT(e.student_id, '-', e.subject_id)) AS total_evaluations,
    COUNT(DISTINCT e.student_id) AS unique_students,
    COUNT(DISTINCT e.teacher_id) AS evaluated_teachers,
    COUNT(DISTINCT e.subject_id) AS evaluated_subjects,
    ROUND(AVG(er.response), 2) AS average_rating,
    NULL AS first_evaluation,
    NULL AS latest_evaluation
    FROM evaluation_responses er 
    JOIN evaluations e ON er.evaluation_id = e.evaluation_id
    WHERE $where_clause";

$stmt = $conn->prepare($stats_sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Check if selected school year/semester has any data
$has_data = ($stats && $stats['total_evaluations'] > 0);
if (!$has_data) {
    // If selected semester has no rows for this year, automatically widen to all semesters.
    if ($semester_num !== null) {
        $sem_retry_sql = "SELECT COUNT(DISTINCT CONCAT(e.student_id, '-', e.subject_id)) AS total_evaluations
                          FROM evaluation_responses er
                          JOIN evaluations e ON er.evaluation_id = e.evaluation_id
                          WHERE REPLACE(REPLACE(e.school_year, '–', '-'), '—', '-') = ?";
        $sem_retry_stmt = $conn->prepare($sem_retry_sql);
        if ($sem_retry_stmt) {
            $sem_retry_stmt->bind_param("s", $school_year);
            $sem_retry_stmt->execute();
            $sem_retry_result = $sem_retry_stmt->get_result()->fetch_assoc();
            $sem_retry_stmt->close();
            if ($sem_retry_result && (int)$sem_retry_result['total_evaluations'] > 0) {
                header("Location: evaluation_result.php?school_year=" . urlencode($school_year) . "&semester=all");
                exit();
            }
        }
    }

    // Year is selected but has no data - clear filters and try again
    if (!empty($teacher_filter)) {
        // Retry without teacher filter to see if base year has data
        $retry_sql = "SELECT 
            COUNT(DISTINCT CONCAT(e.student_id, '-', e.subject_id)) AS total_evaluations
            FROM evaluation_responses er 
            JOIN evaluations e ON er.evaluation_id = e.evaluation_id
            WHERE REPLACE(REPLACE(e.school_year, '–', '-'), '—', '-') = ?";
        if ($semester_num !== null) {
            $retry_sql .= " AND e.semester = ?";
        }
        
        $retry_stmt = $conn->prepare($retry_sql);
        if ($retry_stmt) {
            if ($semester_num !== null) {
                $retry_stmt->bind_param("si", $school_year, $semester_num);
            } else {
                $retry_stmt->bind_param("s", $school_year);
            }
            $retry_stmt->execute();
            $retry_result = $retry_stmt->get_result()->fetch_assoc();
            $retry_stmt->close();
            
            // If base year has data, redirect without filters
            if ($retry_result && $retry_result['total_evaluations'] > 0) {
                header("Location: evaluation_result.php?school_year=" . urlencode($school_year) . "&semester=" . htmlspecialchars($semester));
                exit();
            }
        }
    }
}

if (!$stats) {
    $stats = [
        'total_evaluations' => 0,
        'unique_students' => 0,
        'evaluated_teachers' => 0,
        'evaluated_subjects' => 0,
        'average_rating' => 0,
        'first_evaluation' => null,
        'latest_evaluation' => null,
    ];
} else {
    $stats['average_rating'] = $stats['average_rating'] !== null ? round((float)$stats['average_rating'], 2) : 0;
    foreach (['total_evaluations', 'unique_students', 'evaluated_teachers', 'evaluated_subjects'] as $_sk) {
        $stats[$_sk] = (int)($stats[$_sk] ?? 0);
    }
}

// Overall results: aggregated score per teacher + subject (all students, all question rows)
$summary_sql = "SELECT 
    e.teacher_id,
    e.subject_id,
    ROUND(AVG(er.response), 2) AS overall_rating,
    COUNT(DISTINCT e.student_id) AS students_rated,
    COALESCE(NULLIF(TRIM(CONCAT_WS(' ', NULLIF(tu.first_name, ''), NULLIF(tu.last_name, ''))), ''), 'TBD') AS teacher_name,
    MAX(s.subject_name) AS subject_name
    FROM evaluation_responses er
    JOIN evaluations e ON er.evaluation_id = e.evaluation_id
    LEFT JOIN teachers t ON e.teacher_id = t.teacher_id
    LEFT JOIN users tu ON t.user_id = tu.user_id
    INNER JOIN subjects s ON e.subject_id = s.subject_id
    WHERE $where_clause
    GROUP BY e.teacher_id, e.subject_id
    ORDER BY overall_rating DESC, teacher_name ASC, subject_name ASC";

$stmt = $conn->prepare($summary_sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$teacher_subject_summary = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Categories / questions for this school year + semester
// IMPORTANT: Results must include historical (inactive) setup items.
// We therefore derive the displayed categories/questions from submitted `evaluation_responses`,
// not from the current active setup tables.
$cats_sql = "SELECT DISTINCT
        COALESCE(eq.category_id, 0) AS category_id,
        COALESCE(ec.category_name, CONCAT('Category #', COALESCE(eq.category_id, 0))) AS category_name
    FROM evaluation_responses er
    LEFT JOIN evaluation_questions eq ON er.question_id = eq.question_id
    LEFT JOIN evaluation_categories ec ON eq.category_id = ec.category_id
    JOIN evaluations e ON er.evaluation_id = e.evaluation_id
    WHERE $where_clause
    ORDER BY eq.category_id ASC";

$cats_stmt = $conn->prepare($cats_sql);
$categories = [];
if ($cats_stmt) {
    if (!empty($params)) {
        $cats_stmt->bind_param($types, ...$params);
    }
    $cats_stmt->execute();
    $categories = $cats_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $cats_stmt->close();
}

foreach ($categories as &$cat_row) {
    $cat_row['id'] = (int)$cat_row['category_id'];
    $cat_row['name'] = $cat_row['category_name'];
    $cat_row['color'] = '#800000';
    $cat_row['icon'] = 'fas fa-folder-open';
}
unset($cat_row);

$qs_sql = "SELECT DISTINCT
        er.question_id,
        COALESCE(eq.question, CONCAT('Question #', er.question_id)) AS question,
        COALESCE(eq.category_id, 0) AS category_id,
        COALESCE(ec.category_name, CONCAT('Category #', COALESCE(eq.category_id, 0))) AS category_name,
        er.question_id AS question_number
    FROM evaluation_responses er
    LEFT JOIN evaluation_questions eq ON er.question_id = eq.question_id
    LEFT JOIN evaluation_categories ec ON eq.category_id = ec.category_id
    JOIN evaluations e ON er.evaluation_id = e.evaluation_id
    WHERE $where_clause
    ORDER BY eq.category_id ASC, er.question_id ASC";

$qs_stmt = $conn->prepare($qs_sql);
$questions = [];
if ($qs_stmt) {
    if (!empty($params)) {
        $qs_stmt->bind_param($types, ...$params);
    }
    $qs_stmt->execute();
    $questions = $qs_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $qs_stmt->close();
}

foreach ($questions as &$qrow) {
    $qrow['id'] = (int)$qrow['question_id'];
    $qrow['question_text'] = $qrow['question'];
}
unset($qrow);

$question_position_map = [];
foreach ($questions as $idx => $q) {
    $question_position_map[(int)$q['question_id']] = $idx + 1;
}

$qavg_sql = "SELECT er.question_id, ROUND(AVG(er.response), 2) AS avg_rating
    FROM evaluation_responses er 
    JOIN evaluations e ON er.evaluation_id = e.evaluation_id
    WHERE $where_clause
    GROUP BY er.question_id";

$qavg_stmt = $conn->prepare($qavg_sql);
if ($qavg_stmt) {
    if (!empty($params)) {
        $qavg_stmt->bind_param($types, ...$params);
    }
    $qavg_stmt->execute();
    $avg_by_qid = [];
    $res_avg = $qavg_stmt->get_result();
    while ($r = $res_avg->fetch_assoc()) {
        $avg_by_qid[(int)$r['question_id']] = (float)$r['avg_rating'];
    }
    $qavg_stmt->close();
} else {
    $avg_by_qid = [];
}

$question_averages = [];
foreach ($questions as $idx => $q) {
    $pos                     = $idx + 1;
    $question_averages[$pos] = $avg_by_qid[(int)$q['question_id']] ?? 0;
}

$category_averages = [];
foreach ($categories as $category) {
    $cid = (int)$category['category_id'];
    $category_questions = array_filter($questions, function ($q) use ($cid) {
        return (int)($q['category_id'] ?? 0) === $cid;
    });
    $total = 0;
    $count = 0;
    foreach ($category_questions as $question) {
        $q_position = $question_position_map[(int)$question['question_id']] ?? null;
        if ($q_position && isset($question_averages[$q_position]) && $question_averages[$q_position] > 0) {
            $total += $question_averages[$q_position];
            $count++;
        }
    }
    $category_averages[$category['id']] = $count > 0 ? round($total / $count, 2) : 0;
}

// Star distribution from individual question responses (aligns "4–5 star" with actual ratings)
$rating_distribution = ['5' => 0, '4' => 0, '3' => 0, '2' => 0, '1' => 0];
$rd_sql = "
    SELECT
        LEAST(5, GREATEST(1, CAST(ROUND(er.response) AS SIGNED))) AS star,
        COUNT(*) AS cnt
    FROM evaluation_responses er
    JOIN evaluations e ON er.evaluation_id = e.evaluation_id
    WHERE $where_clause AND er.response IS NOT NULL
    GROUP BY star
";
$rd_stmt = $conn->prepare($rd_sql);
$total_ratings = 0;
if ($rd_stmt) {
    if (!empty($params)) {
        $rd_stmt->bind_param($types, ...$params);
    }
    $rd_stmt->execute();
    $rd_res = $rd_stmt->get_result();
    while ($rrow = $rd_res->fetch_assoc()) {
        $k = (string) (int) $rrow['star'];
        if (isset($rating_distribution[$k])) {
            $rating_distribution[$k] = (int) $rrow['cnt'];
            $total_ratings += (int) $rrow['cnt'];
        }
    }
    $rd_stmt->close();
}

$rating_percentages = [];
foreach ($rating_distribution as $rating => $count) {
    $rating_percentages[$rating] = $total_ratings > 0 ? round(($count / $total_ratings) * 100, 1) : 0;
}

// Question-level averages keyed by question_id for PDF export
$question_avg_by_id = [];
foreach ($questions as $q) {
    $qid = (int) $q['question_id'];
    $question_avg_by_id[$qid] = $avg_by_qid[$qid] ?? 0;
}

/** Display name on PDF footer (customize for your school). */
$institution_display_name = 'Educational Institution';

// Teacher feedback comments (optional text per evaluation)
$ec_where = ["REPLACE(REPLACE(ec.school_year, '–', '-'), '—', '-') = ?"];
$ec_params = [$school_year];
$ec_types = "s";
if ($semester_num !== null) {
    $ec_where[] = "ec.semester = ?";
    $ec_params[] = $semester_num;
    $ec_types .= "i";
}

if (!empty($teacher_filter)) {
    $ec_where[] = "ec.teacher_id = ?";
    $ec_params[] = (int)$teacher_filter;
    $ec_types .= "i";
}

if ($user_role === 'teacher' && !empty($logged_in_teacher_id)) {
    $ec_where[] = "ec.teacher_id = ?";
    $ec_params[] = $logged_in_teacher_id;
    $ec_types .= "i";
}

$ec_clause = implode(" AND ", $ec_where);
$evaluation_comments = [];
$comments_sql = "SELECT ec.comment_id, ec.comment,
        COALESCE(NULLIF(TRIM(CONCAT_WS(' ', NULLIF(tu.first_name, ''), NULLIF(tu.last_name, ''))), ''), 'TBD') AS teacher_name,
        s.subject_name
    FROM evaluation_comments ec
    LEFT JOIN teachers t ON ec.teacher_id = t.teacher_id
    LEFT JOIN users tu ON t.user_id = tu.user_id
    INNER JOIN subjects s ON ec.subject_id = s.subject_id
    WHERE $ec_clause
    ORDER BY ec.comment_id DESC";

$ec_stmt = $conn->prepare($comments_sql);
if ($ec_stmt) {
    if (!empty($ec_params)) {
        $ec_stmt->bind_param($ec_types, ...$ec_params);
    }
    $ec_stmt->execute();
    $evaluation_comments = $ec_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $ec_stmt->close();
}

// $teachers, $subjects, and $filter_edges are built above from evaluation scope (admin / non-teacher roles)
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="/capstone/images/school-logo.png" type="image/png">
    <title>Evaluation Results</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
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
            font-weight: 500;
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
            font-weight: 600;
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

        .filter-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            border: 1px solid #e9ecef;
            transition: all 0.3s ease;
        }
        
        .filter-card:hover {
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
        }
        
        .filter-group {
            margin-bottom: 0;
        }
        
        .filter-group label {
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 8px;
            display: block;
            font-size: 0.95rem;
        }
        
        .btn-apply {
            background: var(--primary-color);
            border: none;
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
            font-family: 'Poppins', sans-serif;
        }
        
        .btn-apply:hover {
            background: var(--primary-hover);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.12);
        }
        
        .btn-primary-custom {
            background: var(--primary-color);
            border: none;
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-primary-custom:hover {
            background: var(--primary-hover);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.12);
        }
        
        .btn-outline-secondary {
            border: 2px solid #6c757d;
            color: #6c757d;
            background: white;
            font-weight: 600;
            border-radius: 8px;
            transition: all 0.3s ease;
            font-family: 'Poppins', sans-serif;
        }
        
        .btn-outline-secondary:hover {
            background: #6c757d;
            color: white;
            border-color: #6c757d;
        }
        
        .btn-outline-danger {
            border: 2px solid #dc3545;
            color: #dc3545;
            background: white;
            font-weight: 600;
            border-radius: 8px;
            transition: all 0.3s ease;
            font-family: 'Poppins', sans-serif;
        }
        
        .btn-outline-danger:hover {
            background: #dc3545;
            color: white;
            border-color: #dc3545;
        }

        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f8f9fa;
        }

        .card-header h2, .card-header h3 {
            margin: 0;
            font-size: 1.4rem;
            font-weight: 600;
            color: var(--primary-color);
        }

        .card-header i {
            font-size: 1.5rem;
            color: var(--primary-color);
            background: rgba(128, 0, 0, 0.1);
            padding: 10px;
            border-radius: 10px;
        }
        
        .section-title {
            font-size: 1.4rem;
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .section-subtitle {
            color: #6c757d;
            font-size: 0.9rem;
            font-weight: 500;
            margin: 0;
        }
        
        .table-container {
            overflow-x: auto;
        }
        
        .table-custom {
            margin-bottom: 0;
        }
        
        .table-custom thead th {
            background: #f8f9fa;
            border: none;
            font-weight: 600;
            color: var(--text-dark);
            padding: 15px 12px;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .table-custom tbody td {
            padding: 15px 12px;
            border-top: 1px solid #f0f0f0;
            vertical-align: middle;
            font-size: 0.9rem;
        }
        
        .table-custom tbody tr:hover {
            background: #f8f9fa;
        }
        
        .rating-badge {
            padding: 6px 12px;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 600;
            color: white;
        }
        
        .rating-excellent { background: #28a745; }
        .rating-good { background: #20c997; }
        .rating-average { background: #ffc107; color: #000; }
        .rating-poor { background: #fd7e14; }
        .rating-very-poor { background: #dc3545; }
        
        .category-header {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-hover));
            color: white;
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .category-icon {
            font-size: 1.2rem;
            background: rgba(255, 255, 255, 0.2);
            padding: 8px;
            border-radius: 8px;
        }
        
        .progress-custom {
            height: 8px;
            border-radius: 10px;
            background: #e9ecef;
        }
        
        .progress-bar-custom {
            border-radius: 10px;
            transition: width 0.6s ease;
        }
        
        .chart-container {
            position: relative;
            height: 400px;
            margin: 20px 0;
        }
        
        .nav-tabs-custom {
            border-bottom: 3px solid var(--primary-color);
            margin-bottom: 30px;
        }
        
        .nav-tabs-custom .nav-link {
            border: none;
            color: #6c757d;
            font-weight: 600;
            padding: 15px 25px;
            border-radius: 10px 10px 0 0;
            transition: all 0.3s ease;
        }
        
        .nav-tabs-custom .nav-link.active {
            background: var(--primary-color);
            color: white;
        }
        
        .nav-tabs-custom .nav-link:hover {
            background: var(--primary-light);
            color: var(--primary-color);
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
        
        /* Enhanced Comments Tab Styling */
        .comment-item {
            background: #ffffff;
            border: 1px solid #e9ecef;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 15px;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            position: relative;
        }
        
        .comment-item:hover {
            box-shadow: 0 4px 16px rgba(128, 0, 0, 0.1);
            border-color: var(--primary-color);
            transform: translateY(-2px);
        }
        
        .comment-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
            gap: 10px;
        }
        
        .comment-meta {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }
        
        .comment-teacher {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            color: var(--primary-color);
            font-size: 1.05rem;
        }
        
        .comment-teacher i {
            background: var(--primary-light);
            padding: 8px 10px;
            border-radius: 6px;
            color: var(--primary-color);
            font-size: 0.9rem;
        }
        
        .comment-subject {
            display: flex;
            align-items: center;
            gap: 8px;
            background: var(--primary-light);
            padding: 8px 12px;
            border-radius: 8px;
            font-weight: 600;
            color: var(--primary-color);
            font-size: 0.95rem;
        }
        
        .comment-subject i {
            font-size: 0.85rem;
        }
        
        .comment-text {
            background: #f8f9fa;
            padding: 15px;
            border-left: 4px solid var(--primary-color);
            border-radius: 6px;
            color: var(--text-dark);
            line-height: 1.6;
            font-size: 0.95rem;
            word-wrap: break-word;
        }
        
        .comments-container {
            display: flex;
            flex-direction: column;
            gap: 0;
        }
        
        .comments-table-container {
            overflow-x: auto;
        }
        
        .comments-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 15px;
            margin-top: 15px;
        }
        
        /* Responsive adjustments for comments */
        @media (max-width: 768px) {
            .comment-header {
                flex-direction: column;
                gap: 8px;
            }
            
            .comment-meta {
                flex-direction: column;
                gap: 10px;
            }
            
            .comment-item {
                padding: 15px;
            }
        }

        .form-control, .form-select {
            border: 2px solid #e9ecef;
            border-radius: 8px;
            padding: 8px 12px;
            font-family: 'Poppins', sans-serif;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(128, 0, 0, 0.25);
        }
        
        .dashboard-card form .form-select:focus,
        .dashboard-card form .form-select:hover,
        .dashboard-card form .form-control:focus,
        .dashboard-card form .form-control:hover,
        .filter-card form .form-select:focus,
        .filter-card form .form-select:hover,
        .filter-card form .form-control:focus,
        .filter-card form .form-control:hover {
            border-color: #800000 !important;
            box-shadow: 0 0 0 0.2rem rgba(128, 0, 0, 0.15) !important;
            outline: none !important;
            color: #212529;
        }

        /* Enhanced PDF preview modal with professional styling */
        .pdf-preview-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 9999;
            align-items: center;
            justify-content: center;
        }

        .pdf-preview-modal.active {
            display: flex;
        }

        .pdf-preview-container {
            background: white;
            border-radius: 15px;
            width: 90%;
            max-width: 900px;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }

        .pdf-preview-header {
            padding: 20px;
            border-bottom: 2px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .pdf-preview-header h3 {
            margin: 0;
            color: var(--primary-color);
            font-weight: 600;
        }

        .pdf-preview-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #6c757d;
        }

        .pdf-preview-close:hover {
            color: var(--primary-color);
        }

        .pdf-preview-content {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
            background: #f8f9fa;
        }

        .pdf-preview-document {
            background: white;
            padding: 50px;
            margin: 0 auto;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            max-width: 850px;
            line-height: 1.7;
            font-family: 'Segoe UI', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: #1f2937;
        }

        .pdf-preview-frame {
            width: 100%;
            min-height: 70vh;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            background: #ffffff;
            display: none;
        }

        .pdf-preview-status {
            padding: 20px;
            border-radius: 8px;
            font-size: 14px;
            margin-bottom: 12px;
        }

        .pdf-preview-status.loading {
            background: #e8f4fd;
            color: #0c5460;
        }

        .pdf-preview-status.error {
            background: #fff3cd;
            color: #856404;
        }

        /* Professional report styling for title page and sections */
        .pdf-cover-page {
            text-align: center;
            padding: 40px 20px;
            border-bottom: 4px solid var(--primary-color);
            margin-bottom: 40px;
        }

        .pdf-cover-page h1 {
            color: var(--primary-color);
            font-size: 2.8rem;
            margin-bottom: 8px;
            font-weight: 800;
            letter-spacing: -0.5px;
        }

        .pdf-report-subtitle {
            color: #6c757d;
            font-size: 1.1rem;
            margin-bottom: 30px;
            font-weight: 400;
            line-height: 1.6;
        }

        .pdf-report-meta {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            padding: 24px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 0.95rem;
            border: 1px solid #e5e7eb;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }

        .pdf-report-meta-item {
            margin-bottom: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
        }

        .pdf-report-meta-label {
            font-weight: 600;
            color: #374151;
        }

        .pdf-toc {
            page-break-after: always;
            margin-bottom: 30px;
        }

        .pdf-toc h2 {
            color: var(--primary-color);
            border-bottom: 2px solid var(--primary-color);
            padding-bottom: 10px;
            margin-bottom: 20px;
        }

        .pdf-toc-item {
            margin-bottom: 10px;
            padding-left: 20px;
        }

        .pdf-toc-item a {
            color: #800000;
            text-decoration: none;
        }

        .pdf-section {
            margin-bottom: 45px;
            page-break-inside: avoid;
        }

        .pdf-section-title {
            color: white;
            background: linear-gradient(135deg, var(--primary-color) 0%, #a00000 100%);
            padding: 18px 24px;
            margin-bottom: 24px;
            border-radius: 6px;
            font-size: 1.35rem;
            font-weight: 700;
            letter-spacing: 0.3px;
            box-shadow: 0 2px 8px rgba(128, 0, 0, 0.15);
        }

        .pdf-section-subtitle {
            color: var(--primary-color);
            font-size: 1.15rem;
            font-weight: 700;
            margin-top: 24px;
            margin-bottom: 16px;
            border-left: 5px solid var(--primary-color);
            padding-left: 16px;
            letter-spacing: 0.2px;
        }

        .pdf-executive-summary {
            background: linear-gradient(135deg, #f0f4f8 0%, #ffffff 100%);
            padding: 24px;
            border-left: 6px solid var(--primary-color);
            margin-bottom: 24px;
            border-radius: 6px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        .pdf-executive-summary h3 {
            color: var(--primary-color);
            margin-top: 0;
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 16px;
        }

        .pdf-key-insight {
            margin-bottom: 16px;
            padding: 12px 16px;
            background: white;
            border-radius: 6px;
            border-left: 3px solid #e5e7eb;
            line-height: 1.6;
        }

        .pdf-key-insight strong {
            color: var(--primary-color);
            font-weight: 700;
        }

        .pdf-stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 18px;
            margin-bottom: 24px;
        }

        .pdf-stat-box {
            background: linear-gradient(135deg, #fafbfc 0%, #f3f4f6 100%);
            padding: 20px;
            border-radius: 8px;
            border-left: 5px solid var(--primary-color);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.06);
        }

        .pdf-stat-label {
            font-size: 0.88rem;
            color: #6b7280;
            margin-bottom: 8px;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .pdf-stat-value {
            font-size: 1.8rem;
            font-weight: 800;
            color: var(--primary-color);
            line-height: 1.2;
        }

        .pdf-progress-bar {
            height: 24px;
            background: #e5e7eb;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.05);
        }

        .pdf-progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary-color), #a00000);
            border-radius: 12px;
            transition: width 0.4s ease;
            box-shadow: 0 2px 4px rgba(128, 0, 0, 0.2);
        }

        /* Summary Report Styles */
        .pdf-summary-metrics {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 18px;
            margin: 24px 0;
        }

        .pdf-summary-metric-box {
            background: linear-gradient(135deg, #fafbfc 0%, #f3f4f6 100%);
            padding: 20px;
            border-radius: 8px;
            border-top: 4px solid var(--primary-color);
            text-align: center;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.06);
        }

        .pdf-summary-metric-value {
            font-size: 2rem;
            font-weight: 800;
            color: var(--primary-color);
            margin: 12px 0;
            line-height: 1.1;
        }

        .pdf-summary-metric-label {
            font-size: 0.88rem;
            color: #6b7280;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .pdf-highlight-box {
            background: linear-gradient(135deg, rgba(128, 0, 0, 0.08) 0%, rgba(128, 0, 0, 0.04) 100%);
            padding: 20px;
            border-radius: 6px;
            border-left: 6px solid var(--primary-color);
            margin: 20px 0;
            line-height: 1.7;
        }

        .pdf-preview-document table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            font-size: 0.92rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
            border-radius: 6px;
            overflow: hidden;
        }

        .pdf-preview-document table th {
            background: linear-gradient(135deg, var(--primary-color) 0%, #a00000 100%);
            color: white;
            padding: 16px;
            text-align: left;
            font-weight: 700;
            border: none;
            letter-spacing: 0.3px;
            text-transform: uppercase;
            font-size: 0.85rem;
        }

        .pdf-preview-document table td {
            padding: 14px 16px;
            border-bottom: 1px solid #e5e7eb;
        }

        .pdf-preview-document table tr:nth-child(even) {
            background: #f9fafb;
        }
        
        .pdf-preview-document table tr:last-child td {
            border-bottom: none;
        }

        /* Comments table specific styling */
        .comments-table-container th:nth-child(1) {
            width: 20%;
        }
        
        .comments-table-container th:nth-child(2) {
            width: 15%;
        }
        
        .comments-table-container th:nth-child(3) {
            width: 65%;
        }

        .comments-table-container td {
            word-wrap: break-word;
            overflow-wrap: break-word;
            line-height: 1.5;
        }

        .comments-table-container td:nth-child(3) {
            white-space: normal;
            max-width: 65%;
        }

        .pdf-preview-footer {
            padding: 20px;
            border-top: 2px solid #e9ecef;
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }

        .pdf-preview-footer button {
            padding: 10px 25px;
            border-radius: 8px;
            border: none;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-cancel {
            background: #e9ecef;
            color: #6c757d;
        }

        .btn-cancel:hover {
            background: #dee2e6;
        }

        .btn-download {
            background: var(--primary-color);
            color: white;
        }

        .btn-download:hover {
            background: var(--primary-hover);
        }

        /* Print-friendly styles for PDF output */
        @media print {
            body {
                background: white;
            }
            .pdf-preview-modal, .pdf-preview-header, .pdf-preview-footer {
                display: none;
            }
            .pdf-preview-document {
                box-shadow: none;
                max-width: 100%;
                padding: 0;
            }
            .pdf-section {
                page-break-inside: avoid;
            }
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

            .pdf-preview-container {
                width: 95%;
                max-height: 95vh;
            }

            .pdf-preview-document {
                padding: 28px 20px;
                max-width: 100%;
            }

            .pdf-stats-grid {
                grid-template-columns: 1fr;
            }
            
            .pdf-summary-metrics {
                grid-template-columns: 1fr 1fr;
            }
            
            .pdf-preview-document table {
                font-size: 12px;
            }
            
            .pdf-preview-document table th,
            .pdf-preview-document table td {
                padding: 10px 8px;
            }
        }
        
        @media print {
            .pdf-preview-document {
                box-shadow: none;
                max-width: 100%;
                padding: 0;
                background: white;
            }
            
            .pdf-section {
                page-break-inside: avoid;
                margin-bottom: 30px;
            }
            
            .pdf-section-title {
                page-break-after: avoid;
            }
        }

        /* Dynamic Filter Loading States */
select:disabled {
    background-color: #f8f9fa !important;
    cursor: not-allowed;
}

select:disabled::placeholder {
    color: #6c757d;
}

/* Loading animation for dropdowns */
select.loading {
    background: linear-gradient(90deg, #f8f9fa 25%, #e9ecef 50%, #f8f9fa 75%);
    background-size: 200% 100%;
    animation: loading-shimmer 1.5s infinite;
}

@keyframes loading-shimmer {
    0% { background-position: 200% 0; }
    100% { background-position: -200% 0; }
}
    </style>
</head>
<body>
    <?php include 'includes/side_bar.php'; ?>
    <?php include 'includes/navbar.php'; ?>

    <div class="main-content">
        <!-- Header Section -->
        <div class="header-section">
            <h1><i class="fas fa-chart-bar me-3"></i>Evaluation Results</h1>
            
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($stats['total_evaluations']); ?></div>
                    <div class="stat-label">Total Evaluations</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($stats['unique_students']); ?></div>
                    <div class="stat-label">Students Participated</div>
                </div>
                <?php if ($user_role === 'teacher'): ?>
                <div class="stat-card">
                    <div class="stat-number"><?php echo count($subjects_teaching); ?></div>
                    <div class="stat-label">Subjects Teaching</div>
                </div>
                <?php else: ?>
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($stats['evaluated_teachers']); ?></div>
                    <div class="stat-label">Teachers Evaluated</div>
                </div>
                <?php endif; ?>
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($stats['average_rating'], 1); ?></div>
                    <div class="stat-label">Average Rating</div>
                </div>
            </div>
        </div>

     

        <?php if ($stats['total_evaluations'] == 0): ?>
        <div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
            <strong style="color: #856404;"><i class="fas fa-exclamation-triangle me-2"></i>No Data Available</strong>
            <p style="margin: 10px 0 0 0; color: #856404;">
                No evaluation data found for <strong><?php echo htmlspecialchars($school_year); ?></strong> - <strong><?php echo htmlspecialchars($semester); ?></strong>
                <?php if (!empty($teacher_filter)): ?>
                    with the selected filters.
                   <?php else: ?>
                    . Please check if data has been added to the database for this period.
                <?php endif; ?>
            </p>
        </div>
        <?php endif; ?>
        <div class="filter-card">
            <form id="evaluationFiltersForm" class="row g-3 align-items-end" method="get" action="">
                <div class="col-12 col-md-2">
                    <label class="form-label fw-semibold mb-1">School Year</label>
                    <select class="form-select" name="school_year" onchange="this.form.submit()">
                        <?php foreach ($school_year_options as $sy_opt):
                            $norm = str_replace(array("–", "—"), "-", $sy_opt);
                        ?>
                            <option value="<?php echo htmlspecialchars($norm); ?>" <?php echo ($school_year === $norm) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($norm); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 col-md-2">
                    <label class="form-label fw-semibold mb-1">Semester</label>
                    <select class="form-select" name="semester" onchange="this.form.submit()">
                        <option value="all" <?php echo $semester === 'All Semesters' ? 'selected' : ''; ?>>All Semesters</option>
                        <option value="1st Semester" <?php echo $semester === '1st Semester' ? 'selected' : ''; ?>>1st Semester</option>
                        <option value="2nd Semester" <?php echo $semester === '2nd Semester' ? 'selected' : ''; ?>>2nd Semester</option>
                    </select>
                </div>

                <?php if ($user_role !== 'teacher'): ?>
                <div class="col-12 col-md-2">
                    <label class="form-label fw-semibold mb-1">Teacher</label>
                    <select class="form-select" id="teacherFilter" name="teacher_filter" onchange="this.form.submit()">
                        <option value="">All Teachers</option>
                        <?php foreach ($teachers as $teacher): ?>
                            <option value="<?php echo (int)$teacher['teacher_id']; ?>" <?php echo (string)$teacher_filter === (string)$teacher['teacher_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($teacher['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="col-12 col-md-auto ms-md-auto d-flex gap-2 justify-content-end">
                    <a class="btn btn-outline-secondary" href="evaluation_result.php">
                        <i class="fas fa-rotate-left me-1"></i>Reset
                    </a>
                    <button id="downloadPDF" type="button" class="btn btn-primary-custom" title="<?= !$has_data ? 'No evaluation data for the selected filters' : 'Generate and preview PDF report' ?>">
                        <i class="fas fa-file-pdf me-1"></i>Preview PDF
                    </button>
                </div>
            </form>
        </div>

        <!-- Navigation Tabs -->
        <ul class="nav nav-tabs nav-tabs-custom" id="resultsTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="categories-tab" data-bs-toggle="tab" data-bs-target="#categories" type="button" role="tab">
                    <i class="fas fa-folder me-2"></i>By Category
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="overall-tab" data-bs-toggle="tab" data-bs-target="#overall" type="button" role="tab">
                    <i class="fas fa-chalkboard-teacher me-2"></i>Overall Results
                </button>
            </li>
            <?php if ($user_role === 'teacher'): ?>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="comments-tab" data-bs-toggle="tab" data-bs-target="#comments" type="button" role="tab">
                    <i class="fas fa-comment-dots me-2"></i>Comments
                </button>
            </li>
            <?php endif; ?>
        </ul>

        <div class="tab-content" id="resultsTabsContent">
            <!-- Categories Tab -->
            <div class="tab-pane fade show active" id="categories" role="tabpanel">
                <?php foreach ($categories as $category): ?>
                    <div class="dashboard-card">
                        <div class="category-header" style="background: linear-gradient(135deg, <?php echo $category['color']; ?>, <?php echo $category['color']; ?>dd);">
                            <div class="category-icon">
                                <i class="<?php echo $category['icon']; ?>"></i>
                            </div>
                            <div class="flex-grow-1">
                                <h5 class="mb-0"><?php echo htmlspecialchars($category['name']); ?></h5>
                                <small>Average Rating: <?php echo $category_averages[$category['id']]; ?>/5.0</small>
                            </div>
                            <div class="text-end">
                                <?php
                                $avg = $category_averages[$category['id']];
                                $rating_class = '';
                                if ($avg >= 4.5) $rating_class = 'rating-excellent';
                                elseif ($avg >= 4.0) $rating_class = 'rating-good';
                                elseif ($avg >= 3.0) $rating_class = 'rating-average';
                                elseif ($avg >= 2.0) $rating_class = 'rating-poor';
                                else $rating_class = 'rating-very-poor';
                                ?>
                                <span class="rating-badge <?php echo $rating_class; ?>">
                                    <?php echo $avg; ?>/5.0
                                </span>
                            </div>
                        </div>
                        
                        <table class="table table-custom">
                            <thead>
                                <tr>
                                    <th>Question</th>
                                    <th style="width: 150px; text-align: center;">Rating</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $category_questions = array_filter($questions, function ($q) use ($category) {
                                    return (int)($q['category_id'] ?? 0) === (int)$category['id'];
                                });

                                foreach ($category_questions as $question):
                                    $q_pos = $question_position_map[$question['id']] ?? null;
                                    $avg_rating = ($q_pos && isset($question_averages[$q_pos])) ? $question_averages[$q_pos] : 0;
                                    $rating_class = '';
                                    if ($avg_rating >= 4.5) $rating_class = 'rating-excellent';
                                    elseif ($avg_rating >= 4.0) $rating_class = 'rating-good';
                                    elseif ($avg_rating >= 3.0) $rating_class = 'rating-average';
                                    elseif ($avg_rating >= 2.0) $rating_class = 'rating-poor';
                                    else $rating_class = 'rating-very-poor';
                                ?>
                                    <tr>
                                        <td>
                                            <?php echo htmlspecialchars($question['question_text']); ?>
                                        </td>
                                        <td style="width: 150px; text-align: center;">
                                            <span class="rating-badge <?php echo $rating_class; ?>">
                                                <?php echo number_format($avg_rating, 1); ?>/5.0
                                            </span>
                                        </td>
                                    </tr>
                                <?php 
                                endforeach; 
                                ?>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Overall Results: one row per teacher + subject (aggregated from evaluation_responses) -->
            <div class="tab-pane fade" id="overall" role="tabpanel">
                <div class="dashboard-card">
                    <div class="card-header">
                        <div style="display: flex; align-items: center; gap: 15px;">
                            <i class="fas fa-chart-line"></i>
                            <div>
                                <h3>Overall Results by Teacher &amp; Subject</h3>
                                <p class="section-subtitle">Average rating across all student evaluations and criteria for each instructor and course</p>
                            </div>
                        </div>
                    </div>
                    <?php if (count($teacher_subject_summary) > 0): ?>
                        <div class="table-container">
                            <table class="table table-custom">
                                <thead>
                                    <tr>
                                        <th>Teacher</th>
                                        <th>Subject</th>
                                        <th style="text-align: center;">Students</th>
                                        <th style="text-align: center;">Overall Rating</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($teacher_subject_summary as $row): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($row['teacher_name']); ?></td>
                                            <td><strong><?php echo htmlspecialchars($row['subject_name']); ?></strong></td>
                                            <td style="text-align: center;"><?php echo (int)($row['students_rated'] ?? 0); ?></td>
                                            <td style="text-align: center;">
                                                <?php
                                                $rating = (float)($row['overall_rating'] ?? 0);
                                                $rating_class = '';
                                                if ($rating >= 4.5) {
                                                    $rating_class = 'rating-excellent';
                                                } elseif ($rating >= 4.0) {
                                                    $rating_class = 'rating-good';
                                                } elseif ($rating >= 3.0) {
                                                    $rating_class = 'rating-average';
                                                } elseif ($rating >= 2.0) {
                                                    $rating_class = 'rating-poor';
                                                } else {
                                                    $rating_class = 'rating-very-poor';
                                                }
                                                ?>
                                                <span class="rating-badge <?php echo $rating_class; ?>">
                                                    <?php echo number_format($rating, 2); ?>/5.0
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-chart-bar"></i>
                            <h4>No aggregated results for this period</h4>
                            <p>Adjust school year, semester, or filters — or confirm students have submitted evaluations.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Comments: free-text feedback per evaluation (Teacher Role Only) -->
            <?php if ($user_role === 'teacher'): ?>
            <div class="tab-pane fade" id="comments" role="tabpanel">
                <div class="dashboard-card">
                    <div class="card-header">
                        <div style="display: flex; align-items: center; gap: 15px;">
                            <i class="fas fa-comment-dots"></i>
                            <div>
                                <h3>Student Comments</h3>
                                <p class="section-subtitle">Feedback submitted by students for your subjects</p>
                            </div>
                        </div>
                    </div>

                    <?php if (count($evaluation_comments) > 0): ?>
                        <div class="comments-grid">
                            <?php foreach ($evaluation_comments as $c): ?>
                                <div class="comment-item">
                                    <div class="comment-text">
                                        <?php echo htmlspecialchars($c['comment'] ?? ''); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-comment-slash"></i>
                            <h4>No comments for this period</h4>
                            <p>Try a different school year/semester or adjust filters.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>


        </div>
    </div>

    <!-- PDF Preview Modal -->
    <div id="pdfPreviewModal" class="pdf-preview-modal" role="dialog" aria-modal="true" aria-labelledby="pdfPreviewModalTitle" aria-hidden="true">
        <div class="pdf-preview-container">
            <div class="pdf-preview-header">
                <h3 id="pdfPreviewModalTitle"><i class="fas fa-file-pdf me-2" aria-hidden="true"></i>PDF report preview</h3>
                <button type="button" class="pdf-preview-close" aria-label="Close preview" onclick="event.stopPropagation(); event.preventDefault(); closePDFPreview();">&times;</button>
            </div>
            <div class="pdf-preview-content">
                <p class="text-muted small mb-2 px-1" id="pdfPreviewScopeNote" style="display:none;"></p>
                <div id="pdfPreviewStatus" class="pdf-preview-status loading" style="display:none;"></div>
                <iframe id="pdfPreviewFrame" class="pdf-preview-frame" title="PDF Preview"></iframe>
                <div class="pdf-preview-document" id="pdfContent">
                    <!-- PDF content will be generated here -->
                </div>
            </div>
            <div class="pdf-preview-footer">
                <button type="button" class="pdf-preview-footer button btn-cancel" onclick="event.stopPropagation(); closePDFPreview()">Cancel</button>
                <button type="button" id="pdfModalDownloadBtn" class="pdf-preview-footer button btn-download" onclick="event.stopPropagation(); event.preventDefault(); downloadPDFFile(event)">
                    <i class="fas fa-download me-2"></i>Download PDF
                </button>
            </div>
        </div>
    </div>

    <!-- PDF Generation Libraries -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Animation for table rows
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
        });

        const evaluationData = <?php echo json_encode([
            'stats' => $stats,
            'teacher_subject_summary' => $teacher_subject_summary,
            'teacher_subject_count' => count($teacher_subject_summary),
            'evaluation_comments' => $evaluation_comments,
            'categories' => $categories,
            'category_averages' => $category_averages,
            'rating_percentages' => $rating_percentages,
            'school_year' => $school_year,
            'semester' => $semester,
            'questions' => $questions,
            'question_avg_by_id' => $question_avg_by_id,
            'institution_name' => $institution_display_name,
            'has_data' => $has_data,
            'user_role' => $user_role,
            'total_rating_responses' => $total_ratings,
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        let isGeneratingPDF = false;
        let pdfPreviewLastFocus = null;
        let currentPreviewPdfBlobUrl = null;

        function escapeHtml(text) {
            if (text === null || text === undefined) return '';
            const el = document.createElement('div');
            el.textContent = String(text);
            return el.innerHTML;
        }

        function safePdfFilenamePart(str) {
            return String(str || '').replace(/[^\w.-]+/g, '_').substring(0, 64);
        }

        function initializePDFButton() {
            const previewPdfBtn = document.getElementById('downloadPDF');
            if (previewPdfBtn) {
                previewPdfBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    console.log('PDF Preview button clicked');
                    try {
                        generatePDFPreview();
                    } catch (err) {
                        console.error('Error in generatePDFPreview:', err);
                        alert('Error generating PDF preview: ' + err.message);
                    }
                });
            } else {
                console.warn('Download PDF button not found');
            }
        }

        // Initialize PDF button when DOM is ready
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initializePDFButton);
        } else {
            initializePDFButton();
        }

        function generateSummaryReportInsights(summaryRows, categories, categoryAverages, avgRatingNum, stats, ratingPercentages) {
            // Analyze categories to identify top performers and improvement areas
            const categoryMetrics = categories.map(cat => {
                const avg = parseFloat(categoryAverages[cat.id] || 0);
                let level = 'Needs Improvement';
                if (avg >= 4.5) level = 'Excellent';
                else if (avg >= 4.0) level = 'Good';
                else if (avg >= 3.0) level = 'Average';
                else if (avg >= 2.0) level = 'Poor';
                
                return {
                    name: cat.name,
                    id: cat.id,
                    avg: avg,
                    level: level,
                    gap: avg - avgRatingNum
                };
            }).sort((a, b) => b.avg - a.avg);

            // Identify top categories (above average or top 3)
            const topCategories = categoryMetrics
                .filter(cat => cat.avg >= avgRatingNum)
                .slice(0, 3)
                .length > 0 ? 
                categoryMetrics.filter(cat => cat.avg >= avgRatingNum).slice(0, 3) :
                categoryMetrics.slice(0, 3);

            // Identify categories needing improvement (below average)
            const improvementAreas = categoryMetrics
                .filter(cat => cat.avg < avgRatingNum)
                .slice(0, 3);

            return {
                topCategories: topCategories,
                improvementAreas: improvementAreas,
                categoryMetrics: categoryMetrics
            };
        }

        function openPdfPreviewModal() {
            console.log('openPdfPreviewModal called');
            pdfPreviewLastFocus = document.activeElement;
            const modal = document.getElementById('pdfPreviewModal');
            
            if (!modal) {
                console.error('Modal element not found!');
                return;
            }
            
            console.log('Modal element found:', modal);
            console.log('Modal current classes before:', modal.className);
            
            modal.classList.add('active');
            modal.setAttribute('aria-hidden', 'false');
            
            console.log('Modal current classes after:', modal.className);
            console.log('Modal display style:', window.getComputedStyle(modal).display);
            
            const scopeNote = document.getElementById('pdfPreviewScopeNote');
            if (scopeNote) scopeNote.style.display = 'block';
            const btn = document.getElementById('pdfModalDownloadBtn');
            if (btn) {
                btn.disabled = false;
                setTimeout(() => btn.focus(), 50);
            }
        }

        function setPreviewStatus(message, variant = 'loading') {
            const statusEl = document.getElementById('pdfPreviewStatus');
            if (!statusEl) return;
            statusEl.className = `pdf-preview-status ${variant}`;
            statusEl.style.display = 'block';
            statusEl.innerHTML = message;
        }

        function hidePreviewStatus() {
            const statusEl = document.getElementById('pdfPreviewStatus');
            if (!statusEl) return;
            statusEl.style.display = 'none';
            statusEl.innerHTML = '';
        }

        function resetPreviewFrame() {
            const frame = document.getElementById('pdfPreviewFrame');
            if (frame) {
                frame.style.display = 'none';
                frame.removeAttribute('src');
            }
            const htmlSource = document.getElementById('pdfContent');
            if (htmlSource) {
                htmlSource.style.display = 'none';
            }
            if (currentPreviewPdfBlobUrl) {
                URL.revokeObjectURL(currentPreviewPdfBlobUrl);
                currentPreviewPdfBlobUrl = null;
            }
        }

        function getPdfFilename() {
            const timestamp = new Date().toISOString().split('T')[0];
            const syPart = safePdfFilenamePart(evaluationData.school_year);
            return `Evaluation_Report_${syPart}_${timestamp}.pdf`;
        }

        async function renderPreviewPdfFromHtmlSource() {
            const source = document.getElementById('pdfContent');
            if (!source) {
                throw new Error('PDF source content not found.');
            }
            const { jsPDF } = window.jspdf;
            const canvas = await html2canvas(source, {
                scale: 2,
                useCORS: true,
                logging: false,
                backgroundColor: '#ffffff',
                allowTaint: false,
                width: source.scrollWidth,
                height: source.scrollHeight,
                windowWidth: source.scrollWidth,
                windowHeight: source.scrollHeight
            });

            const imgData = canvas.toDataURL('image/png', 1.0);
            const pdf = new jsPDF({
                orientation: 'portrait',
                unit: 'mm',
                format: 'a4'
            });
            const imgWidth = 210;
            const pageHeight = 297;
            const imgHeight = (canvas.height * imgWidth) / canvas.width;
            let heightLeft = imgHeight;
            let position = 0;

            pdf.addImage(imgData, 'PNG', 0, position, imgWidth, imgHeight);
            heightLeft -= pageHeight;

            while (heightLeft > 0) {
                position = heightLeft - imgHeight;
                pdf.addPage();
                pdf.addImage(imgData, 'PNG', 0, position, imgWidth, imgHeight);
                heightLeft -= pageHeight;
            }

            return pdf.output('blob');
        }

        function buildTeacherStatistics(stats) {
            const avgRating = parseFloat(stats.average_rating || 0);
            let performanceLevel = 'Needs Improvement';
            if (avgRating >= 4.5) performanceLevel = 'Excellent';
            else if (avgRating >= 4.0) performanceLevel = 'Good';
            else if (avgRating >= 3.0) performanceLevel = 'Average';
            else if (avgRating >= 2.0) performanceLevel = 'Poor';
            
            let html = '<div style="margin-bottom:32px;"><h3 style="color:#800000;font-size:18px;font-weight:700;margin-top:0;margin-bottom:16px;">📊 Overall Performance Summary</h3>';
            html += '<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:18px;margin-bottom:24px;">';
            
            // Average Rating Card
            html += '<div style="background:linear-gradient(135deg, #fafbfc 0%, #f3f4f6 100%);padding:20px;border-radius:8px;border-left:5px solid #800000;box-shadow:0 2px 4px rgba(0,0,0,0.06);text-align:center;">';
            html += '<div style="font-size:0.88rem;color:#6b7280;font-weight:500;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:8px;">Average Rating</div>';
            html += '<div style="font-size:2rem;font-weight:800;color:#800000;margin:12px 0;line-height:1.1;">' + avgRating.toFixed(1) + '/5</div>';
            html += '<div style="font-size:0.85rem;color:#6b7280;font-weight:600;">' + performanceLevel + '</div>';
            html += '</div>';
            
            // Evaluations Received
            html += '<div style="background:linear-gradient(135deg, #fafbfc 0%, #f3f4f6 100%);padding:20px;border-radius:8px;border-left:5px solid #800000;box-shadow:0 2px 4px rgba(0,0,0,0.06);text-align:center;">';
            html += '<div style="font-size:0.88rem;color:#6b7280;font-weight:500;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:8px;">Evaluations Received</div>';
            html += '<div style="font-size:2rem;font-weight:800;color:#800000;margin:12px 0;line-height:1.1;">' + stats.total_evaluations + '</div>';
            html += '<div style="font-size:0.85rem;color:#6b7280;font-weight:600;">Student Responses</div>';
            html += '</div>';
            
            // Students Evaluated
            html += '<div style="background:linear-gradient(135deg, #fafbfc 0%, #f3f4f6 100%);padding:20px;border-radius:8px;border-left:5px solid #800000;box-shadow:0 2px 4px rgba(0,0,0,0.06);text-align:center;">';
            html += '<div style="font-size:0.88rem;color:#6b7280;font-weight:500;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:8px;">Total Students</div>';
            html += '<div style="font-size:2rem;font-weight:800;color:#800000;margin:12px 0;line-height:1.1;">' + stats.unique_students + '</div>';
            html += '<div style="font-size:0.85rem;color:#6b7280;font-weight:600;">Who Evaluated You</div>';
            html += '</div>';
            
            html += '</div></div>';
            return html;
        }
        
        function buildTeacherSubjectBreakdown(rows) {
            if (!rows.length) return '<div style="margin-bottom:32px;"><h3 style="color:#800000;font-size:18px;font-weight:700;margin-top:0;margin-bottom:16px;">📚 Your Performance by Subject</h3><div style="background:#f3f4f6;padding:20px;border-radius:6px;text-align:center;color:#6b7280;font-size:14px;">No data available.</div></div>';
            
            // Sort subjects by rating descending
            const sortedRows = [...rows].sort((a, b) => parseFloat(b.overall_rating) - parseFloat(a.overall_rating));
            
            let html = '<div style="margin-bottom:32px;"><h3 style="color:#800000;font-size:18px;font-weight:700;margin-top:0;margin-bottom:16px;">📚 Your Performance by Subject</h3>';
            html += '<table style="width:100%;border-collapse:collapse;margin-top:16px;box-shadow:0 1px 3px rgba(0,0,0,0.08);border-radius:6px;overflow:hidden;">';
            html += '<tr style="background:linear-gradient(135deg, #800000 0%, #a00000 100%);color:white;">';
            html += '<th style="padding:14px;text-align:left;font-weight:700;text-transform:uppercase;letter-spacing:0.3px;font-size:12px;">Subject</th>';
            html += '<th style="padding:14px;text-align:center;font-weight:700;text-transform:uppercase;letter-spacing:0.3px;font-size:12px;">Average Rating</th>';
            html += '<th style="padding:14px;text-align:center;font-weight:700;text-transform:uppercase;letter-spacing:0.3px;font-size:12px;">Students</th>';
            html += '</tr>';
            
            sortedRows.forEach((row, idx) => {
                const ratingColor = parseFloat(row.overall_rating) >= 4.5 ? '#28a745' : parseFloat(row.overall_rating) >= 4.0 ? '#20c997' : parseFloat(row.overall_rating) >= 3.0 ? '#ffc107' : '#dc3545';
                html += '<tr style="border-bottom:1px solid #e5e7eb;' + (idx % 2 === 0 ? 'background:#f9fafb;' : '') + '">';
                html += '<td style="padding:14px;text-align:left;color:#1f2937;font-weight:600;">' + escapeHtml(row.subject_name) + '</td>';
                html += '<td style="padding:14px;text-align:center;"><span style="background:' + ratingColor + ';color:white;padding:8px 14px;border-radius:6px;font-weight:700;font-size:14px;">' + parseFloat(row.overall_rating).toFixed(1) + '/5</span></td>';
                html += '<td style="padding:14px;text-align:center;font-weight:600;color:#6b7280;">' + row.students_rated + '</td>';
                html += '</tr>';
            });
            
            html += '</table></div>';
            return html;
        }
        
        function buildTeacherCategoryAnalysis(categories, categoryAverages) {
            if (!categories || !categories.length) return '';
            
            const sortedCats = [...categories].sort((a, b) => parseFloat(categoryAverages[b.id] || 0) - parseFloat(categoryAverages[a.id] || 0));
            
            let html = '<div style="margin-bottom:32px;"><h3 style="color:#800000;font-size:18px;font-weight:700;margin-top:0;margin-bottom:16px;">📈 Category Performance Analysis</h3>';
            html += '<table style="width:100%;border-collapse:collapse;margin-top:16px;box-shadow:0 1px 3px rgba(0,0,0,0.08);border-radius:6px;overflow:hidden;">';
            html += '<tr style="background:linear-gradient(135deg, #800000 0%, #a00000 100%);color:white;">';
            html += '<th style="padding:14px;text-align:left;font-weight:700;text-transform:uppercase;letter-spacing:0.3px;font-size:12px;">Category</th>';
            html += '<th style="padding:14px;text-align:center;font-weight:700;text-transform:uppercase;letter-spacing:0.3px;font-size:12px;">Rating</th>';
            html += '<th style="padding:14px;text-align:left;font-weight:700;text-transform:uppercase;letter-spacing:0.3px;font-size:12px;">Performance Level</th>';
            html += '<th style="padding:14px;text-align:center;font-weight:700;text-transform:uppercase;letter-spacing:0.3px;font-size:12px;">Progress</th>';
            html += '</tr>';
            
            sortedCats.forEach((cat, idx) => {
                const avg = parseFloat(categoryAverages[cat.id] || 0);
                let level = 'Needs Improvement';
                let levelColor = '#dc2626';
                if (avg >= 4.5) { level = 'Excellent'; levelColor = '#059669'; }
                else if (avg >= 4.0) { level = 'Good'; levelColor = '#0891b2'; }
                else if (avg >= 3.0) { level = 'Average'; levelColor = '#d97706'; }
                else if (avg >= 2.0) { level = 'Poor'; levelColor = '#ef4444'; }
                
                const barWidth = (avg / 5.0) * 100;
                html += '<tr style="border-bottom:1px solid #e5e7eb;' + (idx % 2 === 0 ? 'background:#f9fafb;' : '') + '">';
                html += '<td style="padding:14px;color:#1f2937;font-weight:600;">' + escapeHtml(cat.name) + '</td>';
                html += '<td style="padding:14px;text-align:center;font-weight:700;font-size:14px;color:#800000;">' + avg.toFixed(1) + '/5</td>';
                html += '<td style="padding:14px;color:' + levelColor + ';font-weight:700;">' + level + '</td>';
                html += '<td style="padding:14px;"><div style="background:#e5e7eb;height:20px;border-radius:10px;overflow:hidden;box-shadow:inset 0 1px 2px rgba(0,0,0,0.05);"><div style="background:linear-gradient(90deg, ' + levelColor + ', ' + levelColor + 'dd);height:100%;width:' + barWidth + '%;transition:width 0.4s;border-radius:10px;"></div></div></td>';
                html += '</tr>';
            });
            
            html += '</table></div>';
            return html;
        }
        
        function buildTeacherInsights(stats, categoryAverages, categories, rows) {
            const avgRating = parseFloat(stats.average_rating || 0);
            let html = '<div style="margin-bottom:32px;"><h3 style="color:#800000;font-size:18px;font-weight:700;margin-top:0;margin-bottom:16px;">💡 Key Insights & Recommendations</h3>';
            
            // Find highest and lowest rated subjects
            if (rows && rows.length > 0) {
                const sorted = [...rows].sort((a, b) => parseFloat(b.overall_rating) - parseFloat(a.overall_rating));
                const highest = sorted[0];
                const lowest = sorted[sorted.length - 1];
                
                html += '<div style="background:linear-gradient(135deg, rgba(40, 167, 69, 0.08) 0%, rgba(40, 167, 69, 0.04) 100%);border-left:6px solid #28a745;padding:16px;border-radius:6px;margin-bottom:16px;">';
                html += '<strong style="color:#155724;font-size:14px;">✓ Strength:</strong> <span style="color:#155724;">Your highest-rated subject is <strong>' + escapeHtml(highest.subject_name) + '</strong> with an average rating of <strong>' + parseFloat(highest.overall_rating).toFixed(1) + '/5</strong>. This demonstrates strong teaching effectiveness in this area.</span>';
                html += '</div>';
                
                if (parseFloat(lowest.overall_rating) < avgRating) {
                    html += '<div style="background:linear-gradient(135deg, rgba(220, 53, 69, 0.08) 0%, rgba(220, 53, 69, 0.04) 100%);border-left:6px solid #dc3545;padding:16px;border-radius:6px;margin-bottom:16px;">';
                    html += '<strong style="color:#721c24;font-size:14px;">⚠ Growth Opportunity:</strong> <span style="color:#721c24;">Consider focusing on <strong>' + escapeHtml(lowest.subject_name) + '</strong> (rated ' + parseFloat(lowest.overall_rating).toFixed(1) + '/5). Review teaching methodologies and student engagement strategies for this subject.</span>';
                    html += '</div>';
                }
            }
            
            // Category insights
            if (categories && categories.length > 0) {
                const sorted = [...categories].sort((a, b) => parseFloat(categoryAverages[b.id] || 0) - parseFloat(categoryAverages[a.id] || 0));
                const topCat = sorted[0];
                const bottomCat = sorted[sorted.length - 1];
                
                const topAvg = parseFloat(categoryAverages[topCat.id] || 0);
                const bottomAvg = parseFloat(categoryAverages[bottomCat.id] || 0);
                
                if (topAvg >= 4.0) {
                    html += '<div style="background:linear-gradient(135deg, #f0f9ff 0%, #f0f4f8 100%);border-left:6px solid #17a2b8;padding:16px;border-radius:6px;margin-bottom:16px;">';
                    html += '<strong style="color:#1a5f7a;font-size:14px;">⭐ Excellence:</strong> <span style="color:#1a5f7a;">You excel in <strong>' + escapeHtml(topCat.name) + '</strong> with a category average of ' + topAvg.toFixed(1) + '/5. Maintain this high standard.</span>';
                    html += '</div>';
                }
                
                if (bottomAvg < 3.5) {
                    html += '<div style="background:linear-gradient(135deg, #fffbf0 0%, #fff8f0 100%);border-left:6px solid #ffc107;padding:16px;border-radius:6px;margin-bottom:16px;">';
                    html += '<strong style="color:#856404;font-size:14px;">📊 Development Area:</strong> <span style="color:#856404;">Focus on improving <strong>' + escapeHtml(bottomCat.name) + '</strong> (currently ' + bottomAvg.toFixed(1) + '/5). Consider professional development opportunities in this area.</span>';
                    html += '</div>';
                }
            }
            
            html += '</div>';
            return html;
        }

        function buildOverallResultsTable(rows) {
            if (!rows.length) {
                return '<div style="margin-bottom:32px;"><h3 style="color:#800000;font-size:18px;font-weight:700;margin-top:0;margin-bottom:16px;">🏆 Top Performing Teachers</h3><div style="background:#f3f4f6;padding:20px;border-radius:6px;text-align:center;color:#6b7280;font-size:14px;">No data available.</div></div>';
            }
            // Sort by rating descending and take top 5
            const topRows = rows
                .sort((a, b) => parseFloat(b.overall_rating || 0) - parseFloat(a.overall_rating || 0))
                .slice(0, 5);
            
            let body = '';
            topRows.forEach((row, idx) => {
                const rating = parseFloat(row.overall_rating || 0).toFixed(2);
                const ratingInt = Math.round(rating);
                let stars = '★'.repeat(ratingInt) + '☆'.repeat(5 - ratingInt);
                body += '<tr style="border-bottom:1px solid #e5e7eb;">';
                body += '<td style="padding:14px;font-weight:700;color:#1f2937;">' + (idx + 1) + '. ' + escapeHtml(row.teacher_name || '') + '</td>';
                body += '<td style="padding:14px;color:#6b7280;">' + escapeHtml(row.subject_name || '') + '</td>';
                body += '<td style="padding:14px;text-align:center;color:#6b7280;">' + escapeHtml(String(row.students_rated ?? '')) + '</td>';
                body += '<td style="padding:14px;text-align:center;font-weight:700;color:#059669;font-size:15px;">' + escapeHtml(rating) + '/5.0</td>';
                body += '</tr>';
            });
            return '<div style="margin-bottom:32px;"><h3 style="color:#800000;font-size:18px;font-weight:700;margin-top:0;margin-bottom:16px;">🏆 Top Performing Teachers</h3>' +
                '<p style="font-size:14px;color:#6b7280;margin:0 0 18px 0;line-height:1.6;">Teachers with the highest overall evaluation ratings demonstrating excellence in instruction and engagement.</p>' +
                '<table style="width:100%;border-collapse:collapse;font-size:13px;box-shadow:0 1px 3px rgba(0,0,0,0.08);border-radius:6px;overflow:hidden;"><thead><tr style="background:linear-gradient(135deg,#059669 0%,#047857 100%);"><th style="padding:14px;text-align:left;font-weight:700;color:white;text-transform:uppercase;letter-spacing:0.3px;font-size:12px;">Teacher</th><th style="padding:14px;text-align:left;font-weight:700;color:white;text-transform:uppercase;letter-spacing:0.3px;font-size:12px;">Subject</th><th style="padding:14px;text-align:center;font-weight:700;color:white;text-transform:uppercase;letter-spacing:0.3px;font-size:12px;">Students</th><th style="padding:14px;text-align:center;font-weight:700;color:white;text-transform:uppercase;letter-spacing:0.3px;font-size:12px;">Rating</th></tr></thead><tbody>' +
                body + '</tbody></table></div>';
        }

        function buildCriteriaTables(summaryRows) {
            if (!summaryRows || !summaryRows.length) {
                return '<div style="margin-bottom:32px;"><h3 style="color:#800000;font-size:18px;font-weight:700;margin-top:0;margin-bottom:16px;">📈 Focus Areas for Development</h3><div style="background:#f3f4f6;padding:20px;border-radius:6px;text-align:center;color:#6b7280;font-size:14px;">No data available.</div></div>';
            }
            // Sort by rating ascending and take bottom 5 (lowest ratings)
            const bottomRows = summaryRows
                .sort((a, b) => parseFloat(a.overall_rating || 0) - parseFloat(b.overall_rating || 0))
                .slice(0, 5);
            
            let body = '';
            bottomRows.forEach((row, idx) => {
                const rating = parseFloat(row.overall_rating || 0).toFixed(2);
                const ratingInt = Math.round(rating);
                let stars = '★'.repeat(ratingInt) + '☆'.repeat(5 - ratingInt);
                body += '<tr style="border-bottom:1px solid #e5e7eb;">';
                body += '<td style="padding:14px;font-weight:700;color:#1f2937;">' + (idx + 1) + '. ' + escapeHtml(row.teacher_name || '') + '</td>';
                body += '<td style="padding:14px;color:#6b7280;">' + escapeHtml(row.subject_name || '') + '</td>';
                body += '<td style="padding:14px;text-align:center;color:#6b7280;">' + escapeHtml(String(row.students_rated ?? '')) + '</td>';
                body += '<td style="padding:14px;text-align:center;font-weight:700;color:#dc2626;font-size:15px;">' + escapeHtml(rating) + '/5.0</td>';
                body += '</tr>';
            });
            return '<br><br><br><br><br><br><br><br><br><br><br><br><br><br><div style="margin-bottom:32px;"><h3 style="color:#800000;font-size:18px;font-weight:700;margin-top:0;margin-bottom:16px;">📈 Focus Areas for Development</h3>' +
                '<p style="font-size:14px;color:#6b7280;margin:0 0 18px 0;line-height:1.6;">Teachers with lower evaluation ratings who would benefit from targeted professional development, mentoring, and support initiatives.</p>' +
                '<table style="width:100%;border-collapse:collapse;font-size:13px;box-shadow:0 1px 3px rgba(0,0,0,0.08);border-radius:6px;overflow:hidden;"><thead><tr style="background:linear-gradient(135deg,#dc2626 0%,#b91c1c 100%);"><th style="padding:14px;text-align:left;font-weight:700;color:white;text-transform:uppercase;letter-spacing:0.3px;font-size:12px;">Teacher</th><th style="padding:14px;text-align:left;font-weight:700;color:white;text-transform:uppercase;letter-spacing:0.3px;font-size:12px;">Subject</th><th style="padding:14px;text-align:center;font-weight:700;color:white;text-transform:uppercase;letter-spacing:0.3px;font-size:12px;">Students</th><th style="padding:14px;text-align:center;font-weight:700;color:white;text-transform:uppercase;letter-spacing:0.3px;font-size:12px;">Rating</th></tr></thead><tbody>' +
                body + '</tbody></table></div>';
        }

        function buildCommentsSection(role, comments) {
            if (role === 'teacher' || !comments || !comments.length) return '';
            let h = '<div style="margin-bottom:32px;"><h3 style="color:#800000;font-size:18px;font-weight:700;margin-top:0;margin-bottom:16px;">💬 Student Feedback & Comments</h3>';
            comments.forEach(c => {
                h += '<div style="border-left:5px solid #800000;border-radius:6px;padding:16px;margin-bottom:12px;font-size:13px;background:#f9fafb;box-shadow:0 1px 3px rgba(0, 0, 0, 0.06);line-height:1.6;color:#374151;"><div style="white-space:pre-wrap;">' + escapeHtml(c.comment || '') + '</div></div>';
            });
            h += '</div>';
            return h;
        }

        function testModalOpen() {
            console.log('testModalOpen called');
            const modal = document.getElementById('pdfPreviewModal');
            if (!modal) {
                console.error('Modal not found!');
                alert('ERROR: Modal element not found in DOM');
                return;
            }
            console.log('Modal found, adding active class');
            modal.classList.add('active');
            console.log('Modal now has active class:', modal.classList.contains('active'));
            console.log('Computed display:', window.getComputedStyle(modal).display);
        }

        function generatePDFPreview() {
            console.log('generatePDFPreview started');
            const pdfContent = document.getElementById('pdfContent');
            const modal = document.getElementById('pdfPreviewModal');
            
            if (!pdfContent) {
                console.error('pdfContent element not found');
                return;
            }
            
            if (!modal) {
                console.error('pdfPreviewModal element not found');
                return;
            }
            
            // Open modal immediately
            openPdfPreviewModal();
            console.log('Modal opened, active class:', modal.classList.contains('active'));
            
            const scopeNoteEl = document.getElementById('pdfPreviewScopeNote');
            const downloadBtn = document.getElementById('pdfModalDownloadBtn');
            
            resetPreviewFrame();

            if (typeof html2canvas !== 'function' || !window.jspdf) {
                setPreviewStatus('PDF libraries failed to load. Please refresh the page and try again.', 'error');
                if (downloadBtn) downloadBtn.disabled = true;
                return;
            }

            if (!evaluationData.has_data) {
                pdfContent.innerHTML = '<div style="padding:20px;background:#fff3cd;border-radius:8px;color:#856404;">No evaluation data for the selected filters.</div>';
                pdfContent.style.display = 'block';
                if (downloadBtn) downloadBtn.disabled = true;
                if (scopeNoteEl) scopeNoteEl.style.display = 'none';
                setPreviewStatus('No evaluation data for the selected filters.', 'error');
                return;
            }
            const stats = evaluationData.stats;
            const summaryRows = evaluationData.teacher_subject_summary || [];
            const categories = evaluationData.categories;
            const ratingPercentages = evaluationData.rating_percentages;
            const categoryAverages = evaluationData.category_averages || {};
            
            const avgRatingNum = parseFloat(stats.average_rating || 0);
            const avgRating = avgRatingNum.toFixed(1);
            let performanceLevel = 'Needs Improvement';
            if (avgRatingNum >= 4.5) performanceLevel = 'Excellent';
            else if (avgRatingNum >= 4.0) performanceLevel = 'Good';
            else if (avgRatingNum >= 3.0) performanceLevel = 'Average';
            else if (avgRatingNum >= 2.0) performanceLevel = 'Poor';

            const totalResp = evaluationData.total_rating_responses || 0;
            const highRatingPercent = (parseFloat(ratingPercentages[5]) + parseFloat(ratingPercentages[4])).toFixed(1);
            const highRatingCount = Math.round(((parseFloat(ratingPercentages[5]) + parseFloat(ratingPercentages[4])) / 100) * totalResp);

            let html = '';
            
            // Generate different report based on user role
            if (evaluationData.user_role === 'teacher') {
                // Teacher-specific personalized report
                html = `
                    <!-- Teacher Report Header -->
                    <div style="background: linear-gradient(135deg, #800000, #a00000); color: white; padding: 40px; margin-bottom: 32px; border-radius: 8px; box-shadow: 0 4px 12px rgba(128, 0, 0, 0.25);">
                        <h1 style="margin: 0 0 10px 0; font-size: 32px; font-weight: 800; letter-spacing: -0.5px;">Your Evaluation Report</h1>
                        <p style="margin: 0; font-size: 14px; opacity: 0.95; line-height: 1.6;">Personalized feedback and performance analysis for professional growth</p>
                    </div>
                    
                    <!-- Evaluation Period Info -->
                    <div style="background: linear-gradient(135deg, #f9fafb 0%, #ffffff 100%); padding: 28px; margin-bottom: 32px; border-left: 6px solid #800000; border-radius: 8px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);">
                        <h3 style="margin-top: 0; color: #800000; font-size: 16px; font-weight: 700; margin-bottom: 18px;">Evaluation Period</h3>
                        <table style="width: 100%; font-size: 14px; line-height: 2.2;">
                            <tr>
                                <td style="width: 35%; padding: 8px 0;"><strong style="color: #374151; font-weight: 700;">School Year:</strong></td>
                                <td style="padding: 8px 0; color: #6b7280;">${escapeHtml(evaluationData.school_year)}</td>
                            </tr>
                            <tr>
                                <td style="width: 35%; padding: 8px 0;"><strong style="color: #374151; font-weight: 700;">Semester:</strong></td>
                                <td style="padding: 8px 0; color: #6b7280;">${escapeHtml(evaluationData.semester)}</td>
                            </tr>
                            <tr>
                                <td style="width: 35%; padding: 8px 0;"><strong style="color: #374151; font-weight: 700;">Report Generated:</strong></td>
                                <td style="padding: 8px 0; color: #6b7280;">${new Date().toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}</td>
                            </tr>
                        </table>
                    </div>
                    
                    ${buildTeacherStatistics(stats)}
                    ${buildTeacherSubjectBreakdown(summaryRows)}
                    ${buildTeacherCategoryAnalysis(categories, categoryAverages)}
                    ${buildTeacherInsights(stats, categoryAverages, categories, summaryRows)}
                    
                    <!-- Closing Section -->
                    <div style="border-top: 3px solid #800000; padding-top: 32px; margin-top: 40px;">
                        <div style="background: linear-gradient(135deg, #fafbfc 0%, #ffffff 100%); padding: 28px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);">
                            <div style="text-align: center; color: #374151; font-size: 13px; line-height: 1.8;">
                                <p style="margin: 0 0 20px 0; font-size: 14px; font-weight: 700; color: #1f2937;"><strong>${escapeHtml(evaluationData.institution_name)}</strong></p>
                                <p style="margin: 0; font-size: 12px; color: #6b7280; line-height: 1.6;">
                                    <strong>Next Steps:</strong> Use these insights to guide your professional development goals. Share this report with your administration and mentors to discuss strategies for continuous improvement.
                                </p>
                                <p style="margin: 16px 0; font-size: 11px; color: #9ca3af; line-height: 1.6;">
                                    Report Generated: ${new Date().toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit' })} | Evaluation System
                                </p>
                            </div>
                        </div>
                    </div>
                `;
            } else {
                // Admin/Registrar report with comparisons
                const summaryReport = generateSummaryReportInsights(summaryRows, categories, categoryAverages, avgRatingNum, stats, ratingPercentages);
                const overallHtml = buildOverallResultsTable(summaryRows);
                const criteriaHtml = buildCriteriaTables(summaryRows);
                
                html = `
                    <!-- Professional Header Section -->
                    <div style="background: linear-gradient(135deg, #800000, #a00000); color: white; padding: 40px; margin-bottom: 32px; border-radius: 8px; box-shadow: 0 4px 12px rgba(128, 0, 0, 0.25);">
                        <h1 style="margin: 0 0 10px 0; font-size: 32px; font-weight: 800; letter-spacing: -0.5px;">Evaluation Summary</h1>
                        <p style="margin: 0 0 28px 0; font-size: 14px; opacity: 0.95; line-height: 1.6;">Executive summary based on selected filters (school year, semester, and any teacher/subject filters applied to this report).</p>
                        
                        <!-- Top-line Metrics in Professional Cards -->
                        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 18px; margin-top: 24px;">
                            <div style="background: rgba(255,255,255,0.12); padding: 20px; border-radius: 8px; text-align: center; border: 1px solid rgba(255,255,255,0.2);">
                                <div style="font-size: 32px; font-weight: 800; line-height: 1.1;">${avgRating}</div>
                                <div style="font-size: 12px; font-weight: 600; opacity: 0.92; margin-top: 8px; text-transform: uppercase; letter-spacing: 0.4px;">Overall Rating</div>
                            </div>
                            <div style="background: rgba(255,255,255,0.12); padding: 20px; border-radius: 8px; text-align: center; border: 1px solid rgba(255,255,255,0.2);">
                                <div style="font-size: 32px; font-weight: 800; line-height: 1.1;">${stats.total_evaluations}</div>
                                <div style="font-size: 12px; font-weight: 600; opacity: 0.92; margin-top: 8px; text-transform: uppercase; letter-spacing: 0.4px;">Total Evaluations</div>
                            </div>
                            <div style="background: rgba(255,255,255,0.12); padding: 20px; border-radius: 8px; text-align: center; border: 1px solid rgba(255,255,255,0.2);">
                                <div style="font-size: 32px; font-weight: 800; line-height: 1.1;">${performanceLevel}</div>
                                <div style="font-size: 12px; font-weight: 600; opacity: 0.92; margin-top: 8px; text-transform: uppercase; letter-spacing: 0.4px;">Performance Level</div>
                            </div>
                            <div style="background: rgba(255,255,255,0.12); padding: 20px; border-radius: 8px; text-align: center; border: 1px solid rgba(255,255,255,0.2);">
                                <div style="font-size: 32px; font-weight: 800; line-height: 1.1;">${highRatingPercent}%</div>
                                <div style="font-size: 11px; font-weight: 600; opacity: 0.92; margin-top: 8px; text-transform: uppercase; letter-spacing: 0.4px;">4-5 Star Ratings<br><span style="font-weight:500;opacity:.9;font-size:11px;">${highRatingCount} of ${totalResp}</span></div>
                            </div>
                        </div>
                    </div>

                    <!-- Overview Section -->
                    <div style="background: linear-gradient(135deg, #f9fafb 0%, #ffffff 100%); padding: 28px; margin-bottom: 32px; border-left: 6px solid #800000; border-radius: 8px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);">
                        <h3 style="margin-top: 0; color: #800000; font-size: 18px; font-weight: 700; margin-bottom: 20px; letter-spacing: 0.2px;">Evaluation Period Overview</h3>
                        <table style="width: 100%; font-size: 14px; line-height: 2.2;">
                            <tr>
                                <td style="width: 35%; padding: 10px 0;"><strong style="color: #374151; font-weight: 700;">School Year:</strong></td>
                                <td style="padding: 10px 0; color: #6b7280;">${escapeHtml(evaluationData.school_year)}</td>
                            </tr>
                            <tr>
                                <td style="width: 35%; padding: 10px 0;"><strong style="color: #374151; font-weight: 700;">Semester:</strong></td>
                                <td style="padding: 10px 0; color: #6b7280;">${escapeHtml(evaluationData.semester)}</td>
                            </tr>
                            <tr>
                                <td style="width: 35%; padding: 10px 0;"><strong style="color: #374151; font-weight: 700;">Report Generated:</strong></td>
                                <td style="padding: 10px 0; color: #6b7280;">${new Date().toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}</td>
                            </tr>
                            <tr>
                                <td style="width: 35%; padding: 8px 0;"><strong>Total Responses:</strong></td>
                                <td style="padding: 8px 0;">${stats.total_evaluations}</td>
                            </tr>
                        </table>
                    </div>

                    <!-- Scorecard Section -->
                    <div style="background: linear-gradient(135deg, #fafbfc 0%, #ffffff 100%); padding: 28px; margin-bottom: 32px; border: 1px solid #e5e7eb; border-radius: 8px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);">
                        <h3 style="margin-top: 0; color: #800000; font-size: 18px; font-weight: 700; text-align: center; margin-bottom: 20px;">Overall Performance Scorecard</h3>
                        <div style="text-align: center; padding: 28px 0; border-bottom: 2px solid #e5e7eb; margin-bottom: 20px;">
                            <div style="font-size: 56px; font-weight: 800; color: #800000; line-height: 1.1;">${avgRating}</div>
                            <div style="font-size: 15px; color: #6b7280; margin-top: 10px; font-weight: 500;">Overall Rating (out of 5.0)</div>
                        </div>
                        <div style="background: #f0fdf4; border-left: 5px solid #059669; padding: 16px; border-radius: 6px; text-align: center;">
                            <div style="font-size: 14px; color: #047857; line-height: 1.6;"><strong>${highRatingPercent}%</strong> of individual criteria responses rated 4–5 on the 1–5 scale (<strong>${highRatingCount}</strong> of <strong>${totalResp}</strong> responses)</div>
                        </div>
                    </div>
                    <br>
                    <br>
                    <br>
                    <br>
                    <br>
                    <!-- Category Performance (The "Why") -->
                    <div style="margin-bottom: 40px;">
                        <h3 style="color: #800000; font-size: 18px; font-weight: 700; margin-top: 0; margin-bottom: 18px;">Category Performance Analysis</h3>
                        <p style="color: #6b7280; font-size: 13px; margin-bottom: 20px; line-height: 1.6;">Breakdown of average ratings across evaluation categories to identify strengths and areas for improvement.</p>
                        <table style="width: 100%; border-collapse: collapse; font-size: 13px; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08); border-radius: 6px; overflow: hidden;">
                            <thead>
                                <tr style="background: linear-gradient(135deg, #800000 0%, #a00000 100%);">
                                    <th style="padding: 14px; text-align: left; font-weight: 700; color: white; text-transform: uppercase; letter-spacing: 0.3px; font-size: 12px;">Category</th>
                                    <th style="padding: 14px; text-align: center; font-weight: 700; color: white; text-transform: uppercase; letter-spacing: 0.3px; font-size: 12px;">Rating</th>
                                    <th style="padding: 14px; text-align: left; font-weight: 700; color: white; text-transform: uppercase; letter-spacing: 0.3px; font-size: 12px;">Level</th>
                                    <th style="padding: 14px; text-align: center; font-weight: 700; color: white; text-transform: uppercase; letter-spacing: 0.3px; font-size: 12px;">Progress</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${categories.map(cat => {
                                    const avg = parseFloat(categoryAverages[cat.id] || 0);
                                    let level = 'Needs Improvement';
                                    let levelColor = '#dc2626';
                                    if (avg >= 4.5) { level = 'Excellent'; levelColor = '#059669'; }
                                    else if (avg >= 4.0) { level = 'Good'; levelColor = '#0891b2'; }
                                    else if (avg >= 3.0) { level = 'Average'; levelColor = '#d97706'; }
                                    else if (avg >= 2.0) { level = 'Poor'; levelColor = '#ef4444'; }
                                    
                                    const barWidth = (avg / 5.0) * 100;
                                    return `
                                        <tr style="border-bottom: 1px solid #e5e7eb;">
                                            <td style="padding: 14px; color: #1f2937; font-weight: 600;">${escapeHtml(cat.name)}</td>
                                            <td style="padding: 14px; text-align: center; font-weight: 700; font-size: 14px; color: #800000;">${avg.toFixed(1)}/5.0</td>
                                            <td style="padding: 14px; color: ${levelColor}; font-weight: 700;">${level}</td>
                                            <td style="padding: 14px;">
                                                <div style="background: #e5e7eb; height: 24px; border-radius: 12px; overflow: hidden; box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.05);">
                                                    <div style="background: linear-gradient(90deg, ${levelColor}, ${levelColor}dd); height: 100%; width: ${barWidth}%; transition: width 0.4s; border-radius: 12px;"></div>
                                                </div>
                                            </td>
                                        </tr>
                                    `;
                                }).join('')}
                            </tbody>
                        </table>
                    </div>

                    ${overallHtml}
                    ${criteriaHtml}

                    <!-- Strength & Growth Analysis -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 32px;">
                        <!-- Top 3 Strengths -->
                        ${summaryReport.topCategories.length > 0 ? `
                        <div style="background: linear-gradient(135deg, #f0fdf4 0%, #ffffff 100%); padding: 20px; border-left: 6px solid #059669; border-radius: 8px; box-shadow: 0 2px 8px rgba(5, 150, 105, 0.1);">
                            <h4 style="margin-top: 0; color: #047857; font-size: 14px; font-weight: 700; margin-bottom: 14px;">✨ Top 3 Strengths</h4>
                            <ul style="margin: 0; padding-left: 20px; font-size: 13px; color: #047857;">
                                ${summaryReport.topCategories.slice(0, 3).map(cat => {
                                    return `<li style="margin-bottom: 10px; line-height: 1.5;"><strong>${escapeHtml(cat.name)}</strong> rated <strong>${cat.avg.toFixed(1)}/5.0</strong></li>`;
                                }).join('')}
                            </ul>
                        </div>
                        ` : ''}

                        <!-- Top 3 Areas for Improvement -->
                        ${summaryReport.improvementAreas.length > 0 ? `
                        <div style="background: linear-gradient(135deg, #fef2f2 0%, #ffffff 100%); padding: 20px; border-left: 6px solid #dc2626; border-radius: 8px; box-shadow: 0 2px 8px rgba(220, 38, 38, 0.1);">
                            <h4 style="margin-top: 0; color: #991b1b; font-size: 14px; font-weight: 700; margin-bottom: 14px;">📈 Development Opportunities</h4>
                            <ul style="margin: 0; padding-left: 20px; font-size: 13px; color: #991b1b;">
                                ${summaryReport.improvementAreas.slice(0, 3).map(cat => {
                                    return `<li style="margin-bottom: 10px; line-height: 1.5;"><strong>${escapeHtml(cat.name)}</strong> rated <strong>${cat.avg.toFixed(1)}/5.0</strong></li>`;
                                }).join('')}
                            </ul>
                        </div>
                        ` : ''}
                    </div>

                    <!-- Action Items Section -->
                    <div style="background: linear-gradient(135deg, #fef3c7 0%, #fef9e7 100%); padding: 24px; margin-bottom: 32px; border-left: 6px solid #d97706; border-radius: 8px; box-shadow: 0 2px 8px rgba(217, 119, 6, 0.12);">
                        <h3 style="margin-top: 0; color: #92400e; font-size: 16px; font-weight: 700; margin-bottom: 18px;">🎯 Recommended Action Items</h3>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                            <div>
                                <h4 style="margin: 0 0 14px 0; color: #92400e; font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px;">Maintain Strengths</h4>
                                <ul style="margin: 0; padding-left: 20px; font-size: 13px; color: #b45309;">
                                    ${summaryReport.topCategories.slice(0, 3).map(cat => {
                                        return `<li style="margin-bottom: 10px; line-height: 1.5;">Continue fostering excellence in <strong>${escapeHtml(cat.name)}</strong></li>`;
                                    }).join('')}
                                </ul>
                            </div>
                            <div>
                                <h4 style="margin: 0 0 14px 0; color: #92400e; font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px;">Target Development</h4>
                                <ul style="margin: 0; padding-left: 20px; font-size: 13px; color: #b45309;">
                                    ${summaryReport.improvementAreas.slice(0, 3).map(cat => {
                                        return `<li style="margin-bottom: 10px; line-height: 1.5;">Provide targeted support in <strong>${escapeHtml(cat.name)}</strong></li>`;
                                    }).join('')}
                                </ul>
                            </div>
                        </div>
                    </div>
                `;
            }

            pdfContent.innerHTML = html;
            pdfContent.style.display = 'block';
            openPdfPreviewModal();

            // Just show the HTML preview - PDF generation will happen when Download button is clicked
            hidePreviewStatus();
            if (downloadBtn) downloadBtn.disabled = false;
            isGeneratingPDF = false;
            console.log('PDF preview generated successfully');
        }

        function closePDFPreview() {
            const modal = document.getElementById('pdfPreviewModal');
            const scopeNote = document.getElementById('pdfPreviewScopeNote');
            modal.classList.remove('active');
            modal.setAttribute('aria-hidden', 'true');
            if (scopeNote) scopeNote.style.display = 'none';
            hidePreviewStatus();
            if (pdfPreviewLastFocus && typeof pdfPreviewLastFocus.focus === 'function') {
                pdfPreviewLastFocus.focus();
            }
            pdfPreviewLastFocus = null;
        }

        function downloadPDFFile(event) {
            if (event) {
                event.stopPropagation();
                event.preventDefault();
            }
            if (!evaluationData.has_data) {
                alert('No data to export for the selected filters.');
                return;
            }
            if (typeof html2canvas !== 'function' || !window.jspdf) {
                alert('PDF libraries failed to load. Please refresh the page and try again.');
                return;
            }

            const downloadButton = document.getElementById('pdfModalDownloadBtn');
            const originalText = downloadButton.innerHTML;

            downloadButton.disabled = true;
            downloadButton.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Generating PDF...';

            isGeneratingPDF = true;

            const saveBlob = (blob) => {
                const link = document.createElement('a');
                const blobUrl = URL.createObjectURL(blob);
                link.href = blobUrl;
                link.download = getPdfFilename();
                document.body.appendChild(link);
                link.click();
                link.remove();
                URL.revokeObjectURL(blobUrl);
            };

            const handleDone = () => {
                downloadButton.disabled = false;
                downloadButton.innerHTML = originalText;
                isGeneratingPDF = false;
            };

            // Render PDF from the current preview content
            const pdfContent = document.getElementById('pdfContent');
            if (!pdfContent) {
                alert('Preview content not found.');
                handleDone();
                return;
            }

            const { jsPDF } = window.jspdf;
            html2canvas(pdfContent, {
                scale: 2,
                useCORS: true,
                logging: false,
                backgroundColor: '#ffffff',
                allowTaint: true
            }).then(canvas => {
                const imgData = canvas.toDataURL('image/png', 1.0);
                const pdf = new jsPDF({
                    orientation: 'portrait',
                    unit: 'mm',
                    format: 'a4'
                });
                const imgWidth = 210;
                const pageHeight = 297;
                const imgHeight = (canvas.height * imgWidth) / canvas.width;
                let heightLeft = imgHeight;
                let position = 0;

                pdf.addImage(imgData, 'PNG', 0, position, imgWidth, imgHeight);
                heightLeft -= pageHeight;

                while (heightLeft > 0) {
                    position = heightLeft - imgHeight;
                    pdf.addPage();
                    pdf.addImage(imgData, 'PNG', 0, position, imgWidth, imgHeight);
                    heightLeft -= pageHeight;
                }

                const pdfBlob = pdf.output('blob');
                saveBlob(pdfBlob);
                handleDone();
            }).catch(error => {
                console.error('Error generating PDF:', error);
                alert('An error occurred while generating the PDF. Please try again.');
                handleDone();
            });
        }

        // Function to attach modal background click handler
        function attachModalClickHandler() {
            const modal = document.getElementById('pdfPreviewModal');
            modal.addEventListener('click', function modalClickHandler(e) {
                if (e.target === modal && !isGeneratingPDF) {
                    closePDFPreview();
                }
            });
            document.addEventListener('keydown', function pdfPreviewEscape(e) {
                if (e.key !== 'Escape') return;
                if (!modal.classList.contains('active') || isGeneratingPDF) return;
                closePDFPreview();
            });
        }

        // Initialize modal click handler
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', attachModalClickHandler);
        } else {
            attachModalClickHandler();
        }

        /**
         * Teacher filter now submits directly without cascading logic.
         * All JavaScript filter handling has been simplified.
         */

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() {
                // No additional filter initialization needed
            });
        }
    </script>
</body>
</html>
