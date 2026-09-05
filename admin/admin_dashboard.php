<?php
session_start();

// Check if user is logged in and has admin privileges
if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'admin') {
    header("Location: /capstone/index.php");  
    exit();
}

// Include database connection
require_once __DIR__ . '/../includes/db_connection.php';
require_once __DIR__ . '/../includes/modern_alert_system.php';

// Handle announcement actions (Create, Update, Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create':
                $title = $_POST['title'];
                $content = $_POST['content'];
                $target_audience = $_POST['target_audience'];
                $priority = $_POST['priority'];
                $author_id = $_SESSION['user_id'] ?? 1; // Default to admin user

                // Table 'announcements' in some environments may not have AUTO_INCREMENT on id.
                // Use an INSERT ... SELECT that computes a new id = MAX(id)+1 to avoid Duplicate entry '0' for PRIMARY.
                $stmt = $conn->prepare(
                    "INSERT INTO announcements (id, title, content, author_id, target_audience, priority)
                     SELECT COALESCE(MAX(id), 0) + 1, ?, ?, ?, ?, ? FROM announcements"
                );
                $stmt->bind_param("ssiss", $title, $content, $author_id, $target_audience, $priority);

                if ($stmt->execute()) {
                    $_SESSION['success'] = "Announcement created successfully!";
                } else {
                    $_SESSION['error'] = "Error creating announcement: " . $conn->error;
                }
                break;

            case 'update':
                $id = $_POST['announcement_id'];
                $title = $_POST['title'];
                $content = $_POST['content'];
                $target_audience = $_POST['target_audience'];
                $priority = $_POST['priority'];

                $stmt = $conn->prepare("UPDATE announcements SET title=?, content=?, target_audience=?, priority=? WHERE id=?");
                $stmt->bind_param("ssssi", $title, $content, $target_audience, $priority, $id);

                if ($stmt->execute()) {
                    $_SESSION['success'] = "Announcement updated successfully!";
                } else {
                    $_SESSION['error'] = "Error updating announcement: " . $conn->error;
                }
                break;

            case 'delete':
                $id = $_POST['announcement_id'];
                $stmt = $conn->prepare("DELETE FROM announcements WHERE id=?");
                $stmt->bind_param("i", $id);

                if ($stmt->execute()) {
                    $_SESSION['success'] = "Announcement deleted successfully!";
                } else {
                    $_SESSION['error'] = "Error deleting announcement: " . $conn->error;
                }
                break;
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}

// Get filter parameters for announcements
$priority = isset($_GET['priority']) ? $_GET['priority'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$target_audience = isset($_GET['target_audience']) ? $_GET['target_audience'] : '';

// Build WHERE clause for announcements (admin can filter by audience, priority, date)
// Note: Admins see all announcements including inactive ones for management purposes
$where_conditions = [];
$params = [];
$types = "";

if (!empty($target_audience)) {
    $where_conditions[] = "target_audience = ?";
    $params[] = $target_audience;
    $types .= "s";
}
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
$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Get announcements with author information
$announcements_sql = "SELECT a.*, u.first_name, u.last_name 
                      FROM announcements a 
                      LEFT JOIN users u ON a.author_id = u.user_id 
                      $where_clause
                      ORDER BY FIELD(a.priority, 'urgent', 'high', 'medium', 'low'), a.publish_date DESC
                      LIMIT 50";
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
        case 'low': $type = 'info'; break;
    }
    $announcements[] = [
        'id' => $ann['id'],
        'title' => $ann['title'],
        'content' => $ann['content'],
        'date' => $ann['publish_date'],
        'type' => $type,
        'priority' => $ann['priority'],
        'audience' => $ann['target_audience'],
        'author' => $ann['first_name'] . ' ' . $ann['last_name'],
        'is_active' => $ann['is_active']
    ];
}

// Get statistics
$stats_sql = "SELECT 
    COUNT(*) as total_announcements,
    COUNT(CASE WHEN is_active = 1 THEN 1 END) as active_announcements,
    COUNT(CASE WHEN target_audience = 'students' THEN 1 END) as student_announcements,
    COUNT(CASE WHEN priority = 'urgent' THEN 1 END) as urgent_announcements
    FROM announcements";
