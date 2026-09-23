<?php
session_start();

require_once __DIR__ . '/../includes/student_access_control.php';
requireStudentAccess('credential_only');

// Include database connection
require_once __DIR__ . '/../includes/db_connection.php';
require_once __DIR__ . '/../includes/mailer.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Set timezone to UTC for consistent timestamp handling
date_default_timezone_set('UTC');
$conn->query("SET time_zone = '+00:00'");

// If student is restricted (inactive or not enrolled), they are still allowed here.
$restricted_param = $_GET['restricted'] ?? '';
$restricted_notice = ($restricted_param !== '');

// Get student information
$student_username = $_SESSION['username'];

// First, get user_id and student_id
$user_stmt = $conn->prepare("SELECT u.user_id, u.first_name, u.last_name, s.student_id 
                             FROM users u
                             LEFT JOIN students s ON u.user_id = s.user_id
                             WHERE u.account_number = ? AND u.role = 'student'");
$user_stmt->bind_param("s", $student_username);
$user_stmt->execute();
$user_result = $user_stmt->get_result();

if ($user_result->num_rows === 0) {
    die("Student not found. Please contact administrator.");
}

$user_data = $user_result->fetch_assoc();
$user_id = (int)$user_data['user_id'];
$student_id = (int)($user_data['student_id'] ?? 0);
$student_name = trim($user_data['first_name'] . ' ' . $user_data['last_name']);
$user_stmt->close();

// Fetch FRESH email directly from users table (this ensures latest email after edits)
$email_stmt = $conn->prepare("SELECT email FROM users WHERE user_id = ? AND role = 'student'");
$email_stmt->bind_param("i", $user_id);
$email_stmt->execute();
$email_result = $email_stmt->get_result();

if ($email_result->num_rows === 0) {
    die("Student email not found. Please contact administrator.");
}

$email_data = $email_result->fetch_assoc();
$student_email = trim((string)($email_data['email'] ?? ''));
$email_stmt->close();

// Verify email is valid before proceeding
if (empty($student_email) || !filter_var($student_email, FILTER_VALIDATE_EMAIL)) {
    die("Invalid or missing email address. Please update your email in user settings.");
}

// Database schema is installed with deployment/schema-repair.sql, not during requests.

function maskEmail(string $email): string {
    if ($email === '' || strpos($email, '@') === false) {
        return $email;
    }
    [$local, $domain] = explode('@', $email, 2);
    if (strlen($local) <= 2) {
        $maskedLocal = substr($local, 0, 1) . '*';
    } else {
        $maskedLocal = substr($local, 0, 2) . str_repeat('*', max(1, strlen($local) - 2));
    }
    return $maskedLocal . '@' . $domain;
}

// Handle form submission
$success_message = '';
$error_message = '';
$time_until_next_submission = ''; // This variable was present in existing code but not used in updates for time formatting. Kept for completeness.
$otp_expires_minutes = 10;
$otp_resend_cooldown_seconds = 60;
$otp_max_attempts = 5;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'send_otp';
    try {
        if ($action === 'send_otp') {
            $credential_type = $_POST['credential_type'] ?? '';
            $purpose = $_POST['purpose'] ?? '';

            // Basic validation against enum values in DB
            $allowed_credentials = [
                'Registration Form',
                'Certifications',
                'Report Card'
            ];

            $allowed_purposes = [
                'College Admission',
                'Scholarship Application',
                'Transfer to Another School',
                'Employment',
                'Personal Records',
                'Others'
            ];

            if ($student_email === '') {
                $error_message = 'No email is registered in your account. Please contact registrar.';
            } elseif (!in_array($credential_type, $allowed_credentials, true)) {
                $error_message = 'Invalid credential type.';
            } elseif (!in_array($purpose, $allowed_purposes, true)) {
                $error_message = 'Invalid purpose selected.';
            } else {
                // Check for existing requests within the last 24 hours
                $check_stmt = $conn->prepare("SELECT created_at FROM credentials_requests 
                                              WHERE student_id = ? 
                                              AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                                              ORDER BY created_at DESC LIMIT 1");
                $check_stmt->bind_param("i", $student_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();

                if ($check_result->num_rows > 0) {
                    $last_request = $check_result->fetch_assoc();
                    $last_submission_time = strtotime($last_request['created_at']);
                    $current_time = time();
                    $next_submission_time = $last_submission_time + (24 * 60 * 60);

                    if ($current_time < $next_submission_time) {
                        $time_diff = $next_submission_time - $current_time;
                        $hours = floor($time_diff / 3600);
                        $minutes = floor(($time_diff % 3600) / 60);
                        $error_message = "You can submit another request in {$hours}h {$minutes}m.";
                    }
                }
                $check_stmt->close();
            }

            if ($error_message === '') {
                $otp_code = (string)random_int(100000, 999999);
                $otp_hash = password_hash($otp_code, PASSWORD_DEFAULT);
                $expires_at = date('Y-m-d H:i:s', time() + ($otp_expires_minutes * 60));

                $conn->begin_transaction();
                $invalidate_stmt = $conn->prepare("UPDATE credential_request_otps SET is_verified = 1 WHERE student_id = ? AND is_verified = 0");
                $invalidate_stmt->bind_param("i", $student_id);
                $invalidate_stmt->execute();
                $invalidate_stmt->close();

                $otp_insert_stmt = $conn->prepare("INSERT INTO credential_request_otps
                    (student_id, email, credential_type, purpose, otp_hash, expires_at, attempts, is_verified, created_at, resend_at)
                    VALUES (?, ?, ?, ?, ?, ?, 0, 0, NOW(), NOW())");
                $otp_insert_stmt->bind_param("isssss", $student_id, $student_email, $credential_type, $purpose, $otp_hash, $expires_at);
                $otp_insert_stmt->execute();
                $otp_insert_stmt->close();
                $conn->commit();

                // Log the OTP sending attempt for debugging
                error_log('[CredentialForm] OTP Send Attempt: User=' . $student_username . ', StudentID=' . $student_id . ', ToEmail=' . $student_email . ', Name=' . $student_name);

                $mail_error = '';
                $mail_sent = sendCredentialOtpEmail($student_email, $student_name, $otp_code, $otp_expires_minutes, $mail_error);
                if (!$mail_sent) {
                    error_log('[CredentialForm] OTP Send FAILED: Email=' . $student_email . ', Error=' . $mail_error);
                    $error_message = 'Failed to send verification code. ' . ($mail_error !== '' ? $mail_error : 'Please try again.');
                } else {
                    error_log('[CredentialForm] OTP Send SUCCESS: Email=' . $student_email);
                    $_SESSION['credential_request_success'] = 'A verification code has been sent to your email. Enter the code to confirm your request.';
                    header("Location: " . $_SERVER['PHP_SELF']);
                    exit();
                }
            }
        } elseif ($action === 'verify_otp') {
            $otp_input = trim((string)($_POST['otp_code'] ?? ''));
            if (!preg_match('/^\d{6}$/', $otp_input)) {
                $error_message = 'Enter a valid 6-digit verification code.';
            } else {
                $otp_stmt = $conn->prepare("SELECT * FROM credential_request_otps WHERE student_id = ? AND is_verified = 0 ORDER BY otp_id DESC LIMIT 1");
                $otp_stmt->bind_param("i", $student_id);
                $otp_stmt->execute();
                $pending_otp = $otp_stmt->get_result()->fetch_assoc();
                $otp_stmt->close();

                if (!$pending_otp) {
                    $error_message = 'No pending verification request found. Please submit a new request.';
                } elseif (strtotime($pending_otp['expires_at']) < time()) {
                    $expire_stmt = $conn->prepare("UPDATE credential_request_otps SET is_verified = 1 WHERE otp_id = ?");
                    $expire_stmt->bind_param("i", $pending_otp['otp_id']);
                    $expire_stmt->execute();
                    $expire_stmt->close();
                    $error_message = 'Verification code has expired. Please request a new code.';
                } elseif ((int)$pending_otp['attempts'] >= $otp_max_attempts) {
                    $error_message = 'Maximum verification attempts reached. Please resend a new code.';
                } elseif (!password_verify($otp_input, $pending_otp['otp_hash'])) {
                    $inc_stmt = $conn->prepare("UPDATE credential_request_otps SET attempts = attempts + 1 WHERE otp_id = ?");
                    $inc_stmt->bind_param("i", $pending_otp['otp_id']);
                    $inc_stmt->execute();
                    $inc_stmt->close();
                    $remaining = max(0, $otp_max_attempts - ((int)$pending_otp['attempts'] + 1));
                    $error_message = $remaining > 0
                        ? "Invalid code. You have {$remaining} attempt(s) remaining."
                        : "Maximum verification attempts reached. Please resend a new code.";
                } else {
                    // Re-check 24-hour rule before final insert
                    $check_stmt = $conn->prepare("SELECT created_at FROM credentials_requests 
                                                  WHERE student_id = ? 
                                                  AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                                                  ORDER BY created_at DESC LIMIT 1");
                    $check_stmt->bind_param("i", $student_id);
                    $check_stmt->execute();
                    $check_result = $check_stmt->get_result();
                    $recent_request = $check_result->fetch_assoc();
                    $check_stmt->close();

                    if ($recent_request) {
                        $done_stmt = $conn->prepare("UPDATE credential_request_otps SET is_verified = 1 WHERE otp_id = ?");
                        $done_stmt->bind_param("i", $pending_otp['otp_id']);
                        $done_stmt->execute();
                        $done_stmt->close();
                        $error_message = 'A request was already submitted within 24 hours. Please try again later.';
                    } else {
                        $conn->begin_transaction();
                        $verify_stmt = $conn->prepare("UPDATE credential_request_otps SET is_verified = 1, verified_at = NOW() WHERE otp_id = ? AND is_verified = 0");
                        $verify_stmt->bind_param("i", $pending_otp['otp_id']);
                        $verify_stmt->execute();
                        $verify_stmt->close();

                        $insert_stmt = $conn->prepare("INSERT INTO credentials_requests 
                            (id, student_id, credential_type, purpose, status, date_requested, created_at, updated_at) 
                            VALUES (NULL, ?, ?, ?, 'Pending', NOW(), NOW(), NOW())");
                        $insert_stmt->bind_param("iss", $student_id, $pending_otp['credential_type'], $pending_otp['purpose']);
                        $insert_stmt->execute();
                        $request_id = (int)$conn->insert_id;
                        $insert_stmt->close();

                        $get_registrar = $conn->prepare("SELECT DISTINCT u.user_id 
                            FROM users u 
                            JOIN registrar r ON u.user_id = r.user_id 
                            WHERE u.role = 'registrar' 
                            AND u.status = 'active'");
                        $get_registrar->execute();
                        $registrar_result = $get_registrar->get_result();

                        $notification_stmt = $conn->prepare("INSERT INTO notifications 
                            (id, user_id, user_type, title, message, type, related_request_id, created_at, is_read) 
                            VALUES 
                            (NULL, ?, 'registrar', 'New Credential Request', ?, 'info', ?, NOW(), 0)");
                        $notification_message = "New {$pending_otp['credential_type']} request from {$student_name}";

                        while ($registrar = $registrar_result->fetch_assoc()) {
                            $notification_stmt->bind_param(
                                "isi",
                                $registrar['user_id'],
                                $notification_message,
                                $request_id
                            );
                            $notification_stmt->execute();
                        }
                        $get_registrar->close();
                        $notification_stmt->close();

                        $conn->commit();
                        $_SESSION['credential_request_success'] = 'Your credential request has been submitted successfully!';
                        header("Location: " . $_SERVER['PHP_SELF']);
                        exit();
                    }
                }
            }
        } elseif ($action === 'resend_otp') {
            $otp_stmt = $conn->prepare("SELECT * FROM credential_request_otps WHERE student_id = ? AND is_verified = 0 ORDER BY otp_id DESC LIMIT 1");
            $otp_stmt->bind_param("i", $student_id);
            $otp_stmt->execute();
            $pending_otp = $otp_stmt->get_result()->fetch_assoc();
            $otp_stmt->close();

            if (!$pending_otp) {
                $error_message = 'No pending request found to resend code.';
            } else {
                // Use UNIX timestamp for accurate calculation
                $now_timestamp = time();
                $resend_at = $pending_otp['resend_at'] ? strtotime($pending_otp['resend_at']) : 0;
                $seconds_since_resend = $now_timestamp - $resend_at;
                
                if ($seconds_since_resend < $otp_resend_cooldown_seconds) {
                    $wait = $otp_resend_cooldown_seconds - $seconds_since_resend;
                    $error_message = "Please wait before resending. ({$wait}s remaining)";
                } else {
                    $otp_code = (string)random_int(100000, 999999);
                    $otp_hash = password_hash($otp_code, PASSWORD_DEFAULT);
                    $expires_at = date('Y-m-d H:i:s', time() + ($otp_expires_minutes * 60));
                    $resend_at_new = date('Y-m-d H:i:s');

                    // DO NOT update created_at - it resets the timer!
                    // Only update otp_hash, expires_at, attempts counter, and resend_at
                    $upd_stmt = $conn->prepare("UPDATE credential_request_otps 
                                                SET otp_hash = ?, expires_at = ?, attempts = 0, resend_at = NOW() 
                                                WHERE otp_id = ?");
                    $upd_stmt->bind_param("ssi", $otp_hash, $expires_at, $pending_otp['otp_id']);
                    $upd_stmt->execute();
                    $upd_stmt->close();

                    // Use the email from the OTP record (which was saved when OTP was created)
                    $resend_email = trim((string)($pending_otp['email'] ?? ''));
                    
                    // Log resend attempt
                    error_log('[CredentialForm] OTP Resend Attempt: StudentID=' . $student_id . ', ToEmail=' . $resend_email . ', OtpID=' . $pending_otp['otp_id']);

                    $mail_error = '';
                    $mail_sent = sendCredentialOtpEmail($resend_email, $student_name, $otp_code, $otp_expires_minutes, $mail_error);
                    if (!$mail_sent) {
                        error_log('[CredentialForm] OTP Resend FAILED: Email=' . $resend_email . ', Error=' . $mail_error);
                        $error_message = 'Failed to resend verification code. ' . ($mail_error !== '' ? $mail_error : 'Please try again.');
                    } else {
                        error_log('[CredentialForm] OTP Resend SUCCESS: Email=' . $resend_email);
                        $_SESSION['credential_request_success'] = 'A new verification code was sent to your email.';
                        header("Location: " . $_SERVER['PHP_SELF']);
                        exit();
                    }
                }
            }
        }
    } catch (Exception $e) {
        if ($conn->errno) {
            $conn->rollback();
        }
        $error_message = 'Error processing request: ' . $e->getMessage();
        error_log('Credential OTP flow error: ' . $e->getMessage());
    }
}

// Resolve latest pending OTP state for UI
$pending_otp = null;
$otp_pending = false;
$otp_seconds_left = 0;
$resend_seconds_left = 0;
$pending_credential_type = '';
$pending_purpose = '';
$masked_email = '';

$pending_stmt = $conn->prepare("SELECT * FROM credential_request_otps WHERE student_id = ? AND is_verified = 0 ORDER BY otp_id DESC LIMIT 1");
$pending_stmt->bind_param("i", $student_id);
$pending_stmt->execute();
$pending_otp = $pending_stmt->get_result()->fetch_assoc();
$pending_stmt->close();

if ($pending_otp) {
    $expiry_ts = strtotime($pending_otp['expires_at']);
    $otp_seconds_left = $expiry_ts - time();
    if ($otp_seconds_left > 0 && (int)$pending_otp['attempts'] < $otp_max_attempts) {
        $otp_pending = true;
        $pending_credential_type = (string)$pending_otp['credential_type'];
        $pending_purpose = (string)$pending_otp['purpose'];
        $masked_email = maskEmail((string)$pending_otp['email']);
        
        // Calculate resend cooldown based on resend_at timestamp
        $now_timestamp = time();
        $resend_ts = $pending_otp['resend_at'] ? strtotime($pending_otp['resend_at']) : 0;
        $elapsed_since_resend = $now_timestamp - $resend_ts;
        $resend_seconds_left = max(0, $otp_resend_cooldown_seconds - $elapsed_since_resend);
    } else {
        $close_stmt = $conn->prepare("UPDATE credential_request_otps SET is_verified = 1 WHERE otp_id = ?");
        $close_stmt->bind_param("i", $pending_otp['otp_id']);
        $close_stmt->execute();
        $close_stmt->close();
    }
}

// Get student's previous requests
$requests_stmt = $conn->prepare("SELECT * FROM credentials_requests WHERE student_id = ? ORDER BY date_requested DESC");
$requests_stmt->bind_param("i", $student_id);
$requests_stmt->execute();
$requests_result = $requests_stmt->get_result();
$previous_requests = $requests_result->fetch_all(MYSQLI_ASSOC);

// Get last submission time for display purposes
$last_submission_info = '';
if (!empty($previous_requests)) {
    $last_request = $previous_requests[0];
    $last_submission_date = new DateTime($last_request['created_at']);
    $current_date = new DateTime();
    $interval = $current_date->diff($last_submission_date);
    
    if ($interval->days == 0) {
        $last_submission_info = $last_submission_date->format('g:i A');
    } else {
        $last_submission_info = $last_submission_date->format('M j, Y');
    }
}

$requests_stmt->close();
// Don't close connection here as it might be needed by other scripts
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="/images/school-logo.png" type="image/png">
    <title>Credential Request Form</title>
    
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
            --success-color: #28a745;
            --danger-color: #dc3545;
            --warning-color: #ffc107;
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
            transform: translateY(-4px);
            box-shadow: 0 12px 35px rgba(0, 0, 0, 0.15);
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
        
        .form-label {
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
        }
        
        .form-control, .form-select {
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 12px 15px;
            font-size: 1rem;
            transition: all 0.3s ease;
            accent-color: var(--primary-color);
        }
        
        .form-control:focus, .form-select:focus,
        input.form-control:focus, select.form-select:focus,
        textarea.form-control:focus {
            outline: none !important;
            border-color: var(--primary-color) !important;
            box-shadow: 0 0 0 0.25rem rgba(128, 0, 0, 0.25) !important;
        }
        
        /* Modern Button System */
        .btn-primary {
            background: var(--primary-color) !important;
            border: none !important;
            color: #fff !important;
            padding: 12px 30px;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(128, 0, 0, 0.2) !important;
        }
        
        .btn-primary:hover,
        .btn-primary:focus,
        .btn-primary:active {
            background: var(--primary-hover) !important;
            color: #fff !important;
            border: none !important;
            box-shadow: 0 6px 18px rgba(128, 0, 0, 0.35) !important;
            transform: translateY(-2px);
            outline: none !important;
        }

        .btn-secondary {
            background: #f0f0f0 !important;
            border: 2px solid var(--primary-color) !important;
            color: var(--primary-color) !important;
            padding: 10px 28px;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-secondary:hover,
        .btn-secondary:focus {
            background: rgba(128, 0, 0, 0.1) !important;
            border-color: var(--primary-hover) !important;
            color: var(--primary-hover) !important;
        }

        .btn-danger {
            background: var(--danger-color) !important;
            border: none !important;
            color: #fff !important;
            padding: 12px 30px;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(220, 53, 69, 0.2) !important;
        }

        .btn-danger:hover {
            background: #c82333 !important;
            box-shadow: 0 6px 18px rgba(220, 53, 69, 0.35) !important;
            transform: translateY(-2px);
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
        
        /* Modern Alert Modal System */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            z-index: 1050;
            animation: fadeIn 0.3s ease;
        }

        .modal-overlay.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modern-modal-content {
            background: white;
            border-radius: 20px;
            padding: 40px 30px;
            max-width: 450px;
            width: 90%;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: slideUp 0.3s ease;
            position: relative;
            text-align: center;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .modal-icon {
            font-size: 3rem;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 80px;
            height: 80px;
            border-radius: 50%;
            margin-left: auto;
            margin-right: auto;
        }

        .modal-icon.success {
            background: rgba(40, 167, 69, 0.1);
            color: var(--success-color);
            animation: scaleIn 0.6s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }

        .modal-icon.error {
            background: rgba(220, 53, 69, 0.1);
            color: var(--danger-color);
            animation: shake 0.6s cubic-bezier(0.36, 0, 0.66, -0.56);
        }

        .close-modal {
            position: absolute;
            top: 15px;
            right: 15px;
            background: none;
            border: none;
            font-size: 1.5rem;
            color: #999;
            cursor: pointer;
            transition: color 0.3s ease;
        }

        .close-modal:hover {
            color: #333;
        }
        
        .info-box {
            background: linear-gradient(135deg, rgba(128, 0, 0, 0.05), rgba(160, 0, 0, 0.05));
            padding: 20px;
            border-radius: 10px;
            border-left: 4px solid var(--primary-color);
            line-height: 1.8;
        }
        
        .info-box p {
            color: #555;
            font-size: 0.95rem;
        }
        
        .info-box strong {
            color: var(--primary-color);
        }
        
        .info-box i {
            min-width: 20px;
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 20px;
            }
            
            .header-section h1 {
                font-size: 2.2rem;
            }

            .modern-modal-content {
                width: 90%;
                padding: 30px 20px;
            }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../includes/side_bar.php'; ?>
    <?php include __DIR__ . '/../includes/navbar.php'; ?>

    <!-- Modal System -->
    <div id="alertModal" class="modal-overlay">
        <div class="modern-modal-content">
            <button class="close-modal" onclick="closeModal()">&times;</button>
            <div class="modal-icon" id="modalIcon"></div>
            <h2 id="modalTitle" style="margin: 0 0 15px 0; font-size: 1.5rem; font-weight: 700; color: #333;"></h2>
            <p id="modalMessage" style="margin: 0 0 20px 0; color: #666; line-height: 1.6;"></p>
            <div id="modalExtra" style="margin-bottom: 20px;"></div>
            <button class="btn btn-primary" onclick="closeModal()">OK</button>
        </div>
    </div>

    <div class="main-content">
        <?php if ($restricted_notice): ?>
            <div class="alert alert-warning alert-dismissible fade show" role="alert" style="margin-bottom: 25px;">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <?php if ($restricted_param === 'inactive'): ?>
                    <strong>Limited access:</strong> Your student account is currently inactive. You can only access Credential Requests.
                <?php else: ?>
                    <strong>Limited access:</strong> You are not enrolled for the current school year and semester. You can only access Credential Requests.
                <?php endif; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Header Section -->
        <div class="header-section">
            <h1><i class="fas fa-file-alt me-3"></i>Credential Request</h1>
        </div>

        <?php
            // Check for success message from session (after redirect)
            if (isset($_SESSION['credential_request_success'])) {
                $success_message = $_SESSION['credential_request_success'];
                unset($_SESSION['credential_request_success']);
            }
        ?>
        
        <?php if ($success_message): ?>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    showModal('success', 'Success!', '<?php echo addslashes($success_message); ?>', true);
                });
            </script>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    showModal('error', 'Unable to Submit', '<?php echo addslashes($error_message); ?>', false);
                });
            </script>
        <?php endif; ?>

        <div class="row">
            <!-- Request Form -->
            <div class="col-lg-6">
                <div class="dashboard-card">
                    <div class="card-header">
                        <i class="fas fa-plus-circle"></i>
                        <h3><?php echo $otp_pending ? 'Verify Request' : 'New Credential Request'; ?></h3>
                    </div>
                    
                    <?php if (!$otp_pending): ?>
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="send_otp">
                            <div class="mb-3">
                                <label for="credential_type" class="form-label">
                                    <i class="fas fa-certificate me-2"></i>Credential Type
                                </label>
                                <select class="form-select" id="credential_type" name="credential_type" required>
                                    <option value="">Select credential type...</option>
                                    <option value="Registration Form">Registration Form</option>
                                    <option value="Certifications">Certifications</option>
                                    <option value="Report Card">Report Card</option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label for="purpose" class="form-label">
                                    <i class="fas fa-bullseye me-2"></i>Purpose of Request
                                </label>
                                <select class="form-select" id="purpose" name="purpose" required>
                                    <option value="">Select purpose...</option>
                                    <option value="College Admission">College Admission</option>
                                    <option value="Scholarship Application">Scholarship Application</option>
                                    <option value="Transfer to Another School">Transfer to Another School</option>
                                    <option value="Employment">Employment</option>
                                    <option value="Personal Records">Personal Records</option>
                                    <option value="Others">Others</option>
                                </select>
                            </div>

                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-envelope-open-text me-2"></i>Send Verification Code
                            </button>
                        </form>
                    <?php else: ?>
                        <div class="mb-3 p-3" style="background:#f8f9fa;border-radius:10px;border:1px solid #e9ecef;">
                            <p class="mb-2"><strong>Request:</strong> <?php echo htmlspecialchars($pending_credential_type); ?></p>
                            <p class="mb-2"><strong>Purpose:</strong> <?php echo htmlspecialchars($pending_purpose); ?></p>
                            <p class="mb-0"><strong>Code sent to:</strong> <?php echo htmlspecialchars($masked_email); ?></p>
                        </div>

                        <form method="POST" action="" class="mb-2">
                            <input type="hidden" name="action" value="verify_otp">
                            <div class="mb-3">
                                <label for="otp_code" class="form-label">
                                    <i class="fas fa-shield-alt me-2"></i>Verification Code
                                </label>
                                <input type="text" class="form-control" id="otp_code" name="otp_code" maxlength="6" pattern="\d{6}" placeholder="Enter 6-digit code" required>
                                <small class="text-muted">Code expires in <?php echo (int)ceil(max(0, $otp_seconds_left) / 60); ?> minute(s).</small>
                            </div>
                            <button type="submit" class="btn btn-primary w-100 mb-2">
                                <i class="fas fa-check-circle me-2"></i>Verify and Submit Request
                            </button>
                        </form>

                        <form method="POST" action="">
                            <input type="hidden" name="action" value="resend_otp">
                            <button type="submit" id="resendCodeBtn" class="btn btn-secondary w-100" <?php echo $resend_seconds_left > 0 ? 'disabled' : ''; ?>>
                                <i class="fas fa-redo me-2"></i>Resend Code
                            </button>
                            <small id="resendCooldownText" class="text-muted d-block mt-2" style="<?php echo $resend_seconds_left > 0 ? '' : 'display:none;'; ?>">
                                You can resend in <span id="resendSeconds"><?php echo (int)$resend_seconds_left; ?></span>s.
                            </small>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Status Tracker -->
            <div class="col-lg-6">
                <div class="dashboard-card">
                    <div class="card-header">
                        <i class="fas fa-info-circle"></i>
                        <h3>Submission Information</h3>
                    </div>
                    
                    <div class="info-box">
                        <p class="mb-2">
                            <i class="fas fa-clock text-warning me-2"></i>
                            <strong>24-Hour Limit:</strong> You can submit one credential request per day.
                        </p>
                        <p class="mb-2">
                            <i class="fas fa-check-circle text-success me-2"></i>
                            <strong>Processing Time:</strong> Registrar will review and process your request within 2-3 business days.
                        </p>
                        <?php if (!empty($last_submission_info)): ?>
                            <p class="mb-0">
                                <i class="fas fa-history text-muted me-2"></i>
                                <strong>Last Submission:</strong> <?php echo htmlspecialchars($last_submission_info); ?>
                            </p>
                        <?php else: ?>
                            <p class="mb-0">
                                <i class="fas fa-question-circle text-info me-2"></i>
                                <strong>Need Help?</strong> Contact the registrar's office for assistance.
                            </p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="dashboard-card">
                    <div class="card-header">
                        <i class="fas fa-tasks"></i>
                        <h3>Request Status Guide</h3>
                    </div>
                    
                    <div class="status-guide">
                        <div class="d-flex align-items-center mb-3">
                            <span class="status-badge status-pending me-3">Pending</span>
                            <span>Your request has been received and is waiting for review.</span>
                        </div>
                        <div class="d-flex align-items-center mb-3">
                            <span class="status-badge status-processing me-3">Processing</span>
                            <span>Your request is being prepared by the registrar.</span>
                        </div>
                        <div class="d-flex align-items-center mb-3">
                            <span class="status-badge status-releasing me-3">Releasing</span>
                            <span>Your credential is ready and available.</span> <!-- Updated based on update -->
                        </div>
                        <!-- Added Completed status to guide -->
                        <div class="d-flex align-items-center mb-3">
                            <span class="status-badge status-completed me-3">Completed</span>
                            <span>Your request has been completed and picked up.</span>
                        </div>
                        <div class="d-flex align-items-center">
                            <span class="status-badge status-declined me-3">Declined</span>
                            <span>Your request was declined. Please contact the registrar for details.</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Previous Requests -->
        <div class="dashboard-card">
            <div class="card-header">
                <i class="fas fa-history"></i>
                <h3>My Previous Requests</h3>
            </div>
            
            <?php if (count($previous_requests) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Credential Type</th>
                                <th>Purpose</th>
                                <th>Status</th>
                                <th>Date Requested</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($previous_requests as $request): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($request['credential_type']); ?></td>
                                    <td><?php echo htmlspecialchars($request['purpose']); ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo strtolower($request['status']); ?>">
                                            <?php echo htmlspecialchars($request['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M j, Y', strtotime($request['date_requested'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-4">
                    <i class="fas fa-file-alt fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">No previous requests</h5>
                    <p class="text-muted">Your credential requests will appear here once submitted.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Modal System Functions
        function showModal(type, title, message, autoClose = false) {
            const modal = document.getElementById('alertModal');
            const modalIcon = document.getElementById('modalIcon');
            const modalTitle = document.getElementById('modalTitle');
            const modalMessage = document.getElementById('modalMessage');

            // Set icon based on type
            if (type === 'success') {
                modalIcon.className = 'modal-icon success';
                modalIcon.innerHTML = '<i class="fas fa-check-circle"></i>';
            } else if (type === 'error') {
                modalIcon.className = 'modal-icon error';
                modalIcon.innerHTML = '<i class="fas fa-exclamation-circle"></i>';
            }

            modalTitle.textContent = title;
            modalMessage.textContent = message;
            modal.classList.add('show');

            // Auto-close after 3 seconds for success messages
            if (autoClose) {
                setTimeout(() => {
                    closeModal();
                }, 3000);
            }
        }

        function closeModal() {
            const modal = document.getElementById('alertModal');
            modal.classList.remove('show');
        }

        // Close modal on escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeModal();
            }
        });

        // Close modal when clicking outside
        const alertModal = document.getElementById('alertModal');
        if (alertModal) {
            alertModal.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeModal();
                }
            });
        }

        // Comprehensive resend cooldown management with localStorage persistence
        const RESEND_COOLDOWN_SECONDS = 120; // 2 minutes
        const COOLDOWN_STORAGE_KEY = 'credential_otp_resend_time';

        // Format seconds into readable time display
        function formatCooldownTime(seconds) {
            const mins = Math.floor(seconds / 60);
            const secs = seconds % 60;
            if (mins > 0) {
                return `${mins}m ${secs}s`;
            }
            return `${secs}s`;
        }

        // Initialize cooldown timer
        function initializeCooldownTimer() {
            const resendBtn = document.getElementById('resendCodeBtn');
            const cooldownText = document.getElementById('resendCooldownText');
            const cooldownSeconds = document.getElementById('resendSeconds');
            
            if (!resendBtn || !cooldownText) return;

            // Get stored resend timestamp from localStorage
            const storedTime = localStorage.getItem(COOLDOWN_STORAGE_KEY);
            let secondsLeft = 0;

            if (storedTime) {
                const storedTimestamp = parseInt(storedTime);
                const currentTime = Math.floor(Date.now() / 1000);
                const elapsedSeconds = currentTime - storedTimestamp;
                secondsLeft = Math.max(0, RESEND_COOLDOWN_SECONDS - elapsedSeconds);
            }

            // If server-side calculation shows remaining time, use that (takes precedence)
            const serverSecondsLeft = <?php echo (int)$resend_seconds_left; ?>;
            if (serverSecondsLeft > 0) {
                secondsLeft = serverSecondsLeft;
            }

            // Start countdown if there's time remaining
            if (secondsLeft > 0) {
                resendBtn.disabled = true;
                cooldownText.style.display = 'block';
                
                const updateCountdown = () => {
                    if (secondsLeft <= 0) {
                        resendBtn.disabled = false;
                        cooldownText.style.display = 'none';
                        localStorage.removeItem(COOLDOWN_STORAGE_KEY);
                        return;
                    }
                    
                    if (cooldownSeconds) {
                        cooldownSeconds.textContent = String(secondsLeft);
                    }
                    cooldownText.innerHTML = `You can resend in <span id="resendSeconds">${formatCooldownTime(secondsLeft)}</span>.`;
                    secondsLeft--;
                };

                // Update immediately, then every second
                updateCountdown();
                const timer = setInterval(updateCountdown, 1000);
                
                // Store timer ID for cleanup if needed
                resendBtn.dataset.timerInterval = timer;
            } else {
                resendBtn.disabled = false;
                cooldownText.style.display = 'none';
                localStorage.removeItem(COOLDOWN_STORAGE_KEY);
            }
        }

        // Handle Send Verification Code form submission
        function setupSendOtpForm() {
            const sendOtpForm = document.querySelector('form[action=""] input[value="send_otp"]');
            if (!sendOtpForm) return;
            
            const form = sendOtpForm.closest('form');
            if (!form) return;

            form.addEventListener('submit', function(e) {
                // Store current timestamp in localStorage to enforce cooldown after page reload
                localStorage.setItem(COOLDOWN_STORAGE_KEY, String(Math.floor(Date.now() / 1000)));
                
                const submitBtn = form.querySelector('button[type="submit"]');
                if (submitBtn) {
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Sending...';
                    submitBtn.disabled = true;
                }
            });
        }

        // Handle Resend Code button
        function setupResendButton() {
            const resendBtn = document.getElementById('resendCodeBtn');
            if (!resendBtn) return;

            // Store the original form for resend action
            const resendForm = resendBtn.closest('form');
            if (resendForm) {
                resendForm.addEventListener('submit', function(e) {
                    // Reset the cooldown timer when resending
                    localStorage.setItem(COOLDOWN_STORAGE_KEY, String(Math.floor(Date.now() / 1000)));
                    
                    resendBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Sending...';
                    resendBtn.disabled = true;
                });
            }
        }

        // Add general form validation
        function setupFormValidation() {
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                // Skip resend form from general submit handling
                if (form.querySelector('input[value="resend_otp"]')) return;
                
                const submitBtn = form.querySelector('button[type="submit"]');
                if (submitBtn && !form.querySelector('input[value="send_otp"]')) {
                    form.addEventListener('submit', function(e) {
                        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Submitting...';
                        submitBtn.disabled = true;
                    });
                }
            });
        }

        // Initialize on DOM ready
        document.addEventListener('DOMContentLoaded', function() {
            // Setup all form handlers
            setupSendOtpForm();
            setupResendButton();
            setupFormValidation();
            
            // Initialize cooldown timer
            initializeCooldownTimer();
        });
    </script>
</body>
</html>
