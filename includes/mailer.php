<?php

/**
 * Mailer Module - Hybrid Email System
 * 
 * Features:
 * - Central SMTP account for all outgoing mail
 * - Dynamic "from" addresses from user database
 * - Support for teacher and admin email sending
 * - Fallback to system default if user email unavailable
 */

/**
 * Get email configuration
 */
function getMailConfig(): array
{
    $config = [
        'host' => getenv('MAIL_HOST') ?: '',
        'port' => (int)(getenv('MAIL_PORT') ?: 587),
        'username' => getenv('MAIL_USERNAME') ?: '',
        'password' => getenv('MAIL_PASSWORD') ?: '',
        'encryption' => getenv('MAIL_ENCRYPTION') ?: 'tls',
        'default_from_address' => getenv('MAIL_FROM_ADDRESS') ?: '',
        'default_from_name' => getenv('MAIL_FROM_NAME') ?: 'Capstone System',
        'use_user_email_as_from' => true,
    ];

    // Load from file if env vars not set
    $configPath = __DIR__ . '/mail_config.php';
    if (file_exists($configPath)) {
        $fileConfig = require $configPath;
        if (is_array($fileConfig)) {
            foreach ($fileConfig as $key => $value) {
                if ($config[$key] === '' && $value !== '') {
                    $config[$key] = $value;
                }
            }
        }
    }

    return $config;
}

/**
 * Get user's email and name from database by user_id
 * 
 * @param int $userId
 * @param object $conn Database connection
 * @return array|null Array with 'email', 'first_name', 'last_name' or null
 */
function getUserEmailInfo(int $userId, $conn): ?array
{
    if (!$conn) return null;
    
    $stmt = $conn->prepare("SELECT email, first_name, last_name FROM users WHERE user_id = ? AND status = 'active'");
    if (!$stmt) return null;
    
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    return $result;
}

/**
 * Get teacher's email and name from database by teacher_id
 * 
 * @param int $teacherId
 * @param object $conn Database connection
 * @return array|null Array with 'email', 'first_name', 'last_name' or null
 */