$stats_result = $conn->query($stats_sql);
$stats = $stats_result->fetch_assoc();

// Get user information
$firstName = $_SESSION['first_name'] ?? $_SESSION['username'] ?? 'Admin';
$lastName = $_SESSION['last_name'] ?? '';
$fullName = trim($firstName . ' ' . $lastName);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="/capstone/images/school-logo.png" type="image/png">
    <title>Admin Dashboard</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
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
        .header-section::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: float 6s ease-in-out infinite;
        }
        .header-section h1 {
            font-weight: 700;
            margin-bottom: 15px;
            font-size: 2.8rem;
            position: relative;
            z-index: 2;
        }
       
        .content-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            border: none;
            margin-bottom: 30px;
        }
        .card-header-custom {
            background: white;
            border-bottom: 2px solid #f8f9fa;
            padding: 25px 30px;
        }
        .filter-controls {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: center;
            margin-bottom: 20px;
        }
        .filter-input {
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 10px 15px;
            font-size: 0.9rem;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        .filter-input:focus {
            outline: none !important;
            border-color: var(--primary-color) !important;
            box-shadow: 0 0 0 0.25rem rgba(128, 0, 0, 0.25) !important;
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
        .announcements-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 25px;
            margin-top: 20px;
        }
        .announcement-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            border-left: 5px solid var(--primary-color);
            transition: all 0.3s ease;
            position: relative;
        }
        .announcement-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
        }
        .announcement-card.urgent {
            border-left-color: var(--danger-color);
            background: linear-gradient(135deg, #fff5f5 0%, #ffffff 100%);
        }
        .announcement-card.high {
            border-left-color: var(--warning-color);
            background: linear-gradient(135deg, #fffbf0 0%, #ffffff 100%);
        }
        .announcement-card.medium {
            border-left-color: var(--info-color);
            background: linear-gradient(135deg, #f0fcff 0%, #ffffff 100%);
        }
        .announcement-card.low {
            border-left-color: var(--success-color);
            background: linear-gradient(135deg, #f0fff4 0%, #ffffff 100%);
        }
        .announcement-card.archived {
            opacity: 0.7;
            border-left-color: #6c757d;
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
        }
        .announcement-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }
        .announcement-title {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--text-dark);
            margin: 0;
            line-height: 1.3;
        }
        .announcement-badges {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .priority-badge {
            padding: 4px 12px;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .priority-urgent { background: var(--danger-color); color: white; }
        .priority-high { background: var(--warning-color); color: #000; }
        .priority-medium { background: var(--info-color); color: white; }
        .priority-low { background: var(--success-color); color: white; }
        .audience-badge {
            padding: 4px 12px;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: 600;
            background: var(--primary-color);
            color: white;
        }
        .announcement-content {
            color: #666;
            line-height: 1.6;
            margin-bottom: 15px;
            font-weight: 500;
        }
        .announcement-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 15px;
            border-top: 1px solid #f0f0f0;
            font-size: 0.85rem;
            color: #999;
        }
        .announcement-author {
            font-weight: 600;
        }
        .announcement-date {
            font-weight: 500;
        }
        .announcement-actions {
            display: flex;
            gap: 8px;
            margin-top: 15px;
        }
        .btn-action {
            padding: 6px 12px;
            border-radius: 6px;
            border: none;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .btn-edit {
            background: var(--primary-light);
            color: var(--primary-color);
        }
        .btn-edit:hover {
            background: var(--primary-color);
            color: white;
        }
        .btn-delete {
            background: #ffebee;
            color: #d32f2f;
        }
        .btn-delete:hover {
            background: #d32f2f;
            color: white;
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
            font-weight: 500;
            transition: all 0.3s ease;
            accent-color: var(--primary-color);
        }
        .form-control-custom:focus {
            outline: none !important;
            border-color: var(--primary-color) !important;
            box-shadow: 0 0 0 0.25rem rgba(128, 0, 0, 0.25) !important;
        }
        .form-label {
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 8px;
        }
        .alert-custom {
            border-radius: 15px;
            border: none;
            padding: 15px 20px;
            margin-bottom: 25px;
            font-weight: 600;
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
        
        /* Updated dashboard-card styling to match Filter Results design */
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

        .form-control, .form-select {
            border: 2px solid #e9ecef;
            border-radius: 8px;
            padding: 8px 12px;
            font-size: 0.9rem;
            font-weight: 500;
            transition: all 0.3s ease;
            accent-color: var(--primary-color);
        }

        .form-control:focus, .form-select:focus {
            outline: none !important;
            border-color: var(--primary-color) !important;
            box-shadow: 0 0 0 0.25rem rgba(128, 0, 0, 0.25) !important;
        }

        /* Modern button styling matching evaluation results */
        .btn-filter {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            color: white;
            font-weight: 600;
            padding: 8px 16px;
            border-radius: 8px;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(128, 0, 0, 0.2);
        }

        .btn-filter:hover {
            background-color: var(--primary-hover);
            border-color: var(--primary-hover);
            color: white;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(128, 0, 0, 0.3);
        }

        .btn-outline-secondary {
            border: 2px solid #6c757d;
            color: #6c757d;
            font-weight: 600;
            padding: 6px 12px;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .btn-outline-secondary:hover {
            background-color: #6c757d;
            border-color: #6c757d;
            color: white;
            transform: translateY(-1px);
        }

        .btn-create {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-hover));
            border: none;
            color: white;
            font-weight: 600;
            padding: 10px 20px;
            border-radius: 10px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(128, 0, 0, 0.25);
        }

        .btn-create:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(128, 0, 0, 0.35);
            background: linear-gradient(135deg, var(--primary-hover), #c00000);
            color: white;
        }

        .dashboard-card form .form-control:focus,
        .dashboard-card form .form-control:hover,
        .dashboard-card form .form-select:focus,
        .dashboard-card form .form-select:hover {
            border-color: #800000 !important;
            box-shadow: 0 0 0 0.2rem rgba(128, 0, 0, 0.15) !important;
            outline: none !important;
            color: #212529;
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
            
            .announcements-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-controls {
                flex-direction: column;
                align-items: stretch;
            }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../includes/side_bar.php'; ?>
    <?php include __DIR__ . '/../includes/navbar.php'; ?>
    <?php renderModernAlertSystem(); ?>

    <div class="main-content">
        <!-- Header Section -->
        <div class="header-section">
            <h1><i class="fas fa-user-shield me-3"></i>Admin Dashboard</h1>
            <p style="font-size: 1.1rem; margin-bottom: 0; opacity: 0.9; position: relative; z-index: 2;">
                Welcome back, <?php echo htmlspecialchars($fullName); ?>!
            </p>
        </div>

        <?php
            $page_success_message = null;
            $page_error_message = null;
            if (isset($_SESSION['success'])) {
                $page_success_message = $_SESSION['success'];
                unset($_SESSION['success']);
            }
            if (isset($_SESSION['error'])) {
                $page_error_message = $_SESSION['error'];
                unset($_SESSION['error']);
            }
        ?>

        <!-- Filter and Create Section -->
        <div class="dashboard-card">
            <div class="card-header">
                <div style="display: flex; align-items: center; gap: 15px;">
                    <i class="fas fa-bullhorn"></i>
                    <h3 style="margin: 0;">Announcement Management</h3>
                </div>
            </div>
            
            <form method="GET" action="">
                <div class="row">
                    <div class="col-md-3">
                        <label class="form-label">Target Audience</label>
                        <select name="target_audience" class="form-control">
                            <option value="">All Audiences</option>
                            <option value="students" <?php echo $target_audience == 'students' ? 'selected' : ''; ?>>Students</option>
                            <option value="teachers" <?php echo $target_audience == 'teachers' ? 'selected' : ''; ?>>Teachers</option>
                            <option value="registrar" <?php echo $target_audience == 'registrar' ? 'selected' : ''; ?>>Registrar</option>
                        </select>
                        <!-- Create Announcement Button directly below Target Audience -->
                        <div class="mt-3">
                            <button type="button" class="btn btn-create w-100" style="color: #fff;" data-bs-toggle="modal" data-bs-target="#createAnnouncementModal">
                                <i class="fas fa-plus me-2"></i>Create Announcement
                            </button>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Priority</label>
                        <select name="priority" class="form-control">
                            <option value="">All Priorities</option>
                            <option value="urgent" <?php echo $priority == 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                            <option value="high" <?php echo $priority == 'high' ? 'selected' : ''; ?>>High</option>
                            <option value="medium" <?php echo $priority == 'medium' ? 'selected' : ''; ?>>Medium</option>
                            <option value="low" <?php echo $priority == 'low' ? 'selected' : ''; ?>>Low</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">From Date</label>
                        <input type="date" name="date_from" class="form-control" 
                               value="<?php echo htmlspecialchars($date_from); ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">To Date</label>
                        <input type="date" name="date_to" class="form-control" 
                               value="<?php echo htmlspecialchars($date_to); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">&nbsp;</label>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn w-100" style="background-color: #800000; color: #fff; font-weight: 600;">
                                <i class="fas fa-filter me-2"></i>Filter
                            </button>
                            <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-outline-secondary">
                                <i class="fas fa-times"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <!-- Announcements Grid -->
        <?php if (count($announcements) > 0): ?>
            <div class="announcements-grid">
                <?php foreach ($announcements as $announcement): ?>
                    <div class="announcement-card <?php echo $announcement['priority']; ?> <?php echo !$announcement['is_active'] ? 'archived' : ''; ?>">
                        <div class="announcement-header">
                            <h5 class="announcement-title"><?php echo htmlspecialchars($announcement['title']); ?></h5>
                            <div class="announcement-badges">
                                <span class="priority-badge priority-<?php echo $announcement['priority']; ?>">
                                    <?php echo ucfirst($announcement['priority']); ?>
                                </span>
                                <span class="audience-badge">
                                    <?php echo ucfirst($announcement['audience']); ?>
                                </span>
                                <?php if (!$announcement['is_active']): ?>
                                <span class="audience-badge" style="background: #6c757d; margin-left: auto;">
                                    <i class="fas fa-archive me-1"></i>Archived
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="announcement-content">
                            <?php echo htmlspecialchars(substr($announcement['content'], 0, 200)) . (strlen($announcement['content']) > 200 ? '...' : ''); ?>
                        </div>
                        
                        <div class="announcement-meta">
                            <span class="announcement-author">
                                <i class="fas fa-user me-1"></i>
                                <?php echo htmlspecialchars($announcement['author']); ?>
                            </span>
                            <span class="announcement-date">
                                <i class="fas fa-calendar me-1"></i>
                                <?php echo date('M d, Y', strtotime($announcement['date'])); ?>
                            </span>
                        </div>
                        
                        <div class="announcement-actions">
                            <button type="button" class="btn btn-action btn-edit" 
                                    onclick="editAnnouncement(<?php echo htmlspecialchars(json_encode($announcement)); ?>)"
                                    data-bs-toggle="modal" data-bs-target="#editAnnouncementModal">
                                <i class="fas fa-edit me-1"></i>Edit
                            </button>
                            <button type="button" class="btn btn-action btn-delete" 
                                    onclick="deleteAnnouncement(<?php echo $announcement['id']; ?>, '<?php echo htmlspecialchars($announcement['title']); ?>')">
                                <i class="fas fa-trash me-1"></i>Delete
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="card content-card">
                <div class="card-body">
                    <div class="empty-state">
                        <i class="fas fa-bullhorn"></i>
                        <h4>No announcements found</h4>
                        <p>Create your first announcement to get started.</p>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Create Announcement Modal -->
    <div class="modal fade" id="createAnnouncementModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-plus-circle me-2"></i>Create New Announcement
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" id="createAnnouncementForm">
                        <input type="hidden" name="action" value="create">
                        <div class="mb-3">
                            <label for="create_title" class="form-label">Title</label>
                            <input type="text" class="form-control form-control-custom" id="create_title" 
                                   name="title" required maxlength="200">
                        </div>
                        <div class="mb-3">
                            <label for="create_content" class="form-label">Content</label>
                            <textarea class="form-control form-control-custom" id="create_content" 
                                      name="content" rows="5" required></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="create_target_audience" class="form-label">Target Audience</label>
                                <select class="form-control form-control-custom" id="create_target_audience" 
                                        name="target_audience" required>
                                    <option value="all">All</option>
                                    <option value="students">Students</option>
                                    <option value="teachers">Teachers</option>
                                    <option value="registrar">Registrar</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="create_priority" class="form-label">Priority</label>
                                <select class="form-control form-control-custom" id="create_priority" 
                                        name="priority" required>
                                    <option value="low">Low</option>
                                    <option value="medium" selected>Medium</option>
                                    <option value="high">High</option>
                                    <option value="urgent">Urgent</option>
                                </select>
                            </div>
                        </div>
                        <div class="text-end">
                            <button type="button" class="btn btn-secondary me-2" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary-custom">
                                <i class="fas fa-save me-2"></i>Create Announcement
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Announcement Modal -->
    <div class="modal fade" id="editAnnouncementModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-edit me-2"></i>Edit Announcement
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" id="editAnnouncementForm">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="announcement_id" id="edit_announcement_id">
                        <div class="mb-3">
                            <label for="edit_title" class="form-label">Title</label>
                            <input type="text" class="form-control form-control-custom" id="edit_title" 
                                   name="title" required maxlength="200">
                        </div>
                        <div class="mb-3">
                            <label for="edit_content" class="form-label">Content</label>
                            <textarea class="form-control form-control-custom" id="edit_content" 
                                      name="content" rows="5" required></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="edit_target_audience" class="form-label">Target Audience</label>
                                <select class="form-control form-control-custom" id="edit_target_audience" 
                                        name="target_audience" required>
                                    <option value="all">All</option>
                                    <option value="students">Students</option>
                                    <option value="teachers">Teachers</option>
                                    <option value="registrar">Registrar</option>
                                    <option value="admins">Admins</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="edit_priority" class="form-label">Priority</label>
                                <select class="form-control form-control-custom" id="edit_priority" 
                                        name="priority" required>
                                    <option value="low">Low</option>
                                    <option value="medium" selected>Medium</option>
                                    <option value="high">High</option>
                                    <option value="urgent">Urgent</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="text-end">
                            <button type="button" class="btn btn-secondary me-2" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary-custom">
                                <i class="fas fa-save me-2"></i>Update Announcement
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <form method="POST" id="deleteForm" style="display: none;">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="announcement_id" id="delete_announcement_id">
    </form>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const successMessage = <?php echo json_encode($page_success_message); ?>;
            const errorMessage = <?php echo json_encode($page_error_message); ?>;
            if (successMessage) {
                showModernAlert('success', 'Success', successMessage, { autoCloseMs: 3000 });
            } else if (errorMessage) {
                showModernAlert('error', 'Error', errorMessage);
            }
        });

        // Edit announcement function
        function editAnnouncement(announcement) {
            document.getElementById('edit_announcement_id').value = announcement.id;
            document.getElementById('edit_title').value = announcement.title;
            document.getElementById('edit_content').value = announcement.content;
            document.getElementById('edit_target_audience').value = announcement.audience;
            document.getElementById('edit_priority').value = announcement.priority;
        }
        
        // Delete announcement function
        function deleteAnnouncement(announcementId, announcementTitle) {
            document.getElementById('delete_announcement_id').value = announcementId;
            showModernConfirm(
                'Delete Announcement?',
                `Are you sure you want to delete "${announcementTitle}"? This action cannot be undone.`,
                function() {
                    document.getElementById('deleteForm').submit();
                },
                { confirmText: 'Delete' }
            );
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            // Add animation to announcement cards
            const cards = document.querySelectorAll('.announcement-card');
            cards.forEach((card, index) => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                
                setTimeout(() => {
                    card.style.transition = 'all 0.6s ease';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 100);
            });
        });
        
        // Form validation
        document.getElementById('createAnnouncementForm').addEventListener('submit', function(e) {
            const title = document.getElementById('create_title').value.trim();
            const content = document.getElementById('create_content').value.trim();
            
            if (title.length < 5) {
                e.preventDefault();
                showModernAlert('warning', 'Validation Required', 'Title must be at least 5 characters long.');
                return false;
            }
            
            if (content.length < 10) {
                e.preventDefault();
                showModernAlert('warning', 'Validation Required', 'Content must be at least 10 characters long.');
                return false;
            }
        });
    </script>
</body>
</html>