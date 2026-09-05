<?php
/**
 * Fix Database Schema for Evaluation Tables
 * This script adds AUTO_INCREMENT to evaluation_categories and evaluation_questions tables
 */

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "capstone";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set charset
$conn->set_charset("utf8mb4");

echo "=== Database Schema Fix ===\n\n";

// 1. Add AUTO_INCREMENT to evaluation_categories
echo "1. Fixing evaluation_categories table...\n";
$sql1 = "ALTER TABLE evaluation_categories MODIFY category_id INT(11) NOT NULL AUTO_INCREMENT";
if ($conn->query($sql1) === TRUE) {
    echo "   ✓ Added AUTO_INCREMENT to category_id\n";
} else {
    echo "   ✗ Error: " . $conn->error . "\n";
}

// 2. Add AUTO_INCREMENT to evaluation_questions
echo "2. Fixing evaluation_questions table...\n";
$sql2 = "ALTER TABLE evaluation_questions MODIFY question_id INT(11) NOT NULL AUTO_INCREMENT";
if ($conn->query($sql2) === TRUE) {
    echo "   ✓ Added AUTO_INCREMENT to question_id\n";
} else {
    echo "   ✗ Error: " . $conn->error . "\n";
}

// 3. Set starting AUTO_INCREMENT value for evaluation_categories
echo "3. Setting AUTO_INCREMENT starting values...\n";
$sql3 = "ALTER TABLE evaluation_categories AUTO_INCREMENT = 1";
if ($conn->query($sql3) === TRUE) {
    echo "   ✓ Set evaluation_categories AUTO_INCREMENT to 1\n";
} else {
    echo "   ✗ Error: " . $conn->error . "\n";
}

// 4. Set starting AUTO_INCREMENT value for evaluation_questions
$sql4 = "ALTER TABLE evaluation_questions AUTO_INCREMENT = 1";
if ($conn->query($sql4) === TRUE) {
    echo "   ✓ Set evaluation_questions AUTO_INCREMENT to 1\n";
} else {
    echo "   ✗ Error: " . $conn->error . "\n";
}

// 5. Verify the table structure
echo "\n=== Verification ===\n\n";

// Check evaluation_categories
echo "evaluation_categories structure:\n";
$result = $conn->query("SHOW CREATE TABLE evaluation_categories\G");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        echo $row['Table'] . "\n";
        echo "Create Table: " . $row['Create Table'] . "\n";
    }
}

echo "\n";

// Check evaluation_questions
echo "evaluation_questions structure:\n";
$result = $conn->query("SHOW CREATE TABLE evaluation_questions\G");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        echo $row['Table'] . "\n";
        echo "Create Table: " . $row['Create Table'] . "\n";
    }
}

// Check current data
echo "\n=== Current Data Count ===\n";
$cat_result = $conn->query("SELECT COUNT(*) as count FROM evaluation_categories");
$cat_row = $cat_result->fetch_assoc();
echo "Categories: " . $cat_row['count'] . "\n";

$q_result = $conn->query("SELECT COUNT(*) as count FROM evaluation_questions");
$q_row = $q_result->fetch_assoc();
echo "Questions: " . $q_row['count'] . "\n";

echo "\n✓ Database schema has been fixed!\n";
echo "You can now import Excel files without errors.\n";

$conn->close();
?>
