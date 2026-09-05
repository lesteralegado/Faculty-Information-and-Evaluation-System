<?php
session_start();

require_once __DIR__ . '/../includes/student_access_control.php';
requireStudentAccess('full');

// Include database connection
require_once __DIR__ . '/../includes/db_connection.php';
require_once __DIR__ . '/../includes/evaluation_status_helper.php';

// Get evaluation status
$evaluation_status = getEvaluationStatus($conn);

// Get filter parameters for announcements
$priority = isset($_GET['priority']) ? $_GET['priority'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Build WHERE clause for announcements (show 'all' and 'students' targeted announcements)
$where_conditions = ["(target_audience = 'all' OR target_audience = 'students')", "is_active = 1"];
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
        case 'urgent': $type = 'important'; break;
        case 'high': $type = 'notice'; break;
        case 'medium': $type = 'info'; break;
        case 'low': $type = 'low'; break;
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

$gradeSchedules = [
    'Kinder' => '7:00 A.M. - 9:00 A.M.',
    'Grades 1-3' => '7:00 A.M. - 11:00 A.M.',
    'Grades 4-6' => '12:00 NN. - 4:00 P.M.',
    'Grades 7-9' => '7:00 A.M. - 11:00 A.M.',
    'Grades 10-12' => '12:00 NN. - 4:00 P.M.'
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="/capstone/images/school-logo.png" type="image/png">
    <title>Student Dashboard</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    
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

        .welcome-section {
            background: linear-gradient(135deg, var(--primary-color) 0%, #600000 100%);
            color: white;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 8px 25px rgba(128, 0, 0, 0.15);
        }

        .welcome-section h1 {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .welcome-section p {
            font-size: 1.1rem;
            opacity: 0.9;
            margin: 0;
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

        .schedule-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .schedule-item {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 20px;
            border-radius: 12px;
            text-align: center;
            border: 2px solid transparent;
            transition: all 0.3s ease;
        }

        .schedule-item:hover {
            border-color: var(--primary-color);
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(128, 0, 0, 0.15);
        }

        .schedule-grade {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 10px;
        }

        .schedule-time {
            font-size: 1rem;
            font-weight: 600;
            color: #333;
            background: white;
            padding: 8px 15px;
            border-radius: 20px;
            display: inline-block;
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

        .contact-info {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            margin-top: 15px;
        }

        .contact-item {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
            font-size: 0.9rem;
        }

        .contact-item:last-child {
            margin-bottom: 0;
        }

        .contact-item i {
            width: 20px;
            text-align: center;
            color: var(--primary-color);
        }

        .contact-item a {
            color: #333;
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .contact-item a:hover {
            color: var(--primary-color);
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 20px;
            }

            .welcome-section h1 {
                font-size: 2rem;
            }

            .schedule-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php include '../includes/side_bar.php'; ?>
    <?php include '../includes/navbar.php'; ?>
    
    <div class="main-content">
        <!-- Welcome Section -->
        <div class="welcome-section">
            <h1>Welcome back, <?= htmlspecialchars($firstName) ?>!</h1>
            <p>Stay updated with the latest announcements.</p>
        </div>

        <?php if (isset($_GET['error']) && $_GET['error'] === 'evaluation_not_available'): ?>
            <div class="alert alert-warning alert-dismissible fade show" role="alert" style="margin-bottom: 25px;">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <strong>Evaluation Not Available:</strong> The evaluation period is not currently active. Please check the evaluation status below.
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <!-- Announcements Section -->
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
                            <?php if (isset($announcement['image'])): ?>
                                <img src="<?= $announcement['image'] ?>" alt="<?= htmlspecialchars($announcement['title']) ?>" class="announcement-image">
                            <?php endif; ?>
                            <div class="announcement-date">
                                <i class="fas fa-calendar-alt me-1"></i>
                                <?= date('F j, Y', strtotime($announcement['date'])) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Sidebar Content -->
            <div class="col-lg-4">
                <!-- Evaluation Status -->
                <div class="dashboard-card mb-4">
                    <div class="card-header">
                            <i class="fas fa-clipboard-check"></i>
                        <h3>Evaluation Status</h3>
                    </div>
                    <?= renderEvaluationStatus($evaluation_status) ?>
                </div>

                <!-- School Info -->
                <div class="dashboard-card">
                    <div class="card-header">
                        <i class="fas fa-school"></i>
                        <h3>School Information</h3>
                    </div>
                    
                    <div class="text-center">
                        <img src="../images/school-logo.png" alt="HRSSI Logo" style="width: 80px; height: 80px; border-radius: 50%; margin-bottom: 15px;">
                        <h5 class="text-primary">Holy Redeemer School</h5>
                        <p class="text-muted mb-2">of San Isidro, Laguna</p>
                        <small class="text-muted">
                            <i class="fas fa-map-marker-alt me-1"></i>
                            San Isidro, Laguna, Philippines
                        </small>
                    </div>

                    <div class="contact-info">
                        <div class="contact-item">
                            <i class="fab fa-facebook"></i>
                            <a href="https://www.facebook.com/hrscabuyaomabuhay" target="_blank">
                                Holy Redeemer School Facebook
                            </a>
                        </div>
                        <div class="contact-item">
                            <i class="fas fa-envelope"></i>
                            <a href="mailto:hrssimabuhay2003@gmail.com">
                                hrssimabuhay2003@gmail.com
                            </a>
                        </div>
                        <div class="contact-item">
                            <i class="fas fa-phone"></i>
                            <a href="tel:+639915220134">
                                0991 522 0134
                            </a>
                        </div>
                    </div>
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

            // Add click handlers for contact links
            document.querySelectorAll('.contact-item a').forEach(link => {
                link.addEventListener('click', function(e) {
                    // Add any tracking or analytics here if needed
                    console.log('Contact link clicked:', this.href);
                });
            });
        });
    </script>
</body>
</html>
