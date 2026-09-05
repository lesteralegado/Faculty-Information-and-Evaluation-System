<?php
/**
 * Advanced Analytics Module
 * Provides filtering, comparison, and what-if analysis capabilities
 */

session_start();

// Check if user is logged in
if (!isset($_SESSION['username'])) {
    header("Location: /capstone/index.php");  
    exit();
}

// Canonical default filters for this page:
// always default to All School Years + All Semesters when either is missing.
if (!isset($_GET['year']) || !isset($_GET['semester'])) {
    $query = $_GET;
    $query['year'] = $query['year'] ?? 'all';
    $query['semester'] = $query['semester'] ?? 'all';
    header("Location: analytics_advanced.php?" . http_build_query($query));
    exit();
}

require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/analytics_filters.php';

// Get user information
$firstName = $_SESSION['first_name'] ?? $_SESSION['username'] ?? 'User';
$lastName = $_SESSION['last_name'] ?? '';
$fullName = trim($firstName . ' ' . $lastName);

// Shared filter metadata (available years/semesters)
$filterState = analytics_get_filter_state($conn);
$available_years = $filterState['available_years'] ?? [];
$selected_year = strtolower((string)($_GET['year'] ?? 'all')) === 'all'
    ? 'all'
    : analytics_normalize_school_year($_GET['year'] ?? 'all');
$selected_semester = analytics_normalize_semester($_GET['semester'] ?? 'all');
$selected_semester = ($selected_semester === 'all') ? 'all' : (int)$selected_semester;
$selected_subject = $_GET['subject'] ?? 'all';
$selected_teacher = $_GET['teacher'] ?? 'all';

// Build WHERE conditions
$erCond = analytics_build_er_condition($conn, $selected_year, $selected_semester, 'e');
$sy_cond = $erCond['sql'];
$school_year_label = $erCond['label_year'];
$semester_label = $erCond['label_semester'];

// Get available subjects (filter by selected year and teacher if applicable)
$subject_sql = "SELECT DISTINCT s.subject_id, s.subject_name FROM subjects s";

// Build WHERE clause for subjects
$subject_where = [];
if ($selected_year !== 'all') {
    $subject_where[] = "REPLACE(REPLACE(e.school_year, '–', '-'), '—', '-') = '" . $conn->real_escape_string($selected_year) . "'";
}
if ($selected_teacher !== 'all') {
    $subject_where[] = "e.teacher_id = " . (int)$selected_teacher;
}

if (!empty($subject_where)) {
    $subject_sql .= " INNER JOIN evaluations e ON s.subject_id = e.subject_id WHERE " . implode(' AND ', $subject_where);
}

$subject_sql .= " ORDER BY s.subject_name";

$subject_result = $conn->query($subject_sql);
$available_subjects = [];
if ($subject_result) {
    while ($row = $subject_result->fetch_assoc()) {
        $available_subjects[] = ['id' => $row['subject_id'], 'name' => $row['subject_name']];
    }
}

// Get available teachers (filter by selected year and subject if applicable)
$teacher_sql = "SELECT DISTINCT t.teacher_id, CONCAT(u.first_name, ' ', u.last_name) as teacher_name 
                FROM teachers t 
                JOIN users u ON t.user_id = u.user_id";

// Build WHERE clause for teachers
$teacher_where = [];
if ($selected_year !== 'all' || $selected_subject !== 'all') {
    $teacher_sql .= " INNER JOIN evaluations e ON t.teacher_id = e.teacher_id";
    if ($selected_year !== 'all') {
        $teacher_where[] = "REPLACE(REPLACE(e.school_year, '–', '-'), '—', '-') = '" . $conn->real_escape_string($selected_year) . "'";
    }
    if ($selected_subject !== 'all') {
        $teacher_where[] = "e.subject_id = " . (int)$selected_subject;
    }
}

if (!empty($teacher_where)) {
    $teacher_sql .= " WHERE " . implode(' AND ', $teacher_where);
}

$teacher_sql .= " ORDER BY u.first_name, u.last_name";

$teacher_result = $conn->query($teacher_sql);
$available_teachers = [];
if ($teacher_result) {
    while ($row = $teacher_result->fetch_assoc()) {
        $available_teachers[] = ['id' => $row['teacher_id'], 'name' => $row['teacher_name']];
    }
}

// Apply subject filter
$subject_cond = "";
if ($selected_subject !== 'all') {
    $subject_cond = " AND s.subject_id = " . (int)$selected_subject;
}

