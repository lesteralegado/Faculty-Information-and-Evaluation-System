<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['username'])) {
    header("Location: /capstone/index.php");  
    exit();
}

// Check if user has access to data analytics (allow teacher and admin only)
$userRole = $_SESSION['role'] ?? '';
if ($userRole === 'registrar') {
    header("Location: /capstone/registrar/registrar_dashboard.php?error=access_denied");
    exit();
}

// Include database connection
require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/analytics_filters.php';

// Get current school year and semester from filter state
$filterState = analytics_get_filter_state($conn);

// Default filter should follow the same source used by evaluation targets:
// currentschoolyearandsemester table (via shared helper).
$currentCtx = analytics_get_current_sy_sem($conn);
$current_school_year = $currentCtx['school_year'] ?? '';
$current_semester = $currentCtx['semester'] ?? null;

// Fallbacks if current term is missing/unset
if ($current_school_year === '') {
    $current_school_year = $filterState['available_years'][0] ?? 'all';
}
if ($current_semester === null) {
    $current_semester = $filterState['available_semesters'][0] ?? 'all';
}

// If filters are not properly set in GET parameters, redirect with current year and semester
$should_redirect = false;
$redirect_year = $_GET['year'] ?? null;
$redirect_semester = $_GET['semester'] ?? null;

// Check if year is missing and set a default
if (!$redirect_year) {
    $redirect_year = $current_school_year;
    $should_redirect = true;
}

// Check if semester is missing and set a default (default to current semester, not "all")
if (!$redirect_semester) {
    $redirect_semester = $current_semester;
    $should_redirect = true;
}

// Redirect if we needed to set defaults
if ($should_redirect) {
    header("Location: data_analytics.php?year=" . urlencode($redirect_year) . "&semester=" . $redirect_semester);
    exit();
}

// Get user information
$firstName = $_SESSION['first_name'] ?? $_SESSION['username'] ?? 'User';
$lastName = $_SESSION['last_name'] ?? '';
$fullName = trim($firstName . ' ' . $lastName);
$userRole = $_SESSION['role'] ?? 'user';

$current_year = date('Y');
$current_month = date('m');

// Shared filters (synced across analytics pages)
$selected_year = $_GET['year'] ?? $current_school_year;
$selected_semester = $_GET['semester'] ?? $current_semester;

$erCond = analytics_build_er_condition($conn, $selected_year, $selected_semester, 'e');
$sy_cond = $erCond['sql'];
$school_year_label = $erCond['label_year'];
$semester_label = $erCond['label_semester'];

// ============================================
// HELPER FUNCTIONS
// ============================================

// Sentiment Analysis - PHP-based keyword classification
function analyzeSentiment($text) {
    $text = strtolower($text);
    
    $positive_keywords = ['excellent', 'great', 'good', 'outstanding', 'impressive', 'wonderful', 'fantastic', 'superb', 'amazing', 'dedicated', 'passionate', 'helpful', 'professional', 'effective', 'engaging', 'knowledgeable'];
    $negative_keywords = ['poor', 'bad', 'terrible', 'awful', 'disappointing', 'ineffective', 'unprofessional', 'rude', 'careless', 'negligent', 'disorganized', 'inefficient', 'biased'];
    
    $positive_count = 0;
    $negative_count = 0;
    
    foreach ($positive_keywords as $keyword) {
        if (strpos($text, $keyword) !== false) $positive_count++;
    }
    
    foreach ($negative_keywords as $keyword) {
        if (strpos($text, $keyword) !== false) $negative_count++;
    }
    
    if ($positive_count > $negative_count) return 'positive';
    if ($negative_count > $positive_count) return 'negative';
    return 'neutral';
}

// Anomaly Detection - Flag unusual rating patterns
function detectAnomalies($ratings) {
    if (count($ratings) < 3) return [];
    
    $mean = array_sum($ratings) / count($ratings);
    $variance = 0;
    
    foreach ($ratings as $value) {
        $variance += pow($value - $mean, 2);
    }
    $variance = $variance / count($ratings);
    $std_dev = sqrt($variance);
    
    $anomalies = [];
    foreach ($ratings as $index => $value) {
        $z_score = abs(($value - $mean) / $std_dev);
        if ($z_score > 2) {
            $anomalies[] = [
                'index' => $index,
                'value' => $value,
                'z_score' => $z_score
            ];
        }
    }
    
    return $anomalies;
}

// Performance Forecast - Simple growth projection
function calculate_forecast($data) {
    if (count($data) < 2) return null;
    
    $n = count($data);
    $values = array_map(fn($d) => floatval($d['daily_avg']), $data);
    
    $sum_x = $n * ($n - 1) / 2;
    $sum_y = array_sum($values);
    $sum_xy = 0;
    $sum_x2 = 0;
    
    foreach ($values as $i => $y) {
        $sum_xy += $i * $y;
        $sum_x2 += $i * $i;
    }
    
    $slope = ($n * $sum_xy - $sum_x * $sum_y) / ($n * $sum_x2 - $sum_x * $sum_x);
    $intercept = ($sum_y - $slope * $sum_x) / $n;
    
    $trend = $slope > 0.01 ? 'improving' : ($slope < -0.01 ? 'declining' : 'stable');
    $forecast_30_days = min(5, max(1, $intercept + $slope * ($n + 30)));
    
    return [
        'trend' => $trend,
        'slope' => round($slope, 4),
        'current_avg' => round($values[$n-1], 2),
        'forecast_30_days' => round($forecast_30_days, 2)
    ];
}

