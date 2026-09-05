<?php
session_start();

// Return JSON response for AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
    header('Content-Type: application/json');

    // Check if the request is POST and the action is change_password
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['action']) || $_POST['action'] !== 'change_password') {
        echo json_encode(['success' => false, 'message' => 'Invalid request']);
        exit();
    }

    // Verify temp session exists
    if (!isset($_SESSION['temp_user_id']) || !isset($_SESSION['temp_username'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid session']);
        exit();
    }

    // Include database connection
    require_once 'includes/db_connection.php';

    $new_password = $_POST['new_password'] ?? '';

    // Validate password
    if (empty($new_password)) {
        echo json_encode(['success' => false, 'message' => 'Password cannot be empty']);
        exit();
    }

    // Password complexity check
    if (!preg_match('/^(?=.*[A-Za-z])(?=.*\d)[A-Za-z\d]{8,}$/', $new_password)) {
        echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters long and contain both letters and numbers']);
        exit();
    }

    // Hash the new password
    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

    // Update the password and set must_change_password to 0
    $stmt = $conn->prepare("UPDATE users SET password = ?, must_change_password = 0 WHERE user_id = ?");
    $stmt->bind_param("si", $hashed_password, $_SESSION['temp_user_id']);

    if ($stmt->execute()) {
        // Get user information for the session
        $user_stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
        $user_stmt->bind_param("i", $_SESSION['temp_user_id']);
        $user_stmt->execute();
        $result = $user_stmt->get_result();
        $user = $result->fetch_assoc();
        $user_stmt->close();

        if (!$user) {
            echo json_encode([
                'success' => false,
                'message' => 'User not found'
            ]);
            $stmt->close();
            $conn->close();
            exit();
        }

        // Clear temporary session variables
        unset($_SESSION['temp_user_id']);
        unset($_SESSION['temp_username']);

        // Set regular session variables
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['username'] = $user['account_number'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['first_name'] = $user['first_name'];
        $_SESSION['last_name'] = $user['last_name'];
        $_SESSION['email'] = $user['email'];

        // Determine redirect URL based on role
        $redirect_url = '';
        switch ($user['role']) {
            case 'admin':
                $redirect_url = '/capstone/admin/admin_dashboard.php';
                break;
            case 'registrar':
                $redirect_url = '/capstone/registrar/registrar_dashboard.php';
                break;
            case 'teacher':
                $redirect_url = '/capstone/teacher/teacher_dashboard.php';
                break;
            case 'student':
                $redirect_url = '/capstone/student/student_dashboard.php';
                break;
        }

        echo json_encode([
            'success' => true,
            'message' => 'Password updated successfully',
            'redirect' => $redirect_url
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to update password'
        ]);
    }

    $stmt->close();
    $conn->close();
    exit();
}

// For page view - render the modal UI
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="/capstone/images/school-logo.png" type="image/png">
    <title>Change Password</title>
    
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
        
        /* Add beautiful modal styling matching faculty_information_management.php */
        .modal-content {
            border-radius: 15px;
            border: none;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            backdrop-filter: blur(10px);
        }

        .modal-header {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-hover));
            color: white;
            border-radius: 15px 15px 0 0;
            border-bottom: none;
            padding: 25px;
        }

        .modal-header .modal-title {
            font-weight: 700;
            font-size: 1.5rem;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .modal-header .modal-title i {
            font-size: 1.8rem;
        }

        .btn-close-white {
            filter: brightness(0) invert(1);
        }

        .modal-body {
            padding: 35px;
            background-color: #ffffff;
        }

        .modal-body .form-label {
            font-weight: 600;
            color: #333;
            margin-bottom: 10px;
        }

        .modal-body .form-label strong {
            color: var(--primary-color);
        }

        /* Enhanced form input styling */
        .form-control, .form-select {
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 12px 16px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(128, 0, 0, 0.15);
            background-color: #fafafa;
        }

        .form-control::placeholder {
            color: #adb5bd;
        }

        /* Password strength indicator */
        .password-strength {
            margin-top: 12px;
            padding: 12px;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 500;
            display: none;
        }

        .password-strength.show {
            display: block;
        }

        .strength-weak {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .strength-medium {
            background-color: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }

        .strength-strong {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        /* Password requirements checklist */
        .password-requirements {
            background-color: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 10px;
            padding: 15px;
            margin-top: 15px;
            font-size: 0.9rem;
        }

        .requirement-item {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 8px;
            color: #6c757d;
        }

        .requirement-item.met {
            color: #155724;
        }

        .requirement-item i {
            font-size: 0.8rem;
            width: 20px;
            text-align: center;
        }

        .requirement-item.pending i {
            color: #ffc107;
        }

        .requirement-item.met i {
            color: #28a745;
        }

        .modal-footer {
            border-top: 1px solid #e9ecef;
            padding: 20px 35px;
            border-radius: 0 0 15px 15px;
            background-color: #f8f9fa;
            gap: 12px;
        }

        /* Enhanced button styling */
        .btn-change-password {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-hover));
            border: none;
            color: white;
            padding: 12px 30px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
            font-size: 1rem;
        }

        .btn-change-password:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(128, 0, 0, 0.3);
            color: white;
        }

        .btn-change-password:active {
            transform: translateY(0);
        }

        .btn-cancel {
            background-color: #6c757d;
            border: none;
            color: white;
            padding: 12px 30px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-cancel:hover {
            background-color: #5a6268;
            color: white;
        }

        /* Alert styling for messages */
        .alert {
            border-radius: 10px;
            border: none;
            padding: 15px;
            margin-bottom: 20px;
            font-weight: 500;
        }

        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
        }

        .alert-success {
            background-color: #d4edda;
            color: #155724;
        }

        .alert-info {
            background-color: #d1ecf1;
            color: #0c5460;
        }

        /* Loading state for button */
        .btn-change-password.loading {
            pointer-events: none;
            opacity: 0.8;
        }

        @media (max-width: 768px) {
            .modal-body {
                padding: 25px;
            }

            .modal-footer {
                padding: 15px 25px;
                flex-direction: column;
            }

            .btn-change-password,
            .btn-cancel {
                width: 100%;
            }

            .modal-header .modal-title {
                font-size: 1.25rem;
            }
        }
    </style>
