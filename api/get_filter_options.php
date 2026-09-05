<?php
/**
 * JSON helper for subject/teacher filter options (analytics scope).
 * Supports bi-directional filtering for analytics_advanced.php
 * 
 * Query params:
 * - type: 'teachers' | 'subjects' | 'subjects_for_year' | 'teachers_for_year'
 * - subject_id: (int) when type=teachers
 * - teacher_id: (int) when type=subjects
 * - school_year: (string) 'all' or specific year (2024-2025)
 * - semester: (int|string) 1, 2, or 'all'
 */
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: max-age=0, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if (!isset($_SESSION['username']) || !isset($_SESSION['role']) || $_SESSION['role'] === 'teacher') {
    http_response_code(403);
    echo json_encode([]);
    exit();
}

try {
    require_once __DIR__ . '/../includes/db_connection.php';

    $type = isset($_GET['type']) ? trim((string)$_GET['type']) : '';
    $subject_id = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : 0;
    $teacher_id = isset($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : 0;
    $school_year = isset($_GET['school_year']) ? trim((string)$_GET['school_year']) : '';
    $semester = isset($_GET['semester']) ? trim((string)$_GET['semester']) : '';

    $result = [];

    // Normalize school year (convert dashes)
    if ($school_year !== '' && strtolower($school_year) !== 'all') {
        $school_year = str_replace(array("–", "—"), "-", $school_year);
    }

    // Normalize semester
    $semester_num = null;
    if ($semester !== '' && strtolower($semester) !== 'all') {
        $semester_lc = strtolower($semester);
        if (ctype_digit($semester_lc)) {
            $semester_num = (int)$semester_lc;
        } elseif (stripos($semester_lc, '2') !== false) {
            $semester_num = 2;
        } else {
            $semester_num = 1;
        }
    }

    // Build WHERE clause based on year and semester
    $where_parts = [];
    $params = [];
    $types = '';

    if ($school_year !== '' && strtolower($school_year) !== 'all') {
        $where_parts[] = "REPLACE(REPLACE(e.school_year, '–', '-'), '—', '-') = ?";
        $params[] = $school_year;
        $types .= 's';
    }

    if ($semester_num !== null) {
        $where_parts[] = "e.semester = ?";
        $params[] = $semester_num;
        $types .= 'i';
    }

    $where_sql = !empty($where_parts) ? ' AND ' . implode(' AND ', $where_parts) : '';

    // Case 1: Get teachers for a selected subject (with year/semester filters)
    if ($type === 'teachers' && $subject_id > 0) {
        $sql = "SELECT DISTINCT t.teacher_id, 
                       COALESCE(NULLIF(TRIM(CONCAT_WS(' ', NULLIF(u.first_name, ''), NULLIF(u.last_name, ''))), ''), 'TBD') AS name
                FROM evaluations e
                INNER JOIN teachers t ON e.teacher_id = t.teacher_id
                INNER JOIN users u ON t.user_id = u.user_id
                WHERE e.subject_id = ? $where_sql
                ORDER BY u.first_name, u.last_name";
        
        $stmt = $conn->prepare($sql);
        $bind_params = array_merge(array($subject_id), $params);
        $bind_types = 'i' . $types;
        $stmt->bind_param($bind_types, ...$bind_params);
        $stmt->execute();
        $rs = $stmt->get_result();
        while ($row = $rs->fetch_assoc()) {
            $result[] = array('id' => (int)$row['teacher_id'], 'name' => $row['name']);
        }
        $stmt->close();
    }
    // Case 2: Get subjects for a selected teacher (with year/semester filters)
    elseif ($type === 'subjects' && $teacher_id > 0) {
        $sql = "SELECT DISTINCT s.subject_id, s.subject_name AS name
                FROM evaluations e
                INNER JOIN subjects s ON e.subject_id = s.subject_id
                WHERE e.teacher_id = ? $where_sql
                ORDER BY s.subject_name";
        
        $stmt = $conn->prepare($sql);
        $bind_params = array_merge(array($teacher_id), $params);
        $bind_types = 'i' . $types;
        $stmt->bind_param($bind_types, ...$bind_params);
        $stmt->execute();
        $rs = $stmt->get_result();
        while ($row = $rs->fetch_assoc()) {
            $result[] = array('id' => (int)$row['subject_id'], 'name' => $row['name']);
        }
        $stmt->close();
    }
    // Case 3: Get all subjects for a year/semester (no specific teacher)
    elseif ($type === 'subjects_for_year') {
        $sql = "SELECT DISTINCT s.subject_id, s.subject_name AS name
                FROM evaluations e
                INNER JOIN subjects s ON e.subject_id = s.subject_id
                WHERE 1=1 $where_sql
                ORDER BY s.subject_name";
        
        if ($types) {
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $rs = $stmt->get_result();
        } else {
            $rs = $conn->query($sql);
        }
        
        while ($row = $rs->fetch_assoc()) {
            $result[] = array('id' => (int)$row['subject_id'], 'name' => $row['name']);
        }
        if ($types) {
            $stmt->close();
        }
    }
    // Case 4: Get all teachers for a year/semester (no specific subject)
    elseif ($type === 'teachers_for_year') {
        $sql = "SELECT DISTINCT t.teacher_id, 
                       COALESCE(NULLIF(TRIM(CONCAT_WS(' ', NULLIF(u.first_name, ''), NULLIF(u.last_name, ''))), ''), 'TBD') AS name
                FROM evaluations e
                INNER JOIN teachers t ON e.teacher_id = t.teacher_id
                INNER JOIN users u ON t.user_id = u.user_id
                WHERE 1=1 $where_sql
                ORDER BY u.first_name, u.last_name";
        
        if ($types) {
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $rs = $stmt->get_result();
        } else {
            $rs = $conn->query($sql);
        }
        
        while ($row = $rs->fetch_assoc()) {
            $result[] = array('id' => (int)$row['teacher_id'], 'name' => $row['name']);
        }
        if ($types) {
            $stmt->close();
        }
    }

    http_response_code(200);
    echo json_encode($result);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(array('error' => 'Internal server error'));
}
