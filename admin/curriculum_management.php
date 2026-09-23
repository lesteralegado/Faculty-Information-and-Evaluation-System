<?php
session_start();

// Check admin privileges
if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

require_once '../includes/db_connection.php';
require_once '../includes/modern_alert_system.php';

// Check if curricula table exists (migration already ran)
$table_check = $conn->query("SHOW TABLES LIKE 'curricula'");
if ($table_check && $table_check->num_rows === 0) {
    throw new RuntimeException("Database tables not initialized. Apply the deployment schema repair before using Curriculum Management.");
}

// Get current school year/semester context
$current_ctx = ['school_year' => '', 'semester' => 1];
$ctx_stmt = $conn->prepare("SELECT school_year, semester FROM currentschoolyearandsemester LIMIT 1");
if ($ctx_stmt) {
    $ctx_stmt->execute();
    $ctx_res = $ctx_stmt->get_result();
    if ($ctx_res && $ctx_res->num_rows > 0) {
        $row = $ctx_res->fetch_assoc();
        $current_ctx['school_year'] = str_replace(['–', '—'], '-', $row['school_year']);
        $current_ctx['semester'] = (int)$row['semester'];
    }
    $ctx_stmt->close();
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'create_curriculum':
            $curriculum_code = $_POST['curriculum_code'] ?? '';
            $title = $_POST['title'] ?? '';
            $description = $_POST['description'] ?? '';
            $subject_id = !empty($_POST['subject_id']) ? (int)$_POST['subject_id'] : null;
            $version = $_POST['version'] ?? '1.0';

            if (empty($curriculum_code) || empty($title)) {
                $_SESSION['error'] = "Curriculum code and title are required.";
                break;
            }

            $stmt = $conn->prepare("
                INSERT INTO curricula 
                (curriculum_code, title, description, subject_id, school_year, semester, version, status, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'draft', ?)
            ");
            
            $user_id = $_SESSION['user_id'] ?? 1;
            $stmt->bind_param(
                "sssisisi",
                $curriculum_code,
                $title,
                $description,
                $subject_id,
                $current_ctx['school_year'],
                $current_ctx['semester'],
                $version,
                $user_id
            );

            if ($stmt->execute()) {
                $_SESSION['success'] = "Curriculum created successfully!";
                $new_curriculum_id = $conn->insert_id;
                
                // Log action
                logCurriculumAction($conn, $new_curriculum_id, 'create', 'New curriculum created', $user_id);
            } else {
                $_SESSION['error'] = "Error creating curriculum: " . $conn->error;
            }
            $stmt->close();
            break;

        case 'update_curriculum':
            $curriculum_id = (int)($_POST['curriculum_id'] ?? 0);
            $title = $_POST['title'] ?? '';
            $description = $_POST['description'] ?? '';
            $subject_id = !empty($_POST['subject_id']) ? (int)$_POST['subject_id'] : null;
            $status = $_POST['status'] ?? 'draft';

            if ($curriculum_id <= 0 || empty($title)) {
                $_SESSION['error'] = "Invalid curriculum or missing title.";
                break;
            }

            $stmt = $conn->prepare("
                UPDATE curricula 
                SET title = ?, description = ?, subject_id = ?, status = ?
                WHERE curriculum_id = ?
            ");
            
            $user_id = $_SESSION['user_id'] ?? 1;
            $stmt->bind_param("ssisi", $title, $description, $subject_id, $status, $curriculum_id);

            if ($stmt->execute()) {
                $_SESSION['success'] = "Curriculum updated successfully!";
                logCurriculumAction($conn, $curriculum_id, 'update', 'Curriculum details updated', $user_id);
            } else {
                $_SESSION['error'] = "Error updating curriculum: " . $conn->error;
            }
            $stmt->close();
            break;

        case 'add_learning_outcome':
            $curriculum_id = (int)($_POST['curriculum_id'] ?? 0);
            $title = $_POST['outcome_title'] ?? '';
            $description = $_POST['outcome_description'] ?? '';
            $bloom_level = $_POST['bloom_level'] ?? 'understand';

            if ($curriculum_id <= 0 || empty($title)) {
                $_SESSION['error'] = "Invalid curriculum or missing outcome title.";
                break;
            }

            $stmt = $conn->prepare("
                INSERT INTO curriculum_learning_outcomes 
                (curriculum_id, title, description, bloom_level)
                VALUES (?, ?, ?, ?)
            ");
            
            $stmt->bind_param("isss", $curriculum_id, $title, $description, $bloom_level);

            if ($stmt->execute()) {
                $_SESSION['success'] = "Learning outcome added successfully!";
                logCurriculumAction($conn, $curriculum_id, 'add_outcome', 'Learning outcome added', $_SESSION['user_id'] ?? 1);
            } else {
                $_SESSION['error'] = "Error adding learning outcome: " . $conn->error;
            }
            $stmt->close();
            break;

        case 'add_unit':
            $curriculum_id = (int)($_POST['curriculum_id'] ?? 0);
            $title = $_POST['unit_title'] ?? '';
            $description = $_POST['unit_description'] ?? '';
            $duration_hours = !empty($_POST['duration_hours']) ? (float)$_POST['duration_hours'] : null;

            if ($curriculum_id <= 0 || empty($title)) {
                $_SESSION['error'] = "Invalid curriculum or missing unit title.";
                break;
            }

            $stmt = $conn->prepare("
                INSERT INTO curriculum_units 
                (curriculum_id, title, description, duration_hours)
                VALUES (?, ?, ?, ?)
            ");
            
            $stmt->bind_param("issd", $curriculum_id, $title, $description, $duration_hours);

            if ($stmt->execute()) {
                $_SESSION['success'] = "Unit added successfully!";
                logCurriculumAction($conn, $curriculum_id, 'add_unit', 'Learning unit added', $_SESSION['user_id'] ?? 1);
            } else {
                $_SESSION['error'] = "Error adding unit: " . $conn->error;
            }
            $stmt->close();
            break;

        case 'add_resource':
            $curriculum_id = (int)($_POST['curriculum_id'] ?? 0);
            $resource_type = $_POST['resource_type'] ?? 'reference';
            $title = $_POST['resource_title'] ?? '';
            $description = $_POST['resource_description'] ?? '';
            $url = $_POST['resource_url'] ?? '';
            $author = $_POST['resource_author'] ?? '';

            if ($curriculum_id <= 0 || empty($title)) {
                $_SESSION['error'] = "Invalid curriculum or missing resource title.";
                break;
            }

            $stmt = $conn->prepare("
                INSERT INTO curriculum_resources 
                (curriculum_id, resource_type, title, description, url, author)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->bind_param("isssss", $curriculum_id, $resource_type, $title, $description, $url, $author);

            if ($stmt->execute()) {
                $_SESSION['success'] = "Resource added successfully!";
                logCurriculumAction($conn, $curriculum_id, 'add_resource', 'Teaching resource added', $_SESSION['user_id'] ?? 1);
            } else {
                $_SESSION['error'] = "Error adding resource: " . $conn->error;
            }
            $stmt->close();
            break;

        case 'publish_curriculum':
            $curriculum_id = (int)($_POST['curriculum_id'] ?? 0);
            
            if ($curriculum_id <= 0) {
                $_SESSION['error'] = "Invalid curriculum selected.";
                break;
            }

            $stmt = $conn->prepare("UPDATE curricula SET status = 'active' WHERE curriculum_id = ?");
            $stmt->bind_param("i", $curriculum_id);

            if ($stmt->execute()) {
                $_SESSION['success'] = "Curriculum published successfully!";
                logCurriculumAction($conn, $curriculum_id, 'publish', 'Curriculum published', $_SESSION['user_id'] ?? 1);
            } else {
                $_SESSION['error'] = "Error publishing curriculum: " . $conn->error;
            }
            $stmt->close();
            break;

        case 'archive_curriculum':
            $curriculum_id = (int)($_POST['curriculum_id'] ?? 0);
            
            if ($curriculum_id <= 0) {
                $_SESSION['error'] = "Invalid curriculum selected.";
                break;
            }

            $stmt = $conn->prepare("UPDATE curricula SET status = 'archived' WHERE curriculum_id = ?");
            $stmt->bind_param("i", $curriculum_id);

            if ($stmt->execute()) {
                $_SESSION['success'] = "Curriculum archived successfully!";
                logCurriculumAction($conn, $curriculum_id, 'archive', 'Curriculum archived', $_SESSION['user_id'] ?? 1);
            } else {
                $_SESSION['error'] = "Error archiving curriculum: " . $conn->error;
            }
            $stmt->close();
            break;
    }

    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Helper function to log curriculum actions
function logCurriculumAction($conn, $curriculum_id, $action, $details, $user_id) {
    $stmt = $conn->prepare("
        INSERT INTO curriculum_audit_log (curriculum_id, action, details, changed_by)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->bind_param("issi", $curriculum_id, $action, $details, $user_id);
    $stmt->execute();
    $stmt->close();
}

// Fetch all curricula
$curricula_sql = "SELECT c.*, s.subject_name, u.first_name, u.last_name
                  FROM curricula c
                  LEFT JOIN subjects s ON c.subject_id = s.subject_id
                  LEFT JOIN users u ON c.created_by = u.user_id
                  ORDER BY c.created_at DESC";
$curricula = $conn->query($curricula_sql)->fetch_all(MYSQLI_ASSOC);

// Fetch all subjects for dropdown
$subjects = $conn->query("SELECT subject_id, subject_name FROM subjects WHERE status = 'active' ORDER BY subject_name")->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Curriculum Management</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --primary-color: #800000;
            --primary-hover: #a00000;
            --primary-light: rgba(128, 0, 0, 0.1);
            --text-dark: #2c3e50;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
        }

        .main-content {
            margin-left: 250px;
            margin-top: 60px;
            padding: 30px;
        }

        .header-section {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-hover));
            color: white;
            padding: 40px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(128, 0, 0, 0.2);
        }

        .header-section h1 {
            font-weight: 700;
            font-size: 2.5rem;
            margin: 0;
        }

        .card-header {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-hover));
            color: white;
            border: none;
            padding: 20px;
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
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .curriculum-card {
            background: white;
            border: 1px solid #e9ecef;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 15px;
            transition: all 0.3s ease;
        }

        .curriculum-card:hover {
            box-shadow: 0 5px 20px rgba(128, 0, 0, 0.1);
            border-color: var(--primary-color);
        }

        .curriculum-code {
            display: inline-block;
            background: var(--primary-light);
            color: var(--primary-color);
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 0.85rem;
            font-weight: 600;
            margin-right: 10px;
        }

        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .status-draft { background: #ffeaa7; color: #d63031; }
        .status-active { background: #d4edda; color: #155724; }
        .status-archived { background: #e2e3e5; color: #383d41; }

        .form-label {
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 8px;
        }

        .form-control, .form-select {
            border: 2px solid #e9ecef;
            border-radius: 8px;
            padding: 10px 12px;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(128, 0, 0, 0.25);
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

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 15px;
            }

            .header-section h1 {
                font-size: 2rem;
            }
        }
    </style>
</head>
<body>
    <?php include '../includes/side_bar.php'; ?>
    <?php include '../includes/navbar.php'; ?>

    <div class="main-content">
        <!-- Header -->
        <div class="header-section">
            <h1><i class="fas fa-book me-3"></i>Curriculum Management</h1>
            <p style="margin-top: 10px; opacity: 0.9;">Create, manage, and publish curriculum for your subjects</p>
        </div>

        <!-- Alerts -->
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i>
                <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Tabs Navigation -->
        <ul class="nav nav-tabs nav-tabs-custom" id="curriculumTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="overview-tab" data-bs-toggle="tab" data-bs-target="#overview" type="button">
                    <i class="fas fa-list me-2"></i>All Curricula
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="create-tab" data-bs-toggle="tab" data-bs-target="#create" type="button">
                    <i class="fas fa-plus-circle me-2"></i>New Curriculum
                </button>
            </li>
        </ul>

        <div class="tab-content" id="curriculumTabContent">
            <!-- Overview Tab -->
            <div class="tab-pane fade show active" id="overview" role="tabpanel">
                <div class="dashboard-card">
                    <h2 style="color: var(--primary-color); margin-bottom: 20px;">
                        <i class="fas fa-book-open me-2"></i>Curricula Overview
                    </h2>

                    <?php if (empty($curricula)): ?>
                        <div style="text-align: center; padding: 40px; color: #6c757d;">
                            <i class="fas fa-inbox" style="font-size: 3rem; margin-bottom: 15px; opacity: 0.5;"></i>
                            <p>No curricula found. Create your first curriculum to get started.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table">
                                <thead style="background: #f8f9fa;">
                                    <tr>
                                        <th>Code</th>
                                        <th>Title</th>
                                        <th>Subject</th>
                                        <th>Version</th>
                                        <th>Status</th>
                                        <th>Created By</th>
                                        <th>Created</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($curricula as $c): ?>
                                        <tr>
                                            <td><span class="curriculum-code"><?php echo htmlspecialchars($c['curriculum_code']); ?></span></td>
                                            <td><?php echo htmlspecialchars($c['title']); ?></td>
                                            <td><?php echo htmlspecialchars($c['subject_name'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($c['version']); ?></td>
                                            <td>
                                                <span class="status-badge status-<?php echo $c['status']; ?>">
                                                    <?php echo ucfirst($c['status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')); ?></td>
                                            <td><?php echo date('M d, Y', strtotime($c['created_at'])); ?></td>
                                            <td>
                                                <button class="btn btn-sm btn-outline-primary" onclick="viewCurriculum(<?php echo $c['curriculum_id']; ?>)" title="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Create Curriculum Tab -->
            <div class="tab-pane fade" id="create" role="tabpanel">
                <div class="dashboard-card">
                    <h2 style="color: var(--primary-color); margin-bottom: 20px;">
                        <i class="fas fa-pen-fancy me-2"></i>Create New Curriculum
                    </h2>

                    <form method="POST" action="">
                        <input type="hidden" name="action" value="create_curriculum">

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Curriculum Code *</label>
                                <input type="text" class="form-control" name="curriculum_code" required placeholder="e.g., MATH-101">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Version</label>
                                <input type="text" class="form-control" name="version" value="1.0" placeholder="1.0">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Title *</label>
                            <input type="text" class="form-control" name="title" required placeholder="Curriculum Title">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Subject (Optional)</label>
                            <select class="form-select" name="subject_id">
                                <option value="">Select a subject</option>
                                <?php foreach ($subjects as $subj): ?>
                                    <option value="<?php echo $subj['subject_id']; ?>">
                                        <?php echo htmlspecialchars($subj['subject_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" rows="4" placeholder="Curriculum description and objectives..."></textarea>
                        </div>

                        <button type="submit" class="btn btn-primary-custom">
                            <i class="fas fa-save me-2"></i>Create Curriculum
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function viewCurriculum(curriculumId) {
            window.location.href = 'curriculum_details.php?id=' + curriculumId;
        }
    </script>
</body>
</html>
