<?php
/**
 * PhpSpreadsheet Installation Helper
 * This script helps install PhpSpreadsheet for Excel import functionality
 */

// Check if PhpSpreadsheet is already installed
$phpspreadsheet_installed = false;
$install_paths = [
    __DIR__ . '/vendor/phpoffice/phpspreadsheet/src/PhpSpreadsheet/IOFactory.php',
    __DIR__ . '/includes/phpspreadsheet/src/PhpSpreadsheet/IOFactory.php'
];

foreach ($install_paths as $path) {
    if (file_exists($path)) {
        $phpspreadsheet_installed = true;
        break;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Install PhpSpreadsheet</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 50px 20px;
        }
        .install-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            padding: 40px;
            max-width: 800px;
            margin: 0 auto;
        }
        .step {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            border-left: 4px solid #667eea;
        }
        .code-block {
            background: #2d2d2d;
            color: #f8f8f2;
            padding: 15px;
            border-radius: 5px;
            font-family: 'Courier New', monospace;
            margin: 10px 0;
            overflow-x: auto;
        }
        .success {
            color: #28a745;
            font-weight: bold;
        }
        .warning {
            color: #ffc107;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="install-card">
        <h1 class="mb-4"><i class="fas fa-download"></i> PhpSpreadsheet Installation Guide</h1>
        
        <?php if ($phpspreadsheet_installed): ?>
            <div class="alert alert-success">
                <strong>✓ PhpSpreadsheet is already installed!</strong> You can use the Excel import functionality.
            </div>
        <?php else: ?>
            <div class="alert alert-warning">
                <strong>⚠ PhpSpreadsheet is not installed.</strong> Please follow the installation steps below.
            </div>
        <?php endif; ?>
        
        <h3>Installation Methods</h3>
        
        <div class="step">
            <h4>Method 1: Using Composer (Recommended)</h4>
            <p>If you have Composer installed globally:</p>
            <div class="code-block">
                cd c:\xampp\htdocs\capstone<br>
                composer require phpoffice/phpspreadsheet
            </div>
            <p><strong>Don't have Composer?</strong> Download it from <a href="https://getcomposer.org/download/" target="_blank">getcomposer.org</a></p>
        </div>
        
        <div class="step">
            <h4>Method 2: Download Composer.phar</h4>
            <p>If Composer is not globally installed:</p>
            <ol>
                <li>Download <code>composer.phar</code> from <a href="https://getcomposer.org/download/" target="_blank">getcomposer.org</a></li>
                <li>Place it in: <code>c:\xampp\htdocs\capstone\composer.phar</code></li>
                <li>Open Command Prompt in the project directory</li>
                <li>Run: <div class="code-block">php composer.phar require phpoffice/phpspreadsheet</div></li>
            </ol>
        </div>
        
        <div class="step">
            <h4>Method 3: Manual Installation</h4>
            <ol>
                <li>Download PhpSpreadsheet from: <a href="https://github.com/PHPOffice/PhpSpreadsheet/releases/latest" target="_blank">GitHub Releases</a></li>
                <li>Extract the ZIP file</li>
                <li>Copy the <code>src</code> folder to:
                    <div class="code-block">c:\xampp\htdocs\capstone\includes\phpspreadsheet\src\</div>
                </li>
                <li>The final structure should be:
                    <div class="code-block">
                        capstone/<br>
                        └── includes/<br>
                            └── phpspreadsheet/<br>
                                └── src/<br>
                                    └── PhpSpreadsheet/<br>
                                        └── IOFactory.php
                    </div>
                </li>
            </ol>
        </div>
        
        <div class="step">
            <h4>Required PHP Extensions</h4>
            <p>Make sure these PHP extensions are enabled in your <code>php.ini</code>:</p>
            <ul>
                <li><code>php_zip</code> - for reading Excel files</li>
                <li><code>php_xml</code> - for XML processing</li>
                <li><code>php_gd2</code> - for image processing (optional)</li>
            </ul>
            <p>In XAMPP, these are usually enabled by default. Check your <code>php.ini</code> file in <code>c:\xampp\php\</code></p>
        </div>
        
        <div class="step">
            <h4>Verification</h4>
            <p>After installation:</p>
            <ol>
                <li>Refresh this page to check if PhpSpreadsheet is detected</li>
                <li>Go to <strong>Admin → Evaluation Management</strong></li>
                <li>The warning message should disappear</li>
            </ol>
        </div>
        
        <div class="mt-4">
            <a href="admin/evaluation_management.php" class="btn btn-primary">Go to Evaluation Management</a>
            <button onclick="location.reload()" class="btn btn-secondary">Refresh This Page</button>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