// Apply teacher filter
$teacher_cond = "";
if ($selected_teacher !== 'all') {
    $teacher_cond = " AND t.teacher_id = " . (int)$selected_teacher;
}

// ============================================
// COMPARISON VIEW: Year-over-Year
// ============================================
$comparison_sql = "
    SELECT 
        REPLACE(REPLACE(e.school_year, '–', '-'), '—', '-') as year,
        e.semester,
        AVG(er.response) as avg_rating,
        COUNT(DISTINCT CONCAT(e.student_id, '-', e.subject_id)) as total_evals,
        COUNT(DISTINCT e.student_id) as unique_students
    FROM evaluation_responses er
    JOIN evaluations e ON er.evaluation_id = e.evaluation_id
    WHERE ";

if ($selected_year !== 'all') {
    $syEsc = $conn->real_escape_string($selected_year);
    $comparison_sql .= "REPLACE(REPLACE(e.school_year, '–', '-'), '—', '-') = '$syEsc'";
} else {
    $comparison_sql .= "1=1";
}

if ($selected_semester !== 'all') {
    $semInt = (int)$selected_semester;
    $comparison_sql .= " AND e.semester = $semInt";
}

// Apply subject filter to comparison
if ($selected_subject !== 'all') {
    $subj_id = (int)$selected_subject;
    $comparison_sql .= " AND e.subject_id = $subj_id";
}

// Apply teacher filter to comparison
if ($selected_teacher !== 'all') {
    $teach_id = (int)$selected_teacher;
    $comparison_sql .= " AND e.teacher_id = $teach_id";
}

$comparison_sql .= " GROUP BY REPLACE(REPLACE(e.school_year, '–', '-'), '—', '-'), e.semester ORDER BY year DESC, e.semester DESC";

// Only apply LIMIT when filters are more specific (not showing all data)
if ($selected_year !== 'all' || $selected_semester !== 'all' || $selected_subject !== 'all' || $selected_teacher !== 'all') {
    $comparison_sql .= " LIMIT 10";
}

$comparison_result = $conn->query($comparison_sql);
$comparison_data = [];
while ($row = $comparison_result->fetch_assoc()) {
    $sem_label = ($row['semester'] == 1) ? '1st Sem' : '2nd Sem';
    $comparison_data[] = [
        'period' => $row['year'] . ' - ' . $sem_label,
        'rating' => $row['avg_rating'],
        'evaluations' => $row['total_evals'],
        'students' => $row['unique_students']
    ];
}

// Determine labels based on filter state
$is_all_years = ($selected_year === 'all');
$is_all_semesters = ($selected_semester === 'all');
$is_all_subjects = ($selected_subject === 'all');
$is_all_teachers = ($selected_teacher === 'all');

if ($is_all_years && $is_all_semesters && $is_all_subjects && $is_all_teachers) {
    $comparison_section_title = "Overall Performance Trends";
    $comparison_card_title = "All Years & Semesters Summary";
} elseif (!$is_all_subjects && !$is_all_teachers) {
    $subject_name = array_values(array_filter($available_subjects, fn($s) => $s['id'] == $selected_subject))[0]['name'] ?? 'Unknown';
    $teacher_name = array_values(array_filter($available_teachers, fn($t) => $t['id'] == $selected_teacher))[0]['name'] ?? 'Unknown';
    $comparison_section_title = "Subject & Teacher Comparison";
    $comparison_card_title = "$teacher_name - $subject_name";
} elseif (!$is_all_subjects) {
    $subject_name = array_values(array_filter($available_subjects, fn($s) => $s['id'] == $selected_subject))[0]['name'] ?? 'Unknown';
    $comparison_section_title = "Subject Performance Analysis";
    $comparison_card_title = "Performance Trends - $subject_name";
} elseif (!$is_all_teachers) {
    $teacher_name = array_values(array_filter($available_teachers, fn($t) => $t['id'] == $selected_teacher))[0]['name'] ?? 'Unknown';
    $comparison_section_title = "Teacher Performance Analysis";
    $comparison_card_title = "Performance Trends - $teacher_name";
} else {
    $comparison_section_title = "Performance Trends";
    $comparison_card_title = "Comparison";
}

