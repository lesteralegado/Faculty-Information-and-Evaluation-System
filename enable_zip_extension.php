<?php
/**
 * PHP Zip Extension Enablement Guide
 * This file provides instructions for enabling the zip extension in XAMPP
 */

// Check current extension status
$zip_enabled = extension_loaded('zip');
$gd_enabled = extension_loaded('gd');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enable ZIP Extension - XAMPP Setup</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            padding: 40px 20px;
            min-height: 100vh;
        }
        .container-custom {
            max-width: 900px;
            background: white;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
        }
        .status-badge {
            padding: 10px 20px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 1.1rem;
        }
        .status-enabled {
            background: #d4edda;
            color: #155724;
        }
        .status-disabled {
            background: #f8d7da;
            color: #721c24;
        }
        .instruction-step {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            border-left: 4px solid #800000;
        }
        .step-number {
            background: #800000;
            color: white;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            margin-bottom: 15px;
        }
        code {
            background: #2c3e50;
            color: #ecf0f1;
            padding: 15px;
            border-radius: 8px;
            display: block;
            overflow-x: auto;
            margin: 15px 0;
            font-size: 0.95rem;
            line-height: 1.6;
        }
        .warning-box {
            background: #fff3cd;
            border: 2px solid #ffc107;
            padding: 20px;
            border-radius: 10px;
            margin: 20px 0;
        }
        .success-box {
            background: #d4edda;
            border: 2px solid #28a745;
            padding: 20px;
            border-radius: 10px;
            margin: 20px 0;
        }
        h2 {
            color: #800000;
            margin-bottom: 30px;
            font-weight: 700;
        }
        h3 {
            color: #2c3e50;
            margin-top: 30px;
            margin-bottom: 20px;
            font-weight: 600;
        }
        .btn-custom {
            background: #800000;
            color: white;
            padding: 12px 30px;
            border-radius: 10px;
            text-decoration: none;
            display: inline-block;
            font-weight: 600;
            transition: all 0.3s ease;
            margin-top: 20px;
        }
        .btn-custom:hover {
            background: #a00000;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(128, 0, 0, 0.3);
        }
    </style>
