<?php
/**
 * API Endpoint: Get Subjects Data
 * 
 * Purpose: Returns filtered subjects based on year_level and strand
 * Also provides a complete mapping of all subjects with their metadata
 * 
 * Usage:
 *   GET /api/get_subjects_data.php?year_level=11&strand=STEM
 *   GET /api/get_subjects_data.php (returns all subjects)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Cache-Control: max-age=300'); // Cache for 5 minutes

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    require_once __DIR__ . '/../includes/db_connection.php';

    $year_level = isset($_GET['year_level']) ? trim($_GET['year_level']) : '';
    $strand = isset($_GET['strand']) ? trim($_GET['strand']) : '';

    // Build base query: Get all active subjects
    $query = "SELECT DISTINCT s.subject_id, s.subject_name, s.year_level, s.strand, s.status
              FROM subjects s
              WHERE s.status = 'active'";

    $params = [];
    $param_types = '';

    if (!empty($year_level)) {
        $query .= " AND s.year_level = ?";
        $params[] = $year_level;
        $param_types .= 's';
    }

    if (!empty($strand)) {
        $query .= " AND s.strand = ?";
        $params[] = $strand;
        $param_types .= 's';
    }

    $query .= " ORDER BY s.subject_name ASC";

    $stmt = $conn->prepare($query);
    
    if (!empty($params)) {
        $stmt->bind_param($param_types, ...$params);
    }

    if (!$stmt->execute()) {
        throw new Exception("Query execution failed: " . $stmt->error);
    }

    $result = $stmt->get_result();
    $subjects = [];

    while ($row = $result->fetch_assoc()) {
        $subjects[] = [
            'subject_id' => (int)$row['subject_id'],
            'subject_name' => $row['subject_name'],
            'year_level' => $row['year_level'],
            'strand' => $row['strand']
        ];
    }

    $stmt->close();

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'count' => count($subjects),
        'data' => $subjects
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

$conn->close();
?>
