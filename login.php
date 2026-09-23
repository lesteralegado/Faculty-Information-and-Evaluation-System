<?php
session_start();

// Prevent browser from caching the page
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Include database connection
include 'includes/db_connection.php';
require_once __DIR__ . '/includes/student_access_control.php';

// Redirect user to their dashboard if already logged in
if (isset($_SESSION['username']) && isset($_SESSION['role'])) {
    switch ($_SESSION['role']) {
        case 'admin':
            header("Location: /admin/admin_dashboard.php");
            exit();
        case 'registrar':
            header("Location: /registrar/registrar_dashboard.php");
            exit();
        case 'teacher':
            header("Location: /teacher/teacher_dashboard.php");
            exit();
        case 'student':
            // Inactive students are restricted to Credential Request only
            if (isset($_SESSION['status']) && strtolower((string)$_SESSION['status']) !== 'active') {
                header("Location: /student/credential_form_request.php?restricted=inactive");
            } else {
                header("Location: /student/student_dashboard.php");
            }
            exit();
    }
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim($_POST['username'] ?? '');
    $pass = trim($_POST['password'] ?? '');

    if (empty($user) || empty($pass)) {
        header("Location: login.php?error=1");
        exit();
    }

    // Query using account_number instead of student_number.
    // NOTE: Inactive students are allowed to log in but are restricted to Credential Request only.
    // Non-student roles must still be active to log in.
    // Use latest matching account to avoid role mix-ups when legacy duplicate account_number rows exist.
    $stmt = $conn->prepare("SELECT user_id, account_number, email, password, role, first_name, last_name, status, must_change_password FROM users WHERE account_number = ? ORDER BY user_id DESC LIMIT 1");
    $stmt->bind_param("s", $user);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();

        $role = $row['role'] ?? '';
        $statusNorm = strtolower((string)($row['status'] ?? 'inactive'));

        // Block inactive/non-active accounts for non-student roles.
        if ($role !== 'student' && $statusNorm !== 'active') {
            header("Location: login.php?error=inactive");
            exit();
        }
        
        // Check password - handle both hashed and plain text passwords
        $password_valid = false;
        if (password_verify($pass, $row['password'])) {
            // Password is hashed and matches
            $password_valid = true;
        } elseif ($row['password'] === $pass) {
            // Password is plain text and matches (for default passwords)
            $password_valid = true;
        }

        if ($password_valid) {
            // Check if user must change password
            if ($row['must_change_password'] == 1) {
                $_SESSION['temp_user_id'] = $row['user_id'];
                $_SESSION['temp_username'] = $user;
                header("Location: login.php?change_password=1");
                exit();
            }

            // Regular login - set session variables
            $_SESSION['user_id'] = $row['user_id'];
            $_SESSION['username'] = $row['account_number'];  // Using account_number as username
            $_SESSION['role'] = $role;
            $_SESSION['first_name'] = $row['first_name'] ?? '';
            $_SESSION['last_name'] = $row['last_name'] ?? '';
            $_SESSION['email'] = $row['email'] ?? '';
            $_SESSION['status'] = $statusNorm; // Store normalized user status in session

            switch ($role) {
                case 'admin':
                    header("Location: /admin/admin_dashboard.php");
                    break;
                case 'registrar':
                    header("Location: /registrar/registrar_dashboard.php");
                    break;
                case 'teacher':
                    header("Location: /teacher/teacher_dashboard.php");
                    break;
                case 'student':
                    // Inactive students can log in but are restricted to Credential Request.
                    if ($statusNorm !== 'active') {
                        header("Location: /student/credential_form_request.php?restricted=inactive");
                        break;
                    }

                    // Enrollment determines access scope for ACTIVE students.
                    // Not-enrolled students can log in but are restricted to Credential Request.
                    if (isset($_SESSION['user_id'])) {
                        $ctx = getStudentAccessContext($conn, (int)$_SESSION['user_id']);
                        if (!empty($ctx['enrolled'])) {
                            header("Location: /student/student_dashboard.php");
                        } else {
                            header("Location: /student/credential_form_request.php?restricted=1");
                        }
                    } else {
                        header("Location: /student/student_dashboard.php");
                    }
                    break;
            }
            exit();
        }
    }
    header("Location: login.php?error=1");
    exit();

    $stmt->close();
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Cache-Control" content="no-store">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Holy Redeemer School of San Isidro</title>
    <link rel="icon" href="images/school-logo.png" type="image/png">

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
            --light-bg: rgba(255, 255, 255, 0.15);
            --glass-border: rgba(255, 255, 255, 0.25);
        }
        body {
            font-family: 'Poppins', sans-serif;
            background: url('images/holy redeemer school bg .png') no-repeat center center / cover;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(4px);
        }
        .login-card {
            background: var(--light-bg);
            backdrop-filter: blur(12px);
            border: 1px solid var(--glass-border);
            border-radius: 20px;
            padding: 40px 35px;
            width: 100%;
            max-width: 420px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.2);
        }
        .login-card img {
            width: 80px;
            margin-bottom: 15px;
        }
        .login-card h2 {
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 25px;
        }
        .form-control {
            border-radius: 10px;
            padding: 12px 15px;
            border: 1px solid #ddd;
            transition: 0.3s;
        }
        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 6px rgba(128,0,0,0.4);
        }
        .btn-login {
            background: var(--primary-color);
            border: none;
            border-radius: 10px;
            padding: 12px;
            color: #fff;
            font-weight: 600;
            transition: 0.3s;
        }
        .btn-login:hover {
            background: var(--primary-hover);
            transform: translateY(-2px);
        }
        .text-forgot {
            display: block;
            margin-top: 12px;
            font-size: 14px;
            text-align: center;
            color: var(--primary-color);
        }
        .text-forgot:hover {
            text-decoration: underline;
        }
        /* Modal Styling */
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

        .btn-change-password.loading {
            pointer-events: none;
            opacity: 0.8;
        }

        .loginErrorPopup {
            display:none;
            position: fixed;
            top: 330px;
            left: 50%;
            transform: translateX(-50%);
            background: #800000;
            color: #fff;
            padding: 16px 32px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 16px;
            z-index: 9999;
            box-shadow: 0 4px 18px rgba(0,0,0,0.15);
            text-align: center;
            min-width: 280px;
            max-width: 90vw;
        }
        .loginErrorPopup.show { display: block; }
    </style>