// ============================================
// FILTERED TEACHER PERFORMANCE
// ============================================
$filtered_teacher_sql = "
    SELECT 
        CONCAT(u.first_name, ' ', u.last_name) as teacher_name,
        s.subject_name,
        t.teacher_id,
        s.subject_id,
        AVG(er.response) as avg_rating,
        COUNT(DISTINCT CONCAT(e.student_id, '-', e.subject_id)) as eval_count,
        MIN(er.response) as min_rating,
        MAX(er.response) as max_rating
    FROM evaluation_responses er
    JOIN evaluations e ON er.evaluation_id = e.evaluation_id
    JOIN teachers t ON e.teacher_id = t.teacher_id
    JOIN users u ON t.user_id = u.user_id
    JOIN subjects s ON e.subject_id = s.subject_id
    WHERE $sy_cond $subject_cond $teacher_cond
    GROUP BY t.teacher_id, s.subject_id
    ORDER BY avg_rating DESC";

$filtered_teacher_result = $conn->query($filtered_teacher_sql);
$filtered_teachers = [];
while ($row = $filtered_teacher_result->fetch_assoc()) {
    $filtered_teachers[] = $row;
}

// ============================================
// WHAT-IF ANALYSIS: Score Improvement Simulation
// ============================================
function calculate_improvement_impact($current_avg, $improvement_percentage) {
    $improvement = ($current_avg * $improvement_percentage) / 100;
    $new_avg = min(5.0, $current_avg + $improvement);
    return [
        'current' => round($current_avg, 2),
        'improvement' => round($improvement, 2),
        'projected' => round($new_avg, 2),
        'impact_percentage' => round((($new_avg - $current_avg) / $current_avg) * 100, 1)
    ];
}

// Get current subject/teacher averages for what-if analysis
$whatif_sql = "
    SELECT 
        CONCAT(u.first_name, ' ', u.last_name) as teacher_name,
        s.subject_name,
        AVG(er.response) as avg_score
    FROM evaluation_responses er
    JOIN evaluations e ON er.evaluation_id = e.evaluation_id
    JOIN teachers t ON e.teacher_id = t.teacher_id
    JOIN users u ON t.user_id = u.user_id
    JOIN subjects s ON e.subject_id = s.subject_id
    WHERE $sy_cond $subject_cond $teacher_cond
    GROUP BY t.teacher_id, s.subject_id
    ORDER BY avg_score ASC
    LIMIT 5";

