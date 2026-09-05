-- Migration: Add school_year column to subjects table
-- Purpose: Track which school year each subject belongs to for proper archival and retrieval
-- Created: May 3, 2026

-- Add school_year column if it doesn't exist
ALTER TABLE subjects 
ADD COLUMN IF NOT EXISTS school_year VARCHAR(50) DEFAULT NULL AFTER semester;

-- Add index for efficient filtering by school_year
ALTER TABLE subjects 
ADD INDEX IF NOT EXISTS idx_school_year_status (school_year, status);

-- Backfill existing active subjects with the current school year from currentschoolyearandsemester
UPDATE subjects s
SET s.school_year = (
    SELECT school_year FROM currentschoolyearandsemester LIMIT 1
)
WHERE s.status = 'active' AND s.school_year IS NULL;

-- Backfill archived subjects with their context school year if available
-- (This query assumes you want to track which school year archived subjects came from)
-- If no context exists, they remain NULL until explicitly set
UPDATE subjects s
SET s.school_year = COALESCE(
    (SELECT MAX(school_year) FROM currentschoolyearandsemester),
    '2025-2026'  -- Fallback to a default if needed
)
WHERE s.status = 'archived' AND s.school_year IS NULL;

-- Optional: Make school_year NOT NULL after data migration
-- Uncomment after verifying backfill worked correctly
-- ALTER TABLE subjects MODIFY COLUMN school_year VARCHAR(50) NOT NULL;
