<?php
session_start();

// Check if user is logged in and has admin privileges
if (!isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    header("Location: ../index.php");  
    exit();
}

if ($_SESSION['role'] !== 'admin') {
    $_SESSION['error'] = 'Only admin accounts can download import templates.';
    header('Location: ../index.php');
    exit();
}

require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\IOFactory;

// Create a new spreadsheet
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Import Template');

// Set column widths
$sheet->getColumnDimension('A')->setWidth(15);
$sheet->getColumnDimension('B')->setWidth(15);
$sheet->getColumnDimension('C')->setWidth(25);
$sheet->getColumnDimension('D')->setWidth(12);
$sheet->getColumnDimension('E')->setWidth(12);
$sheet->getColumnDimension('F')->setWidth(12);
$sheet->getColumnDimension('G')->setWidth(12);

// Add title
$sheet->setCellValue('A1', 'USER IMPORT TEMPLATE');
$sheet->mergeCells('A1:G1');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->setColor(new Color('FFFFFFFF'));
$sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF800000');
$sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
$sheet->getRowDimension(1)->setRowHeight(25);

// Add instructions section
$row = 2;
$sheet->setCellValue('A2', 'INSTRUCTIONS:');
$sheet->getStyle('A2')->getFont()->setBold(true)->setSize(11)->setColor(new Color('FF800000'));
$sheet->mergeCells('A2:G2');

$instructions = [
    '• Fill out all REQUIRED fields (marked with *) for each user',
    '• Role must be: admin, registrar, teacher, or student (case-insensitive)',
    '• Email must be unique and in valid format (user@domain.com)',
    '• Students REQUIRE: Strand (abbreviation) and Year Level (11 or 12)',
    '• Teachers REQUIRE: Strand (abbreviation)',
    '• Valid Strands: ABM (Accountancy Business and Management), HUMSS (Humanities and Social Sciences), STEM (Science Technology Engineering Mathematics), ICT (Information and Communication Technology)',
    '• Status defaults to "active" if left empty',
    '• School Year, Semester, and Section are auto-assigned from system settings'
];

foreach ($instructions as $instruction) {
    $row++;
    $sheet->setCellValue('A' . $row, $instruction);
    $sheet->getStyle('A' . $row)->getFont()->setSize(9)->setItalic(true);
    $sheet->mergeCells('A' . $row . ':G' . $row);
}

// Add blank row
$row += 2;

// Add headers
$headers = ['First Name*', 'Last Name*', 'Email*', 'Role*', 'Status', 'Strand', 'Year Level'];
$col = 'A';
foreach ($headers as $header) {
    $sheet->setCellValue($col . $row, $header);
    $sheet->getStyle($col . $row)->getFont()->setBold(true)->setColor(new Color('FFFFFFFF'))->setSize(10);
    $sheet->getStyle($col . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF800000');
    $sheet->getStyle($col . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
    $col++;
}
$sheet->getRowDimension($row)->setRowHeight(20);

// Add sample data for each role
$row++;
$samples = [
    // Admin
    [
        'first_name' => 'Juan',
        'last_name' => 'Admin',
        'email' => 'juan.admin@school.com',
        'role' => 'admin',
        'status' => 'active',
        'strand' => '',
        'year_level' => ''
    ],
    // Registrar
    [
        'first_name' => 'Maria',
        'last_name' => 'Registrar',
        'email' => 'maria.registrar@school.com',
        'role' => 'registrar',
        'status' => 'active',
        'strand' => '',
        'year_level' => ''
    ],
    // Teacher - Science Technology Engineering Mathematics
    [
        'first_name' => 'Robert',
        'last_name' => 'Santos',
        'email' => 'robert.santos@school.com',
        'role' => 'teacher',
        'status' => 'active',
        'strand' => 'STEM',
        'year_level' => ''
    ],
    // Teacher - Accountancy Business and Management
    [
        'first_name' => 'Ana',
        'last_name' => 'Rodriguez',
        'email' => 'ana.rodriguez@school.com',
        'role' => 'teacher',
        'status' => 'active',
        'strand' => 'ABM',
        'year_level' => ''
    ],
    // Student - Science Technology Engineering Mathematics - Grade 11
    [
        'first_name' => 'Miguel',
        'last_name' => 'Reyes',
        'email' => 'miguel.reyes@school.com',
        'role' => 'student',
        'status' => 'active',
        'strand' => 'STEM',
        'year_level' => '11'
    ],
    // Student - Science Technology Engineering Mathematics - Grade 12
    [
        'first_name' => 'Clara',
        'last_name' => 'Dela Cruz',
        'email' => 'clara.delacruz@school.com',
        'role' => 'student',
        'status' => 'active',
        'strand' => 'STEM',
        'year_level' => '12'
    ],
    // Student - Accountancy Business and Management - Grade 11
    [
        'first_name' => 'Antonio',
        'last_name' => 'Mercado',
        'email' => 'antonio.mercado@school.com',
        'role' => 'student',
        'status' => 'active',
        'strand' => 'ABM',
        'year_level' => '11'
    ],
    // Student - Humanities and Social Sciences - Grade 11 - Inactive example
    [
        'first_name' => 'Rosa',
        'last_name' => 'Garcia',
        'email' => 'rosa.garcia@school.com',
        'role' => 'student',
        'status' => 'inactive',
        'strand' => 'HUMSS',
        'year_level' => '11'
    ]
];

foreach ($samples as $index => $sample) {
    $col = 'A';
    foreach (['first_name', 'last_name', 'email', 'role', 'status', 'strand', 'year_level'] as $field) {
        $value = $sample[$field];
        $sheet->setCellValue($col . $row, $value);
        $col++;
    }
    
    // Apply borders and styles to entire row
    $endCol = 'G';
    $range = 'A' . $row . ':' . $endCol . $row;
    
    $borderStyle = [
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['argb' => 'FFD3D3D3'],
            ],
        ],
    ];
    $sheet->getStyle($range)->applyFromArray($borderStyle);
    
    // Alternate row colors for readability
    if ($index % 2 === 0) {
        $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF8F9FA');
    }
    
    // Center align role, status, year level
    $sheet->getStyle('D' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER); // role
    $sheet->getStyle('E' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER); // status
    $sheet->getStyle('G' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER); // year_level
    
    $sheet->getRowDimension($row)->setRowHeight(18);
    $row++;
}

// Add notes section
$row += 1;
$sheet->setCellValue('A' . $row, 'NOTES:');
$sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(10)->setColor(new Color('FF800000'));
$sheet->mergeCells('A' . $row . ':G' . $row);

$notes = [
    'Valid Strands: ABM (Accountancy Business and Management) | HUMSS (Humanities and Social Sciences) | STEM (Science Technology Engineering Mathematics) | ICT (Information and Communication Technology)',
    'Valid Year Levels: 11, 12 (for students only)',
    'Valid Roles: admin, registrar, teacher, student',
    'Valid Status: active, inactive (defaults to active if empty)',
    'Section is auto-assigned based on Strand and Year Level combination'
];

foreach ($notes as $note) {
    $row++;
    $sheet->setCellValue('A' . $row, '• ' . $note);
    $sheet->getStyle('A' . $row)->getFont()->setSize(9)->setItalic(true)->setColor(new Color('FF666666'));
    $sheet->mergeCells('A' . $row . ':G' . $row);
}

// Clear output buffers
while (ob_get_level() > 0) {
    ob_end_clean();
}

// Set headers for download
$filename = 'User_Import_Template_' . date('Y-m-d') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Write and output
$writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
$writer->save('php://output');
exit();
?>
