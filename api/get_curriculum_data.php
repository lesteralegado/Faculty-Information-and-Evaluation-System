<?php
/**
 * API Endpoint: Get Curriculum Data
 * 
 * Purpose: Provides curriculum information for integration with other modules
 * 
 * Usage:
 *   GET /capstone/api/get_curriculum_data.php?id=1
 *   GET /capstone/api/get_curriculum_data.php?subject_id=5
 *   GET /capstone/api/get_curriculum_data.php?status=active
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Cache-Control: max-age=300');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    require_once __DIR__ . '/../includes/db_connection.php';

    $curriculum_id = isset($_GET['id']) ? (int)$_GET['id'] : null;
    $subject_id = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : null;
    $status = isset($_GET['status']) ? trim($_GET['status']) : 'active';

    $result = [];

    // Fetch specific curriculum with all details
    if ($curriculum_id) {
        $stmt = $conn->prepare("
            SELECT c.*, s.subject_name, u.first_name, u.last_name
            FROM curricula c
            LEFT JOIN subjects s ON c.subject_id = s.subject_id
            LEFT JOIN users u ON c.created_by = u.user_id
            WHERE c.curriculum_id = ?
            LIMIT 1
        ");
        $stmt->bind_param("i", $curriculum_id);
        $stmt->execute();
        $curriculum = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($curriculum) {
            // Fetch related data
            $curriculum['learning_outcomes'] = $conn->query(
                "SELECT * FROM curriculum_learning_outcomes 
                 WHERE curriculum_id = $curriculum_id 
                 ORDER BY sequence_number ASC"
            )->fetch_all(MYSQLI_ASSOC);

            $curriculum['units'] = $conn->query(
                "SELECT * FROM curriculum_units 
                 WHERE curriculum_id = $curriculum_id 
                 ORDER BY sequence_number ASC"
            )->fetch_all(MYSQLI_ASSOC);

            $curriculum['resources'] = $conn->query(
                "SELECT * FROM curriculum_resources 
                 WHERE curriculum_id = $curriculum_id 
                 ORDER BY sequence_number ASC"
            )->fetch_all(MYSQLI_ASSOC);

            $curriculum['assessments'] = $conn->query(
                "SELECT * FROM curriculum_assessments 
                 WHERE curriculum_id = $curriculum_id 
                 ORDER BY sequence_number ASC"
            )->fetch_all(MYSQLI_ASSOC);

            $result = $curriculum;
        } else {
            http_response_code(404);
            $result = ['error' => 'Curriculum not found'];
        }
    }
    // Fetch curricula by subject
    elseif ($subject_id) {
        $query = "
            SELECT c.curriculum_id, c.curriculum_code, c.title, c.version, c.status, c.subject_id
            FROM curricula c
            WHERE c.subject_id = ? AND c.status = ?
            ORDER BY c.created_at DESC
        ";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("is", $subject_id, $status);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
    // Fetch all active curricula
    else {
        $query = "
            SELECT c.curriculum_id, c.curriculum_code, c.title, c.version, c.status, 
                   c.subject_id, s.subject_name, c.created_at
            FROM curricula c
            LEFT JOIN subjects s ON c.subject_id = s.subject_id
            WHERE c.status = ?
            ORDER BY c.created_at DESC
        ";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $status);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }

    http_response_code(200);
    echo json_encode($result);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal server error',
        'message' => $e->getMessage()
    ]);
}
?>
