<?php
session_start();

// Preserve previous form input for redisplay after validation errors
$old_input = $_SESSION['old_input'] ?? [];
unset($_SESSION['old_input']);

// Check if user is logged in and has admin privileges
if (!isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    header("Location: ../index.php");  
    exit();
}

// Include database connection and helper
include '../includes/db_connection.php';
include '../includes/evaluation_status_helper.php';
require_once __DIR__ . '/../includes/dependencies.php';
require_once __DIR__ . '/../includes/modern_alert_system.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;

// Check evaluation status using helper function
$evalStatus = getEvaluationStatus($conn);
$is_evaluation_ongoing = $evalStatus['is_ongoing'];
$evaluation_start_date = $evalStatus['start_date'];
$evaluation_end_date = $evalStatus['end_date'];
$evaluation_phase = $evalStatus['phase'];

// Fetch current school year and semester from the system
$current_sy_sql = "SELECT school_year, semester FROM currentschoolyearandsemester ORDER BY id DESC LIMIT 1";
$current_sy_result = $conn->query($current_sy_sql);
$current_sy_data = $current_sy_result->fetch_assoc();
$current_school_year = $current_sy_data['school_year'] ?? '2025-2026';
$current_semester = $current_sy_data['semester'] ?? 1;

if ($_SESSION['role'] !== 'admin') {
    $_SESSION['error'] = 'Only admin accounts can access User Management.';
    header('Location: ../index.php');
    exit();
}

function normalizeRoleValue($role)
{
    return strtolower(trim((string)$role));
}

function normalizeStatusValue($status)
{
    $value = strtolower(trim((string)$status));
    return in_array($value, ['active', 'inactive'], true) ? $value : 'active';
}

function normalizeOptionalStatusValue($status)
{
    $value = strtolower(trim((string)$status));
    return in_array($value, ['active', 'inactive'], true) ? $value : '';
}

function normalizeSchoolYearValue($school_year)
{
    $value = trim((string)$school_year);
    if ($value === '') {
        return '';
    }

    // Normalize long dashes for consistent filtering.
    return str_replace(array("–", "—"), "-", $value);
}

function validateImportRow($row, $line_number, &$errors, &$valid_rows, $existing_emails, $allowed_roles)
{
    $first_name = trim((string)($row['first_name'] ?? ''));
    $last_name = trim((string)($row['last_name'] ?? ''));
    $email = trim((string)($row['email'] ?? ''));
    $role = normalizeRoleValue($row['role'] ?? '');
    $status = normalizeStatusValue($row['status'] ?? 'active');
    $strand = trim((string)($row['strand'] ?? ''));
    $year_level = trim((string)($row['year_level'] ?? ''));

    // Define allowed strands (full names for database storage)
    $allowed_strands = [
        'Accountancy, Business, and Management',
        'Humanities and Social Sciences',
        'Science, Technology, Engineering, Mathematics',
        'Information and Communication Technology'
    ];

    // Map abbreviations to full strand names (accepts both abbreviations and full names in imports)
    $strand_abbreviations = [
        'ABM' => 'Accountancy, Business, and Management',
        'HUMSS' => 'Humanities and Social Sciences',
        'STEM' => 'Science, Technology, Engineering, Mathematics',
        'ICT' => 'Information and Communication Technology'
    ];

    // Convert abbreviation to full name if user provided abbreviation
    if ($strand !== '' && isset($strand_abbreviations[$strand])) {
        $strand = $strand_abbreviations[$strand];
    }

    $row_errors = [];
    if ($first_name === '' || $last_name === '' || $email === '' || $role === '') {
        $row_errors[] = 'Missing required fields (First Name, Last Name, Email, Role).';
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $row_errors[] = 'Invalid email format.';
    }

    if ($email !== '' && isset($existing_emails[strtolower($email)])) {
        $row_errors[] = 'Email already exists.';
    }

    if (!in_array($role, $allowed_roles, true)) {
        $row_errors[] = 'Invalid role. Allowed: admin, registrar, teacher, student.';
    }

    if ($role === 'student') {
        if ($strand === '' || $year_level === '') {
            $row_errors[] = 'Student requires strand and year_level.';
        }
        if ($strand !== '' && !in_array($strand, $allowed_strands, true)) {
            $row_errors[] = 'Invalid strand. Allowed: ABM, HUMSS, STEM, ICT.';
        }
        if ($year_level !== '' && !in_array($year_level, ['11', '12'], true)) {
            $row_errors[] = 'Student year_level must be 11 or 12.';
        }
    } elseif ($role === 'teacher') {
        if ($strand === '') {
            $row_errors[] = 'Teacher requires strand.';
        } elseif (!in_array($strand, $allowed_strands, true)) {
            $row_errors[] = 'Invalid strand. Allowed: ABM, HUMSS, STEM, ICT.';
        }
    }

    if (!empty($row_errors)) {
        $errors[] = 'Row ' . $line_number . ': ' . implode(' ', $row_errors);
        return;
    }

    $valid_rows[] = [
        'first_name' => $first_name,
        'last_name' => $last_name,
        'email' => $email,
        'role' => $role,
        'status' => $status,
        'strand' => $strand,
        'year_level' => $year_level
    ];
}