// ============================================
// 1. QUESTION-LEVEL ANALYSIS
// ============================================
$question_analysis_sql = "
    SELECT 
        er.question_id,
        CONCAT('Q', er.question_id) as label,
        COALESCE(eq.question, CONCAT('Question #', er.question_id)) as question_text,
        AVG(er.response) as avg_score,
        STDDEV(er.response) as std_dev,
        MIN(er.response) as min_score,
        MAX(er.response) as max_score,
        COUNT(CASE WHEN er.response >= 4 THEN 1 END) as satisfied_count,
        COUNT(CASE WHEN er.response BETWEEN 2 AND 3 THEN 1 END) as neutral_count,
        COUNT(CASE WHEN er.response < 2 THEN 1 END) as dissatisfied_count
    FROM evaluation_responses er
    JOIN evaluations e ON er.evaluation_id = e.evaluation_id
    LEFT JOIN evaluation_questions eq ON er.question_id = eq.question_id
    WHERE $sy_cond
    GROUP BY er.question_id, eq.question
    ORDER BY er.question_id ASC
    LIMIT 25";

$question_analysis = [];
$question_analysis_result = $conn->query($question_analysis_sql);
if ($question_analysis_result) {
    while ($row = $question_analysis_result->fetch_assoc()) {
        $question_analysis[] = $row;
    }
}

// ============================================
// 2. EARLY WARNING SYSTEM
// ============================================
$early_warning_sql = "
    SELECT 
        CONCAT(u.first_name, ' ', u.last_name) as teacher_name,
        s.subject_name,
        e.teacher_id,
        AVG(er.response) as avg_rating,
        COUNT(DISTINCT e.evaluation_id) as evaluation_count,
        STDDEV(er.response) as rating_std_dev
    FROM evaluation_responses er
    JOIN evaluations e ON er.evaluation_id = e.evaluation_id
    JOIN teachers t ON e.teacher_id = t.teacher_id
    JOIN users u ON t.user_id = u.user_id
    JOIN subjects s ON e.subject_id = s.subject_id
    WHERE $sy_cond
    GROUP BY e.teacher_id, s.subject_id
    HAVING COUNT(DISTINCT e.evaluation_id) >= 3 AND AVG(er.response) < 3.0
    ORDER BY avg_rating ASC";

$early_warning_result = $conn->query($early_warning_sql);
$early_warning_teachers = [];
while ($row = $early_warning_result->fetch_assoc()) {
    $early_warning_teachers[] = $row;
}

// ============================================
// 3. DEMOGRAPHIC BREAKDOWN (STRAND-WISE)
// ============================================
$demographic_sql = "
    SELECT 
        s.strand,
        COUNT(DISTINCT e.student_id) as unique_students,
        AVG(er.response) as avg_rating,
        STDDEV(er.response) as rating_std_dev,
        COUNT(*) as total_evaluations,
        COUNT(CASE WHEN er.response >= 4.5 THEN 1 END) as excellent_count,
        COUNT(CASE WHEN er.response >= 3.5 AND er.response < 4.5 THEN 1 END) as good_count,
        COUNT(CASE WHEN er.response >= 2.5 AND er.response < 3.5 THEN 1 END) as average_count,
        COUNT(CASE WHEN er.response < 2.5 THEN 1 END) as poor_count
    FROM evaluation_responses er
    JOIN evaluations e ON er.evaluation_id = e.evaluation_id
    JOIN subjects s ON e.subject_id = s.subject_id
    WHERE $sy_cond
    GROUP BY s.strand
    ORDER BY avg_rating DESC";

$demographic_result = $conn->query($demographic_sql);
$demographic_breakdown = [];
while ($row = $demographic_result->fetch_assoc()) {
    $demographic_breakdown[] = $row;
}

// ============================================
// 4. CATEGORY SCORES (DIAGNOSTIC ANALYTICS)
// ============================================
$category_scores_sql = "
    SELECT 
        DISTINCT COALESCE(eq.category_id, 0) as id,
        COALESCE(ec.category_name, CONCAT('Category #', COALESCE(eq.category_id, 0))) as category_name,
        AVG(er.response) as avg_score
    FROM evaluation_responses er
    LEFT JOIN evaluation_questions eq ON er.question_id = eq.question_id
    LEFT JOIN evaluation_categories ec ON eq.category_id = ec.category_id
    JOIN evaluations e ON er.evaluation_id = e.evaluation_id
    WHERE $sy_cond
    GROUP BY eq.category_id, ec.category_name
    ORDER BY avg_score DESC";

$category_scores_result = $conn->query($category_scores_sql);
$category_scores = [];
while ($row = $category_scores_result->fetch_assoc()) {
    $category_scores[] = $row;
}

// ============================================
// 5. DESCRIPTIVE ANALYTICS
// ============================================
// Count unique evaluations (student-teacher-subject combinations), not individual question responses
$total_evaluations_sql = "SELECT COUNT(DISTINCT e.evaluation_id) as total_evaluations FROM evaluation_responses er JOIN evaluations e ON er.evaluation_id = e.evaluation_id WHERE $sy_cond";
$total_evaluations_result = $conn->query($total_evaluations_sql);
$total_evaluations = $total_evaluations_result->fetch_assoc()['total_evaluations'];

// Student count is based on selected school year
$total_students_sql = "SELECT COUNT(DISTINCT s.student_id) as total_students FROM students s WHERE REPLACE(REPLACE(s.school_year, '–', '-'), '—', '-') = '" . $conn->real_escape_string($selected_year) . "'";

$total_students_result = $conn->query($total_students_sql);
$total_students = $total_students_result->fetch_assoc()['total_students'];

$total_teachers_sql = "SELECT COUNT(DISTINCT e.teacher_id) as total_teachers FROM evaluations e WHERE $sy_cond";
$total_teachers_result = $conn->query($total_teachers_sql);
$total_teachers = $total_teachers_result->fetch_assoc()['total_teachers'];

$unique_students_evaluated_sql = "SELECT COUNT(DISTINCT e.student_id) as students_evaluated FROM evaluation_responses er JOIN evaluations e ON er.evaluation_id = e.evaluation_id WHERE $sy_cond";
$unique_students_result = $conn->query($unique_students_evaluated_sql);
$students_evaluated = $unique_students_result->fetch_assoc()['students_evaluated'];

