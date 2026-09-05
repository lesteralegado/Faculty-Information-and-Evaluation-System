<?php
/**
 * Shared analytics filters (School Year + Semester)
 * - Keeps filters synchronized via GET + SESSION
 * - Normalizes school year dashes and semester formats
 * - Generates safe SQL conditions for normalized evaluation queries
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function analytics_normalize_school_year(?string $sy): string {
    $sy = trim((string)$sy);
    return str_replace(["–", "—"], "-", $sy);
}

/**
 * Accepts: "all", "1", "2", "1st", "2nd", "1st Semester", "2nd Semester"
 * Returns: "all" | 1 | 2
 */
function analytics_normalize_semester($sem) {
    if ($sem === null) return 'all';
    $sem = trim((string)$sem);
    if ($sem === '' || strtolower($sem) === 'all') return 'all';

    $semLower = strtolower($sem);
    if ($semLower === '1' || $semLower === '1st' || $semLower === '1st semester') return 1;
    if ($semLower === '2' || $semLower === '2nd' || $semLower === '2nd semester') return 2;

    // If DB stores numeric-like string
    if (ctype_digit($semLower)) {
        $n = (int)$semLower;
        if ($n === 1 || $n === 2) return $n;
    }
    return 'all';
}

/**
 * Returns distinct school years present in evaluations (normalized).
 * Only returns years that have actual evaluation data.
 * @return string[]
 */
function analytics_get_available_years(mysqli $conn): array {
    $years = [];
    
    // Get years from evaluations that have data
    $sql = "SELECT DISTINCT REPLACE(REPLACE(school_year, '–', '-'), '—', '-') AS school_year
            FROM evaluations
            WHERE school_year IS NOT NULL AND TRIM(school_year) <> ''
            ORDER BY school_year DESC";
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $years[] = $row['school_year'];
        }
    }
    
    // Remove duplicates, normalize spacing/dashes, and sort descending
    $years = array_unique(array_map(function ($year) {
        $year = trim((string)$year);
        return str_replace(["–", "—"], "-", $year);
    }, $years));
    rsort($years);
    
    return $years;
}

/**
 * Returns distinct semesters present in evaluations.
 * Always returns [1, 2] if any evaluation data exists.
 * @return int[]
 */
function analytics_get_available_semesters(mysqli $conn): array {
    // Check if we have any evaluation data
    $checkSql = "SELECT 1 FROM evaluations LIMIT 1";
    $checkRes = $conn->query($checkSql);
    
    // If no evaluations exist, return empty
    if (!$checkRes || $checkRes->num_rows === 0) {
        return [];
    }
    
    // Get distinct semesters from database (including NULL treated as 1)
    $sql = "SELECT DISTINCT COALESCE(semester, 1) as semester
            FROM evaluations
            WHERE school_year IS NOT NULL 
            AND TRIM(school_year) <> ''
            ORDER BY semester ASC";
    
    $res = $conn->query($sql);
    $semesters = [];
    
    if ($res && $res->num_rows > 0) {
        while ($row = $res->fetch_assoc()) {
            $sem = (int)$row['semester'];
            if (($sem === 1 || $sem === 2) && !in_array($sem, $semesters, true)) {
                $semesters[] = $sem;
            }
        }
    }
    
    // If we found semesters, return them; otherwise default to both
    if (empty($semesters)) {
        $semesters = [1, 2];
    }
    
    sort($semesters);
    return $semesters;
}

/**
 * Attempts to read the current school year & semester from the existing table.
 * Returns: ['school_year' => string|null, 'semester' => 1|2|null]
 */
