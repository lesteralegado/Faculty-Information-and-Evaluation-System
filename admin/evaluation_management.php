<?php

session_start();

// Check if user is logged in and has admin privileges
if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");  
    exit();
}

// Include database connection and helper
include '../includes/db_connection.php';
include '../includes/evaluation_status_helper.php';
require_once __DIR__ . '/../includes/modern_alert_system.php';

// Check evaluation status using helper function
$evalStatus = getEvaluationStatus($conn);
$actions_disabled = $evalStatus['is_ongoing'];
$evaluation_start_date = $evalStatus['start_date'];
$evaluation_end_date = $evalStatus['end_date'];
$evaluation_phase = $evalStatus['phase'];
$current_school_year = $evalStatus['school_year'];
$current_semester = $evalStatus['semester'];

// Normalization helpers (keep consistent across modules)
function normalize_school_year(string $sy): string {
    return str_replace(array("–", "—"), "-", trim($sy));
}
function semester_text_to_num($semester): int {
    $sem = is_string($semester) ? trim($semester) : (string)$semester;
    if ($sem === '1st Semester' || $sem === '1') return 1;
    if ($sem === '2nd Semester' || $sem === '2') return 2;
    // fall back: detect "2"
    return (stripos($sem, '2') !== false) ? 2 : 1;
}
function get_current_sy_sem(mysqli $conn): array {
    $default_sy = '';
    $default_sem = 1;
    $stmt = $conn->prepare("SELECT school_year, semester FROM currentschoolyearandsemester LIMIT 1");
    if ($stmt) {
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows > 0) {
            $row = $res->fetch_assoc();
            $default_sy = normalize_school_year($row['school_year'] ?? '');
            $default_sem = (int)($row['semester'] ?? 1);
        }
        $stmt->close();
    }
    return ['school_year' => $default_sy, 'semester' => ($default_sem === 2 ? 2 : 1)];
}
function sync_active_evaluation_set(mysqli $conn, string $school_year, int $semester_num): void {
    $school_year = normalize_school_year($school_year);
    $semester_num = ($semester_num === 2) ? 2 : 1;

    // First, ensure the specified school_year/semester records are marked active
    // Categories
    $stmt = $conn->prepare("UPDATE evaluation_categories 
        SET is_active = CASE 
            WHEN REPLACE(REPLACE(school_year, '–', '-'), '—', '-') = ? AND semester = ? THEN 1 
            ELSE 0 
        END");
    if ($stmt) {
        $stmt->bind_param("si", $school_year, $semester_num);
        $stmt->execute();
        $stmt->close();
    }

    // Questions
    $stmt = $conn->prepare("UPDATE evaluation_questions 
        SET is_active = CASE 
            WHEN REPLACE(REPLACE(school_year, '–', '-'), '—', '-') = ? AND semester = ? THEN 1 
            ELSE 0 
        END");
    if ($stmt) {
        $stmt->bind_param("si", $school_year, $semester_num);
        $stmt->execute();
        $stmt->close();
    }
}

/**
 * Diagnostic function to check why questions aren't displaying
 * Returns array with debugging info: categories count, questions count, data samples
 */
function get_evaluation_diagnostics(mysqli $conn, string $school_year, int $semester_num): array {
    $school_year = normalize_school_year($school_year);
    $semester_num = ($semester_num === 2) ? 2 : 1;
    
    $diag = [
        'school_year' => $school_year,
        'semester' => $semester_num,
        'all_categories_count' => 0,
        'active_categories_count' => 0,
        'categories_for_sy_sem_count' => 0,
        'all_questions_count' => 0,
        'active_questions_count' => 0,
        'questions_for_sy_sem_count' => 0,
        'sample_categories' => [],
        'sample_questions' => [],
        'issues' => []
    ];
    
    // Check all categories
    $all_cats = $conn->query("SELECT COUNT(*) as cnt FROM evaluation_categories");
    if ($all_cats && $row = $all_cats->fetch_assoc()) {
        $diag['all_categories_count'] = (int)$row['cnt'];
    }
    
    // Check active categories
    $active_cats = $conn->query("SELECT COUNT(*) as cnt FROM evaluation_categories WHERE is_active = 1");
    if ($active_cats && $row = $active_cats->fetch_assoc()) {
        $diag['active_categories_count'] = (int)$row['cnt'];
    }
    
    // Check categories for current school year/semester
    $sy_cats = $conn->query("SELECT COUNT(*) as cnt FROM evaluation_categories 
        WHERE REPLACE(REPLACE(school_year, '–', '-'), '—', '-') = '" . $conn->real_escape_string($school_year) . "' 
        AND semester = " . (int)$semester_num);
    if ($sy_cats && $row = $sy_cats->fetch_assoc()) {
        $diag['categories_for_sy_sem_count'] = (int)$row['cnt'];
    }
    
    // Sample categories for current school year/semester
    $sample_cats = $conn->query("SELECT * FROM evaluation_categories 
        WHERE REPLACE(REPLACE(school_year, '–', '-'), '—', '-') = '" . $conn->real_escape_string($school_year) . "' 
        AND semester = " . (int)$semester_num . " LIMIT 3");
    if ($sample_cats) {
        while ($cat = $sample_cats->fetch_assoc()) {
            $diag['sample_categories'][] = $cat;
        }
    }
    
    // Check all questions
    $all_qs = $conn->query("SELECT COUNT(*) as cnt FROM evaluation_questions");
    if ($all_qs && $row = $all_qs->fetch_assoc()) {
        $diag['all_questions_count'] = (int)$row['cnt'];
    }
    
    // Check active questions
    $active_qs = $conn->query("SELECT COUNT(*) as cnt FROM evaluation_questions WHERE is_active = 1");
    if ($active_qs && $row = $active_qs->fetch_assoc()) {
        $diag['active_questions_count'] = (int)$row['cnt'];
    }
    
    // Check questions for current school year/semester
    $sy_qs = $conn->query("SELECT COUNT(*) as cnt FROM evaluation_questions 
        WHERE REPLACE(REPLACE(school_year, '–', '-'), '—', '-') = '" . $conn->real_escape_string($school_year) . "' 
        AND semester = " . (int)$semester_num);
    if ($sy_qs && $row = $sy_qs->fetch_assoc()) {
        $diag['questions_for_sy_sem_count'] = (int)$row['cnt'];
    }
    
    // Sample questions for current school year/semester
    $sample_qs = $conn->query("SELECT q.*, c.category_name FROM evaluation_questions q 
        LEFT JOIN evaluation_categories c ON q.category_id = c.category_id
        WHERE REPLACE(REPLACE(q.school_year, '–', '-'), '—', '-') = '" . $conn->real_escape_string($school_year) . "' 
        AND q.semester = " . (int)$semester_num . " LIMIT 3");
    if ($sample_qs) {
        while ($q = $sample_qs->fetch_assoc()) {
            $diag['sample_questions'][] = $q;
        }
    }
    
    // Identify issues
    if ($diag['all_categories_count'] === 0) {
        $diag['issues'][] = "No categories exist in database";
    }
    if ($diag['all_questions_count'] === 0) {
        $diag['issues'][] = "No questions exist in database";
    }
    if ($diag['categories_for_sy_sem_count'] === 0) {
        $diag['issues'][] = "No categories for school year '$school_year' / semester $semester_num";
    }
    if ($diag['questions_for_sy_sem_count'] === 0) {
        $diag['issues'][] = "No questions for school year '$school_year' / semester $semester_num";
    }
    if ($diag['active_categories_count'] === 0 && $diag['categories_for_sy_sem_count'] > 0) {
        $diag['issues'][] = "Categories exist but none are marked as active (is_active = 1)";
    }
    if ($diag['active_questions_count'] === 0 && $diag['questions_for_sy_sem_count'] > 0) {
        $diag['issues'][] = "Questions exist but none are marked as active (is_active = 1)";
    }
    
    return $diag;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Past Evaluations AJAX Handler
    if (isset($_POST['action']) && $_POST['action'] === 'get_past_evaluations') {
        header('Content-Type: application/json');
        try {
            $school_year = isset($_POST['school_year']) ? normalize_school_year(trim($_POST['school_year'])) : '';
            $semester = isset($_POST['semester']) ? (int)$_POST['semester'] : 0;

            if (empty($school_year) || ($semester !== 1 && $semester !== 2)) {
                throw new Exception("Invalid filters selected.");
            }

            // Get categories for this school year and semester
            $stmt = $conn->prepare("
                SELECT DISTINCT category_id, category_name 
                FROM evaluation_categories 
                WHERE REPLACE(REPLACE(school_year, '–', '-'), '—', '-') = ? 
                AND semester = ? 
                ORDER BY category_id
            ");
            
            if (!$stmt) {
                throw new Exception("Database error: " . $conn->error);
            }

            $stmt->bind_param("si", $school_year, $semester);
            $stmt->execute();
            $categories_result = $stmt->get_result();
            $categories = $categories_result->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            if (empty($categories)) {
                echo json_encode([
                    'success' => false,
                    'error' => 'No past evaluations found for the selected filters.'
                ]);
                exit;
            }

            // Build HTML for categories and questions
            $html = '<div class="evaluation-categories-list">';

            foreach ($categories as $category) {
                $cat_id = $category['category_id'];
                $cat_name = htmlspecialchars($category['category_name']);

            // Get questions for this category - CRITICAL FIX: Added school_year join condition
            $q_stmt = $conn->prepare("
                SELECT q.question_id, q.question as question_text
                FROM evaluation_questions q
                INNER JOIN evaluation_categories c 
                  ON q.category_id = c.category_id 
                  AND REPLACE(REPLACE(q.school_year, '–', '-'), '—', '-') = REPLACE(REPLACE(c.school_year, '–', '-'), '—', '-')
                  AND q.semester = c.semester
                WHERE q.category_id = ? 
                  AND REPLACE(REPLACE(q.school_year, '–', '-'), '—', '-') = ? 
                  AND q.semester = ?
                ORDER BY q.question_id ASC
            ");
            
            if (!$q_stmt) {
                continue;
            }

            $q_stmt->bind_param("isi", $cat_id, $school_year, $semester);
            $q_stmt->execute();
            $questions_result = $q_stmt->get_result();
            $questions = $questions_result->fetch_all(MYSQLI_ASSOC);
            $q_stmt->close();

                // Category card
                $html .= '
                    <div class="category-card" style="margin-bottom: 20px; border: 1px solid #e0e0e0; border-radius: 8px; padding: 15px; background-color: #f9f9f9;">
                        <div style="display: flex; align-items: center; margin-bottom: 15px; cursor: pointer;" onclick="toggleCategory(this)">
                            <i class="fas fa-chevron-down me-2" style="transition: transform 0.3s;"></i>
                            <h6 style="margin: 0; color: #333; font-weight: 600;">' . $cat_name . '</h6>
                        </div>
                        <div class="category-questions" style="display: block; padding-left: 30px;">
                ';

                if (empty($questions)) {
                    $html .= '<p style="color: #999; font-size: 14px;">No questions found for this category.</p>';
                } else {
                    $q_counter = 1;
                    foreach ($questions as $question) {
                        $q_id = (int)$question['question_id'];
                        $q_text = htmlspecialchars($question['question_text']);

                        $html .= '
                            <div style="margin-bottom: 12px; padding: 10px; background-color: white; border-radius: 4px; border-left: 3px solid #4CAF50;">
                                <p style="margin: 0; color: #555; font-size: 14px;">
                                    <strong>' . $q_counter . '.</strong> ' . $q_text . '
                                </p>
                            </div>
                        ';
                        $q_counter++;
                    }
                }

                $html .= '
                        </div>
                    </div>
                ';
            }

            $html .= '</div>';

            echo json_encode([
                'success' => true,
                'html' => $html
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'error' => htmlspecialchars($e->getMessage())
            ]);
        }
        exit;
    }

    // Excel Export Handler
    if (isset($_POST['action']) && $_POST['action'] === 'export_csv') {
        try {
            // Validate that school year and semester are selected
            if (!isset($_POST['export_school_year']) || empty($_POST['export_school_year'])) {
                throw new Exception("Please select a School Year for export.");
            }
            if (!isset($_POST['export_semester']) || empty($_POST['export_semester'])) {
                throw new Exception("Please select a Semester for export.");
            }

            $school_year = normalize_school_year(trim($_POST['export_school_year']));
            $semester = (int)$_POST['export_semester'];

            if (empty($school_year)) {
                throw new Exception("School year is required for export.");
            }
            if ($semester !== 1 && $semester !== 2) {
                throw new Exception("Invalid semester selected.");
            }

            // Check if PhpSpreadsheet is available
            $phpspreadsheet_available = false;
            if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
                require_once __DIR__ . '/../vendor/autoload.php';
                $phpspreadsheet_available = class_exists('PhpOffice\PhpSpreadsheet\IOFactory');
            }
            
            if (!$phpspreadsheet_available) {
                $manual_paths = [
                    __DIR__ . '/../includes/phpspreadsheet/src/phpspreadsheet/IOFactory.php',
                    __DIR__ . '/../includes/phpspreadsheet/src/PhpSpreadsheet/IOFactory.php',
                ];
                
                $found_path = null;
                foreach ($manual_paths as $path) {
                    if (file_exists($path)) {
                        $found_path = $path;
                        break;
                    }
                }
                
                if ($found_path) {
                    if (file_exists(__DIR__ . '/../includes/phpspreadsheet_autoload.php')) {
                        require_once __DIR__ . '/../includes/phpspreadsheet_autoload.php';
                    }
                    if (!class_exists('PhpOffice\PhpSpreadsheet\IOFactory')) {
                        require_once $found_path;
                    }
                    $phpspreadsheet_available = class_exists('PhpOffice\PhpSpreadsheet\IOFactory');
                }
            }
            
            if (!$phpspreadsheet_available && !class_exists('PhpOffice\PhpSpreadsheet\IOFactory')) {
                throw new Exception("PhpSpreadsheet library is not available. Cannot export to Excel.");
            }

            // Query to get categories and questions for the selected filters
            // CRITICAL FIX: Added school_year and semester join conditions to ensure proper data matching
            $query = "SELECT ec.category_name, eq.question
                      FROM evaluation_categories ec
                      LEFT JOIN evaluation_questions eq 
                        ON ec.category_id = eq.category_id 
                        AND REPLACE(REPLACE(eq.school_year, '–', '-'), '—', '-') = REPLACE(REPLACE(ec.school_year, '–', '-'), '—', '-')
                        AND eq.semester = ec.semester
                      WHERE REPLACE(REPLACE(ec.school_year, '–', '-'), '—', '-') = ?
                        AND ec.semester = ?
                      ORDER BY ec.category_id ASC, eq.question_id ASC";

            $stmt = $conn->prepare($query);
            if (!$stmt) {
                throw new Exception("Error preparing query: " . $conn->error);
            }

            $stmt->bind_param("si", $school_year, $semester);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new Exception("Error executing query: " . $stmt->error);
            }

            $result = $stmt->get_result();
            if (!$result) {
                $stmt->close();
                throw new Exception("Error fetching data: " . $stmt->error);
            }

            // Create new Spreadsheet
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Evaluation Data');

            // Set column widths
            $sheet->getColumnDimension('A')->setWidth(35);
            $sheet->getColumnDimension('B')->setWidth(60);

            // Add headers
            $sheet->setCellValue('A1', 'Category Name');
            $sheet->setCellValue('B1', 'Question');

            // Style headers
            $headerStyle = $sheet->getStyle('A1:B1');
            $headerStyle->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFF'));
            $headerStyle->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FF800000');
            $headerStyle->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
            $headerStyle->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

            // Add data rows
            $row = 2;
            $previous_category = null;
            
            while ($data = $result->fetch_assoc()) {
                $current_category = $data['category_name'];
                
                // Only show category name on the first occurrence
                if ($current_category === $previous_category) {
                    $display_category = ''; // Empty cell for repeated categories
                } else {
                    $display_category = $current_category;
                    $previous_category = $current_category;
                }
                
                $sheet->setCellValue('A' . $row, $display_category);
                $sheet->setCellValue('B' . $row, $data['question'] ?? '');
                
                // Style data rows
                $cellStyle = $sheet->getStyle('A' . $row . ':B' . $row);
                $cellStyle->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
                $cellStyle->getAlignment()->setWrapText(true)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP);
                
                $row++;
            }

            // Freeze header row
            $sheet->freezePane('A2');

            // Set row heights for header
            $sheet->getRowDimension(1)->setRowHeight(25);

            // Auto-size rows for content (approximate)
            for ($i = 2; $i < $row; $i++) {
                $sheet->getRowDimension($i)->setRowHeight(-1);
            }

            $stmt->close();

            // Generate Excel file
            $filename = 'evaluation_export_' . date('Y-m-d_His') . '.xlsx';
            
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            
            // Clear any output buffering to prevent corrupting the file
            if (ob_get_level()) {
                ob_end_clean();
            }
            
            // Send file to browser
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: max-age=0');
            header('Pragma: public');
            header('Expires: 0');
            
            $writer->save('php://output');
            exit();
            
        } catch (Exception $e) {
            $_SESSION['error'] = "Excel export failed: " . $e->getMessage();
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }
    }
    
    if (isset($_POST['action']) && $_POST['action'] === 'export_evaluation_set') {
        try {
            // Fetch active categories for the current school year & semester only
            $current_ctx = get_current_sy_sem($conn);
            $sy = normalize_school_year((string)($current_ctx['school_year'] ?? $current_school_year ?? ''));
            $sem = (int)($current_ctx['semester'] ?? semester_text_to_num($current_semester));

            $categories_query = "SELECT * FROM evaluation_categories 
                WHERE is_active = 1 
                  AND REPLACE(REPLACE(school_year, '–', '-'), '—', '-') = ? 
                  AND semester = ?
                ORDER BY category_name ASC";
            $stmt_cats = $conn->prepare($categories_query);
            if (!$stmt_cats) {
                throw new Exception("Error preparing categories query: " . $conn->error);
            }
            $stmt_cats->bind_param("si", $sy, $sem);
            $stmt_cats->execute();
            $categories_result = $stmt_cats->get_result();
            
            if (!$categories_result) {
                $stmt_cats->close();
                throw new Exception("Error fetching categories: " . $stmt_cats->error);
            }
            
            $categories = [];
            $total_questions = 0;
            
            while ($cat = $categories_result->fetch_assoc()) {
                // Fetch questions for this category
                $questions_query = "SELECT * FROM evaluation_questions WHERE category_id = ? AND is_active = 1 ORDER BY question_id ASC";
                $stmt = $conn->prepare($questions_query);
                if (!$stmt) {
                    throw new Exception("Error preparing questions query: " . $conn->error);
                }
                $stmt->bind_param("i", $cat['category_id']);
                $stmt->execute();
                $questions_result = $stmt->get_result();
                
                if (!$questions_result) {
                    $stmt->close();
                    throw new Exception("Error fetching questions for category '{$cat['category_name']}': " . $stmt->error);
                }
                
                $questions = [];
                while ($q = $questions_result->fetch_assoc()) {
                    $questions[] = [
                        'question_number' => (int)$q['question_number'],
                        'question_text' => $q['question'],
                        'sort_order' => (int)$q['sort_order']
                    ];
                    $total_questions++;
                }
                
                $categories[] = [
                    'category_name' => $cat['category_name'],
                    'description' => $cat['description'] ?? '',
                    'questions' => $questions
                ];
                
                $stmt->close();
            }

            $stmt_cats->close();
            
            // Convert semester to text format for export
            $semester_text = ($sem === 1) ? '1st Semester' : '2nd Semester';
            
            $export_data = [
                'export_date' => date('Y-m-d H:i:s'),
                'school_year' => $sy,
                'semester' => $semester_text,
                'total_categories' => count($categories),
                'total_questions' => $total_questions,
                'categories' => $categories
            ];
            
            // Send JSON file download
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="evaluation_set_' . date('Y-m-d_His') . '.json"');
            echo json_encode($export_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit();
        } catch (Exception $e) {
            $_SESSION['error'] = "Export failed: " . $e->getMessage();
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }
    }
    
    if (isset($_POST['action']) && $_POST['action'] === 'import_evaluation_set') {
        // Start transaction for atomicity
        $conn->autocommit(FALSE);
        $transaction_started = true;
        
        try {
            // Check if PhpSpreadsheet is available and load it properly
            $phpspreadsheet_available = false;
            
            // Method 1: Try Composer autoloader (if installed via Composer)
            if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
                require_once __DIR__ . '/../vendor/autoload.php';
                $phpspreadsheet_available = class_exists('PhpOffice\PhpSpreadsheet\IOFactory');
            }
            
            // Method 2: Try manual installation with custom autoloader
            if (!$phpspreadsheet_available) {
                // Check if PhpSpreadsheet files exist
                $manual_paths = [
                    __DIR__ . '/../includes/phpspreadsheet/src/phpspreadsheet/IOFactory.php',  // lowercase (Windows)
                    __DIR__ . '/../includes/phpspreadsheet/src/PhpSpreadsheet/IOFactory.php',  // uppercase
                ];
                
                $found_path = null;
                foreach ($manual_paths as $path) {
                    if (file_exists($path)) {
                        $found_path = $path;
                        break;
                    }
                }
                
                if ($found_path) {
                    // Load the custom autoloader FIRST (must be loaded BEFORE requiring any PhpSpreadsheet files)
                    if (file_exists(__DIR__ . '/../includes/phpspreadsheet_autoload.php')) {
                        require_once __DIR__ . '/../includes/phpspreadsheet_autoload.php';
                    }
                    
                    // Now require IOFactory - the autoloader will handle loading its dependencies
                    if (!class_exists('PhpOffice\PhpSpreadsheet\IOFactory')) {
                        require_once $found_path;
                    }
                    $phpspreadsheet_available = class_exists('PhpOffice\PhpSpreadsheet\IOFactory');
                }
            }
            
            // Method 3: Check if already loaded
            if (!$phpspreadsheet_available && class_exists('PhpOffice\PhpSpreadsheet\IOFactory')) {
                $phpspreadsheet_available = true;
            }
            
            if (!$phpspreadsheet_available) {
                throw new Exception("PhpSpreadsheet library is not available or not properly installed. Please install it using Composer or manual installation with proper autoloader.");
            }
            
            // Validate file upload
            if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
                $error_msg = "File upload error";
                if (isset($_FILES['import_file']['error'])) {
                    switch ($_FILES['import_file']['error']) {
                        case UPLOAD_ERR_INI_SIZE:
                        case UPLOAD_ERR_FORM_SIZE:
                            $error_msg = "File is too large";
                            break;
                        case UPLOAD_ERR_PARTIAL:
                            $error_msg = "File upload was incomplete";
                            break;
                        case UPLOAD_ERR_NO_FILE:
                            $error_msg = "No file was uploaded";
                            break;
                    }
                }
                throw new Exception($error_msg);
            }
            
            // Validate file type
            $file_ext = strtolower(pathinfo($_FILES['import_file']['name'], PATHINFO_EXTENSION));
            $allowed_extensions = ['xlsx', 'xls'];
            if (!in_array($file_ext, $allowed_extensions)) {
                throw new Exception("Invalid file type. Only Excel files (.xlsx, .xls) are allowed.");
            }
            
            // Read Excel file using PhpSpreadsheet
            $inputFileName = $_FILES['import_file']['tmp_name'];
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($inputFileName);
            $worksheet = $spreadsheet->getActiveSheet();
            $highestRow = $worksheet->getHighestRow();
            $highestColumn = $worksheet->getHighestColumn();
            
            if ($highestRow < 2) {
                throw new Exception("Excel file is empty or has no data rows.");
            }
            
            // Get school year and semester from form or use current
            $import_school_year = isset($_POST['import_school_year']) && !empty($_POST['import_school_year'])
                                ? normalize_school_year(trim($_POST['import_school_year']))
                                : normalize_school_year((string)$current_school_year);
            $import_semester_text = isset($_POST['import_semester']) && !empty($_POST['import_semester'])
                                  ? trim($_POST['import_semester'])
                                  : (string)$current_semester;
            $import_semester_num = semester_text_to_num($import_semester_text);

            if (empty($import_school_year)) {
                throw new Exception("School year is required. Please provide it in the import form.");
            }
            
            // Parse Excel data BEFORE deleting anything
            // Expected format: Row 1 = Headers, Rows 2+ = Data
            // Headers: Category Name, Question Text (or Question), School Year, Semester
            $headers = [];
            $headerRow = 1;
            
            // Read header row
            for ($col = 'A'; $col <= $highestColumn; $col++) {
                $cellValue = $worksheet->getCell($col . $headerRow)->getValue();
                $headers[trim(strtolower($cellValue))] = $col;
            }
            
            // Validate required headers
            $required_headers = ['category name', 'question', 'question text'];
            $has_category = isset($headers['category name']);
            $has_question = isset($headers['question']) || isset($headers['question text']);
            
            if (!$has_category || !$has_question) {
                throw new Exception("Excel file is missing required columns. Required: 'Category Name' and 'Question' (or 'Question Text').");
            }
            
            $category_col = $headers['category name'];
            $question_col = isset($headers['question']) ? $headers['question'] : $headers['question text'];
            
            // Group questions by category
            $categories_data = [];
            $current_category = null;
            
            for ($row = 2; $row <= $highestRow; $row++) {
                $category_name = trim($worksheet->getCell($category_col . $row)->getValue());
                $question_text = trim($worksheet->getCell($question_col . $row)->getValue());
                
                // Skip empty rows
                if (empty($category_name) && empty($question_text)) {
                    continue;
                }
                
                // If category name is provided, start a new category
                if (!empty($category_name)) {
                    $current_category = $category_name;
                    if (!isset($categories_data[$current_category])) {
                        $categories_data[$current_category] = [
                            'category_name' => $current_category,
                            'description' => '',
                            'questions' => []
                        ];
                    }
                }
                
                // Add question to current category
                if (!empty($question_text) && $current_category !== null) {
                    $categories_data[$current_category]['questions'][] = [
                        'question_text' => $question_text
                    ];
                }
            }
            
            if (empty($categories_data)) {
                throw new Exception("No valid data found in Excel file. Please check the file format.");
            }
            
            // Now that we've validated all data, delete existing records ONLY for the same school year & semester
            // (Preserve historical sets for reporting; results use response snapshots.)
            $del_q_stmt = $conn->prepare("DELETE FROM evaluation_questions 
                WHERE REPLACE(REPLACE(school_year, '–', '-'), '—', '-') = ? AND semester = ?");
            if (!$del_q_stmt) {
                throw new Exception("Failed to prepare delete questions statement: " . $conn->error);
            }
            $del_q_stmt->bind_param("si", $import_school_year, $import_semester_num);
            if (!$del_q_stmt->execute()) {
                $del_q_stmt->close();
                throw new Exception("Failed to delete existing questions for $import_school_year / $import_semester_text: " . $del_q_stmt->error);
            }
            $del_q_stmt->close();

            $del_c_stmt = $conn->prepare("DELETE FROM evaluation_categories 
                WHERE REPLACE(REPLACE(school_year, '–', '-'), '—', '-') = ? AND semester = ?");
            if (!$del_c_stmt) {
                throw new Exception("Failed to prepare delete categories statement: " . $conn->error);
            }
            $del_c_stmt->bind_param("si", $import_school_year, $import_semester_num);
            if (!$del_c_stmt->execute()) {
                $del_c_stmt->close();
                throw new Exception("Failed to delete existing categories for $import_school_year / $import_semester_text: " . $del_c_stmt->error);
            }
            $del_c_stmt->close();
            
            $imported_categories = 0;
            $imported_questions = 0;
            
            // Determine whether this imported set should be active (only if it matches the current SY/Sem)
            $current_ctx = get_current_sy_sem($conn);
            $should_activate = ($import_school_year === normalize_school_year((string)($current_ctx['school_year'] ?? '')))
                && ((int)$import_semester_num === (int)($current_ctx['semester'] ?? 1));
            $active_flag = $should_activate ? 1 : 0;

            // Prepare insert statements
            $insert_category_stmt = $conn->prepare("INSERT INTO evaluation_categories (category_name, is_active, school_year, semester) VALUES (?, ?, ?, ?)");
            if (!$insert_category_stmt) {
                throw new Exception("Failed to prepare category insert statement: " . $conn->error);
            }
            
            $insert_question_stmt = $conn->prepare("INSERT INTO evaluation_questions (category_id, question, category_name, school_year, semester, is_active) VALUES (?, ?, ?, ?, ?, ?)");
            if (!$insert_question_stmt) {
                throw new Exception("Failed to prepare question insert statement: " . $conn->error);
            }
            
            // Import categories and their questions
            foreach ($categories_data as $cat_index => $category) {
                // Validate category data
                if (empty(trim($category['category_name']))) {
                    throw new Exception("Category #" . ($cat_index + 1) . " is missing a category name.");
                }
                
                $cat_name = trim($category['category_name']);
                
                // Validate category name length (varchar(200) in database)
                if (strlen($cat_name) > 200) {
                    throw new Exception("Category name exceeds maximum length of 200 characters: '$cat_name'");
                }
                
                // Insert category
                $insert_category_stmt->bind_param("sisi", $cat_name, $active_flag, $import_school_year, $import_semester_num);
                if (!$insert_category_stmt->execute()) {
                    throw new Exception("Failed to insert category '$cat_name': " . $insert_category_stmt->error);
                }
                
                // Get the category_id
                $category_id = $conn->insert_id;
                if (!$category_id || $category_id <= 0) {
                    throw new Exception("Failed to retrieve auto-incremented category ID for '$cat_name'");
                }
                
                $imported_categories++;
                
                // Import questions for this category
                if (isset($category['questions']) && is_array($category['questions'])) {
                    foreach ($category['questions'] as $q_index => $question) {
                        // Validate question data
                        if (!isset($question['question_text']) || empty(trim($question['question_text']))) {
                            throw new Exception("Question #" . ($q_index + 1) . " in category '$cat_name' is missing question text.");
                        }
                        
                        $q_text = trim($question['question_text']);
                        
                        // Validate question text length (varchar(100) in database)
                        if (strlen($q_text) > 100) {
                            throw new Exception("Question text in category '$cat_name' exceeds maximum length of 100 characters: '$q_text'");
                        }
                        
                        // Insert question
                        $insert_question_stmt->bind_param("isssii", $category_id, $q_text, $cat_name, $import_school_year, $import_semester_num, $active_flag);
                        if (!$insert_question_stmt->execute()) {
                            throw new Exception("Failed to insert question in category '$cat_name': " . $insert_question_stmt->error);
                        }
                        
                        $imported_questions++;
                    }
                }
            }
            
            // Close prepared statements
            $insert_category_stmt->close();
            $insert_question_stmt->close();
            
            // Ensure only the current (latest) school year/semester is active system-wide
            // (Older or other periods remain stored for historical reference.)
            if ($should_activate) {
                sync_active_evaluation_set($conn, $import_school_year, $import_semester_num);
            }

            // Commit transaction
            if (!$conn->commit()) {
                throw new Exception("Transaction commit failed: " . $conn->error);
            }
            
            // Do NOT reset AUTO_INCREMENT counters (keeps IDs stable over time)
            
            // Build success message
            $success_msg = "Successfully imported evaluation set! ";
            $success_msg .= "Categories: " . $imported_categories . " imported. ";
            $success_msg .= "Questions: " . $imported_questions . " imported. ";
            $success_msg .= "School Year: " . htmlspecialchars($import_school_year) . " | Semester: " . htmlspecialchars($import_semester_text);
            
            $_SESSION['success'] = $success_msg;
            
        } catch (Exception $e) {
            // Rollback transaction on error
            if ($transaction_started) {
                $conn->rollback();
            }
            $_SESSION['error'] = "Import failed: " . $e->getMessage();
        } finally {
            // Restore autocommit
            if ($transaction_started) {
                $conn->autocommit(TRUE);
            }
        }
        
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}

// Handle CRUD operations and other actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $blocked_actions = ['add_category', 'update_category', 'delete_category', 'add_question', 'update_question', 'delete_question'];
        if (isset($_POST['action']) && in_array($_POST['action'], $blocked_actions)) {
            $_SESSION['error'] = 'Manual creation and editing of categories and questions is disabled. Please use Excel Import functionality to manage evaluation sets.';
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }
        
        // Re-check evaluation status on POST for evaluation period modifications
        $post_is_evaluation_ongoing = isEvaluationOngoing($conn);
        
        // Block actions during ongoing evaluation
        if ($post_is_evaluation_ongoing) {
            $blocked_evaluation_actions = ['set_evaluation_period', 'change_school_year_semester'];
            if (isset($_POST['action']) && in_array($_POST['action'], $blocked_evaluation_actions)) {
                $_SESSION['error'] = 'This action is not allowed while an evaluation is ongoing. Please wait until the evaluation period ends.';
                header("Location: " . $_SERVER['PHP_SELF']);
                exit();
            }
        }
        
        switch ($_POST['action']) {
            case 'set_evaluation_period':
                $start_date = $_POST['evaluation_start_date'];
                $end_date = $_POST['evaluation_end_date'];
                // Validate dates
                if (strtotime($start_date) >= strtotime($end_date)) {
                    $_SESSION['error'] = "End date/time must be after start date/time.";
                    break;
                }
                // If a record exists, update it; else insert new
                $check = $conn->query("SELECT id FROM evaluation_status ORDER BY id DESC LIMIT 1");
                if ($check && $check->num_rows > 0) {
                    $row = $check->fetch_assoc();
                    $stmt = $conn->prepare("UPDATE evaluation_status SET status=1, date_start=?, date_end=? WHERE id=?");
                    $stmt->bind_param("ssi", $start_date, $end_date, $row['id']);
                    $stmt->execute();
                } else {
                    $stmt = $conn->prepare("INSERT INTO evaluation_status (status, date_start, date_end) VALUES (1, ?, ?)");
                    $stmt->bind_param("ss", $start_date, $end_date);
                    $stmt->execute();
                }
                $_SESSION['success'] = "Evaluation period has been set successfully!";
                break;

            case 'stop_evaluation_now':
                // Mark as ended (status=0, clear dates using zero date)
                $check = $conn->query("SELECT id FROM evaluation_status ORDER BY id DESC LIMIT 1");
                if ($check && $check->num_rows > 0) {
                    $row = $check->fetch_assoc();
                    $stmt = $conn->prepare("UPDATE evaluation_status SET status=0, date_start='0000-00-00 00:00:00', date_end='0000-00-00 00:00:00' WHERE id=?");
                    $stmt->bind_param("i", $row['id']);
                    $stmt->execute();
                }
                $_SESSION['success'] = "Evaluation period has been reset.";
                break;

            case 'extend_evaluation':
                $end_date = $_POST['evaluation_end_date'];
                // Only update end date if record exists (use latest record)
                $check = $conn->query("SELECT id, date_start FROM evaluation_status ORDER BY id DESC LIMIT 1");
                if ($check && $check->num_rows > 0) {
                    $row = $check->fetch_assoc();
                    if (empty($row['date_start'])) {
                        $_SESSION['error'] = "Cannot extend: start date is not set.";
                        break;
                    }
                    if (strtotime($end_date) <= strtotime($row['date_start'])) {
                        $_SESSION['error'] = "New end date/time must be after the start date/time.";
                        break;
                    }
                    $stmt = $conn->prepare("UPDATE evaluation_status SET date_end=? WHERE id=?");
                    $stmt->bind_param("si", $end_date, $row['id']);
                    $stmt->execute();
                    $_SESSION['success'] = "Evaluation period has been extended.";
                } else {
                    $_SESSION['error'] = "No evaluation period to extend.";
                }
                break;

            case 'change_school_year_semester':
                $school_year = $_POST['school_year'];
                $semester = $_POST['semester'];
                $updated_by = $_SESSION['username'];

                // Update evaluation_status  as before (for backward compatibility)
                $check = $conn->query("SELECT id FROM currentschoolyearandsemester LIMIT 1");
                $semester_val = ($semester == '1st Semester') ? 1 : 2;
                if ($check && $check->num_rows > 0) {
                    $stmt = $conn->prepare("UPDATE currentschoolyearandsemester SET school_year = ?, semester = ? WHERE id = (SELECT id FROM (SELECT id FROM currentschoolyearandsemester LIMIT 1) as t)");
                    $stmt->bind_param("si", $school_year, $semester_val);
                    $stmt->execute();
                } else {
                    $stmt = $conn->prepare("INSERT INTO currentschoolyearandsemester (school_year, semester) VALUES (?, ?)");
                    $stmt->bind_param("si", $school_year, $semester_val);
                    $stmt->execute();
                }

                $_SESSION['success'] = "School year and semester have been updated successfully!";
                break;
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}

// Check if PhpSpreadsheet is available and load it properly
$phpspreadsheet_available = false;

// Method 1: Try Composer autoloader (if installed via Composer)
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
    $phpspreadsheet_available = class_exists('PhpOffice\PhpSpreadsheet\IOFactory');
}

// Method 2: Try manual installation with custom autoloader
if (!$phpspreadsheet_available) {
    // Check if PhpSpreadsheet files exist
    $manual_paths = [
        __DIR__ . '/../includes/phpspreadsheet/src/phpspreadsheet/IOFactory.php',  // lowercase (Windows)
        __DIR__ . '/../includes/phpspreadsheet/src/PhpSpreadsheet/IOFactory.php',  // uppercase
    ];
    
    $found_path = null;
    foreach ($manual_paths as $path) {
        if (file_exists($path)) {
            $found_path = $path;
            break;
        }
    }
    
    if ($found_path) {
        // Load the custom autoloader FIRST (must be loaded BEFORE requiring any PhpSpreadsheet files)
        if (file_exists(__DIR__ . '/../includes/phpspreadsheet_autoload.php')) {
            require_once __DIR__ . '/../includes/phpspreadsheet_autoload.php';
        }
        
        // Now require IOFactory - the autoloader will handle loading its dependencies
        if (!class_exists('PhpOffice\PhpSpreadsheet\IOFactory')) {
            require_once $found_path;
        }
        $phpspreadsheet_available = class_exists('PhpOffice\PhpSpreadsheet\IOFactory');
    }
}

// Method 3: Check if already loaded
if (!$phpspreadsheet_available && class_exists('PhpOffice\PhpSpreadsheet\IOFactory')) {
    $phpspreadsheet_available = true;
}

// Fetch available school years and semesters for export dropdowns
$available_school_years = [];
$available_semesters = [];

$sy_query = "SELECT DISTINCT REPLACE(REPLACE(school_year, '–', '-'), '—', '-') AS school_year
             FROM evaluation_categories
             WHERE school_year IS NOT NULL AND TRIM(school_year) <> ''
             UNION
             SELECT DISTINCT REPLACE(REPLACE(school_year, '–', '-'), '—', '-') AS school_year
             FROM evaluation_questions
             WHERE school_year IS NOT NULL AND TRIM(school_year) <> ''
             ORDER BY school_year DESC";
$sy_result = $conn->query($sy_query);
if ($sy_result) {
    while ($row = $sy_result->fetch_assoc()) {
        $available_school_years[] = $row['school_year'];
    }
}

$sem_query = "SELECT DISTINCT semester FROM evaluation_categories
             WHERE semester IS NOT NULL
             UNION
             SELECT DISTINCT semester FROM evaluation_questions
             WHERE semester IS NOT NULL
             ORDER BY semester ASC";
$sem_result = $conn->query($sem_query);
if ($sem_result) {
    while ($row = $sem_result->fetch_assoc()) {
        $available_semesters[] = (int)$row['semester'];
    }
}
sort($available_semesters);

// Fetch categories and questions for display (ACTIVE records only, current school year & semester)
$current_ctx = get_current_sy_sem($conn);
$active_sy = normalize_school_year((string)($current_ctx['school_year'] ?? $current_school_year ?? ''));
$active_sem = (int)($current_ctx['semester'] ?? semester_text_to_num($current_semester));

// Keep DB flags consistent (auto-inactivate non-current records)
if (!empty($active_sy)) {
    sync_active_evaluation_set($conn, $active_sy, $active_sem);
}

$categories = [];
$questions = [];

// Fixed Categories Query
$categories_sql = "SELECT * FROM evaluation_categories
    WHERE is_active = 1
      AND REPLACE(REPLACE(school_year, '–', '-'), '—', '-') = ?
      AND semester = ?
    ORDER BY category_name ASC";
$stmt_cats = $conn->prepare($categories_sql);
if ($stmt_cats) {
    $stmt_cats->bind_param("si", $active_sy, $active_sem);
    if ($stmt_cats->execute()) {
        $categories = $stmt_cats->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    $stmt_cats->close();
}

// Fixed Questions Query - CRITICAL FIX: Added school_year condition to JOIN
// This ensures questions are joined only with categories from the SAME school year/semester
$questions_sql = "SELECT q.*, c.category_name, c.category_id
    FROM evaluation_questions q
    INNER JOIN evaluation_categories c 
      ON q.category_id = c.category_id 
      AND REPLACE(REPLACE(q.school_year, '–', '-'), '—', '-') = REPLACE(REPLACE(c.school_year, '–', '-'), '—', '-')
      AND q.semester = c.semester
    WHERE q.is_active = 1
      AND c.is_active = 1
      AND REPLACE(REPLACE(q.school_year, '–', '-'), '—', '-') = ?
      AND q.semester = ?
    ORDER BY c.category_id ASC, q.question_id ASC";
$stmt_qs = $conn->prepare($questions_sql);
if ($stmt_qs) {
    $stmt_qs->bind_param("si", $active_sy, $active_sem);
    if ($stmt_qs->execute()) {
        $questions = $stmt_qs->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    $stmt_qs->close();
}

// Get statistics
$safe_sy = $conn->real_escape_string($active_sy);
$safe_sem = (int)$active_sem;
$stats_sql = "SELECT 
    (SELECT COUNT(*) FROM evaluation_categories WHERE is_active = 1 AND REPLACE(REPLACE(school_year, '–', '-'), '—', '-') = '$safe_sy' AND semester = $safe_sem) as total_categories,
    (SELECT COUNT(*) FROM evaluation_questions WHERE is_active = 1 AND REPLACE(REPLACE(school_year, '–', '-'), '—', '-') = '$safe_sy' AND semester = $safe_sem) as total_questions,
    (SELECT COUNT(*) FROM evaluation_responses) as total_responses,
    (SELECT COUNT(DISTINCT e.student_id) FROM evaluation_responses er JOIN evaluations e ON er.evaluation_id = e.evaluation_id) as unique_students
";
$stats_result = $conn->query($stats_sql);
$stats = $stats_result->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="/capstone/images/school-logo.png" type="image/png">
    <title>Evaluation Management</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --primary-color: #800000;
            --primary-hover: #a00000;
            --primary-light: rgba(128, 0, 0, 0.1);
            --text-dark: #2c3e50;
            --border-color: #dee2e6;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --danger-color: #dc3545;
            --info-color: #17a2b8;
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            margin: 0;
            padding: 0;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
        }
        
        .main-content {
            margin-left: 250px;
            margin-top: 60px;
            padding: 30px;
            min-height: calc(100vh - 60px);
        }
        
        .header-section {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-hover));
            color: white;
            padding: 40px;
            border-radius: 20px;
            margin-bottom: 40px;
            box-shadow: 0 15px 35px rgba(128, 0, 0, 0.2);
            position: relative;
            overflow: hidden;
        }
        
        .header-section h1 {
            font-weight: 700;
            margin-bottom: 15px;
            font-size: 2.8rem;
            position: relative;
            z-index: 2;
        }
        
        .quick-actions-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            border: none;
            margin-bottom: 30px;
        }

        .quick-actions-body {
            padding: 30px;
        }

        .action-buttons-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .action-button {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-hover));
            border: none;
            color: white;
            padding: 18px;
            border-radius: 15px;
            font-weight: 600;
            font-size: 1rem;
            transition: all 0.3s ease;
            box-shadow: 0 5px 15px rgba(128, 0, 0, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            min-height: 70px;
            text-align: center;
        }

        .action-button:hover:not(:disabled) {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(128, 0, 0, 0.4);
            color: white;
        }

        .action-button:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .current-settings {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-top: 20px;
        }

        .setting-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #dee2e6;
        }

        .setting-item:last-child {
            border-bottom: none;
        }

        .setting-label {
            font-weight: 600;
            color: var(--text-dark);
        }

        .setting-value {
            color: var(--primary-color);
            font-weight: 500;
        }

        /* Dedicated Evaluation Set Management Section */
        .evaluation-set-management {
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            border: none;
            margin-bottom: 30px;
            border-top: 5px solid #28a745;
        }

        .eval-set-header {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            padding: 25px 30px;
        }

        .eval-set-title {
            font-weight: 700;
            font-size: 1.3rem;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .eval-set-body {
            padding: 30px;
        }

        .eval-set-description {
            background: #f0f9ff;
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 25px;
            border-left: 4px solid #17a2b8;
            font-size: 0.95rem;
            color: var(--text-dark);
        }

        .import-export-buttons {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .excel-import-section {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .btn-import-export {
            background: linear-gradient(135deg, #17a2b8, #138496);
            border: none;
            color: white;
            padding: 15px 30px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 1rem;
            transition: all 0.3s ease;
            box-shadow: 0 5px 15px rgba(23, 162, 184, 0.3);
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            flex: 1;
            min-width: 220px;
            justify-content: center;
        }

        .btn-import-export:hover:not(:disabled) {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(23, 162, 184, 0.4);
            color: white;
            text-decoration: none;
        }

        .btn-import-export:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .btn-import-export i {
            font-size: 1.2rem;
        }

        /* Statistics Section */
        .statistics-section {
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            border: none;
            margin-bottom: 30px;
            padding: 30px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }

        .stat-card {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            padding: 20px;
            border-radius: 15px;
            text-align: center;
            border-left: 4px solid var(--primary-color);
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--primary-color);
            margin: 10px 0;
        }

        .stat-label {
            font-size: 0.95rem;
            color: var(--text-dark);
            font-weight: 600;
        }

        .stat-icon {
            font-size: 2rem;
            color: var(--primary-color);
            opacity: 0.7;
        }
        
        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .content-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            border: none;
            margin-bottom: 30px;
            animation: slideInUp 0.6s ease forwards;
            transition: all 0.3s ease;
        }
        
        .content-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.15);
        }
        
        .card-header-custom {
            background: white;
            border-bottom: 2px solid #f8f9fa;
            padding: 25px 30px;
        }
        
        .search-controls {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: center;
        }
        
        .search-input {
            flex: 1;
            min-width: 300px;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 12px 20px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        
        .search-input:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(128, 0, 0, 0.25);
            outline: none;
        }
        
        .filter-select {
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 12px 15px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        
        .filter-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(128, 0, 0, 0.25);
            outline: none;
        }
        
        .btn-primary-custom {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-hover));
            border: none;
            color: white !important;
            padding: 10px 20px;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 5px 15px rgba(128, 0, 0, 0.3);
        }
        
        .btn-primary-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(128, 0, 0, 0.4);
            background: linear-gradient(135deg, var(--primary-hover), var(--primary-color));
            color: white !important;
        }
        
        /* Disabled notice for manual operations */
        .btn-disabled-notice {
            background: #fff3cd;
            border: 2px solid #ffc107;
            color: #856404;
            padding: 15px 20px;
            border-radius: 10px;
            font-weight: 600;
            margin-bottom: 20px; /* Adjusted margin */
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .btn-disabled-notice i {
            font-size: 1.2rem;
        }

        .table-container {
            overflow-x: auto;
        }
        
        .table-custom {
            margin-bottom: 0;
        }
        
        .table-custom thead th {
            background: #f8f9fa;
            border: none;
            font-weight: 600;
            color: var(--text-dark);
            padding: 20px 15px;
            font-size: 0.95rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .table-custom tbody td {
            padding: 20px 15px;
            border-top: 1px solid #f0f0f0;
            vertical-align: middle;
        }
        
        .table-custom tbody tr:hover {
            background: #f8f9fa;
        }
        
        .category-badge {
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: white;
            background: var(--primary-color);
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        
        .btn-action {
            width: 35px;
            height: 35px;
            border-radius: 8px;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            font-size: 0.9rem;
        }
        
        .btn-edit {
            background: #e3f2fd;
            color: #1976d2;
        }
        
        .btn-edit:hover {
            background: #1976d2;
            color: white;
            transform: scale(1.1);
        }
        
        .btn-delete {
            background: #ffebee;
            color: #d32f2f;
        }
        
        .btn-delete:hover {
            background: #d32f2f;
            color: white;
            transform: scale(1.1);
        }
        
        .modal-content {
            border-radius: 20px;
            border: none;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
        }
        
        .modal-header {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-hover));
            color: white;
            border-radius: 20px 20px 0 0;
            padding: 25px 30px;
            border-bottom: none;
        }
        
        .modal-title {
            font-weight: 700;
            font-size: 1.5rem;
        }
        
        .btn-close {
            filter: invert(1);
        }
        
        .form-control-custom {
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 12px 15px;
            font-size: 1rem;
            transition: all 0.3s ease;
            accent-color: var(--primary-color);
        }
        
        .form-control-custom:focus {
            outline: none !important;
            border-color: var(--primary-color) !important;
            box-shadow: 0 0 0 0.25rem rgba(128, 0, 0, 0.25) !important;
        }
        
        .alert-custom {
            border-radius: 15px;
            border: none;
            padding: 15px 20px;
            margin-bottom: 25px;
            font-weight: 500;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
        }
        
        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 20px;
            }
            
            .header-section h1 {
                font-size: 2.2rem;
            }
            
            .search-controls {
                flex-direction: column;
                align-items: stretch;
            }
            
            .search-input {
                min-width: auto;
            }

            .action-buttons-grid {
                grid-template-columns: 1fr;
            }

            .import-export-buttons {
                flex-direction: column;
            }

            .btn-import-export {
                width: 100%;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../includes/side_bar.php'; ?>
    <?php include __DIR__ . '/../includes/navbar.php'; ?>
    <?php renderModernAlertSystem(); ?>

    <div class="main-content">
        <div class="header-section">
            <h1><i class="fas fa-cogs me-3"></i>Evaluation Management</h1>
        </div>

        <?php
            $page_success_message = null;
            $page_error_message = null;
            if (isset($_SESSION['success'])) {
                $page_success_message = $_SESSION['success'];
                unset($_SESSION['success']);
            }
            if (isset($_SESSION['error'])) {
                $page_error_message = $_SESSION['error'];
                unset($_SESSION['error']);
            }
        ?>
        
        <?php if (!$phpspreadsheet_available): ?>
            <div class="alert alert-warning alert-custom alert-dismissible fade show" role="alert">
                <strong><i class="fas fa-exclamation-triangle me-2"></i>Warning!</strong> 
                PhpSpreadsheet library or its dependencies are not properly installed. Excel import functionality will not work.
                <br><br>
                <strong>To Fix This Issue:</strong>
                <div class="d-flex gap-2 flex-wrap mt-3 mb-3">
                    <a href="../enable_zip_extension.php" target="_blank" class="btn btn-sm btn-warning">
                        <i class="fas fa-wrench"></i> Enable ZIP Extension
                    </a>
                    <a href="../install_dependencies.php" target="_blank" class="btn btn-sm btn-primary">
                        <i class="fas fa-download"></i> Install Dependencies
                    </a>
                </div>
                <div style="background: #f0f0f0; padding: 15px; border-radius: 8px; margin-top: 15px; font-size: 0.9rem;">
                    <p style="margin-bottom: 10px;"><strong>Quick Steps:</strong></p>
                    <ol style="margin-bottom: 0;">
                        <li>First, ensure the ZIP extension is enabled: <a href="../enable_zip_extension.php">Check ZIP Extension</a></li>
                        <li>Then install PhpSpreadsheet dependencies: <a href="../install_dependencies.php">Install Dependencies</a></li>
                        <li>Refresh this page after installation</li>
                    </ol>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Quick Actions Card (Unchanged) -->
        <div class="quick-actions-card">
            <div class="quick-actions-body">
                <div class="action-buttons-grid">
                    <button type="button" class="action-button" data-bs-toggle="modal" data-bs-target="#setEvaluationModal">
                        <i class="fas fa-calendar-check"></i>
                        <span>Set Evaluation Period</span>
                    </button>
                    <button type="button" class="action-button" data-bs-toggle="modal" data-bs-target="#stopEvaluationModal" 
                            style="background: linear-gradient(135deg, #dc3545, #c82333);" 
                            <?php echo (!in_array($evaluation_phase, ['ongoing'])) ? 'disabled' : ''; ?>>
                        <i class="fas fa-stop-circle"></i>
                        <span>Stop Evaluation</span>
                    </button>
                    <button type="button" class="action-button" data-bs-toggle="modal" data-bs-target="#changeSchoolYearModal" 
                            <?php echo (in_array($evaluation_phase, ['upcoming','ongoing'])) ? 'disabled' : ''; ?>>
                        <i class="fas fa-graduation-cap"></i>
                        <span>Change School Year & Semester</span>
                    </button>
                </div>

                <div class="current-settings">
                    <h5 class="mb-3"><i class="fas fa-info-circle me-2"></i>Current Settings</h5>
                    <div class="setting-item">
                        <span class="setting-label">School Year:</span>
                        <span class="setting-value"><?php echo htmlspecialchars($current_school_year ?: 'Not Set'); ?></span>
                    </div>
                    <div class="setting-item">
                        <span class="setting-label">Semester:</span>
                        <span class="setting-value"><?php echo htmlspecialchars($current_semester ?: 'Not Set'); ?></span>
                    </div>
                    <div class="setting-item">
                        <span class="setting-label">Evaluation Start:</span>
                        <span class="setting-value">
                            <?php 
                                if ($evaluation_start_date && $evaluation_start_date !== '' && strtotime($evaluation_start_date) !== false) {
                                    echo date('M d, Y g:i A', strtotime($evaluation_start_date));
                                } else {
                                    echo 'Not Set';
                                }
                            ?>
                        </span>
                    </div>
                    <div class="setting-item">
                        <span class="setting-label">Evaluation End:</span>
                        <span class="setting-value">
                            <?php 
                                if ($evaluation_end_date && $evaluation_end_date !== '' && strtotime($evaluation_end_date) !== false) {
                                    echo date('M d, Y g:i A', strtotime($evaluation_end_date));
                                } else {
                                    echo 'Not Set';
                                }
                            ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Dedicated Excel Import/Export Section -->
        <div class="evaluation-set-management">
            <div class="eval-set-header">
                <h3 class="eval-set-title">
                    <i class="fas fa-file-excel"></i>Excel/CSV Management - Categories & Questions
                </h3>
            </div>
            <div class="eval-set-body">
                <div class="eval-set-description">
                    <strong><i class="fas fa-info-circle me-2"></i>Import Instructions:</strong>
                    Categories and questions must be imported from an Excel file. The Excel file should have the following columns: <strong>Category Name</strong> and <strong>Question</strong> (or <strong>Question Text</strong>). Each row represents a question, and questions are grouped by their category name. Manual creation or editing is disabled.
                </div>

                <div class="excel-import-section">
                    <button type="button" class="btn-import-export" data-bs-toggle="modal" data-bs-target="#excelImportModal" 
                            <?php echo ($evaluation_phase === 'ongoing') ? 'disabled' : ''; ?>
                            title="<?php echo ($evaluation_phase === 'ongoing') ? 'Cannot import while evaluation is ongoing' : 'Import evaluation categories and questions from Excel'; ?>">
                        <i class="fas fa-file-excel"></i>
                        <span>Import from Excel File</span>
                    </button>
                    <button type="button" class="btn-import-export" data-bs-toggle="modal" data-bs-target="#csvExportModal" 
                            title="Export categories and questions to Excel file">
                        <i class="fas fa-download"></i>
                        <span>Export to Excel File</span>
                    </button>
                </div>
            </div>
        </div>

        

        <!-- Categories Section - Read-only display only -->
        <div class="content-card">
            <div class="card-header-custom">
                <h5 class="mb-0"><i class="fas fa-folder me-2"></i>Evaluation Categories</h5>
            </div>
            <div style="padding: 30px;">
                <div class="btn-disabled-notice">
                    <i class="fas fa-lock"></i>
                    <span>Categories can only be managed through Excel Import. Use the Excel Import section above to add or update questions.</span>
                </div>
                <?php if (empty($categories)): ?>
                    <div class="empty-state">
                        <i class="fas fa-folder-open"></i>
                        <h5>No Categories Found</h5>
                        <p>Import an Excel file to add categories.</p>
                    </div>
                <?php else: ?>
                    <div class="table-container">
                        <table class="table table-custom">
                            <thead>
                                <tr>
                                    <th>Category Name</th>
                                    <th>School Year</th>
                                    <th>Semester</th>
                                    <th>Questions</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($categories as $category): ?>
                                    <?php 
                                        $cat_id = $category['category_id'];
                                        $q_count = count(array_filter($questions, function($q) use ($cat_id) { return $q['category_id'] == $cat_id; }));
                                        $semester_text = ((int)$category['semester'] == 1) ? '1st Semester' : '2nd Semester';
                                    ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($category['category_name']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($category['school_year'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($semester_text); ?></td>
                                        <td><span class="category-badge"><?php echo $q_count; ?> Questions</span></td>
                                        <td><span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Active</span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Questions Section - Read-only display only -->
        <div class="content-card">
            <div class="card-header-custom">
                <h5 class="mb-0"><i class="fas fa-comments me-2"></i>Evaluation Questions</h5>
            </div>
            <div style="padding: 30px;">
                <div class="btn-disabled-notice">
                    <i class="fas fa-lock"></i>
                    <span>Questions can only be managed through Excel Import. Use the Excel Import section above to add or update questions.</span>
                </div>
                <?php if (empty($questions)): ?>
                    <div class="empty-state">
                        <i class="fas fa-comments"></i>
                        <h5>No Questions Found</h5>
                        <p>Import an Excel file to add questions.</p>
                    </div>
                <?php else: ?>
                    <div class="table-container">
                        <table class="table table-custom">
                            <thead>
                                <tr>
                                    <th>Question Text</th>
                                    <th>Category</th>
                                    <th>School Year</th>
                                    <th>Semester</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($questions as $question): ?>
                                    <?php 
                                        $semester_text = ((int)$question['semester'] == 1) ? '1st Semester' : '2nd Semester';
                                    ?>
                                    <tr>
                                        <td title="<?php echo htmlspecialchars($question['question']); ?>"><?php echo htmlspecialchars(substr($question['question'], 0, 50)); ?><?php echo strlen($question['question']) > 50 ? '...' : ''; ?></td>
                                        <td>
                                            <span class="category-badge" style="max-width: 150px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: inline-block;" title="<?php echo htmlspecialchars($question['category_name']); ?>">
                                                <?php echo htmlspecialchars(substr($question['category_name'], 0, 20)); ?><?php echo strlen($question['category_name']) > 20 ? '...' : ''; ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($question['school_year'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($semester_text); ?></td>
                                        <td><span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Active</span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Past Evaluation Categories & Questions Section -->
        <div class="content-card">
            <div class="card-header-custom">
                <h5 class="mb-0"><i class="fas fa-history me-2"></i>Past Evaluation Categories & Questions</h5>
            </div>
            <div style="padding: 30px;">
                <!-- Filter Controls -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 25px;">
                    <div>
                        <label class="form-label"><strong>School Year</strong></label>
                        <select id="pastEvalSchoolYear" class="form-select form-control-custom" onchange="filterPastEvaluations()">
                            <option value="">Select School Year</option>
                            <?php 
                                // Fetch all school years from database
                                $sy_query = "SELECT DISTINCT school_year FROM evaluation_categories ORDER BY school_year DESC";
                                $sy_result = $conn->query($sy_query);
                                if ($sy_result) {
                                    while ($row = $sy_result->fetch_assoc()) {
                                        echo '<option value="' . htmlspecialchars($row['school_year']) . '">' . htmlspecialchars($row['school_year']) . '</option>';
                                    }
                                }
                            ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label"><strong>Semester</strong></label>
                        <select id="pastEvalSemester" class="form-select form-control-custom" onchange="filterPastEvaluations()">
                            <option value="">Select Semester</option>
                            <option value="1">1st Semester </option>
                            <option value="2">2nd Semester</option>
                        </select>
                    </div>
                </div>

                <!-- Display Area -->
                <div id="pastEvalResultsContainer">
                    <div class="empty-state">
                        <i class="fas fa-folder-open"></i>
                        <h5>Select Filters to View Past Evaluations</h5>
                        <p>Choose a school year and semester to view past evaluation data.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Excel Import Modal -->
    <div class="modal fade" id="excelImportModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-file-excel me-2"></i>Import Categories & Questions from Excel</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data" id="excelImportForm">
                    <input type="hidden" name="action" value="import_evaluation_set">
                    <div class="modal-body">
                        <div class="alert alert-info" role="alert">
                            <strong><i class="fas fa-info-circle me-2"></i>Excel File Format:</strong>
                            <ul class="mb-0 mt-2">
                                <li>Row 1 must contain headers: <strong>Category Name</strong> and <strong>Question</strong> (or <strong>Question Text</strong>)</li>
                                <li>Each row represents a question</li>
                                <li>Questions are grouped by their Category Name</li>
                                <li>Supported formats: .xlsx, .xls</li>
                            </ul>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label"><strong>Select Excel File</strong></label>
                            <input type="file" name="import_file" class="form-control form-control-custom" accept=".xlsx,.xls" required>
                            <small class="text-muted">Upload an Excel file (.xlsx or .xls) with categories and questions</small>
                        </div>
                        
                        <div class="alert alert-warning" role="alert">
                            <strong><i class="fas fa-exclamation-triangle me-2"></i>Important:</strong> Existing categories and questions for the same <strong>School Year</strong> and <strong>Semester</strong> will be replaced. Other school years/semesters are preserved for historical reporting.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" style="background: linear-gradient(135deg, var(--primary-color), var(--primary-hover)); border: none; color: white;">
                            <i class="fas fa-upload me-2"></i>Import from Excel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Excel Export Modal -->
    <div class="modal fade" id="csvExportModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-download me-2"></i>Export Categories & Questions to Excel</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="csvExportForm">
                    <input type="hidden" name="action" value="export_csv">
                    <div class="modal-body">
                        <div class="alert alert-info" role="alert">
                            <strong><i class="fas fa-info-circle me-2"></i>Export Information:</strong>
                            <p class="mb-0 mt-2">Export categories and questions to an Excel file (.xlsx). Select the School Year and Semester to export specific data.</p>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label"><strong>School Year</strong></label>
                            <select name="export_school_year" class="form-select form-control-custom" required>
                                <option value="">Select School Year</option>
                                <?php foreach ($available_school_years as $sy): ?>
                                    <option value="<?php echo htmlspecialchars($sy); ?>" <?php echo ($sy === $active_sy) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($sy); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Select the school year to export</small>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label"><strong>Semester</strong></label>
                            <select name="export_semester" class="form-select form-control-custom" required>
                                <option value="">Select Semester</option>
                                <?php foreach ($available_semesters as $sem): ?>
                                    <option value="<?php echo $sem; ?>" <?php echo ($sem === $active_sem) ? 'selected' : ''; ?>>
                                        <?php echo ($sem === 1) ? '1st Semester' : '2nd Semester'; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Select the semester to export</small>
                        </div>
                        
                        <div class="alert alert-success" role="alert">
                            <strong><i class="fas fa-check-circle me-2"></i>Excel Content:</strong>
                            <ul class="mb-0 mt-2" style="font-size: 0.9rem;">
                                <li>Category Name</li>
                                <li>Question Text</li>
                                <li>Professional formatting with headers</li>
                                <li>Frozen header row for easy navigation</li>
                            </ul>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success" style="background: linear-gradient(135deg, #28a745, #218838); border: none; color: white;">
                            <i class="fas fa-download me-2"></i>Export to Excel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Set Evaluation Period Modal -->
    <div class="modal fade" id="setEvaluationModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-calendar-check me-2"></i>Evaluation Period</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- Current Period Display -->
                    <?php if ($evaluation_start_date && $evaluation_start_date !== '' && strtotime($evaluation_start_date) !== false): ?>
                        <div class="alert alert-info" role="alert">
                            <strong><i class="fas fa-info-circle me-2"></i>Current Period:</strong>
                            <div class="mt-2">
                                <strong>Start:</strong> <?php echo date('M d, Y \a\t g:i A', strtotime($evaluation_start_date)); ?>
                            </div>
                            <div>
                                <strong>End:</strong> <?php echo date('M d, Y \a\t g:i A', strtotime($evaluation_end_date)); ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Extend Period Form (shown only if evaluation is ongoing) -->
                    <?php if ($evaluation_phase === 'ongoing'): ?>
                        <form method="POST" id="extendEvaluationForm">
                            <input type="hidden" name="action" value="extend_evaluation">
                            <div class="mb-3">
                                <label class="form-label"><strong>New End Date & Time</strong></label>
                                <input type="datetime-local" name="evaluation_end_date" class="form-control form-control-custom" required>
                                <small class="text-muted">Set a new end date to extend the evaluation period</small>
                            </div>
                        </form>
                    <?php else: ?>
                        <!-- Set Period Form (shown when not ongoing) -->
                        <form method="POST" id="setEvaluationForm">
                            <input type="hidden" name="action" value="set_evaluation_period">
                            <div class="mb-3">
                                <label class="form-label"><strong>Start Date & Time</strong></label>
                                <input type="datetime-local" name="evaluation_start_date" class="form-control form-control-custom" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label"><strong>End Date & Time</strong></label>
                                <input type="datetime-local" name="evaluation_end_date" class="form-control form-control-custom" required>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <?php if ($evaluation_phase === 'ongoing'): ?>
                        <button type="submit" form="extendEvaluationForm" class="btn btn-success">
                            <i class="fas fa-expand me-2"></i>Extend Period
                        </button>
                    <?php else: ?>
                        <button type="submit" form="setEvaluationForm" class="btn btn-primary" style="background: linear-gradient(135deg, var(--primary-color), var(--primary-hover)); border: none; color: white;">
                            <i class="fas fa-save me-2"></i>Set Period
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Stop Evaluation Modal -->
    <div class="modal fade" id="stopEvaluationModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-stop-circle me-2"></i>Stop Evaluation</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="stop_evaluation_now">
                    <div class="modal-body">
                        <p>Are you sure you want to stop the current evaluation? This will immediately end the evaluation period.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-stop-circle me-2"></i>Stop Evaluation
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Change School Year Modal -->
    <div class="modal fade" id="changeSchoolYearModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-graduation-cap me-2"></i>Change School Year & Semester</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="change_school_year_semester">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label"><strong>School Year</strong></label>
                            <select name="school_year" class="form-control form-control-custom" required>
                                <option value="">Select School Year</option>
                                <?php for ($year = 2025; $year < 2049; $year++): ?>
                                    <option value="<?php echo $year; ?>-<?php echo $year + 1; ?>"><?php echo $year; ?>-<?php echo $year + 1; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label"><strong>Semester</strong></label>
                            <select name="semester" class="form-control form-control-custom" required>
                                <option value="">Select Semester</option>
                                <option value="1st Semester">1st Semester</option>
                                <option value="2nd Semester">2nd Semester</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" style="background: linear-gradient(135deg, var(--primary-color), var(--primary-hover)); border: none; color: white;">
                            <i class="fas fa-save me-2"></i>Update
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const successMessage = <?php echo json_encode($page_success_message); ?>;
            const errorMessage = <?php echo json_encode($page_error_message); ?>;

            if (successMessage) {
                showModernAlert('success', 'Success', successMessage, { autoCloseMs: 3000 });
            } else if (errorMessage) {
                showModernAlert('error', 'Error', errorMessage);
            }
        });

        // Disable manual CRUD operations on the client side by preventing modal opening
        document.addEventListener('click', function(e) {
            if (e.target.closest('[data-bs-target="#addCategoryModal"]') || 
                e.target.closest('[data-bs-target="#editCategoryModal"]') || 
                e.target.closest('[data-bs-target="#addQuestionModal"]') || 
                e.target.closest('[data-bs-target="#editQuestionModal"]')) {
                
                e.preventDefault(); // Prevent the modal from opening
                
                // Optionally, show a user-friendly message (though server-side validation is primary)
                // alert('Manual category and question management is disabled. Please use Import/Export functionality.');
            }
        });

        // Search functionality
        document.querySelectorAll('.search-input').forEach(input => {
            input.addEventListener('keyup', function() {
                const searchText = this.value.toLowerCase();
                const table = this.closest('.content-card').querySelector('table');
                if (table) {
                    table.querySelectorAll('tbody tr').forEach(row => {
                        const text = row.textContent.toLowerCase();
                        row.style.display = text.includes(searchText) ? '' : 'none';
                    });
                }
            });
        });

        // Toggle category expansion
        function toggleCategory(element) {
            const icon = element.querySelector('.fas');
            const questionsDiv = element.parentElement.querySelector('.category-questions');
            
            if (questionsDiv.style.display === 'none') {
                questionsDiv.style.display = 'block';
                icon.style.transform = 'rotate(0deg)';
            } else {
                questionsDiv.style.display = 'none';
                icon.style.transform = 'rotate(-90deg)';
            }
        }

        // Filter Past Evaluations (auto-triggered on dropdown change)
        function filterPastEvaluations() {
            const schoolYear = document.getElementById('pastEvalSchoolYear').value;
            const semester = document.getElementById('pastEvalSemester').value;

            // Clear results if either dropdown is empty
            const resultsContainer = document.getElementById('pastEvalResultsContainer');
            if (!schoolYear || !semester) {
                resultsContainer.innerHTML = '<div class="empty-state"><i class="fas fa-folder-open"></i><h5>Select Filters to View Past Evaluations</h5><p>Choose a school year and semester to view past evaluation data.</p></div>';
                return;
            }

            // Show loading state
            resultsContainer.innerHTML = '<div class="empty-state"><i class="fas fa-spinner fa-spin"></i><h5>Loading...</h5></div>';

            // Fetch data via AJAX
            const formData = new FormData();
            formData.append('action', 'get_past_evaluations');
            formData.append('school_year', schoolYear);
            formData.append('semester', semester);

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.html) {
                    resultsContainer.innerHTML = data.html;
                } else if (data.error) {
                    resultsContainer.innerHTML = '<div class="alert alert-warning"><i class="fas fa-exclamation-circle me-2"></i>' + data.error + '</div>';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                resultsContainer.innerHTML = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i>Error loading data</div>';
            });
        }
        
        // Animate content cards on page load with staggered effect
        document.addEventListener('DOMContentLoaded', function() {
            const contentCards = document.querySelectorAll('.content-card');
            contentCards.forEach((card, index) => {
                card.style.animationDelay = (index * 0.1) + 's';
            });
        });
    </script>
</body>
</html>