if (isset($_GET['action']) && $_GET['action'] === 'export_excel') {
    app_require_spreadsheet();
    // Clear output buffers to prevent corruption of Excel file
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    $search = trim($_GET['search'] ?? '');
    $role_filter = normalizeRoleValue($_GET['role'] ?? '');
    $status_filter = normalizeOptionalStatusValue($_GET['status'] ?? '');
    $school_year_filter = normalizeSchoolYearValue($_GET['school_year'] ?? '');
    $allowed_roles = ['admin', 'registrar', 'teacher', 'student'];
    if ($role_filter !== '' && !in_array($role_filter, $allowed_roles, true)) {
        $role_filter = '';
    }

    $sql = "SELECT u.account_number, u.first_name, u.last_name, u.email, u.role, u.status,
            CASE WHEN u.role = 'student' THEN s.strand WHEN u.role = 'teacher' THEN t.strand ELSE '' END AS strand,
            CASE WHEN u.role = 'student' THEN s.year_level ELSE '' END AS year_level,
            CASE WHEN u.role = 'student' THEN sec.section_name ELSE '' END AS section_name,
            CASE WHEN u.role = 'student' THEN s.school_year WHEN u.role = 'teacher' THEN t.school_year ELSE '' END AS school_year
            FROM users u
            LEFT JOIN students s ON u.user_id = s.user_id
            LEFT JOIN teachers t ON u.user_id = t.user_id
            LEFT JOIN sections sec ON s.section_id = sec.section_id
            WHERE 1=1";
    $params = [];
    $types = '';

    if ($status_filter !== '') {
        $sql .= " AND u.status = ?";
        $params[] = $status_filter;
        $types .= 's';
    }
    if ($role_filter !== '') {
        $sql .= " AND u.role = ?";
        $params[] = $role_filter;
        $types .= 's';
    }
    if ($school_year_filter !== '') {
        $sql .= " AND (
            (u.role = 'student' AND REPLACE(REPLACE(IFNULL(s.school_year, ''), '–', '-'), '—', '-') = ?)
            OR
            (u.role = 'teacher' AND REPLACE(REPLACE(IFNULL(t.school_year, ''), '–', '-'), '—', '-') = ?)
        )";
        $params[] = $school_year_filter;
        $params[] = $school_year_filter;
        $types .= 'ss';
    }
    if ($search !== '') {
        $sql .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)";
        $search_like = '%' . $search . '%';
        $params[] = $search_like;
        $params[] = $search_like;
        $params[] = $search_like;
        $types .= 'sss';
    }
    $sql .= " ORDER BY CAST(u.account_number AS UNSIGNED) ASC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        $_SESSION['error'] = 'Unable to prepare export query.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }

    $bind_refs = [$types];
    foreach ($params as $key => $value) {
        $bind_refs[] = &$params[$key];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind_refs);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Users');
    $filter_summary = 'Filters: '
        . 'Role=' . ($role_filter !== '' ? ucfirst($role_filter) : 'All')
        . ' | Status=' . ($status_filter !== '' ? ucfirst($status_filter) : 'All')
        . ' | School Year=' . ($school_year_filter !== '' ? $school_year_filter : 'All');

    $sheet->setCellValue('A1', $filter_summary);
    $sheet->mergeCells('A1:J1');
    $sheet->getStyle('A1')->getFont()->setBold(true);
    $sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF3F4F6');

    $headers = ['Account #', 'First Name', 'Last Name', 'Email', 'Role', 'Status', 'School Year', 'Strand', 'Year Level', 'Section'];
    $sheet->fromArray($headers, null, 'A2');
    $sheet->getStyle('A2:J2')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
    $sheet->getStyle('A2:J2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('800000');

    $row_index = 3;
    foreach ($rows as $row) {
        $sheet->setCellValue('A' . $row_index, $row['account_number']);
        $sheet->setCellValue('B' . $row_index, $row['first_name']);
        $sheet->setCellValue('C' . $row_index, $row['last_name']);
        $sheet->setCellValue('D' . $row_index, $row['email']);
        $sheet->setCellValue('E' . $row_index, ucfirst($row['role']));
        $sheet->setCellValue('F' . $row_index, ucfirst($row['status']));
        $sheet->setCellValue('G' . $row_index, $row['school_year']);
        $sheet->setCellValue('H' . $row_index, $row['strand']);
        $sheet->setCellValue('I' . $row_index, $row['year_level']);
        $sheet->setCellValue('J' . $row_index, $row['section_name']);
        $row_index++;
    }

    foreach (range('A', 'J') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    $filename_parts = ['users'];
    $filename_parts[] = $status_filter !== '' ? $status_filter : 'all-status';
    $filename_parts[] = $role_filter !== '' ? $role_filter : 'all-roles';
    if ($school_year_filter !== '') {
        $filename_parts[] = str_replace(' ', '', $school_year_filter);
    }
    $filename = implode('-', $filename_parts) . '-' . date('Y-m-d-His') . '.xlsx';
    
    // Set proper headers for Excel download
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
    $writer->save('php://output');
    exit();
}

// Handle CRUD operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Re-check evaluation status on POST to prevent bypassing - use helper function
    $post_is_evaluation_ongoing = isEvaluationOngoing($conn);
    
    // Block actions during ongoing evaluation - MUST be first check before any processing
    if ($post_is_evaluation_ongoing) {
        $blocked_actions = ['add', 'update', 'bulk_update_status'];
        if (isset($_POST['action']) && in_array($_POST['action'], $blocked_actions)) {
            $_SESSION['error'] = "This action is not allowed while an evaluation is ongoing. Please wait until the evaluation period ends.";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }
        // Also block status changes to inactive (deactivation)
        if (isset($_POST['action']) && $_POST['action'] === 'update' && isset($_POST['status']) && $_POST['status'] === 'inactive') {
            $_SESSION['error'] = "Cannot deactivate users while an evaluation is ongoing. Please wait until the evaluation period ends.";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }
    }
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                $first_name = trim($_POST['first_name'] ?? '');
                $last_name = trim($_POST['last_name'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $role = trim($_POST['role'] ?? '');
                $status = 'active';  // Always set to active by default

                // Basic validation
                // If role was not set (hidden input race), infer 'student' when strand/year_level provided
                if ($role === '') {
                    $possible_strand = trim($_POST['strand'] ?? '');
                    $possible_year = trim($_POST['year_level'] ?? '');
                    if ($possible_strand !== '' || $possible_year !== '') {
                        $role = 'student';
                    }
                }

                // Validate required fields
                if ($first_name === '' || $last_name === '' || $email === '' || $role === '') {
                    $_SESSION['error'] = "Missing required user fields: First Name, Last Name, Email, and Role are required.";
                    $_SESSION['old_input'] = $_POST;
                    break;
                }

                // Validate email format
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $_SESSION['error'] = "Invalid email format.";
                    $_SESSION['old_input'] = $_POST;
                    break;
                }

                // Check for duplicate email address
                $check_email_stmt = $conn->prepare("SELECT user_id FROM users WHERE LOWER(email) = LOWER(?)");
                if (!$check_email_stmt) {
                    $_SESSION['error'] = "Database error while checking email: " . $conn->error;
                    $_SESSION['old_input'] = $_POST;
                    break;
                }
                $check_email_stmt->bind_param("s", $email);
                $check_email_stmt->execute();
                $email_result = $check_email_stmt->get_result();
                
                if ($email_result && $email_result->num_rows > 0) {
                    $_SESSION['error'] = "This email address is already in use. Please enter a different email address.";
                    $_SESSION['old_input'] = $_POST;
                    $check_email_stmt->close();
                    break;
                }
                $check_email_stmt->close();

                // Validate role is one of the allowed values
                $allowed_roles = ['admin', 'registrar', 'teacher', 'student'];
                if (!in_array($role, $allowed_roles)) {
                    $_SESSION['error'] = "Invalid role specified.";
                    $_SESSION['old_input'] = $_POST;
                    break;
                }

                // Validate role-specific required fields
                if ($role === 'student') {
                    // Student uses 'strand' and 'year_level' field names
                    $strand = trim($_POST['strand'] ?? '');
                    $year_level = trim($_POST['year_level'] ?? '');
                    if ($strand === '' || $year_level === '') {
                        $_SESSION['error'] = "Student strand and year level must be provided.";
                        $_SESSION['old_input'] = $_POST;
                        break;
                    }
                } elseif ($role === 'teacher') {
                    // Teacher also uses 'strand' field name (same as student, but only one section is active)
                    $strand = trim($_POST['strand'] ?? '');
                    if ($strand === '') {
                        $_SESSION['error'] = "Teacher strand must be provided.";
                        $_SESSION['old_input'] = $_POST;
                        break;
                    }
                }

                // Generate next sequential account number with UNIQUE constraint handling
                $account_number = null;
                $max_attempts = 10;
                $attempt = 0;

                while ($account_number === null && $attempt < $max_attempts) {
                    // Get the maximum account number, properly cast as unsigned integer
                    $next_acc_sql = "SELECT MAX(CAST(account_number AS UNSIGNED)) AS max_acc FROM users";
                    $next_acc_res = $conn->query($next_acc_sql);
                    $max_acc_row = $next_acc_res ? $next_acc_res->fetch_assoc() : null;
                    $max_acc = $max_acc_row && $max_acc_row['max_acc'] !== null ? (int)$max_acc_row['max_acc'] : 20000;
                    
                    // Generate the next sequential account number
                    $proposed_account_number = $max_acc + 1;
                    
                    // Verify this account number doesn't already exist (handles race conditions with UNIQUE constraint)
                    $verify_stmt = $conn->prepare("SELECT user_id FROM users WHERE account_number = ?");
                    if (!$verify_stmt) {
                        $_SESSION['error'] = 'Unable to verify account number availability.';
                        $_SESSION['old_input'] = $_POST;
                        header('Location: ' . $_SERVER['PHP_SELF']);
                        exit();
                    }
                    $verify_stmt->bind_param("i", $proposed_account_number);
                    $verify_stmt->execute();
                    $verify_result = $verify_stmt->get_result();
                    $verify_stmt->close();
                    
                    // If account number doesn't exist, use it
                    if ($verify_result && $verify_result->num_rows === 0) {
                        $account_number = $proposed_account_number;
                        break;
                    }
                    
                    $attempt++;
                }

                if ($account_number === null) {
                    $_SESSION['error'] = 'Unable to generate a unique account number. Please try again.';
                    $_SESSION['old_input'] = $_POST;
                    header('Location: ' . $_SERVER['PHP_SELF']);
                    exit();
                }

                // Let DB trigger assign default password based on role by passing empty string
                // The trigger checks for NULL or empty string
                $password_for_trigger = '';

                // IMPORTANT: Always use current school year and semester from database, NOT from POST
                // This prevents users from tampering with these values via form manipulation
                // Normalize school year dashes
                $school_year_to_use = str_replace(array("–", "—"), "-", $current_school_year);
                $semester_to_use = (int)$current_semester;
                
                // Security: Ignore any POST values for school_year and semester
                // Always use the current active values from the database
                // This ensures data integrity and prevents unauthorized modifications

                // Use transaction so user + role-specific insert are atomic
                $conn->begin_transaction();
                try {
                    // Insert into users table - password will be set by trigger
                    $stmt = $conn->prepare("INSERT INTO users (account_number, password, first_name, last_name, email, role, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    if (!$stmt) throw new Exception('Prepare users insert failed: ' . $conn->error);
                    $stmt->bind_param("issssss", $account_number, $password_for_trigger, $first_name, $last_name, $email, $role, $status);
                    if (!$stmt->execute()) throw new Exception('Execute users insert failed: ' . $stmt->error);

                    $user_id = $conn->insert_id;

                    // Fallback: Ensure correct default password for registrar role
                    // This handles cases where the database trigger may not have been updated
                    if ($role === 'registrar') {
                        $registrar_default_password = 'registrar123';
                        $update_pwd_stmt = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ? AND password = 'default123'");
                        if ($update_pwd_stmt) {
                            $update_pwd_stmt->bind_param("si", $registrar_default_password, $user_id);
                            $update_pwd_stmt->execute();
                            $update_pwd_stmt->close();
                        }
                    }

                    // Add role-specific data
                    if ($role === 'student') {
                        $strand = trim($_POST['strand'] ?? '');
                        $year_level = trim($_POST['year_level'] ?? '');
                        $section_id = isset($_POST['section_id']) && $_POST['section_id'] !== '' ? intval($_POST['section_id']) : NULL;
                        $section_name = '';

                        // Double-check validation (should already be validated above)
                        if ($strand === '' || $year_level === '') {
                            throw new Exception('Student strand and year level must be provided.');
                        }

                        // Fetch section name from sections table if section_id is provided
                        if ($section_id !== NULL) {
                            $section_stmt = $conn->prepare("SELECT section_name FROM sections WHERE section_id = ?");
                            if ($section_stmt) {
                                $section_stmt->bind_param("i", $section_id);
                                $section_stmt->execute();
                                $section_result = $section_stmt->get_result();
                                if ($section_result && $section_result->num_rows > 0) {
                                    $section_row = $section_result->fetch_assoc();
                                    $section_name = $section_row['section_name'];
                                }
                                $section_stmt->close();
                            }
                        }

                        // Check if student record already exists (may have been created by trigger)
                        $check_student = $conn->prepare("SELECT student_id FROM students WHERE user_id = ?");
                        if ($check_student) {
                            $check_student->bind_param("i", $user_id);
                            $check_student->execute();
                            $student_exists = $check_student->get_result()->num_rows > 0;
                            $check_student->close();
                        } else {
                            $student_exists = false;
                        }

                        // Only insert if student record doesn't exist yet
                        if (!$student_exists) {
                            $student_stmt = $conn->prepare("INSERT INTO students (user_id, strand, year_level, school_year, semester, section_id, section_name) VALUES (?, ?, ?, ?, ?, ?, ?)");
                            if (!$student_stmt) throw new Exception('Prepare students insert failed: ' . $conn->error);
                            $student_stmt->bind_param("isssiis", $user_id, $strand, $year_level, $school_year_to_use, $semester_to_use, $section_id, $section_name);
                            if (!$student_stmt->execute()) throw new Exception('Execute students insert failed: ' . $student_stmt->error);
                        }

                    } elseif ($role === 'teacher') {
                        $strand = trim($_POST['strand'] ?? '');
                        
                        // Double-check validation (should already be validated above)
                        if ($strand === '') {
                            throw new Exception('Teacher strand must be provided.');
                        }
                        
                        // Check if teacher record already exists (may have been created by trigger)
                        $check_teacher = $conn->prepare("SELECT teacher_id FROM teachers WHERE user_id = ?");
                        if ($check_teacher) {
                            $check_teacher->bind_param("i", $user_id);
                            $check_teacher->execute();
                            $teacher_exists = $check_teacher->get_result()->num_rows > 0;
                            $check_teacher->close();
                        } else {
                            $teacher_exists = false;
                        }

                        // Only insert if teacher record doesn't exist yet
                        if (!$teacher_exists) {
                            $teacher_stmt = $conn->prepare("INSERT INTO teachers (user_id, strand, school_year, semester) VALUES (?, ?, ?, ?)");
                            if (!$teacher_stmt) throw new Exception('Prepare teachers insert failed: ' . $conn->error);
                            $teacher_stmt->bind_param("issi", $user_id, $strand, $school_year_to_use, $semester_to_use);
                            if (!$teacher_stmt->execute()) throw new Exception('Execute teachers insert failed: ' . $teacher_stmt->error);
                        }

                    } elseif ($role === 'registrar') {
                        // Check if registrar record already exists
                        $check_registrar = $conn->prepare("SELECT user_id FROM registrar WHERE user_id = ?");
                        if ($check_registrar) {
                            $check_registrar->bind_param("i", $user_id);
                            $check_registrar->execute();
                            $registrar_exists = $check_registrar->get_result()->num_rows > 0;
                            $check_registrar->close();
                        } else {
                            $registrar_exists = false;
                        }

                        // Only insert if registrar record doesn't exist yet
                        if (!$registrar_exists) {
                            $registrar_stmt = $conn->prepare("INSERT INTO registrar (user_id) VALUES (?)");
                            if (!$registrar_stmt) throw new Exception('Prepare registrar insert failed: ' . $conn->error);
                            $registrar_stmt->bind_param("i", $user_id);
                            if (!$registrar_stmt->execute()) throw new Exception('Execute registrar insert failed: ' . $registrar_stmt->error);
                        }
                    } elseif ($role === 'admin') {
                        // Check if admin record already exists
                        $check_admin = $conn->prepare("SELECT admin_id FROM admins WHERE user_id = ?");
                        if ($check_admin) {
                            $check_admin->bind_param("i", $user_id);
                            $check_admin->execute();
                            $admin_exists = $check_admin->get_result()->num_rows > 0;
                            $check_admin->close();
                        } else {
                            $admin_exists = false;
                        }

                        // Only insert if admin record doesn't exist yet
                        if (!$admin_exists) {
                            $next_admin_id = null;
                            $res = $conn->query("SELECT IFNULL(MAX(admin_id), 0) + 1 AS next_id FROM admins");
                            if ($res) {
                                $row = $res->fetch_assoc();
                                $next_admin_id = (int)$row['next_id'];
                            } else {
                                $next_admin_id = 1;
                            }
                            $admin_stmt = $conn->prepare("INSERT INTO admins (admin_id, user_id) VALUES (?, ?)");
                            if (!$admin_stmt) throw new Exception('Prepare admins insert failed: ' . $conn->error);
                            $admin_stmt->bind_param("ii", $next_admin_id, $user_id);
                            if (!$admin_stmt->execute()) throw new Exception('Execute admins insert failed: ' . $admin_stmt->error);
                        }
                    }

                    $conn->commit();
                    $_SESSION['success'] = "User added successfully! Account #: $account_number. Default password set based on role.";
                } catch (Exception $e) {
                    $conn->rollback();
                    // Preserve submitted input to refill the form after redirect
                    $_SESSION['old_input'] = $_POST;
                    $hint = '';
                    if (isset($_POST['role'])) {
                        if ($_POST['role'] === 'student' && (trim($_POST['strand'] ?? '') === '' || trim($_POST['year_level'] ?? '') === '')) {
                            $hint = " Please ensure both Strand and Year Level are selected for students.";
                        } elseif ($_POST['role'] === 'teacher' && trim($_POST['strand'] ?? '') === '') {
                            $hint = " Please ensure Strand is selected for teachers.";
                        }
                    }
                    $_SESSION['error'] = "Error adding user: " . $e->getMessage() . $hint;
                }
                break;
                
            case 'update':
                $id = (int)$_POST['user_id'];
                
                // First, fetch the current user data to check if they are archived
                $check_user_stmt = $conn->prepare("SELECT status, first_name, last_name, email, role FROM users WHERE user_id = ?");
                $check_user_stmt->bind_param("i", $id);
                $check_user_stmt->execute();
                $user_result = $check_user_stmt->get_result();
                
                if (!$user_result || $user_result->num_rows === 0) {
                    $_SESSION['error'] = "User not found.";
                    break;
                }
                
                $current_user = $user_result->fetch_assoc();
                $is_archived = strtolower(trim($current_user['status'])) === 'inactive';
                
                // For archived users, only allow status changes - preserve all other fields
                if ($is_archived) {
                    // For archived users, only allow status changes to reactivate them
                    // All other fields are read-only (disabled in frontend, so not submitted)
                    $first_name = $current_user['first_name'];
                    $last_name = $current_user['last_name'];
                    $email = $current_user['email'];
                    $status = trim($_POST['status'] ?? $current_user['status']); // Allow status change only
                    
                    // Validate status is one of the allowed values
                    if (!in_array($status, ['active', 'inactive'])) {
                        $status = $current_user['status'];
                    }
                    
                    // Update only the status field for archived users
                    $stmt = $conn->prepare("UPDATE users SET status=? WHERE user_id=?");
                    $stmt->bind_param("si", $status, $id);
                    
                    if ($stmt->execute()) {
                        if ($status === 'active') {
                            $_SESSION['success'] = "User reactivated successfully!";
                        } else {
                            $_SESSION['success'] = "User status updated successfully!";
                        }
                    } else {
                        $_SESSION['error'] = "Error updating user status: " . $conn->error;
                    }
                    $stmt->close();
                    break;
                } else {
                    // For active users, allow full editing
                    $first_name = trim($_POST['first_name'] ?? '');
                    $last_name = trim($_POST['last_name'] ?? '');
                    $email = trim($_POST['email'] ?? '');
                    $status = trim($_POST['status'] ?? $current_user['status']); // Read status from form
                    
                    // Validate status is one of the allowed values
                    if (!in_array($status, ['active', 'inactive'])) {
                        $status = $current_user['status'];
                    }
                    
                    // Validate required fields
                    if ($first_name === '' || $last_name === '' || $email === '') {
                        $_SESSION['error'] = "Missing required fields.";
                        break;
                    }
                    
                    // Validate email format
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $_SESSION['error'] = "Invalid email format.";
                        break;
                    }
                    
                    // Check for duplicate email (excluding the current user)
                    $check_email_stmt = $conn->prepare("SELECT user_id FROM users WHERE LOWER(email) = LOWER(?) AND user_id != ?");
                    if (!$check_email_stmt) {
                        $_SESSION['error'] = "Database error while checking email: " . $conn->error;
                        break;
                    }
                    $check_email_stmt->bind_param("si", $email, $id);
                    $check_email_stmt->execute();
                    $email_result = $check_email_stmt->get_result();
                    
                    if ($email_result && $email_result->num_rows > 0) {
                        $_SESSION['error'] = "This email address is already in use by another user. Please enter a different email address.";
                        $check_email_stmt->close();
                        break;
                    }
                    $check_email_stmt->close();
                    
                    // Update user information
                    $stmt = $conn->prepare("UPDATE users SET first_name=?, last_name=?, email=?, status=? WHERE user_id=?");
                    $stmt->bind_param("ssssi", $first_name, $last_name, $email, $status, $id);
                    
                    if ($stmt->execute()) {
                        // If this user is a student, update strand and year level as well
                        if ($current_user['role'] === 'student') {
                            $edit_strand = isset($_POST['strand_student']) ? trim($_POST['strand_student']) : '';
                            $edit_year_level = isset($_POST['year_level_student']) ? trim($_POST['year_level_student']) : '';

                            // Only proceed when both values are provided
                            if ($edit_strand !== '' && $edit_year_level !== '') {
                                // Normalize current school year/semester
                                $school_year = str_replace(array("–", "—"), "-", $current_school_year);
                                $semester = (int)$current_semester;

                                // Check if a students row already exists
                                $check_stmt = $conn->prepare("SELECT student_id FROM students WHERE user_id = ? LIMIT 1");
                                $check_stmt->bind_param("i", $id);
                                $check_stmt->execute();
                                $check_res = $check_stmt->get_result();

                                if ($check_res && $check_res->num_rows > 0) {
                                    // Update existing record
                                    $stu_update_stmt = $conn->prepare("UPDATE students SET strand = ?, year_level = ?, school_year = ?, semester = ? WHERE user_id = ?");
                                    $stu_update_stmt->bind_param("sssii", $edit_strand, $edit_year_level, $school_year, $semester, $id);
                                    $stu_update_stmt->execute();
                                } else {
                                    // Insert new students row if missing
                                    $stu_insert_stmt = $conn->prepare("INSERT INTO students (user_id, strand, year_level, school_year, semester, section_id) VALUES (?, ?, ?, ?, ?, NULL)");
                                    $stu_insert_stmt->bind_param("isssi", $id, $edit_strand, $edit_year_level, $school_year, $semester);
                                    $stu_insert_stmt->execute();
                                }
                            }
                        } elseif ($current_user['role'] === 'teacher') {
                            // Update teacher strand if provided
                            $edit_strand = isset($_POST['strand_teacher']) ? trim($_POST['strand_teacher']) : '';
                            if ($edit_strand !== '') {
                                // Normalize current school year/semester
                                $school_year = str_replace(array("–", "—"), "-", $current_school_year);
                                $semester = (int)$current_semester;
                                
                                // Check if a teachers row already exists
                                $check_stmt = $conn->prepare("SELECT teacher_id FROM teachers WHERE user_id = ? LIMIT 1");
                                $check_stmt->bind_param("i", $id);
                                $check_stmt->execute();
                                $check_res = $check_stmt->get_result();
                                
                                if ($check_res && $check_res->num_rows > 0) {
                                    // Update existing record
                                    $tea_update_stmt = $conn->prepare("UPDATE teachers SET strand = ?, school_year = ?, semester = ? WHERE user_id = ?");
                                    $tea_update_stmt->bind_param("ssii", $edit_strand, $school_year, $semester, $id);
                                    $tea_update_stmt->execute();
                                } else {
                                    // Insert new teachers row if missing
                                    $tea_insert_stmt = $conn->prepare("INSERT INTO teachers (user_id, strand, school_year, semester) VALUES (?, ?, ?, ?)");
                                    $tea_insert_stmt->bind_param("issi", $id, $edit_strand, $school_year, $semester);
                                    $tea_insert_stmt->execute();
                                }
                            }
                        }
                        $_SESSION['success'] = "User updated successfully!";
                    } else {
                        $_SESSION['error'] = "Error updating user: " . $conn->error;
                    }
                }
                break;
                
            case 'bulk_update_status':
                // Handle bulk status update (e.g., mark multiple users as inactive)
                $user_ids_json = $_POST['user_ids'] ?? '[]';
                $new_status = $_POST['status'] ?? 'inactive';
                
                // Validate status
                if (!in_array($new_status, ['active', 'inactive'])) {
                    $_SESSION['error'] = "Invalid status specified.";
                    break;
                }
                
                // Parse JSON user IDs
                $user_ids = json_decode($user_ids_json, true);
                if (!is_array($user_ids) || empty($user_ids)) {
                    $_SESSION['error'] = "No users selected for update.";
                    break;
                }
                
                // Sanitize user IDs (must be integers)
                $user_ids = array_filter(array_map('intval', $user_ids));
                
                if (empty($user_ids)) {
                    $_SESSION['error'] = "Invalid user IDs provided.";
                    break;
                }
                
                // Build placeholders for prepared statement
                $placeholders = implode(',', array_fill(0, count($user_ids), '?'));
                $types = str_repeat('i', count($user_ids)) . 's'; // integer for each user ID, string for status
                
                // Prepare and execute bulk update
                $bulk_update_sql = "UPDATE users SET status = ? WHERE user_id IN ($placeholders)";
                $bulk_stmt = $conn->prepare($bulk_update_sql);
                
                if (!$bulk_stmt) {
                    $_SESSION['error'] = "Database error: " . $conn->error;
                    break;
                }
                
                // Bind parameters: status first, then user IDs
                $bind_params = array_merge([$new_status], $user_ids);
                // Use reflection to bind parameters dynamically
                $param_types = 's' . str_repeat('i', count($user_ids));
                $refs = array();
                foreach ($bind_params as $key => $value) {
                    $refs[$key] = &$bind_params[$key];
                }
                call_user_func_array(array($bulk_stmt, 'bind_param'), array_merge(array($param_types), $refs));
                
                if ($bulk_stmt->execute()) {
                    $affected_rows = $bulk_stmt->affected_rows;
                    $_SESSION['success'] = "Successfully updated " . $affected_rows . " user(s) to " . ucfirst($new_status) . " status.";
                } else {
                    $_SESSION['error'] = "Error updating users: " . $bulk_stmt->error;
                }
                $bulk_stmt->close();
                break;

            case 'deactivate_all_role':
                // Handle deactivate all users of a specific role
                $role_filter = normalizeRoleValue($_POST['role'] ?? '');
                $allowed_roles = ['admin', 'registrar', 'teacher', 'student'];
                
                if ($role_filter === '' || !in_array($role_filter, $allowed_roles, true)) {
                    $_SESSION['error'] = "Invalid role specified.";
                    break;
                }
                
                // Count how many users will be deactivated
                $count_sql = "SELECT COUNT(*) as count FROM users WHERE role = ? AND status = 'active'";
                $count_stmt = $conn->prepare($count_sql);
                if (!$count_stmt) {
                    $_SESSION['error'] = "Database error: " . $conn->error;
                    break;
                }
                $count_stmt->bind_param("s", $role_filter);
                $count_stmt->execute();
                $count_result = $count_stmt->get_result()->fetch_assoc();
                $count_stmt->close();
                
                $affected_count = $count_result['count'] ?? 0;
                
                if ($affected_count === 0) {
                    $_SESSION['error'] = "No active " . ucfirst($role_filter) . " users found to deactivate.";
                    break;
                }
                
                // Deactivate all active users of the specified role
                $deactivate_sql = "UPDATE users SET status = 'inactive' WHERE role = ? AND status = 'active'";
                $deactivate_stmt = $conn->prepare($deactivate_sql);
                if (!$deactivate_stmt) {
                    $_SESSION['error'] = "Database error: " . $conn->error;
                    break;
                }
                
                $deactivate_stmt->bind_param("s", $role_filter);
                if ($deactivate_stmt->execute()) {
                    $_SESSION['success'] = "Successfully deactivated " . $affected_count . " " . ucfirst($role_filter) . "(s).";
                } else {
                    $_SESSION['error'] = "Error deactivating users: " . $deactivate_stmt->error;
                }
                $deactivate_stmt->close();
                break;

            case 'import_excel':
                try {
                    app_require_spreadsheet();
                } catch (Throwable $error) {
                    error_log('Spreadsheet initialization failed: ' . $error->getMessage());
                    $_SESSION['error'] = 'Excel import is unavailable. Ask the administrator to check the installed libraries.';
                    break;
                }
                if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
                    $_SESSION['error'] = "Please upload a valid Excel file.";
                    break;
                }

                $file_extension = strtolower(pathinfo($_FILES['excel_file']['name'], PATHINFO_EXTENSION));
                if (!in_array($file_extension, ['xlsx', 'xls'], true)) {
                    $_SESSION['error'] = "Invalid file type. Please upload .xlsx or .xls file.";
                    break;
                }

                $tmp_path = $_FILES['excel_file']['tmp_name'];
                $allowed_roles = ['admin', 'registrar', 'teacher', 'student'];
                $errors = [];
                $valid_rows = [];

                $existing_emails = [];
                $email_result = $conn->query("SELECT LOWER(email) AS email FROM users");
                if ($email_result) {
                    while ($row = $email_result->fetch_assoc()) {
                        $existing_emails[$row['email']] = true;
                    }
                }

                try {
                    $spreadsheet = IOFactory::load($tmp_path);
                    $sheet = $spreadsheet->getActiveSheet();
                    $rows = $sheet->toArray(null, true, true, true);

                    if (count($rows) < 2) {
                        $_SESSION['error'] = "The Excel file is empty.";
                        break;
                    }

                    $headers = array_map(
                        function ($value) {
                            // Normalize: lowercase, trim, remove special characters, replace spaces with underscores
                            $normalized = strtolower(trim((string)$value));
                            $normalized = str_replace('*', '', $normalized); // Remove asterisks
                            $normalized = str_replace(' ', '_', $normalized); // Replace spaces with underscores
                            return $normalized;
                        },
                        $rows[1]
                    );

                    $required_columns = ['first_name', 'last_name', 'email', 'role'];
                    foreach ($required_columns as $required_column) {
                        if (!in_array($required_column, $headers, true)) {
                            $errors[] = "Missing required column: " . $required_column;
                        }
                    }

                    if (!empty($errors)) {
                        $_SESSION['error'] = implode(' ', $errors);
                        break;
                    }

                    $header_map = [];
                    foreach ($headers as $column_letter => $header_name) {
                        $header_map[$header_name] = $column_letter;
                    }

                    for ($line = 2; $line <= count($rows); $line++) {
                        $row = $rows[$line];
                        $mapped_row = [
                            'first_name' => $row[$header_map['first_name']] ?? '',
                            'last_name' => $row[$header_map['last_name']] ?? '',
                            'email' => $row[$header_map['email']] ?? '',
                            'role' => $row[$header_map['role']] ?? '',
                            'status' => isset($header_map['status']) ? ($row[$header_map['status']] ?? 'active') : 'active',
                            'strand' => isset($header_map['strand']) ? ($row[$header_map['strand']] ?? '') : '',
                            'year_level' => isset($header_map['year_level']) ? ($row[$header_map['year_level']] ?? '') : ''
                        ];

                        if (
                            trim((string)$mapped_row['first_name']) === '' &&
                            trim((string)$mapped_row['last_name']) === '' &&
                            trim((string)$mapped_row['email']) === '' &&
                            trim((string)$mapped_row['role']) === ''
                        ) {
                            continue;
                        }

                        validateImportRow($mapped_row, $line, $errors, $valid_rows, $existing_emails, $allowed_roles);

                        if (isset($mapped_row['email'])) {
                            $existing_emails[strtolower(trim((string)$mapped_row['email']))] = true;
                        }
                    }

                    if (!empty($errors)) {
                        $preview_errors = array_slice($errors, 0, 8);
                        $_SESSION['error'] = "Import failed. " . count($errors) . " row issue(s): " . implode(' | ', $preview_errors);
                        break;
                    }

                    if (empty($valid_rows)) {
                        $_SESSION['error'] = "No valid rows found for import.";
                        break;
                    }

                    $school_year_to_use = str_replace(array("–", "—"), "-", $current_school_year);
                    $semester_to_use = (int)$current_semester;
                    $imported_count = 0;

                    $conn->begin_transaction();
                    try {
                        foreach ($valid_rows as $row_data) {
                            $next_acc_sql = "SELECT MAX(account_number) AS max_acc FROM users";
                            $next_acc_res = $conn->query($next_acc_sql);
                            $max_acc_row = $next_acc_res ? $next_acc_res->fetch_assoc() : null;
                            $max_acc = $max_acc_row && $max_acc_row['max_acc'] !== null ? (int)$max_acc_row['max_acc'] : 20000;
                            $account_number = $max_acc + 1;

                            $password_for_trigger = '';
                            $stmt = $conn->prepare("INSERT INTO users (account_number, password, first_name, last_name, email, role, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
                            if (!$stmt) {
                                throw new Exception('Unable to insert users.');
                            }
                            $stmt->bind_param(
                                "issssss",
                                $account_number,
                                $password_for_trigger,
                                $row_data['first_name'],
                                $row_data['last_name'],
                                $row_data['email'],
                                $row_data['role'],
                                $row_data['status']
                            );
                            if (!$stmt->execute()) {
                                throw new Exception('Insert failed for email ' . $row_data['email'] . ': ' . $stmt->error);
                            }
                            $user_id = $conn->insert_id;
                            $stmt->close();

                            if ($row_data['role'] === 'student') {
                                $check_student = $conn->prepare("SELECT student_id FROM students WHERE user_id = ?");
                                $student_exists = false;
                                if ($check_student) {
                                    $check_student->bind_param("i", $user_id);
                                    $check_student->execute();
                                    $student_exists = $check_student->get_result()->num_rows > 0;
                                    $check_student->close();
                                }
                                if (!$student_exists) {
                                    // Auto-assign section based on strand and year_level
                                    $section_id = null;
                                    if ($row_data['strand'] !== '' && $row_data['year_level'] !== '') {
                                        $section_query = $conn->prepare("SELECT section_id FROM sections WHERE strand = ? AND year_level = ? AND status = 'active' LIMIT 1");
                                        if ($section_query) {
                                            $section_query->bind_param("ss", $row_data['strand'], $row_data['year_level']);
                                            $section_query->execute();
                                            $section_result = $section_query->get_result();
                                            if ($section_result->num_rows > 0) {
                                                $section_data = $section_result->fetch_assoc();
                                                $section_id = $section_data['section_id'];
                                            }
                                            $section_query->close();
                                        }
                                    }
                                    
                                    $student_stmt = $conn->prepare("INSERT INTO students (user_id, strand, year_level, school_year, semester, section_id) VALUES (?, ?, ?, ?, ?, ?)");
                                    if (!$student_stmt) {
                                        throw new Exception('Unable to insert student profile.');
                                    }
                                    $student_stmt->bind_param("isssii", $user_id, $row_data['strand'], $row_data['year_level'], $school_year_to_use, $semester_to_use, $section_id);
                                    if (!$student_stmt->execute()) {
                                        throw new Exception('Student profile insert failed for ' . $row_data['email'] . '.');
                                    }
                                    $student_stmt->close();
                                }
                            } elseif ($row_data['role'] === 'teacher') {
                                $check_teacher = $conn->prepare("SELECT teacher_id FROM teachers WHERE user_id = ?");
                                $teacher_exists = false;
                                if ($check_teacher) {
                                    $check_teacher->bind_param("i", $user_id);
                                    $check_teacher->execute();
                                    $teacher_exists = $check_teacher->get_result()->num_rows > 0;
                                    $check_teacher->close();
                                }
                                if (!$teacher_exists) {
                                    $teacher_stmt = $conn->prepare("INSERT INTO teachers (user_id, strand, school_year, semester) VALUES (?, ?, ?, ?)");
                                    if (!$teacher_stmt) {
                                        throw new Exception('Unable to insert teacher profile.');
                                    }
                                    $teacher_stmt->bind_param("issi", $user_id, $row_data['strand'], $school_year_to_use, $semester_to_use);
                                    if (!$teacher_stmt->execute()) {
                                        throw new Exception('Teacher profile insert failed for ' . $row_data['email'] . '.');
                                    }
                                    $teacher_stmt->close();
                                }
                            } elseif ($row_data['role'] === 'registrar') {
                                $check_registrar = $conn->prepare("SELECT user_id FROM registrar WHERE user_id = ?");
                                $registrar_exists = false;
                                if ($check_registrar) {
                                    $check_registrar->bind_param("i", $user_id);
                                    $check_registrar->execute();
                                    $registrar_exists = $check_registrar->get_result()->num_rows > 0;
                                    $check_registrar->close();
                                }
                                if (!$registrar_exists) {
                                    $registrar_stmt = $conn->prepare("INSERT INTO registrar (user_id) VALUES (?)");
                                    if ($registrar_stmt) {
                                        $registrar_stmt->bind_param("i", $user_id);
                                        $registrar_stmt->execute();
                                        $registrar_stmt->close();
                                    }
                                }
                            } elseif ($row_data['role'] === 'admin') {
                                $check_admin = $conn->prepare("SELECT admin_id FROM admins WHERE user_id = ?");
                                $admin_exists = false;
                                if ($check_admin) {
                                    $check_admin->bind_param("i", $user_id);
                                    $check_admin->execute();
                                    $admin_exists = $check_admin->get_result()->num_rows > 0;
                                    $check_admin->close();
                                }
                                if (!$admin_exists) {
                                    $res = $conn->query("SELECT IFNULL(MAX(admin_id), 0) + 1 AS next_id FROM admins");
                                    $next_admin_id = ($res && $res->num_rows > 0) ? (int)$res->fetch_assoc()['next_id'] : 1;
                                    $admin_stmt = $conn->prepare("INSERT INTO admins (admin_id, user_id) VALUES (?, ?)");
                                    if ($admin_stmt) {
                                        $admin_stmt->bind_param("ii", $next_admin_id, $user_id);
                                        $admin_stmt->execute();
                                        $admin_stmt->close();
                                    }
                                }
                            }

                            // Fallback: Ensure correct default password for registrar role during import
                            if ($row_data['role'] === 'registrar') {
                                $registrar_default_password = 'registrar123';
                                $update_pwd_stmt = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ? AND password = 'default123'");
                                if ($update_pwd_stmt) {
                                    $update_pwd_stmt->bind_param("si", $registrar_default_password, $user_id);
                                    $update_pwd_stmt->execute();
                                    $update_pwd_stmt->close();
                                }
                            }

                            $imported_count++;
                        }

                        $conn->commit();
                        $_SESSION['success'] = "Import completed. Successfully added {$imported_count} user(s).";
                    } catch (Throwable $import_exception) {
                        $conn->rollback();
                        $_SESSION['error'] = "Import failed: " . $import_exception->getMessage();
                    }
                } catch (Throwable $sheet_exception) {
                    $_SESSION['error'] = "Unable to read Excel file. Please check the file content and format.";
                }
                break;
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}

// Fetch active users with school year and semester info
// Use case-insensitive status comparison and sort by numeric account_number ascending
$active_sql = "SELECT u.*, 
    CASE 
        WHEN u.role = 'student' THEN s.school_year 
        WHEN u.role = 'teacher' THEN t.school_year 
        ELSE '' 
    END as school_year,
    CASE 
        WHEN u.role = 'student' THEN s.semester 
        WHEN u.role = 'teacher' THEN t.semester 
        ELSE '' 
    END as semester,
    CASE
        WHEN u.role = 'student' THEN s.strand
        WHEN u.role = 'teacher' THEN t.strand
        ELSE ''
    END as strand,
    CASE
        WHEN u.role = 'student' THEN s.year_level
        ELSE ''
    END as year_level,
    CASE
        WHEN u.role = 'student' THEN sec.section_id
        ELSE NULL
    END as section_id,
    CASE
        WHEN u.role = 'student' THEN sec.section_name
        ELSE ''
    END as section_name
    FROM users u
    LEFT JOIN students s ON u.user_id = s.user_id
    LEFT JOIN teachers t ON u.user_id = t.user_id
    LEFT JOIN sections sec ON s.section_id = sec.section_id
    WHERE LOWER(TRIM(u.status)) = 'active'
    ORDER BY CAST(u.account_number AS UNSIGNED) ASC";
$active_result = $conn->query($active_sql);
if ($active_result) {
    $active_users = $active_result->fetch_all(MYSQLI_ASSOC);
} else {
    $active_users = [];
    $_SESSION['error'] = "Error fetching active users: " . $conn->error;
}

// Fetch inactive users (archived)
// Optimize for speed: avoid non-sargable LOWER/TRIM and unnecessary joins
// We only need base user fields here, so read from users table directly
$inactive_sql = "
    SELECT u.user_id, u.account_number, u.first_name, u.last_name, u.email, u.role, u.status
    FROM users u
    WHERE u.status = 'inactive'
    ORDER BY u.account_number ASC
    LIMIT 1000
";
$inactive_result = $conn->query($inactive_sql);
if ($inactive_result) {
    $inactive_users = $inactive_result->fetch_all(MYSQLI_ASSOC);
} else {
    $inactive_users = [];
    // Append error if there's already one, otherwise set
    if (isset($_SESSION['error']) && $_SESSION['error']) {
        $_SESSION['error'] .= " \nError fetching inactive users: " . $conn->error;
    } else {
        $_SESSION['error'] = "Error fetching inactive users: " . $conn->error;
    }
}

// Fetch all active sections for the section dropdown in Add/Edit Student modals
$sections_sql = "SELECT section_id, section_name, strand, year_level FROM sections WHERE status = 'active' ORDER BY section_name ASC";
$sections_result = $conn->query($sections_sql);
$sections = [];
if ($sections_result) {
    $sections = $sections_result->fetch_all(MYSQLI_ASSOC);
}

// Fetch available school years for export filtering.
$school_year_options = [];
$school_year_sql = "
    SELECT school_year COLLATE utf8mb4_unicode_ci as school_year FROM students WHERE school_year IS NOT NULL AND school_year <> ''
    UNION
    SELECT school_year COLLATE utf8mb4_unicode_ci as school_year FROM teachers WHERE school_year IS NOT NULL AND school_year <> ''
    ORDER BY school_year DESC
";
$school_year_result = $conn->query($school_year_sql);
if ($school_year_result) {
    while ($school_year_row = $school_year_result->fetch_assoc()) {
        $normalized_school_year = normalizeSchoolYearValue($school_year_row['school_year'] ?? '');
        if ($normalized_school_year !== '') {
            $school_year_options[$normalized_school_year] = $normalized_school_year;
        }
    }
}

// Get statistics
$stats_sql = "SELECT 
    COUNT(*) as total_users,
    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_users,
    SUM(CASE WHEN role = 'student' THEN 1 ELSE 0 END) as students,
    SUM(CASE WHEN role = 'teacher' THEN 1 ELSE 0 END) as teachers,
    SUM(CASE WHEN role = 'registrar' THEN 1 ELSE 0 END) as registrar,
    SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) as admins
    FROM users";
