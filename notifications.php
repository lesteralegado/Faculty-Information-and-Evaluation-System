<?php
if (!isset($_SESSION)) { session_start(); }
if (!isset($_SESSION['username'])) {
    header('Location: /capstone/login.php');
    exit();
}

require_once __DIR__ . '/includes/db_connection.php';

$username = $_SESSION['username'];
$role = $_SESSION['role'];

// Resolve role-specific user_id (admins intentionally have no notifications but page should render)
$current_user_id = null;
if ($role === 'student') {
    $stmt = $conn->prepare("SELECT s.student_id as user_id FROM students s JOIN users u ON s.user_id = u.user_id WHERE u.account_number = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) { $current_user_id = $row['user_id']; }
    $stmt->close();
} elseif ($role === 'teacher') {
    $stmt = $conn->prepare("SELECT t.teacher_id as user_id FROM teachers t JOIN users u ON t.user_id = u.user_id WHERE u.account_number = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) { $current_user_id = $row['user_id']; }
    $stmt->close();
} elseif ($role === 'registrar') {
    $stmt = $conn->prepare("SELECT u.user_id FROM registrar r JOIN users u ON r.user_id = u.user_id WHERE u.account_number = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) { $current_user_id = $row['user_id']; }
    $stmt->close();
} else {
    // admin: show empty list (no notifications), proceed without user_id
}

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$page_size = 20;
$offset = ($page - 1) * $page_size;

// Fetch notifications (skip for admin to present blank state)
$rows = [];
$total = 0;
$total_pages = 1;
if ($role !== 'admin' && $current_user_id) {
    $stmt = $conn->prepare("SELECT SQL_CALC_FOUND_ROWS * FROM notifications WHERE user_id = ? AND user_type = ? ORDER BY created_at DESC LIMIT ? OFFSET ?");
    $stmt->bind_param("isii", $current_user_id, $role, $page_size, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($n = $result->fetch_assoc()) { $rows[] = $n; }
    $stmt->close();

    $totalRes = $conn->query("SELECT FOUND_ROWS() AS total");
    $total = ($totalRes && $totalRes->num_rows) ? intval($totalRes->fetch_assoc()['total']) : 0;
    $total_pages = max(1, (int)ceil($total / $page_size));
}

$request_details = [];
if ($role === 'student' && count($rows) > 0) {
    $request_ids = array_unique(array_filter(array_map(function($row) {
        return isset($row['related_request_id']) ? (int)$row['related_request_id'] : 0;
    }, $rows)));

    if (!empty($request_ids)) {
        $id_list = implode(',', $request_ids);
        $extra_result = $conn->query("SELECT id, decline_reason, pickup_date FROM credentials_requests WHERE id IN ({$id_list})");
        if ($extra_result) {
            while ($extra = $extra_result->fetch_assoc()) {
                $request_details[(int)$extra['id']] = $extra;
            }
        }
    }
}

// Helper to compute relative time
function time_ago($ts) {
    $diff = time() - strtotime($ts);
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff/60) . ' minutes ago';
    if ($diff < 86400) return floor($diff/3600) . ' hours ago';
    return floor($diff/86400) . ' days ago';
}

// Determine navigation URL by content
function notif_target_url($n, $role) {
    $title_lower = strtolower($n['title']);
    $message_lower = strtolower($n['message']);
    if (strpos($title_lower, 'evaluation') !== false || strpos($message_lower, 'evaluation') !== false) {
        if ($role === 'student') return '/capstone/student/evaluation_form.php';
        if ($role === 'teacher') return '/capstone/teacher/teacher_dashboard.php';
        return '/capstone/evaluation_result.php';
    }
    if (strpos($title_lower, 'request status updated') !== false || strpos($message_lower, 'request') !== false) {
        if ($role === 'student') return '/capstone/student/credential_form_request.php';
        if ($role === 'registrar') return '/capstone/admin/user_management.php';
    }
    return '/capstone/';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="/capstone/images/school-logo.png" type="image/png">
    <title>Notifications</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --primary-color:#800000; --primary-hover:#a00000; --info-color:#17a2b8; --success-color:#28a745; --warning-color:#ffc107; --danger-color:#dc3545; }
        body { font-family: 'Poppins', sans-serif; background: #f5f7fb; }
        .page-header { background: linear-gradient(135deg, var(--primary-color), #600000); color: #fff; padding: 30px; border-radius: 16px; margin: 90px 0 24px; }
        .notif-card { background:#fff; border:1px solid #e9ecef; border-radius:14px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,.05); }
        .notif-item { display:flex; gap:14px; padding:16px 18px; border-bottom:1px solid #f0f0f0; cursor:pointer; position:relative; }
        .notif-item:last-child { border-bottom:none; }
        .notif-item.unread { background: linear-gradient(90deg, rgba(128,0,0,.03) 0%, transparent 100%); border-left:3px solid var(--primary-color); }
        .notif-dot { position:absolute; left:10px; top:50%; transform:translateY(-50%); width:8px; height:8px; background:var(--primary-color); border-radius:50%; display:none; }
        .notif-item.unread .notif-dot { display:block; }
        .notif-icon { width:36px; height:36px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:14px; }
        .notif-icon.info { background: rgba(23,162,184,.1); color: var(--info-color); }
        .notif-icon.success { background: rgba(40,167,69,.1); color: var(--success-color); }
        .notif-icon.warning { background: rgba(255,193,7,.1); color: var(--warning-color); }
        .notif-icon.danger { background: rgba(220,53,69,.1); color: var(--danger-color); }
        .notif-title { font-weight:600; margin-bottom:2px; }
        .notif-message { color:#666; font-size:14px; line-height: 1.4; }
        .notif-time { color:#999; font-size:12px; }
        .pagination .page-link { color: var(--primary-color); }
        .pagination .active .page-link { background: var(--primary-color); border-color: var(--primary-color); color: #fff; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/includes/side_bar.php'; ?>
    <?php include __DIR__ . '/includes/navbar.php'; ?>

    <div class="container" style="margin-left: 250px;">
        <div class="page-header">
            <h3 class="mb-0"><i class="fas fa-bell me-2"></i>Notifications</h3>
            <small>All notifications for your account</small>
        </div>

        <div class="notif-card mb-4">
            <?php if (count($rows) === 0): ?>
                <div class="p-4 text-center text-muted">No notifications.</div>
            <?php else: ?>
                <?php foreach ($rows as $n): ?>
                    <?php 
                        $stored = isset($n['link']) && $n['link'] ? $n['link'] : null;
                        $url = htmlspecialchars($stored ? $stored : notif_target_url($n, $role)); 
                        $extraMeta = null;
                        if ($role === 'student' && !empty($n['related_request_id'])) {
                            $reqId = (int)$n['related_request_id'];
                            $extraMeta = $request_details[$reqId] ?? null;
                        }
                    ?>
                    <a class="notif-item <?php echo $n['is_read'] ? '' : 'unread'; ?>" href="/capstone/includes/notification_redirect.php?id=<?php echo (int)$n['id']; ?>">
                        <span class="notif-dot"></span>
                        <div class="notif-icon <?php echo htmlspecialchars($n['type']); ?>">
                            <?php
                                $icons = [ 'info' => 'fas fa-info', 'warning' => 'fas fa-exclamation-triangle', 'success' => 'fas fa-check', 'danger' => 'fas fa-times' ];
                            ?>
                            <i class="<?php echo $icons[$n['type']] ?? 'fas fa-info'; ?>"></i>
                        </div>
                        <div class="flex-fill">
                            <div class="notif-title"><?php echo htmlspecialchars($n['title']); ?></div>
                            <!-- Display full message including decline reason if present -->
                            <div class="notif-message"><?php echo htmlspecialchars($n['message']); ?></div>
                            <?php if ($extraMeta): ?>
                                <?php if (!empty($extraMeta['decline_reason'])): ?>
                                    <div class="small text-muted mt-1">
                                        <strong>Decline Reason:</strong>
                                        <?php echo htmlspecialchars($extraMeta['decline_reason']); ?>
                                    </div>
                                <?php endif; ?>
                                <?php 
                                    $pickupDate = $extraMeta['pickup_date'] ?? null;
                                    $hasPickup = $pickupDate && $pickupDate !== '0000-00-00';
                                ?>
                                <?php if ($hasPickup): ?>
                                    <div class="small text-muted">
                                        <strong>Pickup Date:</strong>
                                        <?php echo htmlspecialchars(date('F j, Y', strtotime($pickupDate))); ?>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                            <div class="notif-time"><?php echo htmlspecialchars(time_ago($n['created_at'])); ?></div>
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php if ($total_pages > 1): ?>
        <nav>
            <ul class="pagination">
                <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                    <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $p; ?>"><?php echo $p; ?></a>
                    </li>
                <?php endfor; ?>
            </ul>
        </nav>
        <?php endif; ?>
    </div>

    <script>
    document.addEventListener('click', function(e) {
        const item = e.target.closest('.notif-item');
        if (!item) return;
        const id = parseInt(item.getAttribute('data-id'), 10);
        const url = item.getAttribute('data-url');
        fetch('/capstone/includes/mark_notification_read.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ notification_id: id })
        }).then(r => r.json()).then(data => {
            if (data.success) {
                item.classList.remove('unread');
                if (url) { window.location.href = url; }
            } else if (url) {
                window.location.href = url;
            }
        }).catch(() => { if (url) window.location.href = url; });
    });
    </script>
</body>
</html>
