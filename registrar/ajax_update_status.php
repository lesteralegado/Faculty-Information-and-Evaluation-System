<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['username']) || !in_array($_SESSION['role'], ['registrar', 'admin'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../includes/db_connection.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $request_id = intval($_POST['request_id'] ?? 0);
    $new_status = $_POST['new_status'] ?? '';
    $registrar_id = intval($_POST['registrar_id'] ?? 0);
    $decline_reason = isset($_POST['decline_reason']) ? trim($_POST['decline_reason']) : null;
    $pickup_date_raw = $_POST['pickup_date'] ?? '';
    $pickup_date = null;

    if (!$request_id || !$new_status || !$registrar_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid data']);
        exit;
    }

    if ($new_status === 'Declined') {
        if ($decline_reason === null || $decline_reason === '') {
            echo json_encode(['success' => false, 'message' => 'Decline reason is required.']);
            exit;
        }
    } else {
        $decline_reason = null;
    }

    if ($new_status === 'Releasing') {
        if (empty($pickup_date_raw)) {
            echo json_encode(['success' => false, 'message' => 'Pickup date is required.']);
            exit;
        }
        $pickup_date_obj = DateTime::createFromFormat('Y-m-d', $pickup_date_raw);
        if (!$pickup_date_obj) {
            echo json_encode(['success' => false, 'message' => 'Invalid pickup date.']);
            exit;
        }
        $pickup_date = $pickup_date_obj->format('Y-m-d');
    } else {
        $pickup_date = null;
    }

    // Get request details for notification
    $request_details_stmt = $conn->prepare("
        SELECT cr.*, s.student_id, CONCAT(u.first_name, ' ', u.last_name) as student_name
        FROM credentials_requests cr
        JOIN students s ON cr.student_id = s.student_id
    JOIN users u ON s.user_id = u.user_id
        WHERE cr.id = ?
    ");
    $request_details_stmt->bind_param("i", $request_id);
    $request_details_stmt->execute();
    $request_details = $request_details_stmt->get_result()->fetch_assoc();
    $request_details_stmt->close();

    $update_stmt = $conn->prepare("UPDATE credentials_requests SET status = ?, date_processed = NOW(), processed_by = ?, decline_reason = ?, pickup_date = ? WHERE id = ?");
    $update_stmt->bind_param("sissi", $new_status, $registrar_id, $decline_reason, $pickup_date, $request_id);

    if ($update_stmt->execute() && $request_details) {
        // Notification logic (same as your PHP)
        $notification_type = 'info';
        $notification_title = 'Request Status Updated';
        switch ($new_status) {
            case 'Processing':
                $notification_message = "Your {$request_details['credential_type']} request is now being processed.";
                $notification_type = 'info';
                break;
            case 'Releasing':
                $pickup_display = $pickup_date ? date('F j, Y', strtotime($pickup_date)) : null;
                $date_snippet = $pickup_display ? " on {$pickup_display}" : '';
                $notification_message = "Your {$request_details['credential_type']} request has been approved and is ready for pickup{$date_snippet}.";
                $notification_type = 'success';
                break;
            case 'Declined':
                $notification_message = "Your {$request_details['credential_type']} request has been declined.";
                if ($decline_reason) {
                    $notification_message .= " Reason: {$decline_reason}";
                }
                $notification_type = 'danger';
                break;
            default:
                $notification_message = "Your {$request_details['credential_type']} request status has been updated to {$new_status}.";
                $notification_type = 'info';
        }

        // Insert notification for student
        $student_notification_stmt = $conn->prepare("
            INSERT INTO notifications (user_id, user_type, title, message, type, related_request_id) 
            VALUES (?, 'student', ?, ?, ?, ?)
        ");
        $student_notification_stmt->bind_param("isssi", 
            $request_details['student_id'], 
            $notification_title, 
            $notification_message, 
            $notification_type, 
            $request_id
        );
        $student_notification_stmt->execute();
        $student_notification_stmt->close();

        $update_stmt->close();
        echo json_encode(['success' => true]);
        exit;
    } else {
        echo json_encode(['success' => false, 'message' => 'Database update failed']);
        exit;
    }
}

echo json_encode(['success' => false, 'message' => 'Invalid request']);
exit;