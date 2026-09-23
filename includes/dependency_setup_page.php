<?php
session_start();
if (!isset($_SESSION['username']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: /login.php');
    exit();
}
require_once __DIR__ . '/dependencies.php';
$setupError = '';
try {
    app_require_spreadsheet();
} catch (Throwable $error) {
    error_log('Spreadsheet setup check: ' . $error->getMessage());
    $setupError = 'The spreadsheet libraries or required PHP extensions are missing or incompatible.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Spreadsheet setup</title>
    <style>body{font:16px/1.6 sans-serif;background:#f5f5f5;color:#222;margin:0;padding:32px}main{max-width:720px;margin:auto;background:white;padding:32px;border-radius:12px}pre{padding:16px;background:#eee;overflow:auto}a{color:#800000}</style>
</head>
<body><main>
    <h1>Spreadsheet setup</h1>
    <p role="status"><?= $setupError !== '' ? htmlspecialchars($setupError, ENT_QUOTES, 'UTF-8') : 'The spreadsheet libraries and required PHP extensions are available.' ?></p>
    <p>Install the complete dependency set on your computer, then upload it to InfinityFree.</p>
    <ol>
        <li>Open a terminal in the project folder containing <code>composer.json</code> and <code>composer.lock</code>.</li>
        <li>Using PHP 8.2 or newer (64-bit), run:<pre>composer install --no-dev --prefer-dist --optimize-autoloader</pre></li>
        <li>Upload the complete <code>vendor</code> folder beside <code>login.php</code>. The supplied deployment ZIP already includes this folder.</li>
        <li>Ensure the hosting PHP configuration has the extensions required by Composer, including ZIP, XML/DOM, mbstring and GD.</li>
    </ol>
    <p>Copying only the PhpSpreadsheet source folder is insufficient: its supporting libraries are required too.</p>
    <p><a href="/admin/evaluation_management.php">Return to Evaluation Management</a></p>
</main></body>
</html>
