<?php
session_start();
include '../includes/db_connection.php';

if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

// Get current school year + semester
$currentQuery = $conn->query("SELECT * FROM currentschoolyearandsemester LIMIT 1");
$current = $currentQuery->fetch_assoc();

$currentSY = $current['school_year'];
$currentSem = intval($current['semester']);

// Function to increment school year
function incrementSchoolYear($sy) {
    $years = explode('-', $sy);
    $start = intval($years[0]) + 1;
    $end = intval($years[1]) + 1;
    return $start . "-" . $end;
}

// CASE 1: 1st Semester → 2nd Semester
if ($currentSem == 1) {

    // Update all active sections
    $conn->query("
        UPDATE sections 
        SET semester = 2
        WHERE status = 'active'
    ");

    // Update current semester
    $conn->query("
        UPDATE currentschoolyearandsemester
        SET semester = 2
    ");

    $_SESSION['success'] = "Semester advanced to 2nd Semester successfully!";

}

// CASE 2: 2nd Semester → New School Year
else {

    // 1️⃣ Grade 11 → Grade 12
    $conn->query("
        UPDATE sections
        SET year_level = 12,
            semester = 1
        WHERE year_level = 11
        AND status = 'active'
    ");

    // ALSO UPDATE STUDENTS Grade 11 → Grade 12
    $conn->query("
        UPDATE students
        SET year_level = 12
            WHERE year_level = 11
    ");

    // 2️⃣ Grade 12 (already 12) → Archive (Graduated)
    $conn->query("
        UPDATE sections
        SET status = 'archived'
        WHERE year_level = 12
        AND semester = 2
        AND status = 'active'
    ");

        // Graduate students (optional)
    $conn->query("
        UPDATE students
        SET status = 'graduated'
        WHERE year_level = 12
    ");

    // 3️⃣ Update School Year
    $newSY = incrementSchoolYear($currentSY);

    $conn->query("
        UPDATE currentschoolyearandsemester
        SET school_year = '$newSY',
            semester = 1
    ");

    $_SESSION['success'] = "New School Year started. Sections advanced successfully!";
}

header("Location: section_management.php");
exit();
?>
