<?php
require_once __DIR__ . '/error_handler.php';

/** Load the complete Composer installation only when an operation needs it. */
function app_require_dependencies(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!is_readable($autoload)) {
        throw new RuntimeException('Required libraries are missing. Upload the complete vendor folder from the deployment package.');
    }
    if (PHP_VERSION_ID < 80200 || PHP_INT_SIZE !== 8) {
        throw new RuntimeException('The installed libraries require PHP 8.2 or newer on a 64-bit server.');
    }
    require_once $autoload;
    $loaded = true;
}

function app_require_spreadsheet(): void
{
    app_require_dependencies();
    foreach (['ctype', 'dom', 'fileinfo', 'filter', 'gd', 'iconv', 'libxml', 'mbstring', 'simplexml', 'xml', 'xmlreader', 'xmlwriter', 'zip', 'zlib'] as $extension) {
        if (!extension_loaded($extension)) {
            throw new RuntimeException('Excel import/export requires the PHP ' . $extension . ' extension.');
        }
    }
    foreach (['PhpOffice\\PhpSpreadsheet\\Spreadsheet', 'Psr\\SimpleCache\\CacheInterface', 'ZipStream\\ZipStream', 'Composer\\Pcre\\Preg', 'Matrix\\Matrix', 'Complex\\Complex'] as $class) {
        if (!class_exists($class) && !interface_exists($class)) {
            throw new RuntimeException('The spreadsheet libraries are incomplete. Upload the complete vendor folder.');
        }
    }
}