$participation_rate = $total_students > 0 ? round(($students_evaluated / $total_students) * 100, 1) : 0;
$avg_evaluations_per_student = $students_evaluated > 0 ? round($total_evaluations / $students_evaluated, 1) : 0;

// Average overall rating
$avg_rating_sql = "SELECT AVG(er.response) as avg_rating FROM evaluation_responses er JOIN evaluations e ON er.evaluation_id = e.evaluation_id WHERE $sy_cond";
$avg_rating_result = $conn->query($avg_rating_sql);
$avg_rating = round($avg_rating_result->fetch_assoc()['avg_rating'], 2);

// Rating distribution
$rating_distribution_sql = "
    SELECT 
        CASE 
            WHEN er.response >= 4.5 THEN 'Excellent (4.5-5.0)'
            WHEN er.response >= 3.5 THEN 'Good (3.5-4.4)'
            WHEN er.response >= 2.5 THEN 'Average (2.5-3.4)'
            WHEN er.response >= 1.5 THEN 'Below Average (1.5-2.4)'
            ELSE 'Poor (1.0-1.4)'
        END as rating_category,
        COUNT(*) as count
    FROM evaluation_responses er
    JOIN evaluations e ON er.evaluation_id = e.evaluation_id
    WHERE $sy_cond
    GROUP BY rating_category
    ORDER BY MIN(er.response) DESC";

$rating_distribution_result = $conn->query($rating_distribution_sql);
$rating_distribution = [];
while ($row = $rating_distribution_result->fetch_assoc()) {
    $rating_distribution[] = $row;
}

// ============================================
// 6. TEACHER PERFORMANCE WITH PERCENTILE RANK
// ============================================
$teacher_performance_sql = "
    SELECT 
        CONCAT(u.first_name, ' ', u.last_name) as teacher_name,
        s.subject_name,
        t.teacher_id,
        AVG(er.response) as avg_rating,
        COUNT(DISTINCT e.evaluation_id) as evaluation_count
    FROM evaluation_responses er
    JOIN evaluations e ON er.evaluation_id = e.evaluation_id
    JOIN teachers t ON e.teacher_id = t.teacher_id
    JOIN users u ON t.user_id = u.user_id
    JOIN subjects s ON e.subject_id = s.subject_id
    WHERE $sy_cond
    GROUP BY e.teacher_id, s.subject_id
    HAVING evaluation_count >= 2
    ORDER BY avg_rating DESC";

$teacher_performance_result = $conn->query($teacher_performance_sql);
$teacher_performance = [];
while ($row = $teacher_performance_result->fetch_assoc()) {
    $teacher_performance[] = $row;
}

$total_teachers_count = count($teacher_performance);
foreach ($teacher_performance as &$teacher) {
    $rank_position = array_search($teacher, $teacher_performance) + 1;
    $teacher['percentile_rank'] = round((($total_teachers_count - $rank_position + 1) / $total_teachers_count) * 100);
}
unset($teacher);

// ============================================
// 7. STRAND PERFORMANCE
// ============================================
$strand_performance_sql = "
    SELECT 
        s.strand,
        AVG(er.response) as avg_rating,
        COUNT(DISTINCT e.evaluation_id) as evaluation_count
    FROM evaluation_responses er
    JOIN evaluations e ON er.evaluation_id = e.evaluation_id
    JOIN subjects s ON e.subject_id = s.subject_id
    WHERE $sy_cond
    GROUP BY s.strand
    ORDER BY avg_rating DESC";

$strand_performance_result = $conn->query($strand_performance_sql);
$strand_performance = [];
while ($row = $strand_performance_result->fetch_assoc()) {
    $strand_performance[] = $row;
}

// ============================================
// 8. PD RECOMMENDATIONS (RULE-BASED)
// ============================================
function generate_pd_recommendations($category_scores) {
    $recommendations = [];
    
    foreach ($category_scores as $category) {
        $score = $category['avg_score'];
        $category_name = $category['category_name'];
        
        $pd_map = [
            'Teaching and Learning' => 'Conduct professional development workshops on innovative teaching methods and student engagement strategies.',
            'Teacher\'s Behavior and Respect' => 'Implement peer mentoring and communication skills training to foster better student-teacher relationships.',
            'Classroom and Rules' => 'Review and update classroom management policies; provide coaching on consistent rule enforcement.',
            'Student Feelings and Experience' => 'Conduct focus groups with students to identify pain points and improve overall learning experience.',
            'Classroom Management' => 'Enhance classroom management training with focus on positive behavioral interventions.',
            'Communication Skills' => 'Provide advanced communication workshops including active listening and feedback techniques.',
            'Professionalism and Attitude' => 'Conduct professional ethics and mindset workshops for continuous improvement.',
            'Use of Technology and Resource' => 'Offer training on integrating modern technology and educational resources effectively.',
            'Teaching Effectiveness' => 'Deliver workshops on evidence-based teaching strategies and instructional best practices.',
            'Student Engagement' => 'Provide training on creating interactive and engaging learning environments.',
            'Professional Behavior' => 'Conduct ethics and professional conduct seminars.',
            'Instructional Clarity and Teaching Effectiveness' => 'Workshops on clear instruction design and delivery techniques.'
        ];
        
        if ($score < 2.5) {
            $recommendations[] = [
                'category' => $category_name,
                'score' => $score,
                'level' => 'Critical',
                'recommendation' => $pd_map[$category_name] ?? 'Immediate professional development intervention required.'
            ];
        } elseif ($score < 3.0) {
            $recommendations[] = [
                'category' => $category_name,
                'score' => $score,
                'level' => 'High Priority',
                'recommendation' => $pd_map[$category_name] ?? 'Professional development recommended.'
            ];
        }
    }
    
    return $recommendations;
}

