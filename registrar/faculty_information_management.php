<?php
session_start();

// Check if user is logged in and is registrar or admin
if (!isset($_SESSION['username']) || !in_array($_SESSION['role'], ['registrar', 'admin'])) {
    header("Location: /index.php");
    exit();
}

// Include database connection
require_once __DIR__ . '/../includes/db_connection.php';
require_once __DIR__ . '/../includes/modern_alert_system.php';

// Get user information
$userId = $_SESSION['user_id']; // Assuming you store user_id in session
$stmt = $conn->prepare("SELECT u.*, f.registrar_id as registrar_id FROM users u 
                        LEFT JOIN registrar f ON u.user_id = f.user_id 
                        WHERE u.user_id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
$user_data = $result->fetch_assoc();
$registrar_id = $user_data['registrar_id'];


// Get filter parameters
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$credential_filter = isset($_GET['credential_type']) ? $_GET['credential_type'] : '';
$search = isset($_GET['search']) ? $_GET['search'] : '';

// Build WHERE clause
$where_conditions = ["1=1"];
$params = [];
$types = "";

if (!empty($status_filter)) {
    $where_conditions[] = "cr.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if (!empty($credential_filter)) {
    $where_conditions[] = "cr.credential_type = ?";
    $params[] = $credential_filter;
    $types .= "s";
}

if (!empty($search)) {
    $where_conditions[] = "(CONCAT(u.first_name, ' ', u.last_name) LIKE ? OR cr.credential_type LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= "ss";
}

$where_clause = implode(" AND ", $where_conditions);

// Get all credential requests with student information
$requests_sql = "SELECT cr.*, 
                        CONCAT(u.first_name, ' ', u.last_name) as student_name,
                        u.account_number as student_number, s.strand, s.year_level,
                        CONCAT(pu.first_name, ' ', pu.last_name) as processed_by_name
                 FROM credentials_requests cr
                 JOIN students s ON cr.student_id = s.student_id
                 JOIN users u ON s.user_id = u.user_id
                 LEFT JOIN registrar f ON cr.processed_by = f.registrar_id
                 LEFT JOIN users pu ON f.user_id = pu.user_id
                 WHERE $where_clause
                 ORDER BY cr.date_requested DESC";

$requests_stmt = $conn->prepare($requests_sql);
if (!empty($params)) {
    $requests_stmt->bind_param($types, ...$params);
}
$requests_stmt->execute();
$requests_result = $requests_stmt->get_result();
$all_requests = $requests_result->fetch_all(MYSQLI_ASSOC);

$stats_sql = "SELECT 
                COUNT(*) as total_requests,
                SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN status = 'Processing' THEN 1 ELSE 0 END) as processing_count,
                SUM(CASE WHEN status = 'Releasing' THEN 1 ELSE 0 END) as releasing_count,
                SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) as completed_count,
                SUM(CASE WHEN status = 'Declined' THEN 1 ELSE 0 END) as declined_count
              FROM credentials_requests";
$stats_result = $conn->query($stats_sql);
$stats = $stats_result->fetch_assoc();

