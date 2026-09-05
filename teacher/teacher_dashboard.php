<?php
session_start();

// Check if user is logged in and is a teacher
if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'teacher') {
    header("Location: ../login.php?error=2");
    exit();
}

// Include database connection
require_once __DIR__ . '/../includes/db_connection.php';
require_once __DIR__ . '/../includes/evaluation_status_helper.php';

// Get evaluation status
$evaluation_status = getEvaluationStatus($conn);

// Get teacher information
$teacher_username = $_SESSION['username']; // This is actually account_number from login.php
$stmt = $conn->prepare("SELECT t.teacher_id, t.*, u.first_name, u.last_name, u.email 
                        FROM teachers t 
                        JOIN users u ON t.user_id = u.user_id 
                        WHERE u.account_number = ?");
$stmt->bind_param("s", $teacher_username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("Teacher not found. Please contact administrator.");
}

$teacher_data = $result->fetch_assoc();
$teacher_id = $teacher_data['teacher_id'];
$teacher_name = $teacher_data['first_name'] . ' ' . $teacher_data['last_name'];

// Get the latest academic year and semester from evaluations for this teacher
$year_sem_sql = "SELECT e.school_year, e.semester FROM evaluations e
                 WHERE e.teacher_id = ? 
                 ORDER BY e.school_year DESC, FIELD(e.semester, 1, 2) DESC 
                 LIMIT 1";
$ys_stmt = $conn->prepare($year_sem_sql);
$ys_stmt->bind_param("i", $teacher_id);
$ys_stmt->execute();
$year_sem_result = $ys_stmt->get_result()->fetch_assoc();
$active_year = $year_sem_result ? $year_sem_result['school_year'] : '';
$active_sem = $year_sem_result ? $year_sem_result['semester'] : '';
$ys_stmt->close();

// Get filter parameters for announcements
$priority = isset($_GET['priority']) ? $_GET['priority'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Build WHERE clause for announcements (show 'all' and 'teachers' targeted announcements)
$where_conditions = ["(target_audience = 'all' OR target_audience = 'teachers')", "is_active = 1"];
$params = [];
$types = "";

if (!empty($priority)) {
    $where_conditions[] = "priority = ?";
    $params[] = $priority;
    $types .= "s";
}

if (!empty($date_from)) {
    $where_conditions[] = "DATE(publish_date) >= ?";
    $params[] = $date_from;
    $types .= "s";
}

if (!empty($date_to)) {
    $where_conditions[] = "DATE(publish_date) <= ?";
    $params[] = $date_to;
    $types .= "s";
}

$where_clause = implode(" AND ", $where_conditions);

// Get announcements with author information
$announcements_sql = "SELECT a.*, u.first_name, u.last_name 
                      FROM announcements a 
                      LEFT JOIN users u ON a.author_id = u.user_id 
                      WHERE $where_clause 
                      ORDER BY FIELD(a.priority, 'urgent', 'high', 'medium', 'low'), a.publish_date DESC
                      LIMIT 10";

$stmt = $conn->prepare($announcements_sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$announcements_result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Convert database announcements to dashboard format
$announcements = [];
foreach ($announcements_result as $ann) {
    $type = 'info'; // default
    switch ($ann['priority']) {
        case 'urgent':
            $type = 'important';
            break;
        case 'high':
            $type = 'notice';
            break;
        case 'medium':
            $type = 'info';
            break;
        case 'low':
            $type = 'low';
            break;
    }
    
    $announcements[] = [
        'id' => $ann['id'],
        'title' => $ann['title'],
        'content' => $ann['content'],
        'date' => $ann['publish_date'],
        'type' => $type,
        'author' => $ann['first_name'] . ' ' . $ann['last_name']
    ];
}

// If no classes, set stats to 0 and skip queries
if (!$active_year || !$active_sem) {
    $stats = [
        'total_classes' => 0,
        'total_subjects' => 0,
        'total_evaluations' => 0,
        'average_rating' => 0
    ];
    $conn->close();
} else {

    // Calculate teacher statistics directly from evaluation_responses
    $stats_sql = "SELECT 
        COUNT(DISTINCT e.subject_id) as total_subjects,
        COUNT(DISTINCT e.student_id) as total_evaluations,
        COALESCE(AVG(er.response), 0) as average_rating
    FROM evaluation_responses er
    JOIN evaluations e ON er.evaluation_id = e.evaluation_id
    WHERE e.teacher_id = ? AND e.school_year = ? AND e.semester = ?";

    $stmt = $conn->prepare($stats_sql);
    $stmt->bind_param("iss", $teacher_id, $active_year, $active_sem);
    $stmt->execute();
    $stats = $stmt->get_result()->fetch_assoc();

    // Ensure stats are properly initialized
    if (!$stats) {
        $stats = [
            'total_classes' => 0,
            'total_subjects' => 0,
            'total_evaluations' => 0,
            'average_rating' => 0
        ];
    }

    // Get teacher's subjects and evaluation results
    $classes_sql = "SELECT 
        s.subject_name,
        COUNT(DISTINCT e.student_id) as evaluation_count,
        AVG(er.response) as avg_rating
        FROM evaluation_responses er
        JOIN evaluations e ON er.evaluation_id = e.evaluation_id
        JOIN subjects s ON e.subject_id = s.subject_id
        WHERE e.teacher_id = ? AND e.school_year = ? AND e.semester = ?
        GROUP BY e.subject_id, s.subject_name
        ORDER BY s.subject_name";

    $stmt = $conn->prepare($classes_sql);
    $stmt->bind_param("iss", $teacher_id, $active_year, $active_sem);
    $stmt->execute();
    $classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Fetch all evaluation responses for this teacher (individual responses)
    $eval_sql = "SELECT er.*, s.subject_name
                 FROM evaluation_responses er
                 JOIN evaluations e ON er.evaluation_id = e.evaluation_id
                 JOIN subjects s ON e.subject_id = s.subject_id
                 WHERE e.teacher_id = ? AND e.school_year = ? AND e.semester = ?
                 ORDER BY e.student_id DESC";
    $eval_stmt = $conn->prepare($eval_sql);
    $eval_stmt->bind_param("iss", $teacher_id, $active_year, $active_sem);
    $eval_stmt->execute();
    $eval_responses = $eval_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $stmt->close();
    $eval_stmt->close();
    $conn->close();
} // end else for year/sem check
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="/capstone/images/school-logo.png" type="image/png">
    <title>Teacher Dashboard</title>
    
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
            --sidebar-width: 250px;
            --navbar-height: 60px;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background-color: #f8f9fa;
        }

        .main-content {
            margin-left: var(--sidebar-width);
            margin-top: var(--navbar-height);
            padding: 30px;
            min-height: calc(100vh - var(--navbar-height));
        }

        /* Keep original header section styles */
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
        
        @keyframes float {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(180deg); }
        }
        
        .header-section h1 {
            font-weight: 900;
            margin-bottom: 15px;
            font-size: 2.8rem;
            position: relative;
            z-index: 2;
        }
        
        .teacher-info {
            background: rgba(255, 255, 255, 0.15);
            padding: 25px;
            border-radius: 15px;
            margin-top: 25px;
            backdrop-filter: blur(10px);
            position: relative;
            z-index: 2;
        }
        
        .teacher-info p {
            margin-bottom: 10px;
            font-size: 1.1rem;
            font-weight: 600;
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
            font-weight: 900;
            margin-bottom: 10px;
        }
        
        .stat-label {
            font-size: 1rem;
            font-weight: 600;
            opacity: 0.9;
        }

        /* Student dashboard announcement styles */
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
            gap: 15px;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f8f9fa;
        }

        .card-header h3 {
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

        .announcement-item {
            border-left: 4px solid var(--primary-color);
            padding: 20px;
            margin-bottom: 20px;
            background: #f8f9fa;
            border-radius: 0 10px 10px 0;
            transition: all 0.3s ease;
        }

        .announcement-item:hover {
            background: #e9ecef;
            transform: translateX(5px);
        }

        .announcement-item.important {
            border-left-color: #dc3545;
            background: #fff5f5;
        }

        .announcement-item.notice {
            border-left-color: #ffc107;
            background: #fffbf0;
        }

        .announcement-item.low {
            border-left-color: #28a745;
            background: #f0fff4;
        }

        .announcement-item.info {
            border-left-color: #0dcaf0;
            background: #f0fcff;
        }

        .announcement-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
        }

        .announcement-content {
            color: #666;
            line-height: 1.6;
            margin-bottom: 10px;
        }

        .announcement-date {
            font-size: 0.9rem;
            color: #999;
            font-weight: 500;
        }

        .announcement-author {
            font-size: 0.85rem;
            color: #666;
            font-style: italic;
            margin-top: 5px;
        }

        /* Evaluation Status Styles */
        .evaluation-status-card {
            padding: 20px;
            border-radius: 12px;
            border: 2px solid;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .status-content {
            position: relative;
            z-index: 2;
        }

        .status-icon-wrapper {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, 0.2);
        }

        .status-icon {
            font-size: 1.2rem;
            color: inherit;
        }

        .status-title {
            font-weight: 600;
            color: #333;
            margin: 0;
        }

        .status-message {
            color: #666;
            font-size: 0.9rem;
            line-height: 1.4;
        }

        .academic-info {
            padding-top: 8px;
            border-top: 1px solid rgba(0, 0, 0, 0.1);
        }

        /* Status variants */
        .status-not-set {
            background: #f8f9fa;
            border-color: #dee2e6;
            color: #6c757d;
        }

        .status-upcoming {
            background: #fff3cd;
            border-color: #ffc107;
            color: #856404;
        }

        .status-ongoing {
            background: #d1ecf1;
            border-color: #17a2b8;
            color: #0c5460;
            animation: pulse-glow 2s infinite;
        }

        @keyframes pulse-glow {
            0% { box-shadow: 0 0 0 0 rgba(23, 162, 184, 0.4); }
            70% { box-shadow: 0 0 0 8px rgba(23, 162, 184, 0); }
            100% { box-shadow: 0 0 0 0 rgba(23, 162, 184, 0); }
        }

        .status-finished {
            background: #d4edda;
            border-color: #28a745;
            color: #155724;
        }

        .class-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            border-left: 4px solid var(--primary-color);
        }

        .class-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.12);
        }

        .class-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: #333;
            margin-bottom: 8px;
        }

        .class-details {
            color: #666;
            font-size: 0.9rem;
            margin-bottom: 15px;
        }

        .class-stats {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .rating-display {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .rating-number {
            font-size: 1.5rem;
            font-weight: 700;
            color: #28a745;
        }

        .rating-stars {
            color: #ffc107;
            font-size: 1rem;
        }

        .evaluation-count {
            font-size: 0.85rem;
            color: #666;
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 20px;
            }

            .header-section h1 {
                font-size: 2.2rem;
            }

            
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../includes/side_bar.php'; ?>
    <?php include __DIR__ . '/../includes/navbar.php'; ?>

    <div class="main-content">
        <!-- Keep Original Header Section -->
        <div class="header-section">
            <h1><i class="fas fa-chalkboard-teacher me-3"></i>Teacher Dashboard</h1>
            <p style="font-size: 1.1rem; margin-bottom: 0; opacity: 0.9; position: relative; z-index: 2;">
                Welcome back, <?php echo htmlspecialchars($teacher_name); ?>!
            </p>
            
            <div class="teacher-info">
                <p><i class="fas fa-building me-2"></i><strong>Assigned Strand:</strong> <?php echo htmlspecialchars($teacher_data['strand']); ?></p>
            </div>
            
            
        </div>

        <div class="row">
            <div class="col-lg-8">
                <div class="dashboard-card">
                    <div class="card-header">
                        <i class="fas fa-bullhorn"></i>
                        <h3>Latest Announcements</h3>
                    </div>
                    
                    <?php foreach ($announcements as $announcement): ?>
                        <div class="announcement-item <?= $announcement['type'] ?>">
                            <div class="announcement-title"><?= htmlspecialchars($announcement['title']) ?></div>
                            <div class="announcement-content"><?= htmlspecialchars($announcement['content']) ?></div>
                            <div class="announcement-date">
                                <i class="fas fa-calendar-alt me-1"></i>
                                <?= date('F j, Y', strtotime($announcement['date'])) ?>
                            </div>
                            <?php if (!empty($announcement['author'])): ?>
                                <div class="announcement-author">
                                    <i class="fas fa-user me-1"></i>
                                    By: <?= htmlspecialchars($announcement['author']) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Sidebar Content -->
            <div class="col-lg-4">
                <!-- Evaluation Status -->
                <div class="dashboard-card mb-4"><div class="card-header">
                    <i class="fas fa-clipboard-check"></i>
                    <h3>Evaluation Status</h3>
                </div>
                    <?= renderEvaluationStatus($evaluation_status) ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Add some interactive features
        document.addEventListener('DOMContentLoaded', function() {
            // Animate announcement items on load
            const announcements = document.querySelectorAll('.announcement-item');
            announcements.forEach((item, index) => {
                item.style.opacity = '0';
                item.style.transform = 'translateY(20px)';
                
                setTimeout(() => {
                    item.style.transition = 'all 0.6s ease';
                    item.style.opacity = '1';
                    item.style.transform = 'translateY(0)';
                }, index * 100);
            });
        });
    </script>
</body>
</html>