$stats_result = $conn->query($stats_sql);
$stats = $stats_result->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="/images/school-logo.png" type="image/png">
    <title>User Management</title>
    
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
            --primary-light: rgba(128, 0, 0, 0.1);
            --text-dark: #2c3e50;
            --border-color: #dee2e6;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --danger-color: #dc3545;
            --info-color: #17a2b8;
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            margin: 0;
            padding: 0;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
        }
        
        .main-content {
            margin-left: 250px;
            margin-top: 60px;
            padding: 30px;
            min-height: calc(100vh - 60px);
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
            font-weight: 700;
            margin-bottom: 15px;
            font-size: 2.8rem;
            position: relative;
            z-index: 2;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 20px;
            margin-top: 25px;
            position: relative;
            z-index: 2;
        }
        
        .stat-card {
            background: rgba(255, 255, 255, 0.15);
            padding: 20px;
            border-radius: 15px;
            text-align: center;
            backdrop-filter: blur(10px);
            transition: transform 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 8px;
        }
        
        .stat-label {
            font-size: 0.9rem;
            opacity: 0.9;
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
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.12);
        }

        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f8f9fa;
            flex-wrap: wrap;
        }

        .card-header h3 {
            margin: 0;
            font-size: 1.4rem;
            font-weight: 600;
            color: var(--primary-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-header i {
            font-size: 1.5rem;
            color: var(--primary-color);
            background: rgba(128, 0, 0, 0.1);
            padding: 10px;
            border-radius: 10px;
        }
        
        .tab-navigation {
            display: flex;
            gap: 10px;
            margin-bottom: 25px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .tab-button {
            padding: 12px 24px;
            background: transparent;
            border: none;
            cursor: pointer;
            font-weight: 600;
            font-size: 1rem;
            color: #6c757d;
            transition: all 0.3s ease;
            border-bottom: 3px solid transparent;
            margin-bottom: -2px;
            position: relative;
            top: 2px;
        }
        
        .tab-button.active {
            color: var(--primary-color);
            border-bottom-color: var(--primary-color);
        }
        
        .tab-button:hover {
            color: var(--primary-color);
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .btn-primary-custom {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-hover));
            border: none;
            color: white !important;
            padding: 10px 20px;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 5px 15px rgba(128, 0, 0, 0.3);
        }
        
        .btn-primary-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(128, 0, 0, 0.4);
            background: linear-gradient(135deg, var(--primary-hover), var(--primary-color));
            color: white !important;
        }
        
        .table-custom {
            margin-bottom: 0;
        }
        
        .table-custom thead th {
            background: #f8f9fa;
            border: none;
            font-weight: 600;
            color: var(--text-dark);
            padding: 15px;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .table-custom tbody td {
            padding: 15px;
            border-top: 1px solid #f0f0f0;
            vertical-align: middle;
        }
        
        .table-custom tbody tr {
            transition: all 0.3s ease;
        }
        
        .table-custom tbody tr:hover {
            background: #f8f9fa;
        }
        
        .table-custom .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.9rem;
            margin-right: 10px;
        }
        
        .table-custom .user-info {
            display: flex;
            align-items: center;
            justify-content: flex-start;
        }
        
        .table-custom .user-details h6 {
            margin: 0;
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.95rem;
        }
        
        /* Center alignment for Full Name column (avatar + name) */
        .table-custom th.full-name-col,
        .table-custom td.full-name-col {
            text-align: center;
        }
        .table-custom td.full-name-col .user-info {
            justify-content: center;
        }
        
        .table-custom .user-details small {
            color: #6c757d;
            font-size: 0.8rem;
        }
        
        .badge-role {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .badge-admin { background: #dc3545; color: white; }
        .badge-registrar { background: #6f42c1; color: white; }
    .badge-teacher { background: var(--primary-color); color: white; }
        .badge-student { background: #28a745; color: white; }
        
        .badge-status {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .badge-active { 
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            border: 2px solid #28a745;
        }

        .badge-inactive { 
            background: #f8d7da; 
            color: #721c24;
            border: 2px solid #dc3545;
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
            justify-content: center;
        }
        
        .btn-action {
            width: 35px;
            height: 35px;
            border-radius: 8px;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            font-size: 0.9rem;
            cursor: pointer;
        }
        
        .btn-edit {
            /* Use light maroon tone for the button background and maroon for the icon */
            background: var(--primary-light);
            color: var(--primary-color);
        }

        .btn-edit:hover {
            /* Strong maroon on hover */
            background: var(--primary-color);
            color: white;
            transform: scale(1.1);
        }
        
        .btn-action:disabled,
        .btn-primary-custom:disabled {
            opacity: 0.5;
            cursor: not-allowed !important;
            pointer-events: none;
        }
        
        .btn-primary-custom:disabled {
            background: #6c757d !important;
            box-shadow: none !important;
        }
        
        select.form-control-custom:disabled {
            background-color: #e9ecef;
            cursor: not-allowed;
        }
        
        /* Read-only and disabled field styling */
        input.form-control-custom:disabled,
        input.form-control-custom[readonly],
        select.form-control-custom:disabled {
            background-color: #e9ecef !important;
            cursor: not-allowed !important;
            opacity: 1;
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            user-select: none;
        }
        
        /* Prevent pointer events on disabled selects to prevent dropdown opening */
        select.form-control-custom:disabled {
            pointer-events: none;
        }
        
        .role-selection-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .role-option {
            padding: 20px;
            border: 2px solid #e9ecef;
            border-radius: 12px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            background: white;
        }
        
        .role-option:hover {
            border-color: var(--primary-color);
            background: var(--primary-light);
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        .role-option.selected {
            border-color: var(--primary-color);
            background: var(--primary-light);
        }
        
        .role-option i {
            font-size: 2.5rem;
            color: var(--primary-color);
            margin-bottom: 10px;
        }
        
        .role-option p {
            margin: 0;
            font-weight: 600;
            color: var(--text-dark);
        }
        
        .form-section {
            display: none;
            margin-top: 30px;
            padding-top: 30px;
            border-top: 2px solid #f8f9fa;
        }
        
        .form-section.active {
            display: block;
        }
        
        .modal-content {
            border-radius: 20px;
            border: none;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
        }
        
        .modal-header {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-hover));
            color: white;
            border-radius: 20px 20px 0 0;
            padding: 25px 30px;
            border-bottom: none;
        }
        
        .modal-title {
            font-weight: 700;
            font-size: 1.5rem;
        }
        
        .btn-close {
            filter: invert(1);
        }
        
        .form-control-custom {
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 12px 15px;
            font-size: 0.95rem;
            transition: all 0.3s ease;
        }
        
        /* Stronger focus styles to override browser default blue outlines
           Apply to inputs, selects, and textareas using the custom class */
        .form-control-custom:focus,
        input.form-control-custom:focus,
        select.form-control-custom:focus,
        textarea.form-control-custom:focus {
            outline: none !important;
            border-color: var(--primary-color) !important;
            box-shadow: 0 0 0 0.25rem rgba(128, 0, 0, 0.25) !important;
        }

        /* Use accent-color where supported for form controls */
        .form-control-custom {
            accent-color: var(--primary-color);
        }
        
        .form-label {
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 8px;
            font-size: 0.95rem;
        }
        
        .alert-custom {
            border-radius: 15px;
            border: none;
            padding: 15px 20px;
            margin-bottom: 25px;
            font-weight: 500;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
        }
        
        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 20px;
            }
            
            .header-section h1 {
                font-size: 2rem;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .card-header {
                flex-direction: column;
                align-items: stretch;
            }
            
            .role-selection-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .table-custom thead th {
                font-size: 0.8rem;
                padding: 10px;
            }
        }
    </style>
</head>
<body>
    <?php include '../includes/side_bar.php'; ?>
    <?php include '../includes/navbar.php'; ?>
    <?php renderModernAlertSystem(); ?>

    <div class="main-content">
        <!-- Header Section -->
        <div class="header-section">
            <h1><i class="fas fa-users me-3"></i>User Management</h1>
            
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['total_users']; ?></div>
                    <div class="stat-label">Total Users</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['active_users']; ?></div>
                    <div class="stat-label">Active</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['students']; ?></div>
                    <div class="stat-label">Students</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['teachers']; ?></div>
                    <div class="stat-label">Teachers</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['registrar']; ?></div>
                    <div class="stat-label">Registrar</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['admins']; ?></div>
                    <div class="stat-label">Admins</div>
                </div>
            </div>
        </div>

        <?php
            $page_success_message = null;
            $page_error_message = null;
            if (isset($_SESSION['success'])) {
                $page_success_message = $_SESSION['success'];
                unset($_SESSION['success']);
            }
            if (isset($_SESSION['error'])) {
                $page_error_message = $_SESSION['error'];
                unset($_SESSION['error']);
            }
        ?>

        <!-- Main Content Card -->
        <div class="dashboard-card">
            <div class="card-header">
                <div style="display: flex; align-items: center; gap: 15px;">
                    <i class="fas fa-address-book"></i>
                    <h3>User Directory</h3>
                </div>
                
                <!-- Changed from simple Add User button to role selection dropdown -->
                <button type="button" class="btn btn-primary-custom d-flex align-items-center justify-content-center"
                    <?php if ($is_evaluation_ongoing): ?>
                    disabled title="Cannot add users during ongoing evaluation"
                    onclick="showModernAlert('warning', 'Action Blocked', 'Cannot add users during an ongoing evaluation period.'); return false;"
                    <?php else: ?>
                    data-bs-toggle="modal" data-bs-target="#selectRoleModal"
                    <?php endif; ?>
                    style="height: 40px; min-width: 140px; font-size: 1rem; padding: 0 18px;">
                    <i class="fas fa-plus me-2" style="color: #fff; font-size: 1.1rem;"></i>Add User
                </button>
            </div>
            
            <!-- Tab navigation for Active and Inactive users -->
            <div class="tab-navigation">
                <button class="tab-button active" onclick="switchTab('active')">
                    <i class="fas fa-check-circle me-2"></i>Active Users
                </button>
                <button class="tab-button" onclick="switchTab('inactive')">
                    <i class="fas fa-user-slash me-2"></i>Inactive Users
                </button>
            </div>
            
            <!-- Add search bar and role filter dropdown -->
            <div style="display: flex; gap: 15px; margin-bottom: 20px; flex-wrap: wrap; align-items: center;">
                <!-- Search Bar -->
                <div style="flex: 1; min-width: 250px;">
                    <div style="position: relative;">
                        <i class="fas fa-search" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #999;"></i>
                        <input type="text" id="searchInput" class="form-control form-control-custom" 
                               placeholder="Search by name or email..." 
                               style="padding-left: 38px;"
                               onkeyup="filterTable()">
                    </div>
                </div>
                
                <!-- Role Filter Dropdown -->
                <select id="roleFilter" class="form-control form-control-custom" style="max-width: 180px;" onchange="filterTable()">
                    <option value="">All Roles</option>
                    <option value="student">Student</option>
                    <option value="teacher">Teacher</option>
                    <option value="registrar">Registrar</option>
                    <option value="admin">Admin</option>
                </select>
                
                <!-- Deactivate All Button -->
                <button type="button" class="btn btn-primary-custom d-flex align-items-center"
                        id="deactivateAllBtn"
                        <?php if ($is_evaluation_ongoing): ?>
                        disabled title="Cannot deactivate users during ongoing evaluation"
                        onclick="showModernAlert('warning', 'Action Blocked', 'Cannot deactivate users during an ongoing evaluation period.'); return false;"
                        <?php else: ?>
                        onclick="confirmDeactivateAll()"
                        title="Deactivate all users of the selected role"
                        <?php endif; ?>
                        style="background: linear-gradient(135deg, #dc3545, #c82333); box-shadow: 0 5px 15px rgba(220, 53, 69, 0.3);">
                    <i class="fas fa-ban me-2"></i>Deactivate All
                </button>
                
                <button type="button" class="btn btn-primary-custom d-flex align-items-center"
                        <?php if ($is_evaluation_ongoing): ?>
                        disabled title="Cannot import users during ongoing evaluation"
                        onclick="showModernAlert('warning', 'Action Blocked', 'Cannot import users during an ongoing evaluation period.'); return false;"
                        <?php else: ?>
                        data-bs-toggle="modal" data-bs-target="#importExcelModal" title="Bulk import users from Excel"
                        <?php endif; ?>>
                    <i class="fas fa-file-upload me-2"></i>Import Excel
                </button>
                
                <button type="button" class="btn btn-primary-custom d-flex align-items-center"
                        data-bs-toggle="modal" data-bs-target="#exportExcelModal" title="Export users to Excel with flexible filters">
                    <i class="fas fa-download me-2"></i>Export Excel
                </button>
            </div>
            
            <!-- Active Users Tab -->
            <div id="active" class="tab-content active">
                <?php if (count($active_users) > 0): ?>
                    <div class="table-container">
                        <table class="table table-custom" id="activeUsersTable">
                            <thead>
                                <tr>
                                    <th>Account #</th>
                                    <th class="full-name-col">Full Name</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($active_users as $user): ?>
                                    <tr data-user-id="<?php echo $user['user_id']; ?>" data-user-name="<?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>">
                                        <td>
                                            <strong><?php echo htmlspecialchars($user['account_number']); ?></strong>
                                        </td>
                                        <td class="full-name-col">
                                            <div class="user-info">
                                                <div class="user-avatar">
                                                    <?php echo strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)); ?>
                                                </div>
                                                <div class="user-details">
                                                    <h6><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></h6>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <i class="fas fa-envelope me-2 text-muted"></i>
                                            <?php echo htmlspecialchars($user['email']); ?>
                                        </td>
                                        <td>
                                            <span class="badge badge-role badge-<?php echo $user['role']; ?>">
                                                <?php echo ucfirst($user['role']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge badge-status badge-<?php echo $user['status']; ?>">
                                                <?php if ($user['status'] === 'active'): ?>
                                                    <i class="fas fa-check-circle"></i>
                                                <?php else: ?>
                                                    <i class="fas fa-times-circle"></i>
                                                <?php endif; ?>
                                                <?php echo ucfirst($user['status']); ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <div class="action-buttons justify-content-center">
                                                <button type="button" class="btn btn-action btn-edit" 
                                                        onclick="editUser(<?php echo htmlspecialchars(json_encode($user)); ?>); return false;"
                                                        <?php if (!$is_evaluation_ongoing): ?>
                                                        data-bs-toggle="modal" data-bs-target="#editUserModal"
                                                        <?php endif; ?>
                                                        title="<?php echo $is_evaluation_ongoing ? 'Cannot edit users during ongoing evaluation' : 'Edit'; ?>"
                                                        <?php echo $is_evaluation_ongoing ? 'disabled' : ''; ?>>
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div id="activePagination" class="d-flex justify-content-end mt-3"></div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-users"></i>
                        <h4>No active users found</h4>
                        <p>Add a new user to get started.</p>
                    </div>
                <?php endif; ?>
            </div>
            
                            <!-- Inactive Users Tab (Archived) -->
            <div id="inactive" class="tab-content">
                <?php if (count($inactive_users) > 0): ?>
                    <div class="table-container">
                        <table class="table table-custom">
                            <thead>
                                <tr>
                                    <th>Account #</th>
                                    <th class="full-name-col">Full Name</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($inactive_users as $user): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo str_pad($user['account_number'], 6, '0', STR_PAD_LEFT); ?></strong>
                                        </td>
                                        <td class="full-name-col">
                                            <div class="user-info">
                                                <div class="user-avatar">
                                                    <?php echo strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)); ?>
                                                </div>
                                                <div class="user-details">
                                                    <h6><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></h6>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <i class="fas fa-envelope me-2 text-muted"></i>
                                            <?php echo htmlspecialchars($user['email']); ?>
                                        </td>
                                        <td>
                                            <span class="badge badge-role badge-<?php echo $user['role']; ?>">
                                                <?php echo ucfirst($user['role']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge badge-status badge-<?php echo $user['status']; ?>">
                                                <?php if ($user['status'] === 'active'): ?>
                                                    <i class="fas fa-check-circle"></i>
                                                <?php else: ?>
                                                    <i class="fas fa-times-circle"></i>
                                                <?php endif; ?>
                                                <?php echo ucfirst($user['status']); ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <div class="action-buttons justify-content-center">
                                                <button type="button" class="btn btn-action" 
                                                        style="background: linear-gradient(135deg, #28a745, #20c997); color: white;"
                                                        onclick="activateUser(<?php echo htmlspecialchars(json_encode($user)); ?>); return false;"
                                                        title="<?php echo $is_evaluation_ongoing ? 'Cannot activate users during ongoing evaluation' : 'Activate'; ?>"
                                                        <?php echo $is_evaluation_ongoing ? 'disabled' : ''; ?>>
                                                    <i class="fas fa-redo-alt"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div id="inactivePagination" class="d-flex justify-content-end mt-3"></div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-archive"></i>
                        <h4>No inactive users</h4>
                        <p>Inactive users will appear here.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Role Selection Modal -->
    <div class="modal fade" id="selectRoleModal" tabindex="-1" aria-hidden="true" <?php if ($is_evaluation_ongoing): ?>data-evaluation-blocked="true"<?php endif; ?>>
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-user-plus me-2"></i>Select User Role
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php if ($is_evaluation_ongoing): ?>
                        <div class="alert alert-warning mb-4">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Action Disabled:</strong> Cannot add users during an ongoing evaluation period.
                        </div>
                    <?php endif; ?>
                    <p class="text-muted mb-4">Choose a user role to create the appropriate account:</p>
                    <div class="role-selection-grid">
                        <div class="role-option" onclick="selectRole('student', this)">
                            <i class="fas fa-graduation-cap"></i>
                            <p>Student</p>
                        </div>
                        <div class="role-option" onclick="selectRole('teacher', this)">
                            <i class="fas fa-chalkboard-user"></i>
                            <p>Teacher</p>
                        </div>
                        <div class="role-option" onclick="selectRole('registrar', this)">
                            <i class="fas fa-book"></i>
                            <p>Registrar</p>
                        </div>
                        <div class="role-option" onclick="selectRole('admin', this)">
                            <i class="fas fa-shield"></i>
                            <p>Admin</p>
                        </div>
                    </div>
                    <button type="button" class="btn btn-primary-custom w-100 mt-4" onclick="proceedToForm()" id="proceedBtn" disabled>
                        Continue to Form
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Add User Modal with Role-Specific Forms -->
    <div class="modal fade" id="addUserModal" tabindex="-1" aria-hidden="true" <?php if ($is_evaluation_ongoing): ?>data-evaluation-blocked="true"<?php endif; ?>>
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-user-plus me-2"></i><span id="modalTitle">Add New User</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php if ($is_evaluation_ongoing): ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Action Disabled:</strong> Cannot add users during an ongoing evaluation period.
                        </div>
                    <?php endif; ?>
                    <form method="POST" id="addUserForm" <?php if ($is_evaluation_ongoing): ?>onsubmit="return false;"<?php endif; ?>>
                        <input type="hidden" name="action" value="add">
                        <input type="hidden" name="role" id="selectedRole">
                        
                        <!-- Common Fields for All Roles -->
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                    <label for="add_first_name" class="form-label">First Name *</label>
                                    <input type="text" class="form-control form-control-custom" id="add_first_name" 
                                        name="first_name" required value="<?php echo htmlspecialchars($old_input['first_name'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                    <label for="add_last_name" class="form-label">Last Name *</label>
                                    <input type="text" class="form-control form-control-custom" id="add_last_name" 
                                        name="last_name" required value="<?php echo htmlspecialchars($old_input['last_name'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="add_email" class="form-label">Email *</label>
                            <input type="email" class="form-control form-control-custom" id="add_email" 
                                name="email" required value="<?php echo htmlspecialchars($old_input['email'] ?? ''); ?>">
                        </div>
                        
                        <!-- Student-Specific Fields -->
                        <div class="form-section" id="studentFields">
                            <h6 class="mb-3">Student Information</h6>
                            <div class="mb-3">
                                <label for="add_strand" class="form-label">Strand *</label>
                                <select class="form-control form-control-custom" id="add_strand" name="strand" required onchange="filterAddSectionsByStrandAndYearLevel('add_strand', 'add_year_level', 'add_section_id')">
                                    <option value="">Select Strand</option>
                                    <option value="Accountancy, Business, and Management" <?php if (($old_input['strand'] ?? '') === 'Accountancy, Business, and Management') echo 'selected'; ?>>Accountancy, Business, and Management</option>
                                    <option value="Humanities and Social Sciences" <?php if (($old_input['strand'] ?? '') === 'Humanities and Social Sciences') echo 'selected'; ?>>Humanities and Social Sciences</option>
                                    <option value="Science, Technology, Engineering, Mathematics" <?php if (($old_input['strand'] ?? '') === 'Science, Technology, Engineering, Mathematics') echo 'selected'; ?>>Science, Technology, Engineering, Mathematics</option>
                                    <option value="Information and Communication Technology" <?php if (($old_input['strand'] ?? '') === 'Information and Communication Technology') echo 'selected'; ?>>Information and Communication Technology</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="add_year_level" class="form-label">Year Level *</label>
                                <select class="form-control form-control-custom" id="add_year_level" name="year_level" required onchange="filterAddSectionsByStrandAndYearLevel('add_strand', 'add_year_level', 'add_section_id')">
                                    <option value="">Select Year Level</option>
                                    <option value="11" <?php if (($old_input['year_level'] ?? '') === '11') echo 'selected'; ?>>Grade 11</option>
                                    <option value="12" <?php if (($old_input['year_level'] ?? '') === '12') echo 'selected'; ?>>Grade 12</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="add_section_id" class="form-label">Section *</label>
                                <select class="form-control form-control-custom" id="add_section_id" name="section_id" required>
                                    <option value="">Select Section</option>
                                </select>
                                <small class="form-text text-muted">Section list is filtered by Strand and Year Level</small>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="add_school_year_student" class="form-label">Current School Year *</label>
                                    <input type="text" class="form-control form-control-custom" id="add_school_year_student" 
                                           value="<?php echo htmlspecialchars($current_school_year); ?>" 
                                           readonly disabled 
                                           style="background-color: #e9ecef; cursor: not-allowed;"
                                           title="This field is automatically set and cannot be modified">
                                    <input type="hidden" name="school_year" value="<?php echo htmlspecialchars($current_school_year); ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="add_semester_student" class="form-label">Current Semester *</label>
                                    <select class="form-control form-control-custom" id="add_semester_student" 
                                            disabled 
                                            style="background-color: #e9ecef; cursor: not-allowed;"
                                            title="This field is automatically set and cannot be modified">
                                        <option value="1" <?php echo $current_semester == 1 ? 'selected' : ''; ?>>1st Semester</option>
                                        <option value="2" <?php echo $current_semester == 2 ? 'selected' : ''; ?>>2nd Semester</option>
                                    </select>
                                    <input type="hidden" name="semester" value="<?php echo $current_semester; ?>">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Teacher-Specific Fields -->
                        <div class="form-section" id="teacherFields">
                            <h6 class="mb-3">Teacher Information</h6>
                            <div class="mb-3">
                                <label for="add_strand_teacher" class="form-label">Strand *</label>
                                <select class="form-control form-control-custom" id="add_strand_teacher" name="strand" required>
                                    <option value="">Select Strand</option>
                                    <option value="Accountancy, Business, and Management" <?php if (($old_input['strand'] ?? '') === 'Accountancy, Business, and Management') echo 'selected'; ?>>Accountancy, Business, and Management</option>
                                    <option value="Humanities and Social Sciences" <?php if (($old_input['strand'] ?? '') === 'Humanities and Social Sciences') echo 'selected'; ?>>Humanities and Social Sciences</option>
                                    <option value="Science, Technology, Engineering, Mathematics" <?php if (($old_input['strand'] ?? '') === 'Science, Technology, Engineering, Mathematics') echo 'selected'; ?>>Science, Technology, Engineering, Mathematics</option>
                                    <option value="Information and Communication Technology" <?php if (($old_input['strand'] ?? '') === 'Information and Communication Technology') echo 'selected'; ?>>Information and Communication Technology</option>
                                </select>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="add_school_year_teacher" class="form-label">Current School Year *</label>
                                    <input type="text" class="form-control form-control-custom" id="add_school_year_teacher" 
                                           value="<?php echo htmlspecialchars($current_school_year); ?>" 
                                           readonly disabled 
                                           style="background-color: #e9ecef; cursor: not-allowed;"
                                           title="This field is automatically set and cannot be modified">
                                    <input type="hidden" name="school_year" value="<?php echo htmlspecialchars($current_school_year); ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="add_semester_teacher" class="form-label">Current Semester *</label>
                                    <select class="form-control form-control-custom" id="add_semester_teacher" 
                                            disabled 
                                            style="background-color: #e9ecef; cursor: not-allowed;"
                                            title="This field is automatically set and cannot be modified">
                                        <option value="1" <?php echo $current_semester == 1 ? 'selected' : ''; ?>>1st Semester</option>
                                        <option value="2" <?php echo $current_semester == 2 ? 'selected' : ''; ?>>2nd Semester</option>
                                    </select>
                                    <input type="hidden" name="semester" value="<?php echo $current_semester; ?>">
                                </div>
                            </div>
                        </div>
                        
                        
                        <div class="text-end mt-4">
                            <button type="button" class="btn btn-secondary me-2" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary-custom">
                                <i class="fas fa-save me-2"></i>Add User
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Import Excel Modal -->
    <div class="modal fade" id="importExcelModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-file-excel me-2"></i>Import Users from Excel</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <a href="download_import_template.php" class="btn btn-light border-2 w-100 d-flex align-items-center justify-content-center" style="border-color: var(--primary-color); color: var(--primary-color); font-weight: 600;">
                            <i class="fas fa-download me-2"></i>Download Import Template
                        </a>
                        <small class="d-block text-center mt-2 text-muted">Use this template to prepare your user data</small>
                    </div>
                    <hr>
                    <form method="POST" enctype="multipart/form-data" id="importExcelForm">
                        <input type="hidden" name="action" value="import_excel">
                        <div class="mb-3">
                            <label for="excel_file" class="form-label">Excel File (.xlsx/.xls)</label>
                            <input type="file" class="form-control form-control-custom" id="excel_file" name="excel_file" accept=".xlsx,.xls" required>
                        </div>
                        <button type="submit" class="btn btn-primary-custom w-100">
                            <i class="fas fa-upload me-2"></i>Start Import
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Export Excel Modal -->
    <div class="modal fade" id="exportExcelModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-file-export me-2"></i>Export Users to Excel</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted mb-3">Combine filters to export specific user segments.</p>
                    <div class="mb-3">
                        <label for="exportRoleFilter" class="form-label">Role</label>
                        <select id="exportRoleFilter" class="form-control form-control-custom">
                            <option value="">All Roles</option>
                            <option value="student">Student</option>
                            <option value="teacher">Teacher</option>
                            <option value="registrar">Registrar</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="exportStatusFilter" class="form-label">Status</label>
                        <select id="exportStatusFilter" class="form-control form-control-custom">
                            <option value="">All Statuses</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="exportSchoolYearFilter" class="form-label">School Year</label>
                        <select id="exportSchoolYearFilter" class="form-control form-control-custom">
                            <option value="">All School Years</option>
                            <?php foreach ($school_year_options as $school_year_option): ?>
                                <option value="<?php echo htmlspecialchars($school_year_option); ?>">
                                    <?php echo htmlspecialchars($school_year_option); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary-custom" onclick="exportToExcel()">
                        <i class="fas fa-download me-2"></i>Export
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div class="modal fade" id="editUserModal" tabindex="-1" aria-hidden="true" <?php if ($is_evaluation_ongoing): ?>data-evaluation-blocked="true"<?php endif; ?>>
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-user-edit me-2"></i>Edit User
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php if ($is_evaluation_ongoing): ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Action Disabled:</strong> Cannot edit users during an ongoing evaluation period.
                        </div>
                    <?php endif; ?>
                    <!-- Info message for archived users (dynamically inserted) -->
                    <form method="POST" id="editUserForm" <?php if ($is_evaluation_ongoing): ?>onsubmit="return false;"<?php endif; ?>>
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="user_id" id="edit_user_id">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="edit_first_name" class="form-label">First Name</label>
                                <input type="text" class="form-control form-control-custom" id="edit_first_name" 
                                       name="first_name" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="edit_last_name" class="form-label">Last Name</label>
                                <input type="text" class="form-control form-control-custom" id="edit_last_name" 
                                       name="last_name" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="edit_email" class="form-label">Email</label>
                            <input type="email" class="form-control form-control-custom" id="edit_email" 
                                   name="email" required>
                        </div>
                        
                        <!-- Status Field -->
                        <div class="mb-3">
                            <label for="edit_status" class="form-label">Status</label>
                            <select class="form-control form-control-custom" id="edit_status" name="status">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>

                        <!-- Student-Specific Fields (Edit) -->
                        <div class="form-section" id="edit_studentFields">
                            <h6 class="mb-3">Student Information</h6>
                            <div class="mb-3">
                                <label for="edit_strand_student" class="form-label">Strand</label>
                                <select class="form-control form-control-custom" id="edit_strand_student" name="strand_student" onchange="filterSectionsByStrandAndYearLevel('edit_strand_student', 'edit_year_level_student', 'edit_section_id_student')">
                                    <option value="">Select Strand</option>
                                    <option value="Accountancy, Business, and Management">Accountancy, Business, and Management</option>
                                    <option value="Humanities and Social Sciences">Humanities and Social Sciences</option>
                                    <option value="Science, Technology, Engineering, Mathematics">Science, Technology, Engineering, Mathematics</option>
                                    <option value="Information and Communication Technology">Information and Communication Technology</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="edit_year_level_student" class="form-label">Year Level</label>
                                <select class="form-control form-control-custom" id="edit_year_level_student" name="year_level_student" disabled title="Year Level cannot be changed">
                                    <option value="">Select Year Level</option>
                                    <option value="11">Grade 11</option>
                                    <option value="12">Grade 12</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="edit_section_id_student" class="form-label">Section</label>
                                <select class="form-control form-control-custom" id="edit_section_id_student" name="section_id_student">
                                    <option value="">Select Section</option>
                                </select>
                                <small class="form-text text-muted">Section list is filtered by Strand and Year Level</small>
                            </div>
                        </div>

                        <!-- Teacher-Specific Fields (Edit) -->
                        <div class="form-section" id="edit_teacherFields">
                            <h6 class="mb-3">Teacher Information</h6>
                            <div class="mb-3">
                                <label for="edit_strand_teacher" class="form-label">Strand</label>
                                <select class="form-control form-control-custom" id="edit_strand_teacher" name="strand_teacher">
                                    <option value="">Select Strand</option>
                                    <option value="Accountancy, Business, and Management">Accountancy, Business, and Management</option>
                                    <option value="Humanities and Social Sciences">Humanities and Social Sciences</option>
                                    <option value="Science, Technology, Engineering, Mathematics">Science, Technology, Engineering, Mathematics</option>
                                    <option value="Information and Communication Technology">Information and Communication Technology</option>
                                </select>
                            </div>
                        </div>
                        <div class="text-end">
                            <button type="button" class="btn btn-secondary me-2" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary-custom">
                                <i class="fas fa-save me-2"></i>Update User
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Available sections from PHP backend
        const availableSections = <?php echo json_encode($sections); ?>;
        
        // Filter sections by Strand and Year Level
        function filterSectionsByStrandAndYearLevel(strandSelectId, yearLevelSelectId, sectionSelectId) {
            const strandSelect = document.getElementById(strandSelectId);
            const yearLevelSelect = document.getElementById(yearLevelSelectId);
            const sectionSelect = document.getElementById(sectionSelectId);
            
            if (!strandSelect || !yearLevelSelect || !sectionSelect) return;
            
            const selectedStrand = strandSelect.value;
            const selectedYearLevel = yearLevelSelect.value;
            
            // Filter sections that match both strand and year level
            const filteredSections = availableSections.filter(section => {
                return section.strand === selectedStrand && section.year_level === selectedYearLevel;
            });
            
            // Preserve the current selection if it's still valid
            const currentValue = sectionSelect.value;
            let isCurrentValueValid = false;
            
            // Clear and repopulate the section dropdown
            sectionSelect.innerHTML = '<option value="">Select Section</option>';
            
            filteredSections.forEach(section => {
                const option = document.createElement('option');
                option.value = section.section_id;
                option.textContent = section.section_name;
                sectionSelect.appendChild(option);
                
                // Check if current value is still valid
                if (currentValue === String(section.section_id)) {
                    isCurrentValueValid = true;
                }
            });
            
            // Restore selection if it's still valid
            if (isCurrentValueValid) {
                sectionSelect.value = currentValue;
            } else {
                sectionSelect.value = '';
            }
        }
        
        // Filter sections for Add Student form
        function filterAddSectionsByStrandAndYearLevel(strandSelectId, yearLevelSelectId, sectionSelectId) {
            return filterSectionsByStrandAndYearLevel(strandSelectId, yearLevelSelectId, sectionSelectId);
        }
        
        function confirmDeactivateAll() {
            <?php if ($is_evaluation_ongoing): ?>
            showModernAlert('warning', 'Action Blocked', 'Cannot deactivate users during an ongoing evaluation period.');
            return;
            <?php endif; ?>
            
            const roleFilter = document.getElementById('roleFilter').value;
            
            if (roleFilter === '') {
                showModernAlert('warning', 'Role Required', 'Please select a specific role to deactivate. You cannot deactivate all users across all roles at once.');
                return;
            }
            
            // Count the number of rows visible in the current tab
            const activeTab = document.getElementById('active');
            const visibleRows = activeTab.querySelectorAll('table tbody tr:not([style*="display: none"])');
            
            if (visibleRows.length === 0) {
                showModernAlert('warning', 'No Users Found', 'No active users found with the selected role to deactivate.');
                return;
            }
            
            // Show confirmation dialog with count
            showModernConfirm(
                'Deactivate All ' + capitalizeWord(roleFilter) + 's',
                `Are you sure you want to deactivate all ${visibleRows.length} active ${roleFilter}(s)? This action cannot be undone.`,
                function() {
                    performDeactivateAll(roleFilter);
                },
                { confirmText: 'Yes, Deactivate All' }
            );
        }
        
        function performDeactivateAll(role) {
            // Prepare form data
            const formData = new FormData();
            formData.append('action', 'deactivate_all_role');
            formData.append('role', role);
            
            // Show loading state
            const deactivateBtn = document.getElementById('deactivateAllBtn');
            const originalText = deactivateBtn.innerHTML;
            deactivateBtn.disabled = true;
            deactivateBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Processing...';
            
            // Send AJAX request
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(data => {
                // Reload page to show updated data
                window.location.reload();
            })
            .catch(error => {
                console.error('Error:', error);
                showModernAlert('error', 'Deactivation Failed', 'An error occurred while deactivating users. Please try again.');
                deactivateBtn.disabled = false;
                deactivateBtn.innerHTML = originalText;
            });
        }
        
        function capitalizeWord(word) {
            return word.charAt(0).toUpperCase() + word.slice(1);
        }
        
        const PAGE_SIZE = 10;
        const paginationState = {
            active: 1,
            inactive: 1
        };

        function syncExportFiltersFromTable() {
            const tableRoleFilter = document.getElementById('roleFilter');
            const exportRoleFilter = document.getElementById('exportRoleFilter');

            if (tableRoleFilter && exportRoleFilter) {
                exportRoleFilter.value = tableRoleFilter.value || '';
            }
        }

        function exportToExcel() {
            const search = '';

            const exportRoleEl = document.getElementById('exportRoleFilter');
            const exportStatusEl = document.getElementById('exportStatusFilter');
            const exportSchoolYearEl = document.getElementById('exportSchoolYearFilter');

            const role = exportRoleEl ? exportRoleEl.value.trim() : '';
            const status = exportStatusEl ? exportStatusEl.value.trim() : '';
            const schoolYear = exportSchoolYearEl ? exportSchoolYearEl.value.trim() : '';

            const params = new URLSearchParams({
                action: 'export_excel',
                search: search,
                role: role,
                status: status,
                school_year: schoolYear
            });

            const exportModalEl = document.getElementById('exportExcelModal');
            if (exportModalEl && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                const modalInstance = bootstrap.Modal.getInstance(exportModalEl) || new bootstrap.Modal(exportModalEl);
                modalInstance.hide();
            }

            // Delay download slightly so the hide transition can complete cleanly.
            setTimeout(() => {
                window.location.href = `${window.location.pathname}?${params.toString()}`;
            }, 200);
        }

        function renderPagination(tabName, rows) {
            const paginationEl = document.getElementById(`${tabName}Pagination`);
            if (!paginationEl) return;

            const totalRows = rows.length;
            const totalPages = Math.max(1, Math.ceil(totalRows / PAGE_SIZE));
            if (paginationState[tabName] > totalPages) paginationState[tabName] = totalPages;
            if (paginationState[tabName] < 1) paginationState[tabName] = 1;

            const currentPage = paginationState[tabName];
            rows.forEach((row, index) => {
                const start = (currentPage - 1) * PAGE_SIZE;
                const end = start + PAGE_SIZE;
                row.style.display = index >= start && index < end ? '' : 'none';
            });

            if (totalRows <= PAGE_SIZE) {
                paginationEl.innerHTML = '';
                return;
            }

            paginationEl.innerHTML = `
                <nav>
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item ${currentPage === 1 ? 'disabled' : ''}">
                            <button class="page-link" onclick="changePage('${tabName}', -1)" type="button">Previous</button>
                        </li>
                        <li class="page-item disabled"><span class="page-link">Page ${currentPage} of ${totalPages}</span></li>
                        <li class="page-item ${currentPage === totalPages ? 'disabled' : ''}">
                            <button class="page-link" onclick="changePage('${tabName}', 1)" type="button">Next</button>
                        </li>
                    </ul>
                </nav>
            `;
        }

        function changePage(tabName, delta) {
            paginationState[tabName] += delta;
            filterTable();
        }
        
        // ============ EXISTING FUNCTIONS ============
        // Role selection and form display logic
        let selectedRole = null;
        
        function selectRole(role, element) {
            selectedRole = role;
            
            // Update selected state
            document.querySelectorAll('.role-option').forEach(opt => {
                opt.classList.remove('selected');
            });
            element.classList.add('selected');
            
            // Enable proceed button
            document.getElementById('proceedBtn').disabled = false;
        }
        
        function proceedToForm() {
            if (!selectedRole) return;
            
            <?php if ($is_evaluation_ongoing): ?>
            showModernAlert('warning', 'Action Blocked', 'Cannot add users during an ongoing evaluation period.');
            return;
            <?php endif; ?>
            
            // Close role selection modal
            const roleModal = bootstrap.Modal.getInstance(document.getElementById('selectRoleModal'));
            roleModal.hide();

            // Reset the form first (clears previous values) then set the hidden role
            const form = document.getElementById('addUserForm');
            if (form) form.reset();

            // Set selected role in form (after reset to ensure value persists)
            document.getElementById('selectedRole').value = selectedRole;

            // Update modal title
            const roleNames = {
                'student': 'Add New Student',
                'teacher': 'Add New Teacher',
                'registrar': 'Add New Registrar',
                'admin': 'Add New Admin'
            };
            document.getElementById('modalTitle').textContent = roleNames[selectedRole];
            
            // Show/hide role-specific fields and disable inactive fields to prevent submission
            // Disabled fields are not submitted by HTML forms
            const protectedFieldIds = ['add_school_year_student', 'add_semester_student', 'add_school_year_teacher', 'add_semester_teacher'];
            
            document.querySelectorAll('.form-section').forEach(section => {
                section.classList.remove('active');
                // Disable all inputs in inactive sections (except hidden fields which are always submitted)
                section.querySelectorAll('input:not([type="hidden"]), select, textarea').forEach(field => {
                    field.disabled = true;
                });
            });
            const activeSection = document.getElementById(selectedRole + 'Fields');
            if (activeSection) {
                activeSection.classList.add('active');
                // Enable all inputs in active section EXCEPT protected fields (school_year, semester)
                activeSection.querySelectorAll('input:not([type="hidden"]), select, textarea').forEach(field => {
                    // Always keep school_year and semester fields disabled - they are read-only
                    if (!protectedFieldIds.includes(field.id)) {
                        field.disabled = false;
                    } else {
                        field.disabled = true; // Ensure protected fields remain disabled
                    }
                });
            }
            
            // Show the add user modal
            const addModal = new bootstrap.Modal(document.getElementById('addUserModal'));
            addModal.show();
        }
        
        function switchTab(tabName) {
            const tabContents = document.querySelectorAll('.tab-content');
            tabContents.forEach(content => {
                content.classList.remove('active');
            });
            
            const tabButtons = document.querySelectorAll('.tab-button');
            tabButtons.forEach(button => {
                button.classList.remove('active');
            });
            
            document.getElementById(tabName).classList.add('active');
            event.target.classList.add('active');
            
            // Enable/disable "Deactivate All" button based on tab
            const deactivateAllBtn = document.getElementById('deactivateAllBtn');
            if (deactivateAllBtn) {
                if (tabName === 'inactive') {
                    deactivateAllBtn.disabled = true;
                    deactivateAllBtn.title = 'Only available on Active Users tab';
                } else {
                    deactivateAllBtn.disabled = false;
                    deactivateAllBtn.title = 'Deactivate all users of the selected role';
                }
            }
            
            // Re-apply filters to the newly visible tab
            filterTable();
        }
        
        function activateUser(user) {
            <?php if ($is_evaluation_ongoing): ?>
            showModernAlert('warning', 'Action Blocked', 'Cannot activate users during an ongoing evaluation period.');
            return;
            <?php endif; ?>
            
            // Create and submit a form to activate the user
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = window.location.href;
            form.style.display = 'none';
            
            // Add hidden inputs
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'update';
            form.appendChild(actionInput);
            
            const userIdInput = document.createElement('input');
            userIdInput.type = 'hidden';
            userIdInput.name = 'user_id';
            userIdInput.value = user.user_id;
            form.appendChild(userIdInput);
            
            const statusInput = document.createElement('input');
            statusInput.type = 'hidden';
            statusInput.name = 'status';
            statusInput.value = 'active';
            form.appendChild(statusInput);
            
            // Add form to page and submit
            document.body.appendChild(form);
            form.submit();
        }
        
        function editUser(user) {
            <?php if ($is_evaluation_ongoing): ?>
            showModernAlert('warning', 'Action Blocked', 'Cannot edit users during an ongoing evaluation period.');
            return;
            <?php endif; ?>
            document.getElementById('edit_user_id').value = user.user_id;
            document.getElementById('edit_first_name').value = user.first_name;
            document.getElementById('edit_last_name').value = user.last_name;
            document.getElementById('edit_email').value = user.email;
            document.getElementById('edit_status').value = user.status;

            // Check if user is archived (inactive)
            const isArchived = user.status === 'inactive';
            
            // Fields that should be disabled for archived users
            // NOTE: edit_year_level_student is ALWAYS disabled (both active and archived) - not included here
            const fieldsToDisable = [
                'edit_first_name',
                'edit_last_name',
                'edit_email',
                'edit_strand_student',
                'edit_section_id_student',
                'edit_strand_teacher'
            ];

            // Enable/disable fields based on archived status
            fieldsToDisable.forEach(fieldId => {
                const field = document.getElementById(fieldId);
                if (field) {
                    if (isArchived) {
                        field.disabled = true;
                        field.readOnly = true;
                        field.style.backgroundColor = '#e9ecef';
                        field.style.cursor = 'not-allowed';
                        // Remove name attribute to prevent submission (security)
                        field.setAttribute('data-original-name', field.getAttribute('name'));
                        field.removeAttribute('name');
                    } else {
                        field.disabled = false;
                        field.readOnly = false;
                        field.style.backgroundColor = '';
                        field.style.cursor = '';
                        // Restore name attribute if it was stored
                        if (field.hasAttribute('data-original-name')) {
                            field.setAttribute('name', field.getAttribute('data-original-name'));
                            field.removeAttribute('data-original-name');
                        }
                    }
                }
            });
            
            // Status field should ALWAYS be enabled so users can be reactivated
            const statusField = document.getElementById('edit_status');
            if (statusField) {
                statusField.disabled = false;
                statusField.style.backgroundColor = '';
                statusField.style.cursor = '';
                if (statusField.hasAttribute('data-original-name')) {
                    statusField.setAttribute('name', statusField.getAttribute('data-original-name'));
                    statusField.removeAttribute('data-original-name');
                }
            }

            // Hide all edit role sections first
            document.querySelectorAll('#editUserModal .form-section').forEach(section => {
                section.classList.remove('active');
            });

            // Show and populate role-specific edit fields
            if (user.role === 'student') {
                const studentSection = document.getElementById('edit_studentFields');
                if (studentSection) {
                    studentSection.classList.add('active');
                    const strandField = document.getElementById('edit_strand_student');
                    const yearLevelField = document.getElementById('edit_year_level_student');
                    const sectionSelect = document.getElementById('edit_section_id_student');
                    if (strandField) strandField.value = user.strand || '';
                    if (yearLevelField) yearLevelField.value = user.year_level || '';
                    
                    // Populate and filter sections based on strand and year level
                    if (sectionSelect) {
                        // Trigger the filtering function to populate sections
                        filterSectionsByStrandAndYearLevel('edit_strand_student', 'edit_year_level_student', 'edit_section_id_student');
                        // Set the current section
                        setTimeout(() => {
                            if (user.section_id) {
                                sectionSelect.value = user.section_id;
                            }
                        }, 0);
                    }
                    
                    // Apply disabled state to student fields if archived
                    if (isArchived) {
                        if (strandField) {
                            strandField.disabled = true;
                            strandField.style.backgroundColor = '#e9ecef';
                            strandField.style.cursor = 'not-allowed';
                            strandField.setAttribute('data-original-name', strandField.getAttribute('name'));
                            strandField.removeAttribute('name');
                        }
                        if (yearLevelField) {
                            yearLevelField.disabled = true;
                            yearLevelField.style.backgroundColor = '#e9ecef';
                            yearLevelField.style.cursor = 'not-allowed';
                            yearLevelField.setAttribute('data-original-name', yearLevelField.getAttribute('name'));
                            yearLevelField.removeAttribute('name');
                        }
                        if (sectionSelect) {
                            sectionSelect.disabled = true;
                            sectionSelect.style.backgroundColor = '#e9ecef';
                            sectionSelect.style.cursor = 'not-allowed';
                            sectionSelect.setAttribute('data-original-name', sectionSelect.getAttribute('name'));
                            sectionSelect.removeAttribute('name');
                        }
                    }
                }
            } else if (user.role === 'teacher') {
                const teacherSection = document.getElementById('edit_teacherFields');
                if (teacherSection) {
                    teacherSection.classList.add('active');
                    const strandField = document.getElementById('edit_strand_teacher');
                    if (strandField) strandField.value = user.strand || '';
                    
                    // Apply disabled state to teacher field if archived
                    if (isArchived && strandField) {
                        strandField.disabled = true;
                        strandField.style.backgroundColor = '#e9ecef';
                        strandField.style.cursor = 'not-allowed';
                        strandField.setAttribute('data-original-name', strandField.getAttribute('name'));
                        strandField.removeAttribute('name');
                    }
                }
            }
            
            // Show info message for archived users
            let infoMsg = document.getElementById('edit_archived_info');
            if (isArchived) {
                if (!infoMsg) {
                    infoMsg = document.createElement('div');
                    infoMsg.id = 'edit_archived_info';
                    infoMsg.className = 'alert alert-info mb-3';
                    infoMsg.innerHTML = '<i class="fas fa-info-circle me-2"></i><strong>Archived User:</strong> User profile is locked. You can change the Status field to reactivate this user.';
                    const form = document.getElementById('editUserForm');
                    form.insertBefore(infoMsg, form.firstChild);
                }
                infoMsg.style.display = 'block';
            } else {
                if (infoMsg) {
                    infoMsg.style.display = 'none';
                }
            }
        }
        
        // Table filtering logic
        function filterTable() {
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            const roleFilter = document.getElementById('roleFilter').value.toLowerCase();
            
            // Get current active tab
            const activeTab = document.querySelector('.tab-content.active');
            const rows = activeTab.querySelectorAll('.table-custom tbody tr');
            const filteredRows = [];
            let visibleCount = 0;
            
            rows.forEach(row => {
                // Get row data
                const fullNameCell = row.querySelector('.full-name-col .user-details h6');
                const emailCell = row.querySelector('td:nth-child(3)'); // Adjusted after removing checkbox column
                const roleCell = row.querySelector('.badge-role');
                
                const fullName = fullNameCell ? fullNameCell.textContent.toLowerCase() : '';
                const email = emailCell ? emailCell.textContent.toLowerCase() : '';
                const role = roleCell ? roleCell.textContent.toLowerCase().trim() : '';
                
                // Check if row matches search and filter criteria
                const matchesSearch = fullName.includes(searchTerm) || email.includes(searchTerm);
                const matchesRole = !roleFilter || role === roleFilter;
                
                if (matchesSearch && matchesRole) {
                    row.style.display = '';
                    visibleCount++;
                    filteredRows.push(row);
                    
                    // Add animation
                    row.style.animation = 'fadeIn 0.3s ease';
                } else {
                    row.style.display = 'none';
                }
            });

            const activeTabId = activeTab.id === 'inactive' ? 'inactive' : 'active';
            renderPagination(activeTabId, filteredRows);
            
            // Show empty state if no results
            const tableContainer = activeTab.querySelector('.table-container');
            let emptyState = tableContainer ? tableContainer.nextElementSibling : null;
            
            if (visibleCount === 0 && tableContainer) {
                if (!emptyState || !emptyState.classList.contains('empty-state-filter')) {
                    emptyState = document.createElement('div');
                    emptyState.className = 'empty-state empty-state-filter';
                    emptyState.innerHTML = '<i class="fas fa-search"></i><h4>No users found</h4><p>Try adjusting your search or filter criteria.</p>';
                    tableContainer.parentNode.insertBefore(emptyState, tableContainer.nextSibling);
                }
                emptyState.style.display = 'block';
            } else if (emptyState && emptyState.classList.contains('empty-state-filter')) {
                emptyState.style.display = 'none';
            }
            
            // Update "Deactivate All" button state
            const deactivateAllBtn = document.getElementById('deactivateAllBtn');
            if (deactivateAllBtn) {
                const isActiveTab = activeTab && activeTab.id === 'active';
                const roleSelected = roleFilter !== '';
                
                if (isActiveTab && roleSelected && visibleCount > 0) {
                    deactivateAllBtn.disabled = false;
                    deactivateAllBtn.title = `Deactivate all ${visibleCount} active ${roleFilter}(s)`;
                } else {
                    deactivateAllBtn.disabled = true;
                    if (!isActiveTab) {
                        deactivateAllBtn.title = 'Only available on Active Users tab';
                    } else if (!roleSelected) {
                        deactivateAllBtn.title = 'Select a specific role to deactivate';
                    } else if (visibleCount === 0) {
                        deactivateAllBtn.title = 'No users match the current filters';
                    }
                }
            }
        }
        
        // Add fade-in animation
        const style = document.createElement('style');
        style.textContent = '@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }';
        document.head.appendChild(style);
        
        // Note: previously we prevented the role selection modal from closing
        // when no role was selected which blocked the close button/backdrop.
        // Allow the modal to be closed normally (close button, backdrop, Escape).

        document.addEventListener('DOMContentLoaded', function() {
            const exportExcelModal = document.getElementById('exportExcelModal');
            if (exportExcelModal) {
                exportExcelModal.addEventListener('show.bs.modal', syncExportFiltersFromTable);
            }
            
            // Initialize "Deactivate All" button state
            const deactivateAllBtn = document.getElementById('deactivateAllBtn');
            if (deactivateAllBtn) {
                deactivateAllBtn.disabled = true; // Disabled by default until a role is selected
                deactivateAllBtn.title = 'Select a specific role to deactivate';
            }
            
            const successMessage = <?php echo json_encode($page_success_message); ?>;
            const errorMessage = <?php echo json_encode($page_error_message); ?>;
            if (successMessage) {
                showModernAlert('success', 'Success', successMessage, { autoCloseMs: 3200 });
            } else if (errorMessage) {
                showModernAlert('error', 'Error', errorMessage);
            }
            
            <?php if ($is_evaluation_ongoing): ?>
            showModernAlert(
                'warning',
                'Evaluation Period Active',
                <?php echo json_encode("User management actions (Add, Edit, Deactivate) are disabled while the evaluation is ongoing. The evaluation period ends on " . date('M d, Y g:i A', strtotime($evaluation_end_date)) . "."); ?>
            );
            <?php endif; ?>
            
            const tableRows = document.querySelectorAll('.table-custom tbody tr');
            tableRows.forEach((row, index) => {
                row.style.opacity = '0';
                row.style.transform = 'translateY(20px)';
                
                setTimeout(() => {
                    row.style.transition = 'all 0.6s ease';
                    row.style.opacity = '1';
                    row.style.transform = 'translateY(0)';
                }, index * 50);
            });

            // Initial filter application when the page loads
            filterTable();
        });

        // Prevent modification of read-only school year and semester fields
        (function() {
            const protectFields = function() {
                // Protect school year and semester fields from modification
                const schoolYearFields = ['add_school_year_student', 'add_school_year_teacher'];
                const semesterFields = ['add_semester_student', 'add_semester_teacher'];
                
                schoolYearFields.forEach(id => {
                    const field = document.getElementById(id);
                    if (field) {
                        field.disabled = true;
                        field.readOnly = true;
                        // Prevent any attempts to enable the field
                        field.addEventListener('focus', function(e) {
                            e.preventDefault();
                            e.target.blur();
                        });
                        // Prevent context menu (right-click)
                        field.addEventListener('contextmenu', function(e) {
                            e.preventDefault();
                            return false;
                        });
                    }
                });
                
                semesterFields.forEach(id => {
                    const field = document.getElementById(id);
                    if (field) {
                        field.disabled = true;
                        // Prevent dropdown from opening
                        field.addEventListener('mousedown', function(e) {
                            e.preventDefault();
                            return false;
                        });
                        field.addEventListener('click', function(e) {
                            e.preventDefault();
                            return false;
                        });
                        // Prevent context menu
                        field.addEventListener('contextmenu', function(e) {
                            e.preventDefault();
                            return false;
                        });
                    }
                });
            };
            
            // Run protection when DOM is ready
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', protectFields);
            } else {
                protectFields();
            }
            
            // Re-apply protection when modal is shown (in case fields are re-enabled somehow)
            const addUserModal = document.getElementById('addUserModal');
            if (addUserModal) {
                addUserModal.addEventListener('shown.bs.modal', protectFields);
            }
        })();

        // Protect archived user fields from modification in edit modal
        (function() {
            const protectArchivedFields = function() {
                const editModal = document.getElementById('editUserModal');
                if (!editModal) return;
                
                // List of fields that should be protected for archived users
                const protectedFieldIds = [
                    'edit_first_name',
                    'edit_last_name',
                    'edit_email',
                    'edit_strand_student',
                    'edit_year_level_student',
                    'edit_strand_teacher'
                ];
                
                protectedFieldIds.forEach(fieldId => {
                    const field = document.getElementById(fieldId);
                    if (field && field.disabled) {
                        // Prevent focus
                        field.addEventListener('focus', function(e) {
                            e.preventDefault();
                            e.target.blur();
                        });
                        
                        // Prevent context menu
                        field.addEventListener('contextmenu', function(e) {
                            e.preventDefault();
                            return false;
                        });
                        
                        // For select fields, prevent dropdown opening
                        if (field.tagName === 'SELECT') {
                            field.addEventListener('mousedown', function(e) {
                                e.preventDefault();
                                return false;
                            });
                            field.addEventListener('click', function(e) {
                                e.preventDefault();
                                return false;
                            });
                        }
                    }
                });
            };
            
            // Re-apply protection when edit modal is shown
            const editUserModal = document.getElementById('editUserModal');
            if (editUserModal) {
                editUserModal.addEventListener('shown.bs.modal', protectArchivedFields);
                
                // Reset form and restore fields when modal is hidden
                editUserModal.addEventListener('hidden.bs.modal', function() {
                    const form = document.getElementById('editUserForm');
                    if (form) {
                        form.reset();
                        // Restore all field names and enable states
                        const allFields = form.querySelectorAll('input, select, textarea');
                        allFields.forEach(field => {
                            if (field.hasAttribute('data-original-name')) {
                                field.setAttribute('name', field.getAttribute('data-original-name'));
                                field.removeAttribute('data-original-name');
                            }
                            field.disabled = false;
                            field.readOnly = false;
                            field.style.backgroundColor = '';
                            field.style.cursor = '';
                        });
                        // Hide info message if it exists
                        const infoMsg = document.getElementById('edit_archived_info');
                        if (infoMsg) {
                            infoMsg.style.display = 'none';
                        }
                    }
                });
            }
        })();

        // Client-side validation to ensure role-specific fields are filled before submit
        (function() {
            const addForm = document.getElementById('addUserForm');
            if (!addForm) return;
            addForm.addEventListener('submit', function(e) {
                // If evaluation is ongoing the server-side code blocks submission separately
                const roleEl = document.getElementById('selectedRole');
                const role = roleEl ? roleEl.value : '';
                
                if (role === 'student') {
                    const strand = (document.getElementById('add_strand') || {}).value || '';
                    const year = (document.getElementById('add_year_level') || {}).value || '';
                    if (!strand.trim() || !year.trim()) {
                        e.preventDefault();
                        e.stopPropagation();
                        showModernAlert('warning', 'Validation Required', 'Please select both Strand and Year Level for students before submitting.');
                        return false;
                    }
                } else if (role === 'teacher') {
                    const strand = (document.getElementById('add_strand_teacher') || {}).value || '';
                    if (!strand.trim()) {
                        e.preventDefault();
                        e.stopPropagation();
                        showModernAlert('warning', 'Validation Required', 'Please select Strand for teachers before submitting.');
                        return false;
                    }
                }
                return true;
            });
        })();

        // Block modals from opening during ongoing evaluation
        <?php if ($is_evaluation_ongoing): ?>
        document.addEventListener('DOMContentLoaded', function() {
            // Prevent Bootstrap modals from opening
            const modals = ['selectRoleModal', 'addUserModal', 'editUserModal'];
            modals.forEach(function(modalId) {
                const modalElement = document.getElementById(modalId);
                if (modalElement) {
                    modalElement.addEventListener('show.bs.modal', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        showModernAlert('warning', 'Action Disabled', 'This action is disabled during an ongoing evaluation period.');
                        return false;
                    }, true);
                }
            });
        });
        <?php endif; ?>

        // Prevent form submission during ongoing evaluation
        <?php if ($is_evaluation_ongoing): ?>
        document.getElementById('addUserForm').addEventListener('submit', function(e) {
            e.preventDefault();
            e.stopPropagation();
            showModernAlert('warning', 'Action Blocked', 'Cannot add users during an ongoing evaluation period.');
            return false;
        });

        document.getElementById('editUserForm').addEventListener('submit', function(e) {
            e.preventDefault();
            e.stopPropagation();
            showModernAlert('warning', 'Action Blocked', 'Cannot edit users during an ongoing evaluation period.');
            return false;
        });
        <?php endif; ?>
        
    </script>
</body>
</html>