$stmt->close();
$requests_stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="/images/school-logo.png" type="image/png">
    <title>Faculty Information Management</title>
    
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
            font-weight: 900;
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
        
        .status-badge {
            padding: 8px 14px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-pending {
            background: #fff3cd;
            color: #856404;
        }
        
        .status-processing {
            background: #cce5ff;
            color: #004085;
        }
        
        .status-releasing {
            background: #d4edda;
            color: #155724;
        }

        .status-completed {
            background: #c3e6cb;
            color: #0f3622;
        }
        
        .status-declined {
            background: #f8d7da;
            color: #721c24;
        }
        
        .table {
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
        }
        
        .table thead th {
            background: var(--primary-color);
            color: white;
            border: none;
            font-weight: 600;
            padding: 15px;
        }
        
        .table tbody td {
            padding: 15px;
            vertical-align: middle;
            border-color: #f8f9fa;
        }
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 0.875rem;
            border-radius: 6px;
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
            justify-content: center;
        }
        
        .btn-action {
            width: 35px;
            height: 35px;
            border-radius: 8px;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }
        
        .btn-view {
            background: #e8e9ff;
            color: var(--primary-color);
        }
        
        .btn-view:hover {
            background: var(--primary-color);
            color: white;
            transform: scale(1.05);
        }
        
        .btn-edit {
            background: #e8e9ff;
            color: var(--primary-color);
        }
        
        .btn-edit:hover {
            background: var(--primary-color);
            color: white;
            transform: scale(1.05);
        }
        
        .form-control, .form-select {
            border: 2px solid #e9ecef;
            border-radius: 8px;
            padding: 8px 12px;
            accent-color: var(--primary-color);
        }
        
        .form-control:focus, .form-select:focus,
        input.form-control:focus, select.form-select:focus,
        textarea.form-control:focus {
            outline: none !important;
            border-color: var(--primary-color) !important;
            box-shadow: 0 0 0 0.25rem rgba(128, 0, 0, 0.25) !important;
        }

        /* Modal Styling */
        .modal-content {
            border-radius: 15px;
            border: none;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }

        .modal-header {
            border-radius: 15px 15px 0 0;
            border-bottom: 1px solid #dee2e6;
            padding: 20px 25px;
            background-color: var(--primary-color);
            color: white;
        }

        .modal-body {
            padding: 25px;
        }

        .modal-footer {
            border-top: 1px solid #dee2e6;
            padding: 20px 25px;
            border-radius: 0 0 15px 15px;
        }

        .modal-title {
            font-weight: 600;
            font-size: 1.25rem;
        }

        /* Confirmation Modal */
        .confirmation-modal .modal-header {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-hover));
        }

        .confirmation-modal .modal-body {
            padding: 30px 25px;
            text-align: center;
        }

        .confirmation-modal .modal-body i {
            font-size: 3rem;
            margin-bottom: 20px;
        }

        .confirmation-modal .modal-body h5 {
            color: var(--primary-color);
            font-weight: 600;
            margin-bottom: 15px;
        }

        .confirmation-modal .btn-confirm {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-hover));
            border: none;
            color: white;
            padding: 10px 25px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .confirmation-modal .btn-confirm:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(128, 0, 0, 0.3);
            color: white;
        }

        .btn-close-white {
            filter: brightness(0) invert(1);
        }

        .alert {
            border: none;
            border-radius: 10px;
            border-left: 4px solid;
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
            <h1><i class="fas fa-clipboard-list me-3"></i>Faculty Information Management</h1>
            
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['total_requests']; ?></div>
                    <div class="stat-label">Total Requests</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['pending_count']; ?></div>
                    <div class="stat-label">Pending</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['processing_count']; ?></div>
                    <div class="stat-label">Processing</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['releasing_count']; ?></div>
                    <div class="stat-label">Releasing</div>
                </div>
                <!-- Added Completed status stat -->
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['completed_count']; ?></div>
                    <div class="stat-label">Completed</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['declined_count']; ?></div>
                    <div class="stat-label">Declined</div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="dashboard-card">
            <div class="card-header">
                <i class="fas fa-filter"></i>
                <h3>Filter Requests</h3>
            </div>
            
            <form method="GET" action="">
                <div class="row">
                    <div class="col-md-3">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="">All Statuses</option>
                            <option value="Pending" <?php echo $status_filter === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="Processing" <?php echo $status_filter === 'Processing' ? 'selected' : ''; ?>>Processing</option>
                            <option value="Releasing" <?php echo $status_filter === 'Releasing' ? 'selected' : ''; ?>>Releasing</option>
                            <!-- Added Completed status option -->
                            <option value="Completed" <?php echo $status_filter === 'Completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="Declined" <?php echo $status_filter === 'Declined' ? 'selected' : ''; ?>>Declined</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Credential Type</label>
                        <select name="credential_type" class="form-select">
                            <option value="">All Types</option>
                            <option value="Registration Form" <?php echo $credential_filter === 'Registration Form' ? 'selected' : ''; ?>>Registration Form</option>
                            <option value="Certifications" <?php echo $credential_filter === 'Certifications' ? 'selected' : ''; ?>>Certifications</option>
                            <option value="Report Card" <?php echo $credential_filter === 'Report Card' ? 'selected' : ''; ?>>Report Card</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Search Student</label>
                        <input type="text" name="search" class="form-control" placeholder="Student name..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn w-100" style="background-color: var(--primary-color); border-color: var(--primary-color); color: white;">
                            <i class="fas fa-search me-2"></i>Filter
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Requests Table -->
        <div class="dashboard-card">
            <div class="card-header">
                <i class="fas fa-table"></i>
                <h3>All Requests (<?php echo count($all_requests); ?>)</h3>
            </div>
            
            <?php if (count($all_requests) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Credential Type</th>
                                <th>Purpose</th>
                                <th>Status</th>
                                <th>Date Requested</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($all_requests as $request): ?>
                                <tr>
                                    <td>
                                        <div>
                                            <strong><?php echo htmlspecialchars($request['student_name']); ?></strong>
                                            <br>
                                            <small class="text-muted">
                                                <?php echo htmlspecialchars($request['student_number']); ?> | 
                                                <?php echo htmlspecialchars($request['strand']); ?> | 
                                                <?php echo htmlspecialchars($request['year_level']); ?>
                                            </small>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($request['credential_type']); ?></td>
                                    <td><?php echo htmlspecialchars($request['purpose']); ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo strtolower($request['status']); ?>">
                                            <?php echo htmlspecialchars($request['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M j, Y', strtotime($request['date_requested'])); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button type="button" class="btn btn-action btn-view" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#requestModal<?php echo $request['id']; ?>"
                                                    title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button type="button" class="btn btn-action btn-edit"
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#statusModal<?php echo $request['id']; ?>"
                                                    title="Edit Status">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-4">
                    <i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">No requests found</h5>
                    <p class="text-muted">No credential requests match your current filters.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Request Details Modals -->
    <?php foreach ($all_requests as $request): ?>
        <!-- Request Details Modal -->
        <div class="modal fade" id="requestModal<?php echo $request['id']; ?>" tabindex="-1" aria-labelledby="requestModalLabel<?php echo $request['id']; ?>" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="requestModalLabel<?php echo $request['id']; ?>">
                            <i class="fas fa-eye me-2"></i>Request Details
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6 class="mb-3" style="color: #800000; font-weight: 700;">
                                    <i class="fas fa-user me-2"></i>Student Information
                                </h6>
                                <p>
                                    <span style="color: #800000; font-weight: 600;">Name:</span>
                                    <?php echo htmlspecialchars($request['student_name']); ?>
                                </p>
                                <p>
                                    <span style="color: #800000; font-weight: 600;">Student Number:</span>
                                    <?php echo htmlspecialchars($request['student_number']); ?>
                                </p>
                                <p>
                                    <span style="color: #800000; font-weight: 600;">Strand:</span>
                                    <?php echo htmlspecialchars($request['strand']); ?>
                                </p>
                                <p>
                                    <span style="color: #800000; font-weight: 600;">Year Level:</span>
                                    <?php echo htmlspecialchars($request['year_level']); ?>
                                </p>
                            </div>
                            <div class="col-md-6">
                                <h6 class="mb-3" style="color: #800000; font-weight: 700;">
                                    <i class="fas fa-file-alt me-2"></i>Request Information
                                </h6>
                                <p>
                                    <span style="color: #800000; font-weight: 600;">Credential Type:</span>
                                    <?php echo htmlspecialchars($request['credential_type']); ?>
                                </p>
                                <p>
                                    <span style="color: #800000; font-weight: 600;">Purpose:</span>
                                    <?php echo htmlspecialchars($request['purpose']); ?>
                                </p>
                                <p>
                                    <span style="color: #800000; font-weight: 600;">Status:</span>
                                    <span class="status-badge status-<?php echo strtolower($request['status']); ?>">
                                        <?php echo htmlspecialchars($request['status']); ?>
                                    </span>
                                </p>
                            </div>
                        </div>
                        <hr>
                        <div class="row">
                            <div class="col-md-6">
                                <p>
                                    <strong style="color: #800000;">
                                        <i class="fas fa-calendar me-2"></i>Date Requested:
                                    </strong>
                                    <span style="white-space: nowrap; font-weight: 500;">
                                        <?php echo date('F j, Y g:i A', strtotime($request['date_requested'])); ?>
                                    </span>
                                </p>
                            </div>
                            <div class="col-md-6">
                                <?php if ($request['processed_by_name']): ?>
                                    <p>
                                        <strong style="color: #800000;">
                                            <i class="fas fa-user-check me-2"></i>Processed By:
                                        </strong>
                                        <?php echo htmlspecialchars($request['processed_by_name']); ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Status Update Modal -->
        <div class="modal fade" id="statusModal<?php echo $request['id']; ?>" tabindex="-1" aria-labelledby="statusModalLabel<?php echo $request['id']; ?>" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="statusModalLabel<?php echo $request['id']; ?>">
                            <i class="fas fa-edit me-2"></i>Update Status
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form method="POST" action="" id="statusForm<?php echo $request['id']; ?>">
                        <div class="modal-body">
                            <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>">
                            
                            <div class="mb-3">
                                <label class="form-label"><strong>Student:</strong></label>
                                <p class="mb-2"><?php echo htmlspecialchars($request['student_name']); ?></p>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label"><strong>Credential Type:</strong></label>
                                <p class="mb-2"><?php echo htmlspecialchars($request['credential_type']); ?></p>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label"><strong>Current Status:</strong></label>
                                <div class="mb-2">
                                    <span class="status-badge status-<?php echo strtolower($request['status']); ?>">
                                        <?php echo htmlspecialchars($request['status']); ?>
                                    </span>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="new_status_<?php echo $request['id']; ?>" class="form-label">
                                    <strong>New Status <span class="text-danger">*</span></strong>
                                </label>
                                <?php
                                // Status workflow progression - one step at a time
                                $statusWorkflow = [
                                    'Pending' => 'Processing',
                                    'Processing' => 'Releasing',
                                    'Releasing' => 'Completed',
                                    'Completed' => null,
                                    'Declined' => null
                                ];
                                $isTerminalStatus = in_array($request['status'], ['Completed', 'Declined']);
                                $nextStatus = $statusWorkflow[$request['status']] ?? null;
                                ?>
                                <select name="new_status" id="new_status_<?php echo $request['id']; ?>" class="form-select status-select" required data-modal-id="<?php echo $request['id']; ?>" data-current-status="<?php echo $request['status']; ?>" <?php echo $isTerminalStatus ? 'disabled' : ''; ?>>
                                    <option value="">Select new status...</option>
                                    <?php if ($nextStatus): ?>
                                        <option value="<?php echo $nextStatus; ?>"><?php echo $nextStatus; ?></option>
                                    <?php endif; ?>
                                    <?php if (!$isTerminalStatus && $request['status'] !== 'Releasing' && $request['status'] !== 'Processing'): ?>
                                        <option value="Declined">Declined</option>
                                    <?php endif; ?>
                                </select>
                                <?php if ($isTerminalStatus): ?>
                                    <small class="text-muted d-block mt-2">
                                        <i class="fas fa-lock me-1"></i>This request has reached its final state and cannot be modified.
                                    </small>
                                <?php endif; ?>
                                <div id="status_warning_<?php echo $request['id']; ?>" class="alert alert-warning mt-2" style="display: none;">
                                    <i class="fas fa-exclamation-circle me-2"></i>
                                    <strong>Cannot Revert:</strong> <span id="warning_text_<?php echo $request['id']; ?>"></span>
                                </div>
                            </div>

                            <!-- Decline Reason -->
                            <div class="mb-3 decline-reason-container" id="decline_reason_<?php echo $request['id']; ?>" style="display: none;">
                                <label for="decline_reason_input_<?php echo $request['id']; ?>" class="form-label">
                                    <strong>Reason for Decline <span class="text-danger">*</span></strong>
                                </label>
                                <textarea name="decline_reason" id="decline_reason_input_<?php echo $request['id']; ?>" class="form-control" 
                                          placeholder="Please provide a reason for declining this request..." rows="3"></textarea>
                                <small class="text-muted">This field is required when declining a request.</small>
                            </div>

                            <!-- Pickup Date -->
                            <div class="mb-3 pickup-date-container" id="pickup_date_<?php echo $request['id']; ?>" style="display: none;">
                                <label for="pickup_date_input_<?php echo $request['id']; ?>" class="form-label">
                                    <strong>Pickup Date <span class="text-danger">*</span></strong>
                                </label>
                                <input type="date" name="pickup_date" id="pickup_date_input_<?php echo $request['id']; ?>" class="form-control" />
                                <small class="text-muted">Set the date when the credential will be ready for pickup.</small>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="submit" name="update_status" class="btn status-update-btn" style="background-color: var(--primary-color); border-color: var(--primary-color); color: white;">
                                <i class="fas fa-save me-2"></i>Update Status
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        let currentForm = null;
        let currentRequestId = null;
        let currentNewStatus = null;
        let confirmInProgress = false;
        const registrarId = <?php echo json_encode($registrar_id); ?>;

        const statusHierarchy = {
            'Pending': 0,
            'Processing': 1,
            'Releasing': 2,
            'Completed': 3,
            'Declined': 3
        };

        const statusWorkflow = {
            'Pending': 'Processing',
            'Processing': 'Releasing',
            'Releasing': 'Completed',
            'Completed': null,
            'Declined': null
        };

        function canTransitionToStatus(currentStatus, newStatus) {
            // Can't change from terminal states (Declined or Completed)
            if (currentStatus === 'Declined' || currentStatus === 'Completed') {
                return false;
            }
            
            // Allow declining from any non-terminal status
            if (newStatus === 'Declined') {
                return true;
            }
            
            // For other statuses, must be the next status in the workflow
            const nextAllowedStatus = statusWorkflow[currentStatus];
            return newStatus === nextAllowedStatus;
        }

        document.querySelectorAll('.status-select').forEach(select => {
            select.addEventListener('change', function() {
                const modalId = this.getAttribute('data-modal-id');
                const currentStatus = this.getAttribute('data-current-status');
                const newStatus = this.value;
                const declineReasonContainer = document.getElementById(`decline_reason_${modalId}`);
                const pickupDateContainer = document.getElementById(`pickup_date_${modalId}`);
                const statusWarning = document.getElementById(`status_warning_${modalId}`);
                const warningText = document.getElementById(`warning_text_${modalId}`);

                // Reset visibility
                declineReasonContainer.style.display = 'none';
                pickupDateContainer.style.display = 'none';
                statusWarning.style.display = 'none';

                if (!newStatus) return;

                if (!canTransitionToStatus(currentStatus, newStatus)) {
                    statusWarning.style.display = 'block';
                    
                    if (currentStatus === 'Declined' || currentStatus === 'Completed') {
                        warningText.textContent = `Cannot update from ${currentStatus} status. This request has reached its final state.`;
                    } else {
                        const nextAllowed = statusWorkflow[currentStatus];
                        warningText.textContent = `You must update to "${nextAllowed}" first. Status changes must follow the workflow sequence: Pending → Processing → Releasing → Completed. You can decline at any step.`;
                    }
                    
                    this.value = '';
                    return;
                }

                // Show/hide decline reason textarea
                if (newStatus === 'Declined') {
                    declineReasonContainer.style.display = 'block';
                    document.getElementById(`decline_reason_input_${modalId}`).focus();
                }

                // Show/hide pickup date field
                if (newStatus === 'Releasing') {
                    pickupDateContainer.style.display = 'block';
                    const pickupDateInput = document.getElementById(`pickup_date_input_${modalId}`);
                    const todayDate = new Date();
                    pickupDateInput.value = todayDate.toISOString().split('T')[0];
                    pickupDateInput.focus();
                }
            });
        });

        // Handle status update form submissions
        document.querySelectorAll('form[method="POST"]').forEach(form => {
            form.addEventListener('submit', function(e) {
                e.preventDefault();

                const newStatusSelect = form.querySelector('select[name="new_status"]');
                const newStatus = newStatusSelect.value;
                const requestId = form.querySelector('input[name="request_id"]').value;
                const modalId = requestId;

                if (!newStatus) {
                    showErrorToast('Please select a new status before updating.');
                    return;
                }

                if (newStatus === 'Declined') {
                    const declineReasonInput = form.querySelector(`#decline_reason_input_${modalId}`);
                    if (!declineReasonInput || !declineReasonInput.value.trim()) {
                        showErrorToast('Please provide a reason for declining the request.');
                        return;
                    }
                }

                if (newStatus === 'Releasing') {
                    const pickupDateInput = form.querySelector(`#pickup_date_input_${modalId}`);
                    if (!pickupDateInput || !pickupDateInput.value) {
                        showErrorToast('Please set a pickup date for the credential.');
                        return;
                    }
                }

                const currentStatusModal = bootstrap.Modal.getInstance(form.closest('.modal'));
                currentForm = form;
                currentRequestId = requestId;
                currentNewStatus = newStatus;

                currentStatusModal.hide();
                showModernConfirm(
                    'Confirm Update',
                    `Are you sure you want to change the status to "${newStatus}"?`,
                    function() {
                        submitStatusUpdate();
                    },
                    { confirmText: 'Yes, Update' }
                );
            });
        });

        function submitStatusUpdate() {
            if (currentForm && currentRequestId && currentNewStatus && !confirmInProgress) {
                confirmInProgress = true;

                const declineReason = currentForm.querySelector('textarea[name="decline_reason"]')?.value || '';
                const pickupDate = currentForm.querySelector('input[name="pickup_date"]')?.value || '';

                fetch('ajax_update_status.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: `request_id=${encodeURIComponent(currentRequestId)}&new_status=${encodeURIComponent(currentNewStatus)}&registrar_id=${encodeURIComponent(registrarId)}&decline_reason=${encodeURIComponent(declineReason)}&pickup_date=${encodeURIComponent(pickupDate)}`
                })
                .then(response => response.json())
                .then(data => {
                    confirmInProgress = false;
                    if (data.success) {
                        showSuccessToast('Status updated successfully!');
                        setTimeout(() => location.reload(), 1200);
                    } else {
                        showErrorToast(data.message || 'Failed to update status.');
                    }
                })
                .catch(() => {
                    confirmInProgress = false;
                    showErrorToast('Network error. Please try again.');
                });
            }
        }

        // Unified alert notification functions
        function showSuccessToast(msg) {
            showModernAlert('success', 'Success', msg, { autoCloseMs: 2200 });
        }
        function showErrorToast(msg) {
            showModernAlert('error', 'Unable to Continue', msg);
        }

        function resetPendingUpdateState() {
            currentForm = null;
            currentRequestId = null;
            currentNewStatus = null;
        }

        // Auto-refresh page every 5 minutes
        setTimeout(() => {
            location.reload();
        }, 300000);

        // Focus on first input when modal opens
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('shown.bs.modal', function () {
                const firstInput = this.querySelector('input, select, textarea');
                if (firstInput) {
                    firstInput.focus();
                }
            });
        });

        document.addEventListener('click', function(e) {
            if (e.target.closest('.status-update-btn')) {
                resetPendingUpdateState();
            }
        });
    });
    </script>
</body>
</html>