</head>
<body>
    <!-- Beautiful Change Password Modal with enhanced UI -->
    <div class="modal fade" id="changePasswordModal" tabindex="-1" aria-labelledby="changePasswordLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="changePasswordLabel">
                        <i class="fas fa-lock"></i>Change Password
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- Alert for messages -->
                    <div id="messageAlert" class="alert d-none" role="alert"></div>

                    <form id="changePasswordForm" method="POST">
                        <input type="hidden" name="action" value="change_password">

                        <div class="mb-4">
                            <label for="newPassword" class="form-label">
                                <i class="fas fa-key me-2" style="color: var(--primary-color);"></i><strong>New Password</strong>
                            </label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="newPassword" name="new_password" placeholder="Enter new password" required>
                                <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label for="confirmPassword" class="form-label">
                                <i class="fas fa-check-circle me-2" style="color: var(--primary-color);"></i><strong>Confirm Password</strong>
                            </label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="confirmPassword" placeholder="Confirm new password" required>
                                <button class="btn btn-outline-secondary" type="button" id="toggleConfirmPassword">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Password strength indicator -->
                        <div id="passwordStrength" class="password-strength"></div>

                        <!-- Password requirements checklist -->
                        <div class="password-requirements">
                            <div style="font-weight: 600; color: var(--primary-color); margin-bottom: 12px;">
                                <i class="fas fa-clipboard-check me-2"></i>Password Requirements:
                            </div>
                            <div class="requirement-item pending" id="lengthReq">
                                <i class="fas fa-circle"></i>
                                <span>At least 8 characters</span>
                            </div>
                            <div class="requirement-item pending" id="letterReq">
                                <i class="fas fa-circle"></i>
                                <span>Contains letters (A-Z, a-z)</span>
                            </div>
                            <div class="requirement-item pending" id="numberReq">
                                <i class="fas fa-circle"></i>
                                <span>Contains numbers (0-9)</span>
                            </div>
                            <div class="requirement-item pending" id="matchReq">
                                <i class="fas fa-circle"></i>
                                <span>Passwords match</span>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-cancel" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Cancel
                    </button>
                    <button type="button" class="btn btn-change-password" id="submitBtn">
                        <i class="fas fa-save me-2"></i>Update Password
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('changePasswordForm');
            const newPasswordInput = document.getElementById('newPassword');
            const confirmPasswordInput = document.getElementById('confirmPassword');
            const togglePasswordBtn = document.getElementById('togglePassword');
            const toggleConfirmPasswordBtn = document.getElementById('toggleConfirmPassword');
            const submitBtn = document.getElementById('submitBtn');
            const messageAlert = document.getElementById('messageAlert');
            const passwordStrength = document.getElementById('passwordStrength');

            togglePasswordBtn.addEventListener('click', function() {
                const type = newPasswordInput.type === 'password' ? 'text' : 'password';
                newPasswordInput.type = type;
                this.innerHTML = type === 'password' ? '<i class="fas fa-eye"></i>' : '<i class="fas fa-eye-slash"></i>';
            });

            toggleConfirmPasswordBtn.addEventListener('click', function() {
                const type = confirmPasswordInput.type === 'password' ? 'text' : 'password';
                confirmPasswordInput.type = type;
                this.innerHTML = type === 'password' ? '<i class="fas fa-eye"></i>' : '<i class="fas fa-eye-slash"></i>';
            });

            newPasswordInput.addEventListener('input', validatePassword);
            confirmPasswordInput.addEventListener('input', validatePassword);

            function validatePassword() {
                const password = newPasswordInput.value;
                const confirmPassword = confirmPasswordInput.value;

                // Check requirements
                const lengthReq = document.getElementById('lengthReq');
                const letterReq = document.getElementById('letterReq');
                const numberReq = document.getElementById('numberReq');
                const matchReq = document.getElementById('matchReq');

                // Length check (8+ characters)
                if (password.length >= 8) {
                    lengthReq.classList.add('met');
                    lengthReq.classList.remove('pending');
                    lengthReq.innerHTML = '<i class="fas fa-check-circle"></i><span>At least 8 characters</span>';
                } else {
                    lengthReq.classList.remove('met');
                    lengthReq.classList.add('pending');
                    lengthReq.innerHTML = '<i class="fas fa-circle"></i><span>At least 8 characters</span>';
                }

                // Letter check
                if (/[A-Za-z]/.test(password)) {
                    letterReq.classList.add('met');
                    letterReq.classList.remove('pending');
                    letterReq.innerHTML = '<i class="fas fa-check-circle"></i><span>Contains letters (A-Z, a-z)</span>';
                } else {
                    letterReq.classList.remove('met');
                    letterReq.classList.add('pending');
                    letterReq.innerHTML = '<i class="fas fa-circle"></i><span>Contains letters (A-Z, a-z)</span>';
                }

                // Number check
                if (/\d/.test(password)) {
                    numberReq.classList.add('met');
                    numberReq.classList.remove('pending');
                    numberReq.innerHTML = '<i class="fas fa-check-circle"></i><span>Contains numbers (0-9)</span>';
                } else {
                    numberReq.classList.remove('met');
                    numberReq.classList.add('pending');
                    numberReq.innerHTML = '<i class="fas fa-circle"></i><span>Contains numbers (0-9)</span>';
                }

                // Match check
                if (confirmPassword && password === confirmPassword) {
                    matchReq.classList.add('met');
                    matchReq.classList.remove('pending');
                    matchReq.innerHTML = '<i class="fas fa-check-circle"></i><span>Passwords match</span>';
                } else if (confirmPassword) {
                    matchReq.classList.remove('met');
                    matchReq.classList.add('pending');
                    matchReq.innerHTML = '<i class="fas fa-circle"></i><span>Passwords match</span>';
                } else {
                    matchReq.classList.remove('met');
                    matchReq.classList.add('pending');
                    matchReq.innerHTML = '<i class="fas fa-circle"></i><span>Passwords match</span>';
                }

                // Update password strength indicator
                updatePasswordStrength(password);
            }

            function updatePasswordStrength(password) {
                let strength = 0;
                if (password.length >= 8) strength++;
                if (/[A-Za-z]/.test(password)) strength++;
                if (/\d/.test(password)) strength++;

                if (strength === 0) {
                    passwordStrength.classList.remove('show');
                } else if (strength <= 1) {
                    passwordStrength.classList.add('show', 'strength-weak');
                    passwordStrength.classList.remove('strength-medium', 'strength-strong');
                    passwordStrength.innerHTML = '<i class="fas fa-shield-alt me-2"></i>Weak password';
                } else if (strength === 2) {
                    passwordStrength.classList.add('show', 'strength-medium');
                    passwordStrength.classList.remove('strength-weak', 'strength-strong');
                    passwordStrength.innerHTML = '<i class="fas fa-shield-alt me-2"></i>Medium strength';
                } else {
                    passwordStrength.classList.add('show', 'strength-strong');
                    passwordStrength.classList.remove('strength-weak', 'strength-medium');
                    passwordStrength.innerHTML = '<i class="fas fa-shield-alt me-2"></i>Strong password';
                }
            }

            submitBtn.addEventListener('click', async function() {
                const password = newPasswordInput.value;
                const confirmPassword = confirmPasswordInput.value;

                // Validate passwords match
                if (password !== confirmPassword) {
                    showMessage('Passwords do not match', 'danger');
                    return;
                }

                // Validate password complexity
                if (!password || !/^(?=.*[A-Za-z])(?=.*\d)[A-Za-z\d]{8,}$/.test(password)) {
                    showMessage('Password must be at least 8 characters with letters and numbers', 'danger');
                    return;
                }

                // Submit form
                submitBtn.disabled = true;
                submitBtn.classList.add('loading');
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Updating...';

                try {
                    const formData = new FormData(form);
                    const response = await fetch('change_password.php', {
                        method: 'POST',
                        body: formData
                    });

                    const data = await response.json();

                    if (data.success) {
                        showMessage('Password updated successfully! Redirecting...', 'success');
                        setTimeout(() => {
                            window.location.href = data.redirect;
                        }, 1500);
                    } else {
                        showMessage(data.message || 'Failed to update password', 'danger');
                        submitBtn.disabled = false;
                        submitBtn.classList.remove('loading');
                        submitBtn.innerHTML = '<i class="fas fa-save me-2"></i>Update Password';
                    }
                } catch (error) {
                    showMessage('Error updating password. Please try again.', 'danger');
                    submitBtn.disabled = false;
                    submitBtn.classList.remove('loading');
                    submitBtn.innerHTML = '<i class="fas fa-save me-2"></i>Update Password';
                }
            });

            function showMessage(message, type) {
                messageAlert.textContent = message;
                messageAlert.className = `alert alert-${type} d-block`;
                messageAlert.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }

            // Show modal on page load if needed
            const modal = new bootstrap.Modal(document.getElementById('changePasswordModal'));
            // Uncomment to auto-show on page load:
            // modal.show();
        });
    </script>
</body>
</html>
