<?php
session_start();
if (!isset($_SESSION['username']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../index.php');
    exit();
}
require_once __DIR__ . '/../includes/db_connection.php';
require_once __DIR__ . '/../includes/semester_rollover.php';

try {
    app_close_semester($conn);
    $_SESSION['success'] = 'The semester was closed and the current term was advanced successfully.';
} catch (Throwable $error) {
    error_log('Semester rollover failed: ' . $error->getMessage());
    $_SESSION['error'] = 'The semester could not be closed. No term changes were saved. Please contact the administrator.';
}
header('Location: section_management.php');
exit();
