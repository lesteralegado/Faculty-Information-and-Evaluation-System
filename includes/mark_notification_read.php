<?php
session_start();
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['username'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

// Include database connection
require_once __DIR__ . '/db_connection.php';

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['notification_id'])) {
    echo json_encode(['success' => false, 'error' => 'Missing notification ID']);
    exit();
}

$notification_id = $input['notification_id'];
$username = $_SESSION['username'];
$user_role = $_SESSION['role'];

// Get user ID based on role
$current_user_id = null;
if ($user_role === 'student') {
    $user_stmt = $conn->prepare("SELECT s.student_id as user_id FROM students s 
                                 JOIN users u ON s.user_id = u.user_id 
                                 WHERE u.account_number = ?");
    $user_stmt->bind_param("s", $username);
    $user_stmt->execute();
    $user_result = $user_stmt->get_result();
    if ($user_data = $user_result->fetch_assoc()) {
        $current_user_id = $user_data['user_id'];
    }
    $user_stmt->close();
} elseif ($user_role === 'teacher') {
    $user_stmt = $conn->prepare("SELECT t.teacher_id as user_id FROM teachers t 
                                 JOIN users u ON t.user_id = u.user_id 
                                 WHERE u.account_number = ?");
    $user_stmt->bind_param("s", $username);
    $user_stmt->execute();
    $user_result = $user_stmt->get_result();
    if ($user_data = $user_result->fetch_assoc()) {
        $current_user_id = $user_data['user_id'];
    }
    $user_stmt->close();
} elseif ($user_role === 'faculty' || $user_role === 'admin') {
    $user_stmt = $conn->prepare("SELECT u.user_id FROM faculty f 
                                 JOIN users u ON f.user_id = u.user_id 
                                 WHERE u.account_number = ?");
    $user_stmt->bind_param("s", $username);
    $user_stmt->execute();
    $user_result = $user_stmt->get_result();
    if ($user_data = $user_result->fetch_assoc()) {
        $current_user_id = $user_data['user_id'];
    }
    $user_stmt->close();
}

if (!$current_user_id) {
    echo json_encode(['success' => false, 'error' => 'User not found']);
    exit();
}

// Mark notification as read
$update_stmt = $conn->prepare("UPDATE notifications SET is_read = 1 
                               WHERE id = ? AND user_id = ? AND user_type = ?");
$update_stmt->bind_param("iis", $notification_id, $current_user_id, $user_role);

if ($update_stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to update notification']);
}

$update_stmt->close();
$conn->close();
?>
