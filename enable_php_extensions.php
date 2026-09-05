<?php
/**
 * PHP Extensions Checker and Enabler Guide
 * This script checks which PHP extensions are enabled and provides instructions
 */

// Get PHP version and configuration
$php_version = phpversion();
$php_ini_path = php_ini_loaded_file();
$extensions_dir = ini_get('extension_dir');

// Check required extensions
$required_extensions = [
    'gd' => 'GD Library (for image processing)',
    'zip' => 'ZipArchive (for reading Excel files)',
    'xml' => 'XML (for XML processing)',
    'mbstring' => 'Multibyte String (for string handling)'
];

$extensions_status = [];
foreach ($required_extensions as $ext => $description) {
    $extensions_status[$ext] = [
        'enabled' => extension_loaded($ext),
        'description' => $description
    ];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHP Extensions Checker</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 50px 20px;
        }
        .check-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            padding: 40px;
            max-width: 900px;
            margin: 0 auto;
        }
        .extension-item {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 15px;
            border-left: 4px solid #dee2e6;
        }
        .extension-item.enabled {
            background: #d4edda;
            border-left-color: #28a745;
        }
        .extension-item.disabled {
            background: #f8d7da;
            border-left-color: #dc3545;
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
        .step-box {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            border-left: 4px solid #667eea;
        }
    </style>
</head>
<body>
    <div class="check-card">
        <h1 class="mb-4"><i class="fas fa-check-circle"></i> PHP Extensions Status</h1>
        
        <div class="alert alert-info">
            <strong>PHP Version:</strong> <?php echo htmlspecialchars($php_version); ?><br>
            <strong>php.ini Location:</strong> <?php echo htmlspecialchars($php_ini_path ?: 'Not found'); ?><br>
            <strong>Extensions Directory:</strong> <?php echo htmlspecialchars($extensions_dir ?: 'Not set'); ?>
        </div>
        
        <h3 class="mt-4 mb-3">Required Extensions for PhpSpreadsheet</h3>
        
        <?php
        $all_enabled = true;
        foreach ($extensions_status as $ext => $status):
            $all_enabled = $all_enabled && $status['enabled'];
        ?>
            <div class="extension-item <?php echo $status['enabled'] ? 'enabled' : 'disabled'; ?>">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <strong><?php echo strtoupper($ext); ?></strong> - <?php echo htmlspecialchars($status['description']); ?>
                    </div>
                    <div>
                        <?php if ($status['enabled']): ?>
                            <span class="badge bg-success"><i class="fas fa-check"></i> Enabled</span>
                        <?php else: ?>
                            <span class="badge bg-danger"><i class="fas fa-times"></i> Disabled</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        
        <?php if ($all_enabled): ?>
            <div class="alert alert-success mt-4">
                <strong>✓ All required extensions are enabled!</strong> You can now install PhpSpreadsheet using Composer.
            </div>
        <?php else: ?>
            <div class="alert alert-warning mt-4">
                <strong>⚠ Some extensions are missing.</strong> Please enable them in your php.ini file.
            </div>
            
            <h3 class="mt-4 mb-3">How to Enable Extensions in XAMPP</h3>
            
            <div class="step-box">
                <h4>Step 1: Locate php.ini</h4>
                <p>Open the php.ini file located at:</p>
                <div class="code-block"><?php echo htmlspecialchars($php_ini_path ?: 'c:\\xampp\\php\\php.ini'); ?></div>
                <p><strong>Note:</strong> Make sure you're editing the correct php.ini file (the one shown above).</p>
            </div>
            
            <div class="step-box">
                <h4>Step 2: Enable Extensions</h4>
                <p>In php.ini, find the following lines and remove the semicolon (;) at the beginning:</p>
                <div class="code-block">
                    <?php if (!$extensions_status['gd']['enabled']): ?>
;extension=gd<br>
                    <?php endif; ?>
                    <?php if (!$extensions_status['zip']['enabled']): ?>
;extension=zip<br>
                    <?php endif; ?>
                    <?php if (!$extensions_status['xml']['enabled']): ?>
;extension=xml<br>
                    <?php endif; ?>
                    <?php if (!$extensions_status['mbstring']['enabled']): ?>
;extension=mbstring<br>
                    <?php endif; ?>
                </div>
                <p>Change them to (remove the semicolon):</p>
                <div class="code-block">
                    <?php if (!$extensions_status['gd']['enabled']): ?>
extension=gd<br>
                    <?php endif; ?>
                    <?php if (!$extensions_status['zip']['enabled']): ?>
extension=zip<br>
                    <?php endif; ?>
                    <?php if (!$extensions_status['xml']['enabled']): ?>
extension=xml<br>
                    <?php endif; ?>
                    <?php if (!$extensions_status['mbstring']['enabled']): ?>
extension=mbstring<br>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="step-box">
                <h4>Step 3: Restart Apache</h4>
                <ol>
                    <li>Open XAMPP Control Panel</li>
                    <li>Stop Apache (if running)</li>
                    <li>Start Apache again</li>
                </ol>
                <p><strong>Important:</strong> You must restart Apache for the changes to take effect.</p>
            </div>
            
            <div class="step-box">
                <h4>Step 4: Verify</h4>
                <p>After restarting Apache, refresh this page to verify that all extensions are now enabled.</p>
                <button onclick="location.reload()" class="btn btn-primary">
                    <i class="fas fa-sync"></i> Refresh This Page
                </button>
            </div>
            
            <div class="alert alert-info mt-4">
                <strong>Alternative:</strong> If you can't enable extensions, you can still use manual installation of PhpSpreadsheet. 
                The library will work, but some features (like image processing) may not be available.
            </div>
        <?php endif; ?>
        
        <div class="mt-4">
            <a href="admin/evaluation_management.php" class="btn btn-primary">
                <i class="fas fa-arrow-left"></i> Back to Evaluation Management
            </a>
            <a href="install_phpspreadsheet.php" class="btn btn-secondary">
                <i class="fas fa-book"></i> Installation Guide
            </a>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