</head>
<body>
    <div class="login-card text-center">
        <img src="images/school-logo.png" alt="School Logo">
        <h2>Faculty Information and Evaluation System </h2>

        <form method="POST" action="login.php" novalidate id="loginForm" autocomplete="off">
            <div class="mb-3">
                <input type="text" class="form-control" id="username" name="username" placeholder="Student Number" required autocomplete="username">
            </div>
            <div class="mb-3 position-relative">
                <input type="password" class="form-control" id="password" name="password" placeholder="Password" required autocomplete="current-password">
                <i class="fas fa-eye-slash position-absolute top-50 end-0 translate-middle-y me-3 text-muted" id="eyeIcon" style="cursor:pointer;" onclick="togglePasswordVisibility()"></i>
            </div>
            <button type="submit" class="btn btn-login w-100" id="loginBtn">
                 Log In
            </button>
            <a href="#" class="text-forgot" onclick="openForgotModal()">Forgot Password?</a>
        </form>
    </div>

    <!-- Forgot Password Modal -->
    <div class="modal fade" id="forgotPasswordModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow-lg">
          <div class="modal-header">
            <h5 class="modal-title"><i class="fas fa-lock me-2"></i> Account Recovery</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body p-4">
            <p>If you are entering the correct credentials but still get <strong>"Invalid login credentials"</strong>, please contact the Holy Redeemer School Official Facebook Page .</p>
            <p>Message us on Facebook and provide your <strong>Full Name, Student Number, Program</strong>, and attach a photo of your <strong>School ID (front/back)</strong> or <strong>Assessment Form</strong>.</p>
            <div class="text-center mt-3">
              <a href="https://www.facebook.com/hrscabuyaomabuhay" target="_blank" class="btn btn-success w-100">
                <i class="fab fa-facebook me-2"></i> facebook.com/hrscabuyaomabuhay
              </a>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Popup Notification -->
    <div id="loginErrorPopup" class="loginErrorPopup">
        <span id="loginErrorPopupMsg">Invalid student number or password. Please try again.</span>
    </div>

    <!-- Change Password Modal -->
    <div class="modal fade" id="changePasswordModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="changePasswordLabel">
                        <i class="fas fa-key me-2"></i>Change Password Required
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- Alert for messages -->
                    <div id="messageAlert" class="alert d-none" role="alert"></div>

                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        For security reasons, you must change your default password before accessing the system.
                    </div>

                    <form id="changePasswordForm" method="POST">
                        <div class="mb-3">
                            <label for="newPassword" class="form-label"><strong>New Password</strong></label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="newPassword" name="newPassword" required
                                       minlength="8" pattern="^(?=.*[A-Za-z])(?=.*\d)[A-Za-z\d]{8,}$">
                                <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                                    <i class="fas fa-eye-slash"></i>
                                </button>
                            </div>
                            <div id="passwordStrength" class="password-strength"></div>
                        </div>

                        <div class="mb-3">
                            <label for="confirmPassword" class="form-label"><strong>Confirm Password</strong></label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="confirmPassword" name="confirmPassword" required>
                                <button class="btn btn-outline-secondary" type="button" id="toggleConfirmPassword">
                                    <i class="fas fa-eye-slash"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Password Requirements Checklist -->
                        <div class="password-requirements">
                            <div id="lengthReq" class="requirement-item pending">
                                <i class="fas fa-circle"></i><span>At least 8 characters</span>
                            </div>
                            <div id="letterReq" class="requirement-item pending">
                                <i class="fas fa-circle"></i><span>Contains letters (A-Z, a-z)</span>
                            </div>
                            <div id="numberReq" class="requirement-item pending">
                                <i class="fas fa-circle"></i><span>Contains numbers (0-9)</span>
                            </div>
                            <div id="matchReq" class="requirement-item pending">
                                <i class="fas fa-circle"></i><span>Passwords match</span>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-cancel" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Cancel
                    </button>
                    <button type="button" class="btn btn-change-password" id="submitBtn">
                        <i class="fas fa-save me-2"></i>Save Changes
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function togglePasswordVisibility() {
            const passwordInput = document.getElementById('password');
            const eyeIcon = document.getElementById('eyeIcon');
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                eyeIcon.classList.replace('fa-eye-slash', 'fa-eye');
            } else {
                passwordInput.type = 'password';
                eyeIcon.classList.replace('fa-eye', 'fa-eye-slash');
            }
        }

        function togglePasswordView(inputId) {
            const input = document.getElementById(inputId);
            const icon = input.nextElementSibling.querySelector('i');
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
            } else {
                input.type = 'password';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
            }
        }

        function openForgotModal() {
            var myModal = new bootstrap.Modal(document.getElementById('forgotPasswordModal'));
            myModal.show();
        }

        // Smooth login UI: prevent lag by disabling button and showing spinner
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const btn = document.getElementById('loginBtn');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Logging in...';
        });

        // Handle password change functionality
        const changePasswordForm = document.getElementById('changePasswordForm');
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
                const formData = new FormData();
                formData.append('action', 'change_password');
                formData.append('new_password', password);

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
                    submitBtn.disabled = false;
                    submitBtn.classList.remove('loading');
                    submitBtn.innerHTML = '<i class="fas fa-save me-2"></i>Save Changes';
                    showMessage(data.message || 'An error occurred. Please try again.', 'danger');
                }
            } catch (error) {
                submitBtn.disabled = false;
                submitBtn.classList.remove('loading');
                submitBtn.innerHTML = '<i class="fas fa-save me-2"></i>Save Changes';
                showMessage('An error occurred. Please try again.', 'danger');
            }
        });

        function showMessage(message, type) {
            messageAlert.textContent = message;
            messageAlert.className = `alert alert-${type} d-block`;
            messageAlert.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        // Show popup notification on error
        function showLoginErrorPopup(msg) {
            var popup = document.getElementById('loginErrorPopup');
            document.getElementById('loginErrorPopupMsg').textContent = msg;
            popup.style.display = 'block';
            setTimeout(function() {
                popup.style.display = 'none';
            }, 3000);
        }

        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            
            // Handle password change requirement
            if (urlParams.get('change_password') === '1') {
                const changePasswordModal = new bootstrap.Modal(document.getElementById('changePasswordModal'));
                changePasswordModal.show();
                // Remove the query parameter
                if (window.history.replaceState) {
                    const url = new URL(window.location);
                    url.searchParams.delete('change_password');
                    window.history.replaceState({}, document.title, url.pathname);
                }
            }
            
            // Handle login errors
            if (urlParams.get('error') === '1') {
                showLoginErrorPopup("Invalid student number or password. Please try again.");
                if (window.history.replaceState) {
                    const url = new URL(window.location);
                    url.searchParams.delete('error');
                    window.history.replaceState({}, document.title, url.pathname);
                }
                const btn = document.getElementById('loginBtn');
                btn.disabled = false;
                btn.innerHTML = 'Log In';
            }
            if (urlParams.get('error') === '2') {
                showLoginErrorPopup("You must log in first to access the dashboard.");
                if (window.history.replaceState) {
                    const url = new URL(window.location);
                    url.searchParams.delete('error');
                    window.history.replaceState({}, document.title, url.pathname);
                }
                const btn = document.getElementById('loginBtn');
                btn.disabled = false;
                btn.innerHTML = 'Log In';
            }
            if (urlParams.get('error') === 'inactive') {
                showLoginErrorPopup("Your account is inactive. Please contact the registrar.");
                if (window.history.replaceState) {
                    const url = new URL(window.location);
                    url.searchParams.delete('error');
                    window.history.replaceState({}, document.title, url.pathname);
                }
                const btn = document.getElementById('loginBtn');
                btn.disabled = false;
                btn.innerHTML = 'Log In';
            }
        });
    </script>
</body>
</html>