$whatif_result = $conn->query($whatif_sql);
$whatif_data = [];
while ($row = $whatif_result->fetch_assoc()) {
    $whatif_data[] = [
        'category' => htmlspecialchars($row['teacher_name'] . ' - ' . $row['subject_name']),
        'current' => $row['avg_score'],
        'improvement_5' => calculate_improvement_impact($row['avg_score'], 5),
        'improvement_10' => calculate_improvement_impact($row['avg_score'], 10),
        'improvement_15' => calculate_improvement_impact($row['avg_score'], 15)
    ];
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Advanced Analytics - Filtering & Comparison</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="icon" href="/capstone/images/school-logo.png" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style>
        :root {
            --primary-color: #800000;
            --primary-hover: #a00000;
            --primary-light: rgba(128, 0, 0, 0.1);
            --text-dark: #2c3e50;
            --border-color: #dee2e6;
            --bg-light: #f8f9fa;
            --sidebar-width: 250px;
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.08);
            --shadow-md: 0 4px 16px rgba(0,0,0,0.12);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            font-weight: 500;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            color: var(--text-dark);
        }
        
        .navbar {
            background: white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            padding: 1rem 0;
        }
        
        .navbar-brand {
            color: var(--primary-color) !important;
            font-weight: 700;
            font-size: 1.3rem;
        }
        
        .main-content {
            margin-left: 250px;
            margin-top: 60px;
            padding: 30px;
            min-height: calc(100vh - 60px);
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
        }
        
        .page-header {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-hover));
            color: white;
            padding: 40px;
            border-radius: 20px;
            margin-bottom: 40px;
            box-shadow: 0 15px 35px rgba(128, 0, 0, 0.2);
            position: relative;
            overflow: hidden;
        }
        
        .page-header h1 {
            font-weight: 700;
            font-size: 2.8rem;
            margin-bottom: 15px;
            position: relative;
            z-index: 2;
        }
        
        .filter-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-sm);
            border: 1px solid #e9ecef;
            transition: all 0.3s ease;
        }
        
        .filter-card:hover {
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
        }
        
        .filter-card h5 {
            display: none;
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
        
        .form-control, .form-select {
            border-radius: 8px;
            border: 2px solid #e9ecef;
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
            box-shadow: var(--shadow-md);
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
            box-shadow: var(--shadow-md);
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
        
        .card-title-custom {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--bg-light);
        }
        
        .card-title-custom h3 {
            margin: 0;
            color: var(--primary-color);
            font-weight: 600;
            font-size: 1.4rem;
        }
        
        .card-title-custom i {
            font-size: 1.5rem;
            color: var(--primary-color);
            background: rgba(128, 0, 0, 0.1);
            padding: 10px;
            border-radius: 10px;
        }
        
        .table-custom {
            margin-bottom: 0;
        }
        
        .table {
            margin-bottom: 0;
        }
        
        .table thead {
            background: #f8f9fa;
        }
        
        .table th, .table-custom thead th {
            border: none;
            font-weight: 600;
            color: var(--text-dark);
            padding: 15px 12px;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            background: #f8f9fa;
        }
        
        .table td, .table-custom tbody td {
            padding: 15px 12px;
            border-top: 1px solid #f0f0f0;
            vertical-align: middle;
            font-size: 0.9rem;
        }
        
        .table tbody tr:hover, .table-custom tbody tr:hover {
            background-color: #f8f9fa;
        }
        
        .comparison-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .comparison-item {
            background: white;
            padding: 25px;
            border-radius: 15px;
            border: 1px solid #e9ecef;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
        }
        
        .comparison-label {
            font-size: 0.85rem;
            color: #6c757d;
            text-transform: uppercase;
            font-weight: 600;
            margin-bottom: 8px;
        }
        
        .comparison-value {
            font-size: 1.8rem;
            color: var(--primary-color);
            font-weight: 700;
        }
        
        .whatif-box {
            background: linear-gradient(135deg, rgba(128, 0, 0, 0.05), rgba(128, 0, 0, 0.1));
            padding: 20px;
            border-radius: 8px;
            border-left: 4px solid var(--primary-color);
            margin-bottom: 20px;
        }
        
        .whatif-title {
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 15px;
            font-size: 1.1rem;
        }
        
        .whatif-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .whatif-metric {
            background: white;
            padding: 12px;
            border-radius: 6px;
            text-align: center;
        }
        
        .whatif-metric-label {
            font-size: 0.8rem;
            color: #6c757d;
            font-weight: 500;
            margin-bottom: 5px;
        }
        
        .whatif-metric-value {
            font-size: 1.4rem;
            font-weight: 700;
            color: var(--primary-color);
        }
        
        .badge-custom {
            padding: 6px 12px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.85rem;
        }
        
        .badge-success {
            background: #d4edda;
            color: #155724;
        }
        
        .badge-warning {
            background: #fff3cd;
            color: #856404;
        }
        
        .badge-danger {
            background: #f8d7da;
            color: #721c24;
        }
        
        .section-title {
            font-size: 1.6rem;
            font-weight: 700;
            color: var(--primary-color);
            margin: 40px 0 25px 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .section-title i {
            font-size: 1.4rem;
        }
        
        .chart-container {
            position: relative;
            height: 350px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .chart-container canvas {
            max-width: 100%;
            max-height: 100%;
        }
        
        @media (max-width: 768px) {
            .page-header {
                padding: 25px;
            }
            
            .page-header h1 {
                font-size: 1.6rem;
            }
            
            .chart-container {
                height: 250px;
            }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/includes/side_bar.php'; ?>

    <?php include __DIR__ . '/includes/navbar.php'; ?>
    
    <div class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <h1><i class="fas fa-filter me-3"></i>Advanced Analytics & Comparison</h1>
            <p>Filter, compare, and analyze evaluation data</p>
        </div>

        <!-- Filter Section -->
        <div class="filter-card">
            <form class="row g-3 align-items-end" method="get" action="" id="filterForm">
                <div class="col-12 col-md-2">
                    <label class="form-label fw-semibold mb-1">School Year</label>
                    <select class="form-select" name="year" id="yearFilter">
                        <option value="all" <?= $selected_year === 'all' ? 'selected' : '' ?>>All School Years</option>
                        <?php foreach ($available_years as $year): ?>
                            <option value="<?= htmlspecialchars($year) ?>" <?= $selected_year === $year ? 'selected' : '' ?>>
                                <?= htmlspecialchars($year) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 col-md-2">
                    <label class="form-label fw-semibold mb-1">Semester</label>
                    <select class="form-select" name="semester" id="semesterFilter">
                        <option value="all" <?= $selected_semester === 'all' ? 'selected' : '' ?>>All Semesters</option>
                        <?php foreach (($filterState['available_semesters'] ?? []) as $sem): ?>
                            <option value="<?= $sem ?>" <?= $selected_semester === $sem ? 'selected' : '' ?>>
                                <?= ($sem === 1) ? '1st Semester' : '2nd Semester' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 col-md-2">
                    <label class="form-label fw-semibold mb-1">Subject</label>
                    <select class="form-select" name="subject" id="subjectFilter">
                        <option value="all">All Subjects</option>
                        <?php foreach ($available_subjects as $subject): ?>
                            <option value="<?= htmlspecialchars($subject['id']) ?>" <?= $selected_subject == $subject['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($subject['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 col-md-2">
                    <label class="form-label fw-semibold mb-1">Teacher</label>
                    <select class="form-select" name="teacher" id="teacherFilter">
                        <option value="all">All Teachers</option>
                        <?php foreach ($available_teachers as $teacher): ?>
                            <option value="<?= htmlspecialchars($teacher['id']) ?>" <?= $selected_teacher == $teacher['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($teacher['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 col-md-4 d-flex gap-2 justify-content-md-end">
                    <a class="btn btn-outline-secondary" href="analytics_advanced.php?year=all&semester=all">
                        <i class="fas fa-rotate-left me-1"></i>Reset
                    </a>
                    <a class="btn btn-primary-custom" href="data_analytics.php?year=<?= htmlspecialchars($selected_year) ?>&semester=<?= htmlspecialchars($selected_semester) ?>">
                        <i class="fas fa-chart-line me-1"></i>Dashboard
                    </a>
                    <a class="btn btn-outline-danger" href="analytics_export.php?year=<?= htmlspecialchars($selected_year) ?>&semester=<?= htmlspecialchars($selected_semester) ?>">
                        <i class="fas fa-download me-1"></i>Export
                    </a>
                </div>
            </form>
        </div>

        <!-- YEAR-OVER-YEAR COMPARISON -->
        <div class="section-title">
            <i class="fas fa-chart-line"></i><?= $comparison_section_title ?>
        </div>

        <?php 
        // When showing all years and semesters, display aggregated summary
        if ($is_all_years && $is_all_semesters && !empty($comparison_data)): 
            $total_evaluations = 0;
            $total_students = 0;
            $total_rating_weighted = 0;
            foreach ($comparison_data as $comp) {
                $total_evaluations += $comp['evaluations'];
                $total_rating_weighted += $comp['rating'] * $comp['evaluations'];
            }
            
            // Get actual distinct students (avoid double-counting across years/semesters)
            $unique_students_sql = "
                SELECT COUNT(DISTINCT e.student_id) as unique_count
                FROM evaluation_responses er
                JOIN evaluations e ON er.evaluation_id = e.evaluation_id
                JOIN subjects s ON e.subject_id = s.subject_id
                JOIN teachers t ON e.teacher_id = t.teacher_id
                WHERE 1=1 $subject_cond $teacher_cond
            ";
            $unique_result = $conn->query($unique_students_sql);
            $unique_row = $unique_result->fetch_assoc();
            $total_students = (int)$unique_row['unique_count'];
            
            $overall_avg = $total_evaluations > 0 ? $total_rating_weighted / $total_evaluations : 0;
        ?>
        <div class="dashboard-card" style="background: linear-gradient(135deg, rgba(128, 0, 0, 0.05), rgba(128, 0, 0, 0.1)); border: 2px solid rgba(128, 0, 0, 0.2);">
            <div class="card-title-custom">
                <i class="fas fa-database"></i>
                <h3>Complete Historical Summary</h3>
            </div>
            <div class="comparison-row">
                <div class="comparison-item" style="background: white;">
                    <div class="comparison-label">Overall Average Rating</div>
                    <div class="comparison-value"><?= round($overall_avg, 2) ?>/5.0</div>
                    <small style="color: #6c757d;">Across all periods</small>
                </div>
                <div class="comparison-item" style="background: white;">
                    <div class="comparison-label">Total Evaluations</div>
                    <div class="comparison-value"><?= number_format($total_evaluations) ?></div>
                    <small style="color: #6c757d;">Responses received</small>
                </div>
                <div class="comparison-item" style="background: white;">
                    <div class="comparison-label">Unique Students</div>
                    <div class="comparison-value"><?= number_format($total_students) ?></div>
                    <small style="color: #6c757d;">Participated in evals</small>
                </div>
                <div class="comparison-item" style="background: white;">
                    <div class="comparison-label">Data Points</div>
                    <div class="comparison-value"><?= count($comparison_data) ?></div>
                    <small style="color: #6c757d;">Year/Semester combinations</small>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="dashboard-card">
            <div class="card-title-custom">
                <i class="fas fa-trending-up"></i>
                <h3><?= $comparison_card_title ?></h3>
            </div>
            <div class="comparison-row">
                <?php if (!empty($comparison_data)): ?>
                    <?php 
                    // When viewing all years + all semesters, show all data. Otherwise limit to 6 for compact view.
                    $display_limit = ($is_all_years && $is_all_semesters) ? count($comparison_data) : 6;
                    $displayed_data = array_slice($comparison_data, 0, $display_limit);
                    foreach ($displayed_data as $comp): 
                    ?>
                        <div class="comparison-item">
                            <div class="comparison-label"><?= htmlspecialchars($comp['period']) ?></div>
                            <div class="comparison-value"><?= round($comp['rating'], 2) ?></div>
                            <small style="color: #6c757d;">Avg Rating • <?= $comp['evaluations'] ?> evals</small>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="alert alert-info" role="alert">
                        <i class="fas fa-info-circle me-2"></i>No data available for the selected filters.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- COMPARISON CHART -->
        <div class="dashboard-card">
            <div class="card-title-custom">
                <i class="fas fa-chart-bar"></i>
                <h3>Performance Comparison Chart</h3>
            </div>
            <?php if (!empty($comparison_data)): ?>
                <div class="chart-container" style="height: 400px; position: relative;">
                    <canvas id="comparisonChart" style="max-height: 100%;"></canvas>
                </div>
                <small style="color: #6c757d; display: block; margin-top: 10px;">
                    <i class="fas fa-info-circle me-1"></i>
                    Displaying <?= count($comparison_data) ?> data point(s) - ordered by most recent first
                </small>
            <?php else: ?>
                <div class="alert alert-info" role="alert">
                    <i class="fas fa-info-circle me-2"></i>No comparison data available for the selected criteria.
                </div>
            <?php endif; ?>
        </div>
        

        

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Analytics Filters: Bi-directional Dynamic Filtering -->
    <script src="/capstone/js/analytics-filters.js"></script>
    
    <script>
        // Comparison Chart
        document.addEventListener('DOMContentLoaded', function() {
            <?php if (!empty($comparison_data)): ?>
            const comparisonCtx = document.getElementById('comparisonChart');
            if (comparisonCtx) {
                const chartData = {
                    labels: [<?php echo implode(",", array_map(fn($c) => "'" . htmlspecialchars($c['period']) . "'", $comparison_data)); ?>],
                    datasets: [{
                        label: 'Average Rating',
                        data: [<?php echo implode(",", array_map(fn($c) => round($c['rating'], 2), $comparison_data)); ?>],
                        borderColor: '#800000',
                        backgroundColor: 'rgba(128, 0, 0, 0.1)',
                        tension: 0.4,
                        fill: true,
                        pointRadius: 6,
                        pointBackgroundColor: '#800000',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        borderWidth: 3,
                        pointHoverRadius: 8,
                        pointHoverBackgroundColor: '#a00000'
                    }]
                };

                new Chart(comparisonCtx, {
                    type: 'line',
                    data: chartData,
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: {
                            mode: 'index',
                            intersect: false,
                        },
                        plugins: {
                            legend: { 
                                labels: { 
                                    font: { size: 12, family: "'Poppins', sans-serif" },
                                    padding: 15,
                                    color: '#2c3e50'
                                },
                                position: 'top'
                            },
                            tooltip: {
                                backgroundColor: 'rgba(0, 0, 0, 0.8)',
                                padding: 12,
                                titleFont: { size: 14 },
                                bodyFont: { size: 12 },
                                callbacks: {
                                    label: function(context) {
                                        return 'Rating: ' + context.parsed.y.toFixed(2) + '/5.0';
                                    }
                                }
                            }
                        },
                        scales: {
                            y: { 
                                beginAtZero: true, 
                                max: 5,
                                ticks: { 
                                    stepSize: 0.5,
                                    font: { size: 11 }
                                },
                                grid: { 
                                    color: 'rgba(0, 0, 0, 0.05)',
                                    drawBorder: true
                                },
                                title: {
                                    display: true,
                                    text: 'Average Rating',
                                    font: { size: 12, weight: 'bold' }
                                }
                            },
                            x: {
                                grid: { display: false },
                                ticks: { 
                                    font: { size: 11 }
                                }
                            }
                        }
                    }
                });
            }
            <?php endif; ?>
        });
    </script>
</body>
</html>
