<?php
/**
 * Migration: Add school_year column to subjects table
 * This script adds tracking of school year for each subject record
 * Allows proper archival and retrieval by school year
 */

require_once __DIR__ . '/../includes/db_connection.php';

// Check if migration already applied
$check_column = $conn->query("SHOW COLUMNS FROM subjects LIKE 'school_year'");
if ($check_column && $check_column->num_rows > 0) {
    die("✓ Migration already applied. The 'school_year' column already exists in the subjects table.\n");
}

echo "Starting migration: Add school_year column to subjects table...\n";

try {
    // Start transaction
    $conn->begin_transaction();

    // Step 1: Add school_year column
    echo "[1/4] Adding school_year column...\n";
    $conn->query("ALTER TABLE subjects ADD COLUMN school_year VARCHAR(50) DEFAULT NULL AFTER semester");
    
    // Step 2: Add index for efficient filtering
    echo "[2/4] Adding index on (school_year, status)...\n";
    $conn->query("ALTER TABLE subjects ADD INDEX idx_school_year_status (school_year, status)");
    
    // Step 3: Get current school year
    echo "[3/4] Backfilling active subjects...\n";
    $ctx_result = $conn->query("SELECT school_year FROM currentschoolyearandsemester LIMIT 1");
    $current_sy = '';
    if ($ctx_result && $row = $ctx_result->fetch_assoc()) {
        $current_sy = $row['school_year'];
    }
    
    if ($current_sy === '') {
        throw new Exception("Current school year not found in currentschoolyearandsemester table. Please configure the school year first.");
    }
    
    // Backfill active subjects
    $stmt = $conn->prepare("UPDATE subjects SET school_year = ? WHERE status = 'active' AND school_year IS NULL");
    $stmt->bind_param("s", $current_sy);
    $stmt->execute();
    $active_updated = $conn->affected_rows;
    echo "   ✓ Updated $active_updated active subject records\n";
    $stmt->close();
    
    // Step 4: Backfill archived subjects
    echo "[4/4] Backfilling archived subjects...\n";
    $stmt = $conn->prepare(
        "UPDATE subjects SET school_year = COALESCE(
            (SELECT MAX(school_year) FROM currentschoolyearandsemester),
            ?
        ) WHERE status = 'archived' AND school_year IS NULL"
    );
    $stmt->bind_param("s", $current_sy);
    $stmt->execute();
    $archived_updated = $conn->affected_rows;
    echo "   ✓ Updated $archived_updated archived subject records\n";
    $stmt->close();

    // Commit transaction
    $conn->commit();
    
    echo "\n✓ Migration completed successfully!\n";
    echo "  - Active subjects: $active_updated\n";
    echo "  - Archived subjects: $archived_updated\n";
    echo "\nThe school_year column is now available for imports and exports.\n";
    echo "Next step: Update your import handler to require Semester and set school_year = '$current_sy'\n";

} catch (Exception $e) {
    // Rollback on error
    $conn->rollback();
    die("✗ Migration failed: " . $e->getMessage() . "\n");
}

$conn->close();
