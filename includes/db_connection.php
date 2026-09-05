<?php
// Database connection configuration
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "capstone";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
// Set charset to utf8
$conn->set_charset("utf8");

// Ensure consistent timezone for all date/time operations
if (!ini_get('date.timezone')) {
    date_default_timezone_set('Asia/Manila');
}
?> 