function getTeacherEmailInfo(int $teacherId, $conn): ?array
{
    if (!$conn) return null;
    
    $stmt = $conn->prepare(
        "SELECT u.email, u.first_name, u.last_name 
         FROM teachers t
         JOIN users u ON t.user_id = u.user_id
         WHERE t.teacher_id = ? AND u.status = 'active'"
    );
    if (!$stmt) return null;
    
    $stmt->bind_param("i", $teacherId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    return $result;
}

/**
 * Send email via SMTP with optional user-based "from" address
 * 
 * @param string $toEmail Recipient email
 * @param string $toName Recipient name
 * @param string $subject Email subject
 * @param string $bodyHtml HTML email body
 * @param string|null $bodyText Plain text version
 * @param string|null $fromEmail Override "from" address
 * @param string|null $fromName Override "from" name
 * @param string $errorMessage Reference for error details
 * @return bool Success/failure
 */
function sendEmailViaSMTP(
    string $toEmail,
    string $toName,
    string $subject,
    string $bodyHtml,
    ?string $bodyText = null,
    ?string $fromEmail = null,
    ?string $fromName = null,
    string &$errorMessage = ''
): bool
{
    $toEmail = trim($toEmail);
    if ($toEmail === '') {
        $errorMessage = 'Recipient email is empty.';
        error_log('[Mailer] ERROR: ' . $errorMessage);
        return false;
    }

    $config = getMailConfig();

    // Use provided from address or config default
    $fromEmail = trim($fromEmail ?: $config['default_from_address']);
    $fromName = trim($fromName ?: $config['default_from_name']);

    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        $errorMessage = 'Invalid from email address.';
        error_log('[Mailer] ERROR: ' . $errorMessage . ' - From: ' . $fromEmail);
        return false;
    }

    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        $errorMessage = 'Invalid recipient email address: ' . $toEmail;
        error_log('[Mailer] ERROR: ' . $errorMessage);
        return false;
    }

    // Log the email sending attempt
    error_log('[Mailer] Attempting to send email: To=' . $toEmail . ', Subject=' . $subject . ', From=' . $fromEmail);

    // Load PHPMailer if available
    if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
        require_once __DIR__ . '/../vendor/autoload.php';
    }

    if (class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = (string)$config['host'];
            $mail->Port = (int)$config['port'];
            $mail->SMTPAuth = true;
            $mail->Username = (string)$config['username'];
            $mail->Password = (string)$config['password'];
            $mail->SMTPSecure = (string)$config['encryption'];
            
            // Validate SMTP settings
            if ($config['host'] === '' || $config['username'] === '' || $config['password'] === '') {
                throw new Exception('SMTP configuration incomplete');
            }

            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($toEmail, $toName);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $bodyHtml;
            if ($bodyText) {
                $mail->AltBody = $bodyText;
            }
            
            $mail->send();
            error_log('[Mailer] SUCCESS: Email sent to ' . $toEmail);
            return true;

        } catch (\Throwable $e) {
            $errorMessage = 'SMTP send failed: ' . $e->getMessage();
            error_log('[Mailer] PHPMAILER ERROR: ' . $errorMessage . ' - To: ' . $toEmail);
        }
    }

    // Fallback to PHP mail()
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
    $headers .= "From: {$fromName} <{$fromEmail}>\r\n";

    if (@mail($toEmail, $subject, $bodyHtml, $headers)) {
        error_log('[Mailer] SUCCESS (fallback): Email sent to ' . $toEmail);
        return true;
    }

    $errorMessage = 'Mail function failed.';
    error_log('[Mailer] FALLBACK ERROR: ' . $errorMessage . ' - To: ' . $toEmail);
    return false;
}

/**
 * Send email FROM a specific teacher (appears from that teacher's account)
 * 
 * @param int $teacherId Teacher sending the email
 * @param string $toEmail Recipient email
 * @param string $toName Recipient name
 * @param string $subject Email subject
 * @param string $bodyHtml HTML body
 * @param string|null $bodyText Plain text body
 * @param string $errorMessage Reference for error details
 * @return bool Success/failure
 */
function sendEmailFromTeacher(
    int $teacherId,
    string $toEmail,
    string $toName,
    string $subject,
    string $bodyHtml,
    ?string $bodyText = null,
    string &$errorMessage = ''
): bool
{
    global $conn;

    $teacherInfo = getTeacherEmailInfo($teacherId, $conn);
    
    if (!$teacherInfo || !filter_var($teacherInfo['email'], FILTER_VALIDATE_EMAIL)) {
        // Fallback: use system default
        $config = getMailConfig();
        return sendEmailViaSMTP(
            $toEmail,
            $toName,
            $subject,
            $bodyHtml,
            $bodyText,
            $config['default_from_address'],
            $config['default_from_name'],
            $errorMessage
        );
    }

    $fromName = trim($teacherInfo['first_name'] . ' ' . $teacherInfo['last_name']);

    return sendEmailViaSMTP(
        $toEmail,
        $toName,
        $subject,
        $bodyHtml,
        $bodyText,
        $teacherInfo['email'],
        $fromName,
        $errorMessage
    );
}

/**
 * Send email FROM a specific user (appears from that user's account)
 * 
 * @param int $userId User sending the email
 * @param string $toEmail Recipient email
 * @param string $toName Recipient name
 * @param string $subject Email subject
 * @param string $bodyHtml HTML body
 * @param string|null $bodyText Plain text body
 * @param string $errorMessage Reference for error details
 * @return bool Success/failure
 */