$pd_recommendations = generate_pd_recommendations($category_scores);

// ============================================
// 9. MULTI-YEAR TREND ANALYSIS (IGNORES FILTERS)
// ============================================
// IMPORTANT: Multi-year trends should show ALL years and semesters
// regardless of current filter selection. The selected filter is only
// for reference and highlighting purposes, NOT for limiting the dataset.
$multi_year_sql = "
    SELECT 
        DISTINCT REPLACE(REPLACE(e.school_year, '–', '-'), '—', '-') as school_year,
        e.semester,
        AVG(er.response) as avg_rating,
        COUNT(DISTINCT e.evaluation_id) as evaluation_count
    FROM evaluation_responses er
    JOIN evaluations e ON er.evaluation_id = e.evaluation_id
    WHERE e.school_year IS NOT NULL AND TRIM(e.school_year) <> ''
    GROUP BY REPLACE(REPLACE(e.school_year, '–', '-'), '—', '-'), e.semester
    ORDER BY e.school_year DESC, e.semester DESC
    LIMIT 20";

$multi_year_result = $conn->query($multi_year_sql);
$multi_year_data = [];
$current_period_key = null;

// Build key for currently selected period to mark it in chart
if ($selected_year !== 'all' || $selected_semester !== 'all') {
    $sem_for_key = $selected_semester === 'all' ? null : (int)$selected_semester;
    $current_period_key = $selected_year . '|' . ($sem_for_key ?? 'all');
}

while ($row = $multi_year_result->fetch_assoc()) {
    $row['period_key'] = $row['school_year'] . '|' . ($row['semester'] ?? 'all');
    $row['is_selected_period'] = ($current_period_key && $row['period_key'] === $current_period_key);
    $multi_year_data[] = $row;
}

// ============================================
// 10. RESPONSE RATE TRACKING
// ============================================
$response_rate_sql = "
    SELECT 
        CONCAT(u.first_name, ' ', u.last_name) as teacher_name,
        t.teacher_id,
        COUNT(DISTINCT e.evaluation_id) as total_responses,
        COUNT(DISTINCT e.student_id) as unique_respondents
    FROM evaluation_responses er
    JOIN evaluations e ON er.evaluation_id = e.evaluation_id
    JOIN teachers t ON e.teacher_id = t.teacher_id
    JOIN users u ON t.user_id = u.user_id
    WHERE $sy_cond
    GROUP BY e.teacher_id
    ORDER BY total_responses DESC
    LIMIT 20";

$response_rate_result = $conn->query($response_rate_sql);
$response_rate_data = [];
while ($row = $response_rate_result->fetch_assoc()) {
    $response_rate_data[] = $row;
}

// ============================================
// 11. BIAS DETECTION (Statistical approach)
// ============================================
function detect_bias($teacher_performance) {
    $biases = [];
    
    // Guard against empty or insufficient data
    if (empty($teacher_performance) || count($teacher_performance) < 2) {
        return $biases;
    }
    
    $ratings = array_map(fn($t) => floatval($t['avg_rating']), $teacher_performance);
    $mean = array_sum($ratings) / count($ratings);
    $variance = array_sum(array_map(fn($r) => pow($r - $mean, 2), $ratings)) / count($ratings);
    
    // Guard against zero variance (all ratings are the same)
    if ($variance <= 0) {
        return $biases;
    }
    
    $std_dev = sqrt($variance);
    
    foreach ($teacher_performance as $teacher) {
        $z_score = ($teacher['avg_rating'] - $mean) / $std_dev;
        
        if ($z_score < -1.96) {
            $biases[] = [
                'teacher' => $teacher['teacher_name'],
                'rating' => $teacher['avg_rating'],
                'alert' => 'Significantly lower than average - recommend review'
            ];
        } elseif ($z_score > 1.96) {
            $biases[] = [
                'teacher' => $teacher['teacher_name'],
                'rating' => $teacher['avg_rating'],
                'alert' => 'Significantly higher than average - verify data quality'
            ];
        }
    }
    
    return $biases;
}

