<?php
session_start();

if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

require_once '../includes/db_connection.php';
require_once '../includes/modern_alert_system.php';

$curriculum_id = (int)($_GET['id'] ?? 0);

if ($curriculum_id <= 0) {
    $_SESSION['error'] = "Invalid curriculum selected.";
    header("Location: curriculum_management.php");
    exit();
}

// Fetch curriculum details
$curr_stmt = $conn->prepare("
    SELECT c.*, s.subject_name, u.first_name, u.last_name
    FROM curricula c
    LEFT JOIN subjects s ON c.subject_id = s.subject_id
    LEFT JOIN users u ON c.created_by = u.user_id
    WHERE c.curriculum_id = ?
    LIMIT 1
");
$curr_stmt->bind_param("i", $curriculum_id);
$curr_stmt->execute();
$curriculum = $curr_stmt->get_result()->fetch_assoc();
$curr_stmt->close();

if (!$curriculum) {
    $_SESSION['error'] = "Curriculum not found.";
    header("Location: curriculum_management.php");
    exit();
}

// Fetch learning outcomes
$outcomes = $conn->query(
    "SELECT * FROM curriculum_learning_outcomes 
     WHERE curriculum_id = $curriculum_id 
     ORDER BY sequence_number ASC"
)->fetch_all(MYSQLI_ASSOC);

// Fetch units
$units = $conn->query(
    "SELECT * FROM curriculum_units 
     WHERE curriculum_id = $curriculum_id 
     ORDER BY sequence_number ASC"
)->fetch_all(MYSQLI_ASSOC);

// Fetch resources
$resources = $conn->query(
    "SELECT * FROM curriculum_resources 
     WHERE curriculum_id = $curriculum_id 
     ORDER BY sequence_number ASC"
)->fetch_all(MYSQLI_ASSOC);

// Fetch assessments
$assessments = $conn->query(
    "SELECT * FROM curriculum_assessments 
     WHERE curriculum_id = $curriculum_id 
     ORDER BY sequence_number ASC"
)->fetch_all(MYSQLI_ASSOC);

// Fetch audit log
$audit_log = $conn->query(
    "SELECT cal.*, u.first_name, u.last_name
     FROM curriculum_audit_log cal
     LEFT JOIN users u ON cal.changed_by = u.user_id
     WHERE cal.curriculum_id = $curriculum_id
     ORDER BY cal.changed_at DESC
     LIMIT 20"
)->fetch_all(MYSQLI_ASSOC);

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'add_learning_outcome':
            $title = $_POST['outcome_title'] ?? '';
            $description = $_POST['outcome_description'] ?? '';
            $bloom_level = $_POST['bloom_level'] ?? 'understand';

            if (empty($title)) {
                $_SESSION['error'] = "Learning outcome title is required.";
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
            } else {
                $_SESSION['error'] = "Error adding learning outcome: " . $conn->error;
            }
            $stmt->close();
            break;

        case 'delete_learning_outcome':
            $outcome_id = (int)($_POST['outcome_id'] ?? 0);
            $stmt = $conn->prepare("DELETE FROM curriculum_learning_outcomes WHERE outcome_id = ? AND curriculum_id = ?");
            $stmt->bind_param("ii", $outcome_id, $curriculum_id);
            if ($stmt->execute()) {
                $_SESSION['success'] = "Learning outcome deleted successfully!";
            } else {
                $_SESSION['error'] = "Error deleting learning outcome.";
            }
            $stmt->close();
            break;

        case 'add_unit':
            $title = $_POST['unit_title'] ?? '';
            $description = $_POST['unit_description'] ?? '';
            $duration_hours = !empty($_POST['duration_hours']) ? (float)$_POST['duration_hours'] : null;

            if (empty($title)) {
                $_SESSION['error'] = "Unit title is required.";
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
            } else {
                $_SESSION['error'] = "Error adding unit: " . $conn->error;
            }
            $stmt->close();
            break;

        case 'delete_unit':
            $unit_id = (int)($_POST['unit_id'] ?? 0);
            $stmt = $conn->prepare("DELETE FROM curriculum_units WHERE unit_id = ? AND curriculum_id = ?");
            $stmt->bind_param("ii", $unit_id, $curriculum_id);
            if ($stmt->execute()) {
                $_SESSION['success'] = "Unit deleted successfully!";
            } else {
                $_SESSION['error'] = "Error deleting unit.";
            }
            $stmt->close();
            break;

        case 'add_resource':
            $resource_type = $_POST['resource_type'] ?? 'reference';
            $title = $_POST['resource_title'] ?? '';
            $description = $_POST['resource_description'] ?? '';
            $url = $_POST['resource_url'] ?? '';
            $author = $_POST['resource_author'] ?? '';

            if (empty($title)) {
                $_SESSION['error'] = "Resource title is required.";
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
            } else {
                $_SESSION['error'] = "Error adding resource: " . $conn->error;
            }
            $stmt->close();
            break;

        case 'delete_resource':
            $resource_id = (int)($_POST['resource_id'] ?? 0);
            $stmt = $conn->prepare("DELETE FROM curriculum_resources WHERE resource_id = ? AND curriculum_id = ?");
            $stmt->bind_param("ii", $resource_id, $curriculum_id);
            if ($stmt->execute()) {
                $_SESSION['success'] = "Resource deleted successfully!";
            } else {
                $_SESSION['error'] = "Error deleting resource.";
            }
            $stmt->close();
            break;

        case 'publish':
            $stmt = $conn->prepare("UPDATE curricula SET status = 'active' WHERE curriculum_id = ?");
            $stmt->bind_param("i", $curriculum_id);
            if ($stmt->execute()) {
                $_SESSION['success'] = "Curriculum published successfully!";
            } else {
                $_SESSION['error'] = "Error publishing curriculum.";
            }
            $stmt->close();
            break;

        case 'archive':
            $stmt = $conn->prepare("UPDATE curricula SET status = 'archived' WHERE curriculum_id = ?");
            $stmt->bind_param("i", $curriculum_id);
            if ($stmt->execute()) {
                $_SESSION['success'] = "Curriculum archived successfully!";
            } else {
                $_SESSION['error'] = "Error archiving curriculum.";
            }
            $stmt->close();
            break;
    }

    header("Location: " . $_SERVER['PHP_SELF'] . "?id=$curriculum_id");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Curriculum Details - <?php echo htmlspecialchars($curriculum['title']); ?></title>
    
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
            padding: 30px 40px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(128, 0, 0, 0.2);
        }

        .header-section h1 {
            font-weight: 700;
            font-size: 2.2rem;
            margin: 0 0 10px 0;
        }

        .breadcrumb-custom {
            background: transparent;
            padding: 0;
            margin: 10px 0 0 0;
        }

        .breadcrumb-custom a {
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
        }

        .breadcrumb-custom a:hover {
            color: white;
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

        .item-card {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 10px;
            transition: all 0.3s ease;
        }

        .item-card:hover {
            background: white;
            border-color: var(--primary-color);
            box-shadow: 0 3px 10px rgba(128, 0, 0, 0.1);
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
        }

        .nav-tabs-custom .nav-link.active {
            background: var(--primary-color);
            color: white;
            border-radius: 10px 10px 0 0;
        }

        .form-control, .form-select {
            border: 2px solid #e9ecef;
            border-radius: 8px;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(128, 0, 0, 0.25);
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 15px;
            }

            .header-section h1 {
                font-size: 1.8rem;
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
            <a href="curriculum_management.php" class="breadcrumb-custom">
                <i class="fas fa-arrow-left me-2"></i>Back to Curricula
            </a>
            <h1><?php echo htmlspecialchars($curriculum['title']); ?></h1>
            <div style="margin-top: 15px;">
                <span class="status-badge status-<?php echo $curriculum['status']; ?>">
                    <?php echo ucfirst($curriculum['status']); ?>
                </span>
                <span style="margin-left: 15px; opacity: 0.9;">
                    Version <?php echo htmlspecialchars($curriculum['version']); ?>
                </span>
            </div>
        </div>

        <!-- Alerts -->
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Action Buttons -->
        <div class="dashboard-card" style="display: flex; justify-content: space-between; align-items: center; gap: 15px;">
            <div>
                <strong>Subject:</strong> <?php echo htmlspecialchars($curriculum['subject_name'] ?? 'Not assigned'); ?><br>
                <strong>Created by:</strong> <?php echo htmlspecialchars(($curriculum['first_name'] ?? '') . ' ' . ($curriculum['last_name'] ?? '')); ?><br>
                <strong>Created:</strong> <?php echo date('M d, Y H:i', strtotime($curriculum['created_at'])); ?>
            </div>
            <div style="display: flex; gap: 10px; flex-wrap: wrap; justify-content: flex-end;">
                <?php if ($curriculum['status'] === 'draft'): ?>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="publish">
                        <button type="submit" class="btn btn-success btn-sm">
                            <i class="fas fa-check-circle me-1"></i>Publish
                        </button>
                    </form>
                <?php endif; ?>

                <?php if ($curriculum['status'] !== 'archived'): ?>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="archive">
                        <button type="submit" class="btn btn-warning btn-sm" onclick="return confirm('Archive this curriculum?');">
                            <i class="fas fa-archive me-1"></i>Archive
                        </button>
                    </form>
                <?php endif; ?>

                <a href="#" class="btn btn-info btn-sm" onclick="generatePDF(); return false;">
                    <i class="fas fa-file-pdf me-1"></i>Export PDF
                </a>
            </div>
        </div>

        <!-- Tabs -->
        <ul class="nav nav-tabs nav-tabs-custom" id="curriculumDetailTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="overview-tab" data-bs-toggle="tab" data-bs-target="#overview" type="button">
                    <i class="fas fa-info-circle me-2"></i>Overview
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="outcomes-tab" data-bs-toggle="tab" data-bs-target="#outcomes" type="button">
                    <i class="fas fa-bullseye me-2"></i>Learning Outcomes (<?php echo count($outcomes); ?>)
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="units-tab" data-bs-toggle="tab" data-bs-target="#units" type="button">
                    <i class="fas fa-layer-group me-2"></i>Units (<?php echo count($units); ?>)
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="resources-tab" data-bs-toggle="tab" data-bs-target="#resources" type="button">
                    <i class="fas fa-book me-2"></i>Resources (<?php echo count($resources); ?>)
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="assessments-tab" data-bs-toggle="tab" data-bs-target="#assessments" type="button">
                    <i class="fas fa-clipboard-check me-2"></i>Assessments (<?php echo count($assessments); ?>)
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="history-tab" data-bs-toggle="tab" data-bs-target="#history" type="button">
                    <i class="fas fa-history me-2"></i>History
                </button>
            </li>
        </ul>

        <div class="tab-content" id="curriculumDetailTabContent">
            <!-- Overview Tab -->
            <div class="tab-pane fade show active" id="overview" role="tabpanel">
                <div class="dashboard-card">
                    <h3 style="color: var(--primary-color); margin-bottom: 15px;">Curriculum Overview</h3>
                    <p style="color: #6c757d; line-height: 1.6;">
                        <?php echo nl2br(htmlspecialchars($curriculum['description'] ?? 'No description provided')); ?>
                    </p>
                </div>
            </div>

            <!-- Learning Outcomes Tab -->
            <div class="tab-pane fade" id="outcomes" role="tabpanel">
                <div class="dashboard-card">
                    <h3 style="color: var(--primary-color); margin-bottom: 20px;">Learning Outcomes & Competencies</h3>

                    <form method="POST" class="mb-4">
                        <input type="hidden" name="action" value="add_learning_outcome">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Outcome Title *</label>
                                <input type="text" class="form-control" name="outcome_title" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Bloom's Level</label>
                                <select class="form-select" name="bloom_level">
                                    <option value="remember">Remember</option>
                                    <option value="understand" selected>Understand</option>
                                    <option value="apply">Apply</option>
                                    <option value="analyze">Analyze</option>
                                    <option value="evaluate">Evaluate</option>
                                    <option value="create">Create</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="outcome_description" rows="3"></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary-custom">
                            <i class="fas fa-plus me-2"></i>Add Outcome
                        </button>
                    </form>

                    <hr>

                    <div class="outcomes-list">
                        <?php if (empty($outcomes)): ?>
                            <p style="text-align: center; color: #6c757d;">No learning outcomes yet. Add one using the form above.</p>
                        <?php else: ?>
                            <?php foreach ($outcomes as $outcome): ?>
                                <div class="item-card">
                                    <div style="display: flex; justify-content: space-between; align-items: start;">
                                        <div style="flex: 1;">
                                            <h5 style="margin: 0 0 5px 0; color: var(--primary-color);">
                                                <?php echo htmlspecialchars($outcome['title']); ?>
                                            </h5>
                                            <small style="color: #6c757d;">
                                                <strong>Bloom's Level:</strong> <?php echo ucfirst($outcome['bloom_level']); ?>
                                            </small>
                                            <?php if (!empty($outcome['description'])): ?>
                                                <p style="margin: 8px 0 0 0; color: #6c757d; font-size: 0.9rem;">
                                                    <?php echo htmlspecialchars($outcome['description']); ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="delete_learning_outcome">
                                            <input type="hidden" name="outcome_id" value="<?php echo $outcome['outcome_id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this outcome?');">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Units Tab -->
            <div class="tab-pane fade" id="units" role="tabpanel">
                <div class="dashboard-card">
                    <h3 style="color: var(--primary-color); margin-bottom: 20px;">Learning Units & Topics</h3>

                    <form method="POST" class="mb-4">
                        <input type="hidden" name="action" value="add_unit">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Unit Title *</label>
                                <input type="text" class="form-control" name="unit_title" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Duration (Hours)</label>
                                <input type="number" class="form-control" name="duration_hours" step="0.5">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="unit_description" rows="3"></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary-custom">
                            <i class="fas fa-plus me-2"></i>Add Unit
                        </button>
                    </form>

                    <hr>

                    <div class="units-list">
                        <?php if (empty($units)): ?>
                            <p style="text-align: center; color: #6c757d;">No units yet. Add one using the form above.</p>
                        <?php else: ?>
                            <?php foreach ($units as $unit): ?>
                                <div class="item-card">
                                    <div style="display: flex; justify-content: space-between; align-items: start;">
                                        <div style="flex: 1;">
                                            <h5 style="margin: 0 0 5px 0; color: var(--primary-color);">
                                                <?php echo htmlspecialchars($unit['title']); ?>
                                            </h5>
                                            <?php if (!empty($unit['duration_hours'])): ?>
                                                <small style="color: #6c757d;">
                                                    <i class="fas fa-clock me-1"></i><?php echo $unit['duration_hours']; ?> hours
                                                </small>
                                            <?php endif; ?>
                                            <?php if (!empty($unit['description'])): ?>
                                                <p style="margin: 8px 0 0 0; color: #6c757d; font-size: 0.9rem;">
                                                    <?php echo htmlspecialchars($unit['description']); ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="delete_unit">
                                            <input type="hidden" name="unit_id" value="<?php echo $unit['unit_id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this unit?');">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Resources Tab -->
            <div class="tab-pane fade" id="resources" role="tabpanel">
                <div class="dashboard-card">
                    <h3 style="color: var(--primary-color); margin-bottom: 20px;">Teaching Resources & References</h3>

                    <form method="POST" class="mb-4">
                        <input type="hidden" name="action" value="add_resource">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Resource Title *</label>
                                <input type="text" class="form-control" name="resource_title" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Resource Type</label>
                                <select class="form-select" name="resource_type">
                                    <option value="textbook">Textbook</option>
                                    <option value="reference">Reference</option>
                                    <option value="online_resource">Online Resource</option>
                                    <option value="video">Video</option>
                                    <option value="worksheet">Worksheet</option>
                                    <option value="assessment_tool">Assessment Tool</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Author</label>
                                <input type="text" class="form-control" name="resource_author">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">URL</label>
                                <input type="url" class="form-control" name="resource_url" placeholder="https://...">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="resource_description" rows="3"></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary-custom">
                            <i class="fas fa-plus me-2"></i>Add Resource
                        </button>
                    </form>

                    <hr>

                    <div class="resources-list">
                        <?php if (empty($resources)): ?>
                            <p style="text-align: center; color: #6c757d;">No resources yet. Add one using the form above.</p>
                        <?php else: ?>
                            <?php foreach ($resources as $resource): ?>
                                <div class="item-card">
                                    <div style="display: flex; justify-content: space-between; align-items: start;">
                                        <div style="flex: 1;">
                                            <h5 style="margin: 0 0 5px 0; color: var(--primary-color);">
                                                <?php echo htmlspecialchars($resource['title']); ?>
                                            </h5>
                                            <small style="color: #6c757d;">
                                                <strong>Type:</strong> <?php echo ucfirst(str_replace('_', ' ', $resource['resource_type'])); ?>
                                            </small>
                                            <?php if (!empty($resource['author'])): ?>
                                                <small style="margin-left: 15px; color: #6c757d;">
                                                    <strong>Author:</strong> <?php echo htmlspecialchars($resource['author']); ?>
                                                </small>
                                            <?php endif; ?>
                                            <?php if (!empty($resource['url'])): ?>
                                                <br>
                                                <a href="<?php echo htmlspecialchars($resource['url']); ?>" target="_blank" style="font-size: 0.9rem;">
                                                    <i class="fas fa-link me-1"></i><?php echo htmlspecialchars($resource['url']); ?>
                                                </a>
                                            <?php endif; ?>
                                            <?php if (!empty($resource['description'])): ?>
                                                <p style="margin: 8px 0 0 0; color: #6c757d; font-size: 0.9rem;">
                                                    <?php echo htmlspecialchars($resource['description']); ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="delete_resource">
                                            <input type="hidden" name="resource_id" value="<?php echo $resource['resource_id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this resource?');">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Assessments Tab -->
            <div class="tab-pane fade" id="assessments" role="tabpanel">
                <div class="dashboard-card">
                    <h3 style="color: var(--primary-color); margin-bottom: 15px;">Assessment Methods</h3>
                    <p style="color: #6c757d;">Assessment methods are automatically configured based on the evaluation system.</p>
                    <p style="color: #6c757d; font-size: 0.9rem;">
                        Current system uses: Formative assessments during instruction and summative evaluations at the end of term.
                    </p>
                </div>
            </div>

            <!-- History Tab -->
            <div class="tab-pane fade" id="history" role="tabpanel">
                <div class="dashboard-card">
                    <h3 style="color: var(--primary-color); margin-bottom: 20px;">Change History & Audit Log</h3>

                    <?php if (empty($audit_log)): ?>
                        <p style="text-align: center; color: #6c757d;">No changes recorded yet.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead style="background: #f8f9fa;">
                                    <tr>
                                        <th>Action</th>
                                        <th>Details</th>
                                        <th>Changed By</th>
                                        <th>Date & Time</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($audit_log as $log): ?>
                                        <tr>
                                            <td>
                                                <span style="background: var(--primary-light); color: var(--primary-color); padding: 4px 8px; border-radius: 4px; font-size: 0.85rem; font-weight: 600;">
                                                    <?php echo ucfirst(str_replace('_', ' ', $log['action'])); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($log['details'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars(($log['first_name'] ?? '') . ' ' . ($log['last_name'] ?? '')); ?></td>
                                            <td><?php echo date('M d, Y H:i', strtotime($log['changed_at'])); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function generatePDF() {
            alert('PDF export feature will be available soon!');
        }
    </script>
</body>
</html>
