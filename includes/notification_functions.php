<?php
/**
 * Notification Management Functions
 * Provides centralized functions for creating and managing notifications
 */

require_once __DIR__ . '/db_connection.php';

/**
 * Create a notification for a user
 * 
 * @param int $user_id The ID of the user to notify
 * @param string $user_type The type of user ('student' or 'registrar')
 * @param string $title The notification title
 * @param string $message The notification message
 * @param string $type The notification type ('info', 'success', 'warning', 'danger')
 * @param int|null $related_request_id Optional related request ID
 * @param string|null $link Optional absolute/relative URL for redirection
 * @return bool Success status
 */
function createNotification($user_id, $user_type, $title, $message, $type = 'info', $related_request_id = null, $link = null) {
    global $conn;
    
    $stmt = $conn->prepare("
        INSERT INTO notifications (user_id, user_type, title, message, type, related_request_id, link) 
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->bind_param("issssis", $user_id, $user_type, $title, $message, $type, $related_request_id, $link);
    $result = $stmt->execute();
    $stmt->close();
    
    return $result;
}

/**
 * Create the same notification for all users of a given role
 *
 * @param string $role One of 'student', 'teacher', 'registrar'
 * @param string $title Notification title
 * @param string $message Notification message
 * @param string $type One of 'info','success','warning','danger'
 * @return bool Success status
 */
function notifyAllUsersOfRole($role, $title, $message, $type = 'info', $link = null) {
    global $conn;

    if (!in_array($role, ['student', 'teacher', 'registrar'])) {
        return false;
    }

    if ($role === 'student') {
        $sql = "INSERT INTO notifications (user_id, user_type, title, message, type, link)
                SELECT s.student_id, 'student', ?, ?, ?, ? FROM students s";
    } elseif ($role === 'teacher') {
        $sql = "INSERT INTO notifications (user_id, user_type, title, message, type, link)
                SELECT t.teacher_id, 'teacher', ?, ?, ?, ? FROM teachers t";
    } else { // registrar
        $sql = "INSERT INTO notifications (user_id, user_type, title, message, type, link)
                SELECT r.registrar_id, 'registrar', ?, ?, ?, ? FROM registrar r";
    }

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssss", $title, $message, $type, $link);
    $result = $stmt->execute();
    $stmt->close();

    return $result;
}

/**
 * Create notifications for all registrar when a student submits a request
 * 
 * @param string $credential_type The type of credential requested
 * @param string $student_name The name of the student
 * @param int $request_id The ID of the request
 * @return bool Success status
 */
function notifyRegistrarOfNewRequest($credential_type, $student_name, $request_id) {
    global $conn;
    
    $stmt = $conn->prepare("
        INSERT INTO notifications (user_id, user_type, title, message, type, related_request_id, link) 
        SELECT r.registrar_id, 'registrar', 
               'New Credential Request', 
               CONCAT('New ', ?, ' request from ', ?), 
               'info', 
               ?,
               '/registrar/faculty_information_management.php'
        FROM registrar f
    ");
    
    $stmt->bind_param("ssi", $credential_type, $student_name, $request_id);
    $result = $stmt->execute();
    $stmt->close();
    
    return $result;
}

/**
 * Create notification for student when their request status is updated
 * 
 * @param int $student_id The student ID
 * @param string $credential_type The type of credential
 * @param string $new_status The new status
 * @param int $request_id The request ID
 * @return bool Success status
 */
function notifyStudentOfStatusUpdate($student_id, $credential_type, $new_status, $request_id) {
    global $conn;
    
    // Customize notification based on status
    $notification_type = 'info';
    $notification_title = 'Request Status Updated';
    
    switch ($new_status) {
        case 'Processing':
            $notification_message = "Your {$credential_type} request is now being processed.";
            $notification_type = 'info';
            break;
        case 'Releasing':
            $notification_message = "Your {$credential_type} request has been approved and is ready for pickup.";
            $notification_type = 'success';
            break;
        case 'Declined':
            $notification_message = "Your {$credential_type} request has been declined. Please contact the registrar for details.";
            $notification_type = 'danger';
            break;
        default:
            $notification_message = "Your {$credential_type} request status has been updated to {$new_status}.";
            $notification_type = 'info';
    }
    
    $link = '/student/credential_form_request.php';
    return createNotification($student_id, 'student', $notification_title, $notification_message, $notification_type, $request_id, $link);
}

/**
 * Get notifications for a specific user
 * 
 * @param int $user_id The user ID
 * @param string $user_type The user type ('student' or 'registrar')
 * @param int $limit Maximum number of notifications to retrieve
 * @param bool $unread_only Whether to get only unread notifications
 * @return array Array of notifications
 */
function getUserNotifications($user_id, $user_type, $limit = 10, $unread_only = false) {
    global $conn;
    
    $where_clause = "WHERE user_id = ? AND user_type = ?";
    $params = [$user_id, $user_type];
    $types = "is";
    
    if ($unread_only) {
        $where_clause .= " AND is_read = 0";
    }
    
    $stmt = $conn->prepare("
        SELECT * FROM notifications 
        {$where_clause}
        ORDER BY created_at DESC 
        LIMIT ?
    ");
    
    $params[] = $limit;
    $types .= "i";
    
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $notifications = [];
    while ($notification = $result->fetch_assoc()) {
        $time_diff = time() - strtotime($notification['created_at']);
        if ($time_diff < 60) {
            $time_ago = 'Just now';
        } elseif ($time_diff < 3600) {
            $time_ago = floor($time_diff / 60) . ' minutes ago';
        } elseif ($time_diff < 86400) {
            $time_ago = floor($time_diff / 3600) . ' hours ago';
        } else {
            $time_ago = floor($time_diff / 86400) . ' days ago';
        }
        
        $notifications[] = [
            'id' => $notification['id'],
            'title' => $notification['title'],
            'message' => $notification['message'],
            'type' => $notification['type'],
            'time' => $time_ago,
            'read' => (bool)$notification['is_read'],
            'created_at' => $notification['created_at'],
            'related_request_id' => $notification['related_request_id'],
            'link' => $notification['link'] ?? null
        ];
    }
    
    $stmt->close();
    return $notifications;
}

/**
 * Get unread notification count for a user
 * 
 * @param int $user_id The user ID
 * @param string $user_type The user type ('student' or 'registrar')
 * @return int Number of unread notifications
 */
function getUnreadNotificationCount($user_id, $user_type) {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT COUNT(*) as unread_count 
        FROM notifications 
        WHERE user_id = ? AND user_type = ? AND is_read = 0
    ");
    
    $stmt->bind_param("is", $user_id, $user_type);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    $stmt->close();
    
    return (int)$data['unread_count'];
}

/**
 * Mark a notification as read
 * 
 * @param int $notification_id The notification ID
 * @param int $user_id The user ID (for security)
 * @param string $user_type The user type (for security)
 * @return bool Success status
 */
function markNotificationAsRead($notification_id, $user_id, $user_type) {
    global $conn;
    
    $stmt = $conn->prepare("
        UPDATE notifications 
        SET is_read = 1 
        WHERE id = ? AND user_id = ? AND user_type = ?
    ");
    
    $stmt->bind_param("iis", $notification_id, $user_id, $user_type);
    $result = $stmt->execute();
    $stmt->close();
    
    return $result;
}

/**
 * Mark all notifications as read for a user
 * 
 * @param int $user_id The user ID
 * @param string $user_type The user type
 * @return bool Success status
 */
function markAllNotificationsAsRead($user_id, $user_type) {
    global $conn;
    
    $stmt = $conn->prepare("
        UPDATE notifications 
        SET is_read = 1 
        WHERE user_id = ? AND user_type = ? AND is_read = 0
    ");
    
    $stmt->bind_param("is", $user_id, $user_type);
    $result = $stmt->execute();
    $stmt->close();
    
    return $result;
}

/**
 * Delete old notifications (cleanup function)
 * 
 * @param int $days_old Number of days old to delete (default 30)
 * @return bool Success status
 */
function deleteOldNotifications($days_old = 30) {
    global $conn;
    
    $stmt = $conn->prepare("
        DELETE FROM notifications 
        WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
    ");
    
    $stmt->bind_param("i", $days_old);
    $result = $stmt->execute();
    $stmt->close();
    
    return $result;
}

/**
 * Get user ID based on username and role
 * 
 * @param string $username The username
 * @param string $role The user role ('student', 'registrar', 'admin')
 * @return int|null The user ID or null if not found
 */
function getUserIdByUsernameAndRole($username, $role) {
    global $conn;
    
    if ($role === 'student') {
        $stmt = $conn->prepare("
            SELECT s.student_id as user_id 
            FROM students s 
            JOIN users u ON s.user_id = u.user_id 
            WHERE u.account_number = ?
        ");
    } elseif ($role === 'registrar' || $role === 'admin') {
        $stmt = $conn->prepare("
            SELECT u.user_id 
            FROM registrar r 
            JOIN users u ON r.user_id = u.user_id 
            WHERE u.account_number = ?
        ");
    } else {
        return null;
    }
    
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($user_data = $result->fetch_assoc()) {
        $user_id = $user_data['user_id'];
        $stmt->close();
        return $user_id;
    }
    
    $stmt->close();
    return null;
}
?>
