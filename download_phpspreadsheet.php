<?php
/**
 * PhpSpreadsheet Download Helper
 * This script attempts to download and extract PhpSpreadsheet automatically
 */

// Set execution time limit for large downloads
set_time_limit(300);

$target_dir = __DIR__ . '/includes/phpspreadsheet';
$zip_file = __DIR__ . '/phpspreadsheet.zip';
$github_api_url = 'https://api.github.com/repos/PHPOffice/PhpSpreadsheet/releases/latest';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Download PhpSpreadsheet</title>
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
        .code-block {
            background: #2d2d2d;
            color: #f8f8f2;
            padding: 15px;
            border-radius: 5px;
            font-family: 'Courier New', monospace;
            margin: 10px 0;
            overflow-x: auto;
        }
    </style>
</head>
<body>
    <div class="install-card">
        <h1 class="mb-4"><i class="fas fa-download"></i> Automatic PhpSpreadsheet Installation</h1>
        
        <?php
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['download'])) {
            echo '<div class="alert alert-info">Attempting to download PhpSpreadsheet...</div>';
            
            // Use direct GitHub release download URL (more reliable than API)
            // Latest stable version - update this if needed
            $version = '2.0.0';
            $zip_url = "https://github.com/PHPOffice/PhpSpreadsheet/archive/refs/tags/{$version}.zip";
            
            echo '<div class="alert alert-info">Downloading PhpSpreadsheet version ' . htmlspecialchars($version) . ' from GitHub...</div>';
            
            // Use cURL for better download handling with proper headers
            $ch = curl_init($zip_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 300);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
            $zip_content = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($error) {
                echo '<div class="alert alert-danger">cURL Error: ' . htmlspecialchars($error) . '</div>';
            } elseif ($http_code == 200 && $zip_content && strlen($zip_content) > 1000) {
                // Successfully downloaded
                if (file_put_contents($zip_file, $zip_content)) {
                    
                    if ($zip_content && file_put_contents($zip_file, $zip_content)) {
                        echo '<div class="alert alert-success">Download complete! Extracting...</div>';
                        
                        // Check if ZipArchive is available
                        if (class_exists('ZipArchive')) {
                            $zip = new ZipArchive;
                            if ($zip->open($zip_file) === TRUE) {
                                // Create target directory
                                if (!is_dir($target_dir)) {
                                    mkdir($target_dir, 0777, true);
                                }
                                
                                // Extract to temporary location first
                                $temp_extract = __DIR__ . '/temp_phpspreadsheet';
                                $zip->extractTo($temp_extract);
                                $zip->close();
                                
                                // Find the src directory and move it
                                $files = new RecursiveIteratorIterator(
                                    new RecursiveDirectoryIterator($temp_extract),
                                    RecursiveIteratorIterator::LEAVES_ONLY
                                );
                                
                                $src_found = false;
                                foreach ($files as $file) {
                                    if (strpos($file->getPathname(), 'src/PhpSpreadsheet/IOFactory.php') !== false) {
                                        $src_path = dirname(dirname($file->getPathname()));
                                        $target_src = $target_dir . '/src';
                                        
                                        // Copy src directory
                                        if (is_dir($src_path)) {
                                            if (!is_dir($target_src)) {
                                                mkdir($target_src, 0777, true);
                                            }
                                            copy_directory($src_path, $target_src);
                                            $src_found = true;
                                            break;
                                        }
                                    }
                                }
                                
                                // Clean up
                                delete_directory($temp_extract);
                                unlink($zip_file);
                                
                                if ($src_found) {
                                    echo '<div class="alert alert-success"><strong>✓ Installation Complete!</strong> PhpSpreadsheet has been installed successfully.</div>';
                                    echo '<p><a href="admin/evaluation_management.php" class="btn btn-primary">Go to Evaluation Management</a></p>';
                                } else {
                                    echo '<div class="alert alert-warning">Extraction completed but src directory not found. Please install manually.</div>';
                                }
                            } else {
                                echo '<div class="alert alert-danger">Failed to extract ZIP file. Please install manually.</div>';
                            }
                        } else {
                            echo '<div class="alert alert-warning">ZipArchive class not available. Please install manually or enable php_zip extension.</div>';
                        }
                    } else {
                        echo '<div class="alert alert-danger">Failed to save downloaded file. Please check write permissions for: ' . htmlspecialchars($zip_file) . '</div>';
                    }
                } else {
                    echo '<div class="alert alert-danger">Failed to download file (HTTP ' . $http_code . '). ';
                    if ($http_code == 403) {
                        echo 'GitHub is blocking the request. This is common with automated downloads. ';
                    } elseif ($http_code == 404) {
                        echo 'The download URL was not found. ';
                    }
                    echo 'Please use <strong>manual installation</strong> instead.</div>';
                    echo '<div class="alert alert-info mt-3">';
                    echo '<strong>Manual Installation Steps:</strong><ol>';
                    echo '<li>Visit: <a href="https://github.com/PHPOffice/PhpSpreadsheet/releases/latest" target="_blank">https://github.com/PHPOffice/PhpSpreadsheet/releases/latest</a></li>';
                    echo '<li>Download the <code>Source code (zip)</code> file</li>';
                    echo '<li>Extract the ZIP file</li>';
                    echo '<li>Copy the <code>src</code> folder to: <code>c:\\xampp\\htdocs\\capstone\\includes\\phpspreadsheet\\src\\</code></li>';
                    echo '</ol></div>';
                }
        } else {
        ?>
        
        <div class="alert alert-warning">
            <strong>⚠ Important:</strong> GitHub may block automated downloads (403 Forbidden). If the automatic installation fails, please use manual installation below.
        </div>
        
        <div class="alert alert-info">
            <strong>Note:</strong> This automatic installer requires:
            <ul class="mb-0">
                <li>PHP <code>allow_url_fopen</code> enabled OR cURL extension</li>
                <li>PHP <code>ZipArchive</code> extension enabled</li>
                <li>Write permissions to the <code>includes</code> directory</li>
            </ul>
        </div>
        
        <form method="POST">
            <button type="submit" name="download" class="btn btn-primary btn-lg">
                <i class="fas fa-download"></i> Try Automatic Download and Install
            </button>
        </form>
        
        <hr>
        
        <h4>Manual Installation (Recommended - Most Reliable)</h4>
        <ol>
            <li>Visit: <a href="https://github.com/PHPOffice/PhpSpreadsheet/releases/latest" target="_blank">https://github.com/PHPOffice/PhpSpreadsheet/releases/latest</a></li>
            <li>Click on <strong>"Source code (zip)"</strong> to download the ZIP file</li>
            <li>Extract the downloaded ZIP file (you'll get a folder like <code>PhpSpreadsheet-2.0.0</code>)</li>
            <li>Inside the extracted folder, find the <code>src</code> folder</li>
            <li>Copy the entire <code>src</code> folder to:
                <div class="code-block">c:\xampp\htdocs\capstone\includes\phpspreadsheet\src\</div>
            </li>
            <li>The final structure should be:
                <div class="code-block">
                    capstone/<br>
                    └── includes/<br>
                        └── phpspreadsheet/<br>
                            └── src/<br>
                                └── PhpSpreadsheet/<br>
                                    ├── IOFactory.php<br>
                                    └── (other files...)
                </div>
            </li>
            <li>After copying, refresh the Evaluation Management page to verify installation</li>
        </ol>
        
        <div class="alert alert-success">
            <strong>Quick Tip:</strong> You can also use Composer if you have it installed:<br>
            <div class="code-block">composer require phpoffice/phpspreadsheet</div>
        </div>
        
        <p><a href="install_phpspreadsheet.php" class="btn btn-secondary">View Detailed Installation Guide</a></p>
        
        <?php } ?>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<?php
// Helper functions
function copy_directory($src, $dst) {
    $dir = opendir($src);
    @mkdir($dst);
    while (($file = readdir($dir)) !== false) {
        if ($file != '.' && $file != '..') {
            if (is_dir($src . '/' . $file)) {
                copy_directory($src . '/' . $file, $dst . '/' . $file);
            } else {
                copy($src . '/' . $file, $dst . '/' . $file);
            }
        }
    }
    closedir($dir);
}

function delete_directory($dir) {
    if (!is_dir($dir)) return;
    $files = array_diff(scandir($dir), array('.', '..'));
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        is_dir($path) ? delete_directory($path) : unlink($path);
    }
    rmdir($dir);
}
?>
