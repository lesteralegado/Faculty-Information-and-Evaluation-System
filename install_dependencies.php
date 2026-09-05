<?php
/**
 * PhpSpreadsheet Installation Helper
 * Installs required dependencies via Composer
 */

// Check if Composer is available
$composer_paths = [
    'C:\\ProgramData\\ComposerSetup\\bin\\composer.bat',
    'C:\\xampp\\php\\composer.phar',
    'C:\\composer.phar',
    'composer', // System PATH
    'composer.bat' // System PATH
];

$composer_found = false;
$composer_cmd = '';

foreach ($composer_paths as $path) {
    if (file_exists($path) || shell_exec("where $path 2>nul")) {
        $composer_found = true;
        $composer_cmd = $path;
        break;
    }
}

$installation_attempted = false;
$installation_success = false;
$installation_output = '';
$installation_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'install') {
    $installation_attempted = true;
    
    if (!$composer_found) {
        $installation_error = "Composer not found on your system. Please install Composer first.";
    } else {
        $capstone_dir = __DIR__;
        
        // Change to capstone directory and run composer require
        $cmd = "cd \"$capstone_dir\" && composer require phpoffice/phpspreadsheet 2>&1";
        
        $output = shell_exec($cmd);
        $installation_output = $output;
        
        // Check if installation was successful
        if (strpos($output, 'successfully') !== false || file_exists($capstone_dir . '/vendor/phpoffice/phpspreadsheet/src/PhpSpreadsheet.php')) {
            $installation_success = true;
        } else {
            $installation_error = "Installation may have failed. Check the output above.";
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PhpSpreadsheet Installation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            padding: 40px 20px;
            min-height: 100vh;
        }
        .container-custom {
            max-width: 1000px;
            background: white;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
        }
        h1 {
            color: #800000;
            margin-bottom: 30px;
            font-weight: 700;
        }
        .status-box {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
            border-left: 4px solid #ffc107;
        }
        .step {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            border-left: 4px solid #800000;
        }
        .step h3 {
            color: #800000;
            margin-bottom: 15px;
            font-weight: 600;
        }
        code {
            background: #2c3e50;
            color: #ecf0f1;
            padding: 15px;
            border-radius: 8px;
            display: block;
            overflow-x: auto;
            margin: 15px 0;
            font-size: 0.9rem;
            line-height: 1.6;
        }
        .btn-custom {
            background: #800000;
            color: white;
            padding: 12px 30px;
            border-radius: 10px;
            border: none;
            font-weight: 600;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        .btn-custom:hover {
            background: #a00000;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(128, 0, 0, 0.3);
            color: white;
        }
        .success-box {
            background: #d4edda;
            border: 2px solid #28a745;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
        }
        .error-box {
            background: #f8d7da;
            border: 2px solid #dc3545;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
        }
        .output-box {
            background: #2c3e50;
            color: #ecf0f1;
            padding: 20px;
            border-radius: 10px;
            overflow-x: auto;
            font-family: monospace;
            font-size: 0.85rem;
            line-height: 1.6;
            max-height: 400px;
            overflow-y: auto;
            margin-bottom: 20px;
        }
        .composer-status {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-weight: 600;
        }
        .composer-found {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .composer-not-found {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
    </style>
</head>
<body>
    <div class="container-custom">
        <h1><i class="fas fa-download me-2"></i>PhpSpreadsheet Installation</h1>

        <?php if ($installation_success): ?>
            <div class="success-box">
                <strong><i class="fas fa-check-circle me-2"></i>Installation Successful!</strong><br>
                PhpSpreadsheet has been installed successfully. You can now return to the Evaluation Management page.
                <a href="admin/evaluation_management.php" class="btn btn-custom" style="margin-top: 15px;">Go to Evaluation Management</a>
            </div>
        <?php endif; ?>

        <?php if ($installation_attempted && !$installation_success && !empty($installation_error)): ?>
            <div class="error-box">
                <strong><i class="fas fa-exclamation-circle me-2"></i>Installation Error!</strong><br>
                <?php echo htmlspecialchars($installation_error); ?>
            </div>
        <?php endif; ?>

        <?php if ($installation_attempted && !empty($installation_output)): ?>
            <div>
                <h4>Installation Output:</h4>
                <div class="output-box">
                    <?php echo htmlspecialchars($installation_output); ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="status-box">
            <strong>Composer Status:</strong><br>
            <?php if ($composer_found): ?>
                <div class="composer-status composer-found">
                    <i class="fas fa-check-circle me-2"></i> Composer is available: <?php echo htmlspecialchars($composer_cmd); ?>
                </div>
                <p style="margin: 10px 0 0 0; color: #155724;">You can proceed with the installation.</p>
            <?php else: ?>
                <div class="composer-status composer-not-found">
                    <i class="fas fa-times-circle me-2"></i> Composer is not detected on your system
                </div>
                <p style="margin: 10px 0 0 0; color: #721c24;">You need to install Composer first. Follow the steps below:</p>
            <?php endif; ?>
        </div>

        <?php if ($composer_found): ?>
            <div class="step">
                <h3><i class="fas fa-1 me-2"></i> Install PhpSpreadsheet via Composer</h3>
                <p>Click the button below to automatically install PhpSpreadsheet and all its dependencies:</p>
                <form method="POST">
                    <input type="hidden" name="action" value="install">
                    <button type="submit" class="btn-custom">
                        <i class="fas fa-download me-2"></i> Install PhpSpreadsheet
                    </button>
                </form>
            </div>
        <?php else: ?>
            <div class="step">
                <h3><i class="fas fa-1 me-2"></i> Install Composer (Required)</h3>
                <p>PhpSpreadsheet requires Composer to manage dependencies. Follow these steps:</p>
                <ol>
                    <li>Visit <a href="https://getcomposer.org/download/" target="_blank">https://getcomposer.org/download/</a></li>
                    <li>Download the Windows Installer (Composer-Setup.exe)</li>
                    <li>Run the installer and follow the prompts</li>
                    <li>When asked to find php.exe, point it to: <code style="display: inline;">C:\xampp\php\php.exe</code></li>
                    <li>Complete the installation and restart your computer</li>
                    <li>Refresh this page to verify Composer was installed</li>
                </ol>
            </div>

            <div class="step">
                <h3><i class="fas fa-2 me-2"></i> Manual Installation (Alternative)</h3>
                <p>If you prefer to install without Composer, you can manually run the command in Command Prompt:</p>
                <code>cd C:\xampp\htdocs\capstone && composer require phpoffice/phpspreadsheet</code>
                <p style="margin-top: 15px;"><strong>Note:</strong> Composer must be installed first (Step 1).</p>
            </div>

            <div class="step">
                <h3><i class="fas fa-3 me-2"></i> Verify Installation</h3>
                <p>After installing Composer, refresh this page to see if it's detected, then click the installation button.</p>
            </div>
        <?php endif; ?>

        <div class="step">
            <h3><i class="fas fa-info-circle me-2"></i> What is Composer?</h3>
            <p>Composer is a PHP dependency manager that automatically downloads and installs required packages. PhpSpreadsheet depends on several packages that Composer helps manage. It's the standard way to manage PHP packages.</p>
        </div>

        <div class="step">
            <h3><i class="fas fa-arrow-left me-2"></i> Return to Admin Panel</h3>
            <p>Once installation is complete, you can return to the Evaluation Management page:</p>
            <a href="admin/evaluation_management.php" class="btn-custom">Go to Evaluation Management</a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
