<?php
require_once __DIR__ . '/error_handler.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Database connection configuration
$servername = getenv('CAPSTONE_DB_HOST') ?: 'localhost';
$username = getenv('CAPSTONE_DB_USER') ?: 'root';
$password = getenv('CAPSTONE_DB_PASSWORD') ?: '';
// InfinityFree assigns a prefixed database name. Set CAPSTONE_DB_NAME=capstone locally;
// use the assigned hosted name on InfinityFree.
$dbname = getenv('CAPSTONE_DB_NAME') ?: 'capstone';

// Reuse the connection; legacy pages may close it before including the navbar.
$connectionOpen = false;
if (isset($conn) && $conn instanceof mysqli) {
    try {
        $connectionOpen = $conn->thread_id > 0;
    } catch (Throwable $error) {
        $connectionOpen = false;
    }
}
if (!$connectionOpen) {
    $conn = new mysqli($servername, $username, $password, $dbname);
    $conn->set_charset("utf8mb4");
    // InfinityFree may reject legitimate reporting joins under its default MAX_JOIN_SIZE.
    // Allow the application’s explicitly scoped joins to run for this connection.
    $conn->query("SET SESSION SQL_BIG_SELECTS = 1");
}

// Ensure consistent timezone for all date/time operations
if (!ini_get('date.timezone')) {
    date_default_timezone_set('Asia/Manila');
}
