<?php
/**
 * QUICK FIX: Activate Questions & Categories for Current School Year/Semester
 * This script diagnoses and fixes the is_active flag issues
 */

include 'includes/db_connection.php';

echo "<pre>";
echo "====================================\n";
echo "EVALUATION QUESTIONS DISPLAY FIX\n";
echo "====================================\n\n";

// Get current school year and semester
$current = $conn->query("SELECT school_year, semester FROM currentschoolyearandsemester LIMIT 1");
$current_sy = '2025-2026';
$current_sem = 1;
if ($current && $current->num_rows > 0) {
    $row = $current->fetch_assoc();
    $current_sy = str_replace(["–", "—"], "-", $row['school_year']);
    $current_sem = (int)$row['semester'];
}

echo "✓ Current School Year: $current_sy\n";
echo "✓ Current Semester: $current_sem\n\n";

// 1. Check status before fix
echo "BEFORE FIX:\n";
echo "-----------------------------------\n";

$check = $conn->query("
    SELECT 'Categories' as type, school_year, semester, is_active, COUNT(*) as count
    FROM evaluation_categories
    GROUP BY school_year, semester, is_active
    UNION ALL
    SELECT 'Questions' as type, school_year, semester, is_active, COUNT(*) as count
    FROM evaluation_questions
    GROUP BY school_year, semester, is_active
    ORDER BY school_year DESC, semester DESC
");

while ($row = $check->fetch_assoc()) {
    echo $row['type'] . " | " . $row['school_year'] . " | Sem " . $row['semester'] . " | Active=" . $row['is_active'] . " | Count=" . $row['count'] . "\n";
}

echo "\n";

// 2. Apply fixes
echo "APPLYING FIXES:\n";
echo "-----------------------------------\n";

// Fix 1: Activate current school year/semester
$fix1_cats = $conn->query("
    UPDATE evaluation_categories 
    SET is_active = 1 
    WHERE REPLACE(REPLACE(school_year, '–', '-'), '—', '-') = '$current_sy'
    AND semester = $current_sem
");
echo "✓ Fixed Categories - Activated $current_sy / Sem $current_sem\n";

$fix1_qs = $conn->query("
    UPDATE evaluation_questions 
    SET is_active = 1 
    WHERE REPLACE(REPLACE(school_year, '–', '-'), '—', '-') = '$current_sy'
    AND semester = $current_sem
");
echo "✓ Fixed Questions - Activated $current_sy / Sem $current_sem\n";

// Fix 2: Deactivate all other school years/semesters
$fix2_cats = $conn->query("
    UPDATE evaluation_categories 
    SET is_active = 0 
    WHERE NOT (REPLACE(REPLACE(school_year, '–', '-'), '—', '-') = '$current_sy' AND semester = $current_sem)
");
echo "✓ Deactivated Categories - All other years/semesters\n";

$fix2_qs = $conn->query("
    UPDATE evaluation_questions 
    SET is_active = 0 
    WHERE NOT (REPLACE(REPLACE(school_year, '–', '-'), '—', '-') = '$current_sy' AND semester = $current_sem)
");
echo "✓ Deactivated Questions - All other years/semesters\n";

echo "\n";

// 3. Verify fix
echo "AFTER FIX:\n";
echo "-----------------------------------\n";

$verify = $conn->query("
    SELECT 'Categories' as type, school_year, semester, is_active, COUNT(*) as count
    FROM evaluation_categories
    WHERE REPLACE(REPLACE(school_year, '–', '-'), '—', '-') = '$current_sy'
    AND semester = $current_sem
    GROUP BY is_active
    UNION ALL
    SELECT 'Questions' as type, school_year, semester, is_active, COUNT(*) as count
    FROM evaluation_questions
    WHERE REPLACE(REPLACE(school_year, '–', '-'), '—', '-') = '$current_sy'
    AND semester = $current_sem
    GROUP BY is_active
");

while ($row = $verify->fetch_assoc()) {
    echo $row['type'] . " | " . $row['school_year'] . " | Sem " . $row['semester'] . " | Active=" . $row['is_active'] . " | Count=" . $row['count'] . "\n";
}

echo "\n";

// 4. Test the JOIN query
echo "TESTING QUESTION DISPLAY QUERY:\n";
echo "-----------------------------------\n";

$test = $conn->query("
    SELECT q.*, c.category_name, c.category_id
    FROM evaluation_questions q
    INNER JOIN evaluation_categories c 
      ON q.category_id = c.category_id 
      AND REPLACE(REPLACE(q.school_year, '–', '-'), '—', '-') = REPLACE(REPLACE(c.school_year, '–', '-'), '—', '-')
      AND q.semester = c.semester
    WHERE q.is_active = 1
      AND c.is_active = 1
      AND REPLACE(REPLACE(q.school_year, '–', '-'), '—', '-') = '$current_sy'
      AND q.semester = $current_sem
    ORDER BY c.category_id, q.question_id
    LIMIT 1
");

if ($test && $test->num_rows > 0) {
    $first = $test->fetch_assoc();
    echo "✓ Sample Question Found:\n";
    echo "  Category: " . $first['category_name'] . "\n";
    echo "  Question: " . substr($first['question'], 0, 60) . "...\n";
    echo "  Active: " . $first['is_active'] . "\n";
    
    // Count total
    $count = $conn->query("
        SELECT COUNT(*) as total
        FROM evaluation_questions q
        INNER JOIN evaluation_categories c 
          ON q.category_id = c.category_id 
          AND REPLACE(REPLACE(q.school_year, '–', '-'), '—', '-') = REPLACE(REPLACE(c.school_year, '–', '-'), '—', '-')
          AND q.semester = c.semester
        WHERE q.is_active = 1
          AND c.is_active = 1
          AND REPLACE(REPLACE(q.school_year, '–', '-'), '—', '-') = '$current_sy'
          AND q.semester = $current_sem
    ");
    $count_row = $count->fetch_assoc();
    echo "\n✓ Total Questions Available: " . $count_row['total'] . "\n";
} else {
    echo "✗ No questions found! Checking what exists...\n";
    $check_exists = $conn->query("
        SELECT COUNT(*) as count FROM evaluation_questions 
        WHERE REPLACE(REPLACE(school_year, '–', '-'), '—', '-') = '$current_sy'
        AND semester = $current_sem
    ");
    $ex = $check_exists->fetch_assoc();
    echo "  Questions in DB: " . $ex['count'] . "\n";
}

echo "\n";
echo "====================================\n";
echo "✓ FIX COMPLETE!\n";
echo "====================================\n";
echo "\nRefresh the Admin Evaluation Management page to see questions.\n";
echo "</pre>";

$conn->close();
?>