</head>
<body>
    <div class="container-custom">
        <h1><i class="fas fa-cogs"></i> PHP ZIP Extension Setup</h1>
        
        <h2>Current Status</h2>
        <div style="margin-bottom: 30px;">
            <p>
                <strong>ZIP Extension:</strong>
                <span class="status-badge <?php echo $zip_enabled ? 'status-enabled' : 'status-disabled'; ?>">
                    <?php echo $zip_enabled ? '✓ ENABLED' : '✗ DISABLED'; ?>
                </span>
            </p>
            <p>
                <strong>GD Extension (Image support):</strong>
                <span class="status-badge <?php echo $gd_enabled ? 'status-enabled' : 'status-disabled'; ?>">
                    <?php echo $gd_enabled ? '✓ ENABLED' : '✗ DISABLED'; ?>
                </span>
            </p>
        </div>

        <?php if (!$zip_enabled): ?>
        <div class="warning-box">
            <strong><i class="fas fa-exclamation-triangle"></i> Action Required:</strong>
            The ZIP extension is not enabled. Follow the steps below to enable it.
        </div>

        <h3>Steps to Enable ZIP Extension in XAMPP (Windows)</h3>

        <div class="instruction-step">
            <div class="step-number">1</div>
            <h5>Locate php.ini file</h5>
            <p>Open File Explorer and navigate to your XAMPP installation directory (usually <code style="display: inline; padding: 2px 5px;">C:\xampp</code>). The php.ini file is located in the <code style="display: inline; padding: 2px 5px;">php</code> subdirectory:</p>
            <code>C:\xampp\php\php.ini</code>
        </div>

        <div class="instruction-step">
            <div class="step-number">2</div>
            <h5>Open php.ini with a text editor</h5>
            <p>Right-click on <code style="display: inline; padding: 2px 5px;">php.ini</code> and select "Open with" → "Notepad" (or your preferred text editor)</p>
        </div>

        <div class="instruction-step">
            <div class="step-number">3</div>
            <h5>Find and uncomment the zip extension</h5>
            <p>Press <kbd>Ctrl+F</kbd> to open the Find dialog and search for:</p>
            <code>;extension=zip</code>
            <p>You should find a line that looks like:</p>
            <code>;extension=zip</code>
            <p>Remove the semicolon (<strong>;</strong>) at the beginning to enable it:</p>
            <code>extension=zip</code>
        </div>

        <div class="instruction-step">
            <div class="step-number">4</div>
            <h5>Also enable GD extension (for image support)</h5>
            <p>While you're in php.ini, find and uncomment the GD extension as well:</p>
            <code>;extension=gd</code>
            <p>Change it to:</p>
            <code>extension=gd</code>
        </div>

        <div class="instruction-step">
            <div class="step-number">5</div>
            <h5>Save the file</h5>
            <p>Press <kbd>Ctrl+S</kbd> to save, then close the text editor</p>
        </div>

        <div class="instruction-step">
            <div class="step-number">6</div>
            <h5>Restart Apache and PHP</h5>
            <p>Open XAMPP Control Panel and:</p>
            <ul>
                <li>Click <strong>Stop</strong> on the Apache module</li>
                <li>Wait 3-5 seconds</li>
                <li>Click <strong>Start</strong> on the Apache module</li>
            </ul>
            <p>Apache will restart with the new PHP configuration</p>
        </div>

        <div class="instruction-step">
            <div class="step-number">7</div>
            <h5>Verify the extension is loaded</h5>
            <p>Refresh this page in your browser. The status above should change to <span style="background: #d4edda; padding: 2px 8px; border-radius: 5px;">✓ ENABLED</span></p>
        </div>

        <div class="success-box" style="display: none;" id="next-steps">
            <strong><i class="fas fa-check-circle"></i> Extension Enabled!</strong><br>
            Once the ZIP extension is enabled, you can return to the Evaluation Management page and import Excel files.
            <a href="admin/evaluation_management.php" class="btn-custom">Go to Evaluation Management</a>
        </div>

        <?php else: ?>
        <div class="success-box">
            <strong><i class="fas fa-check-circle"></i> ZIP Extension is Enabled!</strong><br>
            Your system is ready to use Excel import functionality. Return to the Evaluation Management page and try importing your evaluation set.
            <a href="admin/evaluation_management.php" class="btn-custom">Go to Evaluation Management</a>
        </div>
        <?php endif; ?>

        <h3>Troubleshooting</h3>
        <div class="instruction-step">
            <h5>If the extension still doesn't appear enabled after restart:</h5>
            <ul>
                <li><strong>Check for typos:</strong> Make sure you wrote <code style="display: inline; padding: 2px 5px;">extension=zip</code> exactly (no extra spaces)</li>
                <li><strong>Check php.ini location:</strong> Make sure you're editing the correct php.ini (in <code style="display: inline; padding: 2px 5px;">C:\xampp\php\php.ini</code>)</li>
                <li><strong>Restart Apache properly:</strong> Use XAMPP Control Panel to stop and start Apache (not just close XAMPP)</li>
                <li><strong>Clear browser cache:</strong> Press <kbd>Ctrl+Shift+Delete</kbd> to clear browser cache and reload this page</li>
                <li><strong>Check for 64-bit PHP:</strong> Some XAMPP installations use 64-bit PHP which may have different extension names</li>
            </ul>
        </div>

        <div class="instruction-step">
            <h5>Alternative: Check PHP Info</h5>
            <p>Create a file called <code style="display: inline; padding: 2px 5px;">phpinfo.php</code> in your <code style="display: inline; padding: 2px 5px;">C:\xampp\htdocs\capstone</code> directory with this content:</p>
            <code>&lt;?php phpinfo(); ?&gt;</code>
            <p>Then visit <code style="display: inline; padding: 2px 5px;">http://localhost/phpinfo.php</code> and search for "zip" to see detailed extension information</p>
        </div>

        <h3>Contact Support</h3>
        <p>If you continue to have issues after following these steps, please provide:</p>
        <ul>
            <li>Your XAMPP version</li>
            <li>Your PHP version (visible on the status above or in phpinfo)</li>
            <li>A screenshot of your XAMPP Control Panel</li>
            <li>The error message you receive</li>
        </ul>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-refresh every 5 seconds to check if extension gets enabled
        <?php if (!$zip_enabled): ?>
        setTimeout(function() {
            location.reload();
        }, 5000);
        <?php else: ?>
        // Show next steps if already enabled
        document.getElementById('next-steps').style.display = 'block';
        <?php endif; ?>
    </script>
</body>
</html>
