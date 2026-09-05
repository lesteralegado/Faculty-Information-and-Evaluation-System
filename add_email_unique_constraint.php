<?php
/**
 * Database Migration: Add Unique Email Constraint to Users Table
 * 
 * This script adds a unique constraint to the email column in the users table
 * to prevent duplicate email addresses in the system.
 * 
 * Run this script once to update your database schema.
 */

// Include database connection
include 'includes/db_connection.php';

echo "Starting database migration...<br><br>";

try {
    // Check if the email column exists and what constraints are on it
    $check_constraints = $conn->query("
        SELECT CONSTRAINT_NAME 
        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
        WHERE TABLE_NAME = 'users' 
        AND COLUMN_NAME = 'email' 
        AND CONSTRAINT_NAME != 'PRIMARY'
    ");
    
    $constraints = [];
    if ($check_constraints) {
        while ($row = $check_constraints->fetch_assoc()) {
            $constraints[] = $row['CONSTRAINT_NAME'];
        }
    }
    
    // If unique constraint already exists, skip
    if (in_array('email', $constraints) || in_array('unique_email', $constraints)) {
        echo "<div style='padding: 10px; background-color: #d4edda; color: #155724; border-radius: 5px;'>";
        echo "<strong>✓ Email unique constraint already exists.</strong><br>";
        echo "No action needed.";
        echo "</div>";
    } else {
        // Drop any existing non-unique index on email if it exists
        $drop_index = "ALTER TABLE users DROP INDEX IF EXISTS email";
        if ($conn->query($drop_index)) {
            echo "<div style='padding: 10px; background-color: #cfe2ff; color: #084298; border-radius: 5px; margin-bottom: 10px;'>";
            echo "✓ Removed existing non-unique index on email column (if present)<br>";
            echo "</div>";
        }
        
        // Add unique constraint to email column
        $add_constraint = "ALTER TABLE users ADD UNIQUE KEY unique_email (email)";
        
        if ($conn->query($add_constraint)) {
            echo "<div style='padding: 10px; background-color: #d4edda; color: #155724; border-radius: 5px; margin-bottom: 10px;'>";
            echo "<strong>✓ Successfully added unique email constraint to users table!</strong><br>";
            echo "Email addresses will now be enforced as unique at the database level.";
            echo "</div>";
        } else {
            echo "<div style='padding: 10px; background-color: #f8d7da; color: #842029; border-radius: 5px; margin-bottom: 10px;'>";
            echo "<strong>✗ Error adding unique constraint:</strong><br>";
            echo $conn->error;
            echo "</div>";
        }
    }
    
    // Verify the constraint was added
    $verify = $conn->query("
        SELECT CONSTRAINT_NAME 
        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
        WHERE TABLE_NAME = 'users' 
        AND COLUMN_NAME = 'email' 
        AND CONSTRAINT_NAME LIKE '%unique%' OR CONSTRAINT_NAME = 'email'
    ");
    
    if ($verify && $verify->num_rows > 0) {
        echo "<div style='padding: 10px; background-color: #cfe2ff; color: #084298; border-radius: 5px;'>";
        echo "<strong>✓ Verification:</strong> Unique constraint is active on email column.<br>";
        echo "<code>CONSTRAINT_NAME: " . ($row = $verify->fetch_assoc()) ? htmlspecialchars($row['CONSTRAINT_NAME']) : 'unique_email' . "</code>";
        echo "</div>";
    }
    
} catch (Exception $e) {
    echo "<div style='padding: 10px; background-color: #f8d7da; color: #842029; border-radius: 5px;'>";
    echo "<strong>✗ Exception:</strong> " . $e->getMessage();
    echo "</div>";
}

$conn->close();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Database Migration - Email Unique Constraint</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            padding: 20px;
            max-width: 800px;
            margin: 0 auto;
        }
        h1 {
            color: #333;
        }
        .info {
            background-color: #e7f3ff;
            padding: 15px;
            border-left: 4px solid #2196F3;
            margin-top: 20px;
            border-radius: 4px;
        }
        code {
            background-color: #f4f4f4;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
        }
    </style>
</head>
<body>
    <h1>Database Migration Script</h1>
    <p>This script ensures that the email column in the users table has a unique constraint.</p>
    
    <hr>

    <div class="info">
        <strong>What this does:</strong>
        <ul>
            <li>Adds a UNIQUE constraint to the email column in the users table</li>
            <li>Prevents duplicate email addresses at the database level</li>
            <li>Ensures data integrity and consistency</li>
        </ul>
    </div>

    <div class="info">
        <strong>Note:</strong>
        If you have existing duplicate emails in your users table, this migration will fail.
        In that case, you'll need to manually clean up duplicates before running this script.
    </div>

    <hr>
    
    <p style="color: #666; font-size: 0.9em;">
        After running this script once, you can delete this file.
    </p>
</body>
</html>
