<?php
session_start();

// Admin only
if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

require_once __DIR__ . '/../includes/db_connection.php';
require_once __DIR__ . '/../includes/modern_alert_system.php';

// Check if user exists with specified email
$check_email = $_GET['email'] ?? '';
$search_results = [];
$debug_info = [];

if (!empty($check_email)) {
    $check_email = trim($check_email);
    
    // Search for users with this email
    $search_stmt = $conn->prepare(
        "SELECT user_id, account_number, first_name, last_name, email, role, status 
         FROM users 
         WHERE email = ? OR email LIKE ? 
         ORDER BY role, first_name"
    );
    
    $search_like = '%' . $check_email . '%';
    $search_stmt->bind_param("ss", $check_email, $search_like);
    $search_stmt->execute();
    $search_results = $search_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $search_stmt->close();
    
    // Debug: Check raw data in users table
    $debug_info['search_email'] = $check_email;
    $debug_info['results_found'] = count($search_results);
}

// Get all students with their emails
$students_stmt = $conn->prepare(
    "SELECT u.user_id, u.account_number, u.first_name, u.last_name, u.email, u.status, u.role,
            s.student_id, s.year_level
     FROM users u
     LEFT JOIN students s ON u.user_id = s.user_id
     WHERE u.role = 'student'
     ORDER BY u.first_name, u.last_name"
);
$students_stmt->execute();
$all_students = $students_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$students_stmt->close();

// Check for duplicate or invalid emails
$email_issues = [];
$email_count = [];
foreach ($all_students as $student) {
    $email = $student['email'] ?? '';
    if (!empty($email)) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email_issues[] = [
                'user_id' => $student['user_id'],
                'name' => $student['first_name'] . ' ' . $student['last_name'],
                'email' => $email,
                'issue' => 'Invalid email format'
            ];
        }
        if (!isset($email_count[$email])) {
            $email_count[$email] = [];
        }
        $email_count[$email][] = $student['user_id'];
    }
}

// Find duplicates
$duplicate_emails = [];
foreach ($email_count as $email => $user_ids) {
    if (count($user_ids) > 1) {
        $duplicate_emails[$email] = $user_ids;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="/capstone/images/school-logo.png" type="image/png">
    <title>Email Diagnostic Tool</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root { --primary: #800000; --secondary: #f0f0f0; }
        body { font-family: 'Poppins', sans-serif; background-color: #f5f5f5; }
        .main-content { margin-left: 0; padding: 20px; }
        .header-section { margin-bottom: 30px; }
        .header-section h1 { color: var(--primary); font-weight: 700; }
        .dashboard-card { background: white; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); padding: 20px; margin-bottom: 20px; }
        .table-custom { width: 100%; border-collapse: collapse; }
        .table-custom thead th { background-color: var(--primary); color: white; padding: 12px; text-align: left; }
        .table-custom tbody td { padding: 12px; border-bottom: 1px solid #ddd; }
        .table-custom tbody tr:hover { background-color: #f9f9f9; }
        .alert { margin-bottom: 20px; }
        .search-form { display: flex; gap: 10px; margin-bottom: 20px; }
        .search-form input { flex: 1; padding: 10px; border: 1px solid #ddd; border-radius: 5px; }
        .search-form button { background-color: var(--primary); color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; }
        .email-valid { color: green; }
        .email-invalid { color: red; }
    </style>
</head>
<body>
    <?php include '../includes/side_bar.php'; ?>
    <?php include '../includes/navbar.php'; ?>
    <?php renderModernAlertSystem(); ?>

    <div class="main-content">
        <div class="header-section">
            <h1><i class="fas fa-stethoscope me-2"></i>Email Diagnostic Tool</h1>
            <p class="text-muted">Identify email issues and verify student email addresses</p>
        </div>

        <!-- Search Form -->
        <div class="dashboard-card">
            <h3 class="mb-3">Search for Email Issues</h3>
            <form method="GET" class="search-form">
                <input 
                    type="email" 
                    name="email" 
                    placeholder="Search for email address or domain..." 
                    value="<?php echo htmlspecialchars($check_email); ?>"
                >
                <button type="submit"><i class="fas fa-search"></i> Search</button>
                <?php if (!empty($check_email)): ?>
                    <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-secondary">Clear</a>
                <?php endif; ?>
            </form>

            <?php if (!empty($check_email) && count($search_results) > 0): ?>
                <div class="alert alert-info">
                    Found <?php echo count($search_results); ?> user(s) with email containing "<?php echo htmlspecialchars($check_email); ?>"
                </div>
                <table class="table-custom">
                    <thead>
                        <tr>
                            <th>User ID</th>
                            <th>Account</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($search_results as $user): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($user['user_id']); ?></td>
                                <td><?php echo htmlspecialchars($user['account_number']); ?></td>
                                <td><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></td>
                                <td><?php echo htmlspecialchars($user['email']); ?></td>
                                <td><?php echo htmlspecialchars($user['role']); ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $user['status'] === 'active' ? 'success' : 'warning'; ?>">
                                        <?php echo htmlspecialchars($user['status']); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Email Issues -->
        <?php if (count($email_issues) > 0): ?>
            <div class="dashboard-card">
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i> Found <?php echo count($email_issues); ?> invalid email(s)
                </div>
                <table class="table-custom">
                    <thead>
                        <tr>
                            <th>User ID</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Issue</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($email_issues as $issue): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($issue['user_id']); ?></td>
                                <td><?php echo htmlspecialchars($issue['name']); ?></td>
                                <td class="email-invalid"><?php echo htmlspecialchars($issue['email']); ?></td>
                                <td><?php echo htmlspecialchars($issue['issue']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <!-- Duplicate Emails -->
        <?php if (count($duplicate_emails) > 0): ?>
            <div class="dashboard-card">
                <div class="alert alert-danger">
                    <i class="fas fa-clone"></i> Found <?php echo count($duplicate_emails); ?> duplicate email address(es)
                </div>
                <table class="table-custom">
                    <thead>
                        <tr>
                            <th>Email</th>
                            <th>User IDs Using This Email</th>
                            <th>Count</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($duplicate_emails as $email => $user_ids): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($email); ?></td>
                                <td><?php echo htmlspecialchars(implode(', ', $user_ids)); ?></td>
                                <td><span class="badge bg-danger"><?php echo count($user_ids); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <!-- All Students Email List -->
        <div class="dashboard-card">
            <h3 class="mb-4">All Student Emails</h3>
            <div class="table-responsive">
                <table class="table-custom">
                    <thead>
                        <tr>
                            <th style="width: 60px;">User ID</th>
                            <th style="width: 120px;">Account</th>
                            <th style="width: 200px;">Name</th>
                            <th style="width: 250px;">Email</th>
                            <th style="width: 80px;">Valid</th>
                            <th style="width: 80px;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($all_students as $student): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($student['user_id']); ?></td>
                                <td><?php echo htmlspecialchars($student['account_number']); ?></td>
                                <td><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></td>
                                <td><?php echo htmlspecialchars($student['email'] ?? '(No email)'); ?></td>
                                <td>
                                    <?php if (empty($student['email'])): ?>
                                        <span class="email-invalid"><i class="fas fa-times"></i> Missing</span>
                                    <?php elseif (filter_var($student['email'], FILTER_VALIDATE_EMAIL)): ?>
                                        <span class="email-valid"><i class="fas fa-check"></i> Valid</span>
                                    <?php else: ?>
                                        <span class="email-invalid"><i class="fas fa-times"></i> Invalid</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo $student['status'] === 'active' ? 'success' : 'warning'; ?>">
                                        <?php echo htmlspecialchars($student['status']); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
