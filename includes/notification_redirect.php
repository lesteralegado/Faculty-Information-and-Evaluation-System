<?php
if (!isset($_SESSION)) { session_start(); }

// Require auth
if (!isset($_SESSION['username'])) {
    header('Location: /capstone/login.php');
    exit();
}

require_once __DIR__ . '/db_connection.php';

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) {
    header('Location: /capstone/index.php');
    exit();
}

$username = $_SESSION['username'];
$role = $_SESSION['role'];

// Resolve current user's role-specific id
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
} else { // registrar/admin map to user_id
    $stmt = $conn->prepare("SELECT u.user_id FROM registrar r JOIN users u ON r.user_id = u.user_id WHERE u.account_number = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) { $current_user_id = $row['user_id']; }
    $stmt->close();
}

// Fetch notification (only if belongs to current user)
$stmt = $conn->prepare("SELECT * FROM notifications WHERE id = ? AND user_id = ? AND user_type = ?");
$stmt->bind_param("iis", $id, $current_user_id, $role);
$stmt->execute();
$notif = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$notif) {
    header('Location: /capstone/index.php');
    exit();
}

// Mark as read
$upd = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?");
$upd->bind_param("i", $id);
$upd->execute();
$upd->close();

// Resolve link; fallback heuristics
$link = isset($notif['link']) && $notif['link'] ? $notif['link'] : '';
$title_lower = strtolower($notif['title']);
$message_lower = strtolower($notif['message']);

if (!$link) {
    if (strpos($title_lower, 'evaluation') !== false || strpos($message_lower, 'evaluation') !== false) {
        if ($role === 'student') {
            $link = '/capstone/student/evaluation_form.php';
        } else {
            $link = '/capstone/evaluation_result.php';
        }
    } elseif (strpos($title_lower, 'request') !== false) {
        if ($role === 'student') {
            $link = '/capstone/student/credential_form_request.php';
        } elseif ($role === 'registrar') {
            $link = '/capstone/registrar/faculty_information_management.php';
        } else {
            $link = '/capstone/';
        }
    } else {
        $link = '/capstone/';
    }
}

// Final safety: normalize to existing root-prefixed path
if (strpos($link, '/capstone/') !== 0) {
    $link = '/capstone/' . ltrim($link, '/');
}

header('Location: ' . $link);
exit();
?>