$bias_detections = detect_bias($teacher_performance);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="/capstone/images/school-logo.png" type="image/png">
    <title>Data Analytics Dashboard</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- Chart.js Plugins -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/chartjs-plugin-datalabels/2.2.0/chartjs-plugin-datalabels.min.js"></script>
    
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
            --bg-light: #f8f9fa;
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
            color: var(--text-dark);
            min-height: 100vh;
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
        
        .page-header p {
            font-size: 1rem;
            font-weight: 500;
            opacity: 0.9;
            margin-bottom: 0;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }
        
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid #e9ecef;
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.12);
        }
        
        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 10px;
        }
        
        .stat-label {
            font-size: 1rem;
            color: #6c757d;
            font-weight: 600;
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
        
        .card-header-custom {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--bg-light);
        }
        
        .card-header-custom h3 {
            margin: 0;
            font-size: 1.4rem;
            font-weight: 600;
            color: var(--primary-color);
        }
        
        .card-header-custom i {
            font-size: 1.5rem;
            color: var(--primary-color);
            background: rgba(128, 0, 0, 0.1);
            padding: 10px;
            border-radius: 10px;
        }
        
        .chart-container {
            position: relative;
            height: 350px;
            margin-bottom: 20px;
            min-height: 300px;
        }
        
        .chart-container canvas {
            max-height: 100%;
        }
        
        .multi-year-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .stat-pill {
            background: var(--bg-light);
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid var(--primary-color);
            text-align: center;
        }
        
        .stat-pill-label {
            font-size: 0.8rem;
            color: #6c757d;
            font-weight: 600;
            text-transform: uppercase;
            margin-bottom: 8px;
        }
        
        .stat-pill-value {
            font-size: 1.6rem;
            font-weight: 700;
            color: var(--primary-color);
        }
        
        .stat-pill-subtext {
            font-size: 0.75rem;
            color: #95a5a6;
            margin-top: 5px;
        }
        
        .chart-no-data {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 300px;
            background: var(--bg-light);
            border-radius: 8px;
            color: #95a5a6;
            font-size: 1rem;
        }
        
        .table-container {
            overflow-x: auto;
        }
        
        .table-responsive {
            border-radius: 8px;
            overflow: hidden;
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
        
        .badge-success {
            background-color: var(--success-color) !important;
        }
        
        .badge-warning {
            background-color: var(--warning-color) !important;
            color: #000;
        }
        
        .badge-danger {
            background-color: var(--danger-color) !important;
        }
        
        .badge-info {
            background-color: var(--info-color) !important;
        }
        
        .alert-custom {
            border-left: 5px solid;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .alert-danger-custom {
            background: #fff5f5;
            border-color: var(--danger-color);
            color: #721c24;
        }
        
        .alert-warning-custom {
            background: #fffbf0;
            border-color: var(--warning-color);
            color: #856404;
        }
        
        .alert-info-custom {
            background: #e7f3ff;
            border-color: var(--info-color);
            color: #0c5460;
        }
        
        .metric-box {
            background: var(--bg-light);
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 15px;
            border-left: 4px solid var(--primary-color);
        }
        
        .metric-box strong {
            color: var(--primary-color);
            font-size: 1.1rem;
        }
        
        .filter-section {
            background: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-sm);
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
        
        .export-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .export-buttons .btn {
            flex: 0 1 auto;
        }
        
        .performance-bar {
            width: 100%;
            height: 24px;
            background: #e9ecef;
            border-radius: 12px;
            overflow: hidden;
            margin: 10px 0;
        }
        
        .performance-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary-color), var(--primary-hover));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .grid-2 {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
            margin-bottom: 30px;
        }
        
        .grid-3 {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .section-title {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--primary-color);
            margin: 40px 0 30px 0;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .section-title i {
            font-size: 1.5rem;
        }
        
        .footer-section {
            margin-top: 60px;
            padding-top: 40px;
            border-top: 2px solid var(--border-color);
            text-align: center;
            color: #6c757d;
        }
        
        @media (max-width: 768px) {
            .main-container {
                padding: 20px 10px;
                margin-left: 0;
            }
            
            .navbar {
                margin-left: 0;
            }
            
            .page-header {
                padding: 30px 20px;
            }
            
            .page-header h1 {
                font-size: 1.8rem;
            }
            
            .chart-container {
                height: 250px;
            }
            
            .grid-2, .grid-3 {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
                gap: 15px;
            }
            
            .multi-year-stats {
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
            }
            
            .stat-pill {
                padding: 12px;
            }
            
            .stat-pill-value {
                font-size: 1.3rem;
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
        .dashboard-card form .form-control:hover {
            border-color: #800000 !important;
            box-shadow: 0 0 0 0.2rem rgba(128, 0, 0, 0.15) !important;
            outline: none !important;
            color: #212529;
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/includes/side_bar.php'; ?>
    <?php include __DIR__ . '/includes/navbar.php'; ?>
    <div class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <h1><i class="fas fa-chart-line me-3"></i>Data Analytics Dashboard</h1>
            <p>School Year: <strong><?= htmlspecialchars($school_year_label) ?></strong> | Semester: <strong><?= htmlspecialchars($semester_label) ?></strong></p>
        </div>

        <!-- Filters -->
        <div class="filter-section">
            <form class="row g-3 align-items-end" method="get" action="" id="filterForm">
                <div class="col-12 col-md-5 col-lg-4">
                    <label class="form-label fw-semibold mb-1">School Year</label>
                    <select class="form-select" name="year" id="yearFilter" onchange="submitFilterForm()">
                        <?php foreach (($filterState['available_years'] ?? []) as $y): ?>
                            <option value="<?= htmlspecialchars($y) ?>" <?= $selected_year === $y ? 'selected' : '' ?>>
                                <?= htmlspecialchars($y) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-4 col-lg-3">
                    <label class="form-label fw-semibold mb-1">Semester</label>
                    <select class="form-select" name="semester" id="semesterFilter" onchange="submitFilterForm()">
                        <option value="all" <?= $selected_semester === 'all' ? 'selected' : '' ?>>All Semesters</option>
                        <?php foreach (($filterState['available_semesters'] ?? []) as $sem): ?>
                            <option value="<?= $sem ?>" <?= (string)$selected_semester === (string)$sem ? 'selected' : '' ?>>
                                <?= ($sem === 1) ? '1st Semester' : '2nd Semester' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-3 col-lg-5 d-flex gap-2 justify-content-md-end">
                    <a class="btn btn-outline-secondary" href="data_analytics.php">
                        <i class="fas fa-rotate-left me-1"></i>Reset
                    </a>
                    <a class="btn btn-primary-custom" href="analytics_export.php?<?= htmlspecialchars(analytics_build_query($filterState)) ?>">
                        <i class="fas fa-download me-1"></i>Export
                    </a>
                    <a class="btn btn-outline-danger" href="analytics_advanced.php?year=all&semester=all">
                        <i class="fas fa-sliders-h me-1"></i>Advanced
                    </a>
                </div>
            </form>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-grid">
            
            <div class="stat-card">
                <div class="stat-value"><?= $students_evaluated ?></div>
                <div class="stat-label">Students Evaluated</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= round($avg_rating, 2) ?></div>
                <div class="stat-label">Overall Avg Rating</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= $participation_rate ?>%</div>
                <div class="stat-label">Participation Rate</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= $total_teachers ?></div>
                <div class="stat-label">Total Teachers</div>
            </div>
        </div>

        <!-- EARLY WARNING ALERTS -->
        <?php if (count($early_warning_teachers) > 0): ?>
        <div class="alert-custom alert-danger-custom">
            <h5><i class="fas fa-exclamation-triangle me-2"></i>Early Warning System</h5>
            <p><?= count($early_warning_teachers) ?> teacher(s) have ratings below 3.0 with 3+ evaluations. Immediate PD intervention recommended.</p>
        </div>
        <?php endif; ?>

        <!-- BIAS DETECTION ALERTS -->
        <?php if (count($bias_detections) > 0): ?>
        <div class="alert-custom alert-warning-custom">
            <h5><i class="fas fa-check-circle me-2"></i>Data Quality Indicators</h5>
            <ul style="margin-bottom: 0;">
                <?php foreach ($bias_detections as $bias): ?>
                <li><strong><?= htmlspecialchars($bias['teacher']) ?></strong>: Rating <?= round($bias['rating'], 2) ?> - <?= $bias['alert'] ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <!-- RATING DISTRIBUTION CHART -->
        <div class="dashboard-card">
            <div class="card-header-custom">
                <i class="fas fa-pie-chart"></i>
                <h3>Rating Distribution</h3>
            </div>
            <div class="chart-container">
                <canvas id="ratingDistributionChart"></canvas>
            </div>
        </div>

        <!-- CATEGORY PERFORMANCE & RADAR CHART -->
        <div class="grid-2">
            <div class="dashboard-card">
                <div class="card-header-custom">
                    <i class="fas fa-star"></i>
                    <h3>Category Scores</h3>
                </div>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th>Avg Score</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($category_scores as $cat): ?>
                            <tr>
                                <td><?= htmlspecialchars($cat['category_name']) ?></td>
                                <td><strong><?= round($cat['avg_score'], 2) ?></strong>/5.0</td>
                                <td>
                                    <?php if ($cat['avg_score'] >= 4): ?>
                                        <span class="badge badge-success">Excellent</span>
                                    <?php elseif ($cat['avg_score'] >= 3): ?>
                                        <span class="badge badge-info">Good</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger">Needs Work</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="dashboard-card">
                <div class="card-header-custom">
                    <i class="fas fa-chart-radar"></i>
                    <h3>Category Radar</h3>
                </div>
                <div class="chart-container" style="height: 300px;">
                    <canvas id="categoryRadarChart"></canvas>
                </div>
            </div>
        </div>

        <!-- PROFESSIONAL DEVELOPMENT RECOMMENDATIONS -->
        <div class="dashboard-card">
            <div class="card-header-custom">
                <i class="fas fa-graduation-cap"></i>
                <h3>Professional Development Recommendations</h3>
            </div>
            <?php if (count($pd_recommendations) > 0): ?>
                <div class="grid-2">
                    <?php foreach ($pd_recommendations as $pd): ?>
                    <div class="metric-box">
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 10px;">
                            <strong><?= htmlspecialchars($pd['category']) ?></strong>
                            <span class="badge <?= $pd['level'] === 'Critical' ? 'badge-danger' : 'badge-warning' ?>">
                                <?= $pd['level'] ?>
                            </span>
                        </div>
                        <div style="font-size: 0.85rem; color: #555; line-height: 1.5;">
                            <?= htmlspecialchars($pd['recommendation']) ?>
                        </div>
                        <div style="margin-top: 10px; font-size: 0.9rem;">
                            <strong>Current Score:</strong> <span style="color: var(--danger-color);"><?= round($pd['score'], 2) ?>/5.0</span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="alert alert-success" role="alert">
                    <i class="fas fa-check-circle me-2"></i>All categories are performing above threshold. No immediate PD interventions needed.
                </div>
            <?php endif; ?>
        </div>

        <!-- STRAND PERFORMANCE -->
        <div class="dashboard-card">
            <div class="card-header-custom">
                <i class="fas fa-list"></i>
                <h3>Strand Performance Analysis</h3>
            </div>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Strand</th>
                            <th>Avg Rating</th>
                            <th>Total Evaluations</th>
                            <th>Performance Bar</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($strand_performance as $strand): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($strand['strand']) ?></strong></td>
                            <td><?= round($strand['avg_rating'], 2) ?>/5.0</td>
                            <td><?= $strand['evaluation_count'] ?></td>
                            <td>
                                <div class="performance-bar">
                                    <div class="performance-bar-fill" style="width: <?= ($strand['avg_rating'] / 5) * 100 ?>%;">
                                        <?= round(($strand['avg_rating'] / 5) * 100) ?>%
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- TOP & BOTTOM PERFORMERS -->
        <div class="grid-2">
            <div class="dashboard-card">
                <div class="card-header-custom">
                    <i class="fas fa-trophy"></i>
                    <h3>Top Performers</h3>
                </div>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Teacher</th>
                                <th>Rating</th>
                                <th>Evaluations</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $top_teachers = array_slice($teacher_performance, 0, 5); ?>
                            <?php foreach ($top_teachers as $teacher): ?>
                            <tr>
                                <td><?= htmlspecialchars($teacher['teacher_name']) ?></td>
                                <td><span class="badge badge-success"><?= round($teacher['avg_rating'], 2) ?></span></td>
                                <td><?= $teacher['evaluation_count'] ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="dashboard-card">
                <div class="card-header-custom">
                    <i class="fas fa-exclamation-circle"></i>
                    <h3>Teachers Needing Support</h3>
                </div>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Teacher</th>
                                <th>Rating</th>
                                <th>Evaluations</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $bottom_teachers = array_slice(array_reverse($teacher_performance), 0, 5); ?>
                            <?php foreach ($bottom_teachers as $teacher): ?>
                            <tr>
                                <td><?= htmlspecialchars($teacher['teacher_name']) ?></td>
                                <td><span class="badge badge-danger"><?= round($teacher['avg_rating'], 2) ?></span></td>
                                <td><?= $teacher['evaluation_count'] ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- MULTI-YEAR TREND ANALYSIS -->
        <div class="dashboard-card">
            <div class="card-header-custom">
                <i class="fas fa-line-chart"></i>
                <h3>Multi-Year Performance Trends</h3>
            </div>
            
            <?php if (count($multi_year_data) > 0): ?>
                <div class="multi-year-stats">
                    <div class="stat-pill">
                        <div class="stat-pill-label">Latest Period</div>
                        <div class="stat-pill-value"><?= round($multi_year_data[0]['avg_rating'], 2) ?></div>
                        <div class="stat-pill-subtext"><?= htmlspecialchars($multi_year_data[0]['school_year'] . ' - ' . ($multi_year_data[0]['semester'] == 1 ? '1st Sem' : '2nd Sem')) ?></div>
                    </div>
                    <div class="stat-pill">
                        <div class="stat-pill-label">Highest Rating</div>
                        <div class="stat-pill-value"><?= round(max(array_map(fn($m) => $m['avg_rating'], $multi_year_data)), 2) ?></div>
                        <div class="stat-pill-subtext">Peak Performance</div>
                    </div>
                    <div class="stat-pill">
                        <div class="stat-pill-label">Lowest Rating</div>
                        <div class="stat-pill-value"><?= round(min(array_map(fn($m) => $m['avg_rating'], $multi_year_data)), 2) ?></div>
                        <div class="stat-pill-subtext">Lowest Point</div>
                    </div>
                    <div class="stat-pill">
                        <div class="stat-pill-label">Average Trend</div>
                        <div class="stat-pill-value"><?= round(array_sum(array_map(fn($m) => $m['avg_rating'], $multi_year_data)) / count($multi_year_data), 2) ?></div>
                        <div class="stat-pill-subtext">Overall Average</div>
                    </div>
                </div>
                
                <div class="chart-container">
                    <canvas id="multiYearChart"></canvas>
                </div>
                
                <div style="margin-top: 20px; padding: 15px; background: var(--bg-light); border-radius: 8px; border-left: 4px solid var(--primary-color);">
                    <small style="color: #6c757d;">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Trend Insight:</strong> 
                        <?php 
                            if (count($multi_year_data) > 0) {
                                $current_avg = $multi_year_data[0]['avg_rating'];
                                $previous_avg = count($multi_year_data) > 1 ? $multi_year_data[1]['avg_rating'] : $current_avg;
                                
                                echo "Chart displays <strong>all historical periods</strong> for comprehensive trend analysis. ";
                                
                                if ($current_avg > $previous_avg) {
                                    echo "Latest period shows <strong style='color: var(--success-color);'>improvement</strong> from previous period.";
                                } elseif ($current_avg < $previous_avg) {
                                    echo "Latest period has <strong style='color: var(--danger-color);'>declined</strong> from previous period.";
                                } else {
                                    echo "Latest period is <strong style='color: var(--warning-color);'>stable</strong> compared to previous period.";
                                }
                                
                                if ($current_period_key) {
                                    echo " <em style='color: #666;'>(Currently selected period highlighted in yellow on chart)</em>";
                                }
                            }
                        ?>
                    </small>
                </div>
            <?php else: ?>
                <div class="chart-no-data">
                    <div style="text-align: center;">
                        <i class="fas fa-chart-line" style="font-size: 2rem; margin-bottom: 10px; opacity: 0.5;"></i>
                        <p style="margin: 0;">No multi-year data available for the selected filters.</p>
                        <small>Try selecting a different school year or semester.</small>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- DEMOGRAPHIC BREAKDOWN -->
        <div class="dashboard-card">
            <div class="card-header-custom">
                <i class="fas fa-users"></i>
                <h3>Demographic Breakdown (Strand-wise)</h3>
            </div>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Strand</th>
                            <th>Unique Students</th>
                            <th>Avg Rating</th>
                            <th>Excellent</th>
                            <th>Good</th>
                            <th>Average</th>
                            <th>Poor</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($demographic_breakdown as $demo): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($demo['strand']) ?></strong></td>
                            <td><?= $demo['unique_students'] ?></td>
                            <td><?= round($demo['avg_rating'], 2) ?>/5.0</td>
                            <td><span class="badge badge-success"><?= $demo['excellent_count'] ?></span></td>
                            <td><span class="badge badge-info"><?= $demo['good_count'] ?></span></td>
                            <td><span class="badge badge-warning"><?= $demo['average_count'] ?></span></td>
                            <td><span class="badge badge-danger"><?= $demo['poor_count'] ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- EARLY WARNING DETAILS -->
        <?php if (count($early_warning_teachers) > 0): ?>
        <div class="dashboard-card">
            <div class="card-header-custom">
                <i class="fas fa-alert-circle"></i>
                <h3>Early Warning Details</h3>
            </div>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Teacher Name</th>
                            <th>Subject</th>
                            <th>Avg Rating</th>
                            <th>Evaluations</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($early_warning_teachers as $teacher): ?>
                        <tr>
                            <td><?= htmlspecialchars($teacher['teacher_name']) ?></td>
                            <td><?= htmlspecialchars($teacher['subject_name']) ?></td>
                            <td><span class="badge badge-danger"><?= round($teacher['avg_rating'], 2) ?></span></td>
                            <td><?= $teacher['evaluation_count'] ?></td>
                            <td><span class="badge badge-warning">Needs Intervention</span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Function to submit filter form while preserving both values
        function submitFilterForm() {
            const form = document.getElementById('filterForm');
            const yearFilter = document.getElementById('yearFilter').value;
            const semesterFilter = document.getElementById('semesterFilter').value;
            
            // Ensure both values are present before submitting
            if (yearFilter && semesterFilter) {
                // Build URL with both parameters
                const url = new URL(window.location);
                url.searchParams.set('year', yearFilter);
                url.searchParams.set('semester', semesterFilter);
                window.location.href = url.toString();
            }
        }

        // Prepare data for charts
        const ratingData = {
            labels: [<?php echo implode(",", array_map(fn($r) => "'" . htmlspecialchars($r['rating_category']) . "'", $rating_distribution)); ?>],
            data: [<?php echo implode(",", array_map(fn($r) => $r['count'], $rating_distribution)); ?>]
        };

        const categoryData = {
            labels: [<?php echo implode(",", array_map(fn($c) => "'" . htmlspecialchars($c['category_name']) . "'", $category_scores)); ?>],
            data: [<?php echo implode(",", array_map(fn($c) => $c['avg_score'], $category_scores)); ?>]
        };

        // Multi-year data now includes ALL periods, not filtered
        const multiYearData = {
            labels: [<?php echo implode(",", array_map(fn($m) => "'" . htmlspecialchars($m['school_year'] . ' - ' . ($m['semester'] == 1 ? '1st Sem' : '2nd Sem')) . "'", $multi_year_data)); ?>],
            data: [<?php echo implode(",", array_map(fn($m) => $m['avg_rating'], $multi_year_data)); ?>],
            selectedPeriod: <?php echo json_encode($current_period_key); ?>,
            isSelectedPeriod: [<?php echo implode(",", array_map(fn($m) => ($m['is_selected_period'] ? 'true' : 'false'), $multi_year_data)); ?>]
        };

        // Rating Distribution Pie Chart
        const ratingCtx = document.getElementById('ratingDistributionChart').getContext('2d');
        new Chart(ratingCtx, {
            type: 'doughnut',
            data: {
                labels: ratingData.labels,
                datasets: [{
                    data: ratingData.data,
                    backgroundColor: ['#28a745', '#17a2b8', '#ffc107', '#fd7e14', '#dc3545'],
                    borderColor: '#fff',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { font: { size: 12 }, padding: 15 }
                    }
                }
            }
        });

        // Category Radar Chart
        const radarCtx = document.getElementById('categoryRadarChart').getContext('2d');
        new Chart(radarCtx, {
            type: 'radar',
            data: {
                labels: categoryData.labels,
                datasets: [{
                    label: 'Avg Score',
                    data: categoryData.data,
                    borderColor: '#800000',
                    backgroundColor: 'rgba(128, 0, 0, 0.1)',
                    pointBackgroundColor: '#800000',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    r: { beginAtZero: true, max: 5 }
                },
                plugins: {
                    legend: { labels: { font: { size: 12 } } }
                }
            }
        });

        // Multi-Year Trend Line Chart (Now shows ALL periods regardless of filter)
        if (document.getElementById('multiYearChart')) {
            const trendCtx = document.getElementById('multiYearChart').getContext('2d');
            const gradient = trendCtx.createLinearGradient(0, 0, 0, 350);
            gradient.addColorStop(0, 'rgba(128, 0, 0, 0.3)');
            gradient.addColorStop(1, 'rgba(128, 0, 0, 0.05)');
            
            // Build dataset with highlighting for selected period
            const pointBackgroundColors = multiYearData.isSelectedPeriod.map((isSelected, idx) => {
                return isSelected ? '#ffc107' : '#800000';
            });
            
            const pointRadius = multiYearData.isSelectedPeriod.map((isSelected) => {
                return isSelected ? 8 : 6;
            });
            
            const pointBorderWidth = multiYearData.isSelectedPeriod.map((isSelected) => {
                return isSelected ? 3 : 2.5;
            });
            
            const pointBorderColor = multiYearData.isSelectedPeriod.map((isSelected) => {
                return isSelected ? '#333' : '#fff';
            });
            
            new Chart(trendCtx, {
                type: 'line',
                data: {
                    labels: multiYearData.labels,
                    datasets: [{
                        label: 'Average Rating (All Periods)',
                        data: multiYearData.data,
                        borderColor: '#800000',
                        backgroundColor: gradient,
                        borderWidth: 3,
                        pointRadius: pointRadius,
                        pointHoverRadius: 9,
                        pointBackgroundColor: pointBackgroundColors,
                        pointBorderColor: pointBorderColor,
                        pointBorderWidth: pointBorderWidth,
                        tension: 0.4,
                        fill: true,
                        segment: {
                            borderDash: ctx => ctx.p0DataIndex === undefined ? [] : []
                        }
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        intersect: false,
                        mode: 'index'
                    },
                    animation: {
                        duration: 750,
                        easing: 'easeInOutQuart'
                    },
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top',
                            labels: {
                                font: { size: 12, weight: '600' },
                                padding: 15,
                                usePointStyle: true,
                                pointStyle: 'circle',
                                color: '#2c3e50'
                            }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(0, 0, 0, 0.8)',
                            padding: 12,
                            titleFont: { size: 13, weight: '600' },
                            bodyFont: { size: 12 },
                            borderColor: '#800000',
                            borderWidth: 1,
                            displayColors: true,
                            callbacks: {
                                label: function(context) {
                                    const isSelected = multiYearData.isSelectedPeriod[context.dataIndex];
                                    let label = 'Rating: ' + context.parsed.y.toFixed(2) + ' / 5.0';
                                    if (isSelected) {
                                        label += ' (Currently Selected)';
                                    }
                                    return label;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 5,
                            ticks: {
                                stepSize: 1,
                                font: { size: 11 },
                                color: '#6c757d',
                                padding: 10
                            },
                            grid: {
                                color: 'rgba(0, 0, 0, 0.05)',
                                drawBorder: false
                            }
                        },
                        x: {
                            ticks: {
                                font: { size: 11 },
                                color: '#6c757d',
                                padding: 10,
                                maxRotation: 45,
                                minRotation: 0
                            },
                            grid: {
                                display: false,
                                drawBorder: false
                            }
                        }
                    }
                }
            });
        }
    </script>
</body>
</html>