function analytics_get_current_sy_sem(mysqli $conn): array {
    $out = ['school_year' => null, 'semester' => null];
    $stmt = $conn->prepare("SELECT school_year, semester FROM currentschoolyearandsemester LIMIT 1");
    if ($stmt && $stmt->execute()) {
        $res = $stmt->get_result();
        if ($res && $res->num_rows > 0) {
            $row = $res->fetch_assoc();
            $out['school_year'] = analytics_normalize_school_year($row['school_year'] ?? null);
            $out['semester'] = analytics_normalize_semester($row['semester'] ?? null);
            if ($out['semester'] === 'all') $out['semester'] = null;
        }
        $stmt->close();
    }
    return $out;
}

/**
 * Resolve filter state with synchronization:
 * - GET overrides SESSION
 * - SESSION persists across pages
 *
 * Supported GET params:
 * - year: specific "YYYY-YYYY" OR "all"
 * - semester: "all" | 1 | 2 | "1st" | "2nd" | "1st Semester" | "2nd Semester"
 */
function analytics_get_filter_state(mysqli $conn): array {
    $availableYears = analytics_get_available_years($conn);
    $availableSemesters = analytics_get_available_semesters($conn);
    $current = analytics_get_current_sy_sem($conn);

    $session = $_SESSION['analytics_filters'] ?? [];
    // Default to 'all' instead of specific year
    $defaultYear = 'all';
    $defaultSemester = 'all';

    $year = $_GET['year'] ?? ($session['year'] ?? $defaultYear);
    $semester = $_GET['semester'] ?? ($session['semester'] ?? $defaultSemester);

    $year = strtolower((string)$year) === 'all' ? 'all' : analytics_normalize_school_year($year);
    $semester = analytics_normalize_semester($semester);

    // If the exact year+semester has no rows, relax semester to "all"
    // so historical year views still show available data.
    if ($year !== 'all' && $semester !== 'all') {
        $yearEsc = $conn->real_escape_string($year);
        $semInt = (int)$semester;
        $hasDataSql = "SELECT 1
                       FROM evaluations e
                       WHERE REPLACE(REPLACE(e.school_year, '–', '-'), '—', '-') = '$yearEsc'
                         AND e.semester = $semInt
                       LIMIT 1";
        $hasDataRes = $conn->query($hasDataSql);
        if (!$hasDataRes || $hasDataRes->num_rows === 0) {
            $semester = 'all';
        }
    }

    // If user selected a year not in list, keep it but still normalize.
    $_SESSION['analytics_filters'] = [
        'year' => $year,
        'semester' => $semester
    ];

    return [
        'available_years' => $availableYears,
        'available_semesters' => $availableSemesters,
        'selected_year' => $year,
        'selected_semester' => $semester,
    ];
}

/**
 * Build an SQL condition for evaluations with alias (default: e).
 * Returns: ['sql' => string, 'label_year' => string, 'label_semester' => string]
 */
function analytics_build_er_condition(mysqli $conn, string $selectedYear, $selectedSemester, string $alias = 'e'): array {
    $conditions = [];

    $labelYear = ($selectedYear === 'all') ? 'All School Years' : $selectedYear;
    $labelSemester = ($selectedSemester === 'all') ? 'All Semesters' : (($selectedSemester == 1) ? '1st Semester' : '2nd Semester');

    if ($selectedYear !== 'all') {
        $syEsc = $conn->real_escape_string($selectedYear);
        $conditions[] = "REPLACE(REPLACE($alias.school_year, '–', '-'), '—', '-') = '$syEsc'";
    }

    if ($selectedSemester !== 'all') {
        $semInt = (int)$selectedSemester;
        $conditions[] = "$alias.semester = $semInt";
    }

    $sql = (count($conditions) > 0) ? implode(' AND ', $conditions) : '1=1';
    return ['sql' => $sql, 'label_year' => $labelYear, 'label_semester' => $labelSemester];
}

/**
 * Build a query string preserving current filters + extra params.
 */
function analytics_build_query(array $filterState, array $extra = []): string {
    $params = array_merge([
        'year' => $filterState['selected_year'],
        'semester' => $filterState['selected_semester'],
    ], $extra);
    return http_build_query($params);
}