function sendEmailFromUser(
    int $userId,
    string $toEmail,
    string $toName,
    string $subject,
    string $bodyHtml,
    ?string $bodyText = null,
    string &$errorMessage = ''
): bool
{
    global $conn;

    $userInfo = getUserEmailInfo($userId, $conn);
    
    if (!$userInfo || !filter_var($userInfo['email'], FILTER_VALIDATE_EMAIL)) {
        // Fallback: use system default
        $config = getMailConfig();
        return sendEmailViaSMTP(
            $toEmail,
            $toName,
            $subject,
            $bodyHtml,
            $bodyText,
            $config['default_from_address'],
            $config['default_from_name'],
            $errorMessage
        );
    }

    $fromName = trim($userInfo['first_name'] . ' ' . $userInfo['last_name']);

    return sendEmailViaSMTP(
        $toEmail,
        $toName,
        $subject,
        $bodyHtml,
        $bodyText,
        $userInfo['email'],
        $fromName,
        $errorMessage
    );
}

/**
 * Send credential request OTP email (system email).
 * 
 * @param string $toEmail Recipient email
 * @param string $studentName Student name
 * @param string $otpCode OTP code
 * @param int $expiryMinutes Minutes until expiry
 * @param string $errorMessage Reference for error details
 * @return bool Success/failure
 */
function sendCredentialOtpEmail(
    string $toEmail,
    string $studentName,
    string $otpCode,
    int $expiryMinutes,
    string &$errorMessage = ''
): bool
{
    $toEmail = trim($toEmail);
    if ($toEmail === '') {
        $errorMessage = 'Recipient email is empty.';
        return false;
    }

    $subject = 'Credential Request Verification Code';
    $safeName = htmlspecialchars($studentName, ENT_QUOTES, 'UTF-8');
    $safeOtp = htmlspecialchars($otpCode, ENT_QUOTES, 'UTF-8');
    
    $bodyHtml = "
        <div style=\"font-family: Arial, sans-serif; line-height: 1.5; color: #333;\">
            <h2 style=\"color: #800000;\">Credential Request Verification</h2>
            <p>Hello {$safeName},</p>
            <p>Your verification code is:</p>
            <p style=\"font-size: 28px; font-weight: bold; letter-spacing: 4px; color: #800000;\">{$safeOtp}</p>
            <p>This code expires in {$expiryMinutes} minutes.</p>
            <p>If you did not request this, you may ignore this email.</p>
        </div>
    ";
    
    $bodyText = "Hello {$studentName},\n\nYour credential request verification code is: {$otpCode}\nThis code expires in {$expiryMinutes} minutes.\n\nIf you did not request this, please ignore this email.";

    $config = getMailConfig();
    return sendEmailViaSMTP(
        $toEmail,
        $studentName,
        $subject,
        $bodyHtml,
        $bodyText,
        $config['default_from_address'],
        $config['default_from_name'],
        $errorMessage
    );
}

/**
 * Send notification email to student FROM a teacher
 * 
 * @param int $teacherId Teacher sending the notification
 * @param string $studentEmail Student email
 * @param string $studentName Student name
 * @param string $subject Email subject
 * @param string $message Message body
 * @param string $errorMessage Reference for error details
 * @return bool Success/failure
 */
function sendNotificationToStudent(
    int $teacherId,
    string $studentEmail,
    string $studentName,
    string $subject,
    string $message,
    string &$errorMessage = ''
): bool
{
    $bodyHtml = "
        <div style=\"font-family: Arial, sans-serif; line-height: 1.5; color: #333;\">
            <h3 style=\"color: #800000;\">Notification</h3>
            <p>Hello {$studentName},</p>
            <div>{$message}</div>
            <p style=\"margin-top: 20px; font-size: 12px; color: #666;\">This is an automated notification from the Capstone system.</p>
        </div>
    ";

    return sendEmailFromTeacher(
        $teacherId,
        $studentEmail,
        $studentName,
        $subject,
        $bodyHtml,
        null,
        $errorMessage
    );
}

