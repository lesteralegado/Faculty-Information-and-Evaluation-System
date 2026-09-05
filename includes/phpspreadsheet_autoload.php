<?php
/**
 * Simple Autoloader for PhpSpreadsheet (for manual installations without Composer)
 * This autoloader is used when PhpSpreadsheet is installed manually
 */

if (!function_exists('phpspreadsheet_autoload')) {
    function phpspreadsheet_autoload($class) {
        // Only handle PhpOffice\PhpSpreadsheet classes
        if (strpos($class, 'PhpOffice\\PhpSpreadsheet\\') !== 0) {
            return false;
        }
        
        // Remove the namespace prefix
        $class_path = str_replace('PhpOffice\\PhpSpreadsheet\\', '', $class);
        
        // Convert namespace separators to directory separators
        $class_path = str_replace('\\', DIRECTORY_SEPARATOR, $class_path);
        
        // Possible base paths where PhpSpreadsheet might be installed
        $base_paths = [
            __DIR__ . '/phpspreadsheet/src/phpspreadsheet/',  // lowercase directory (Windows)
            __DIR__ . '/phpspreadsheet/src/PhpSpreadsheet/',  // uppercase directory (Linux/Mac)
            __DIR__ . '/../vendor/phpoffice/phpspreadsheet/src/PhpSpreadsheet/',
        ];
        
        // Try each base path
        foreach ($base_paths as $base_path) {
            $file = $base_path . $class_path . '.php';
            if (file_exists($file)) {
                require_once $file;
                return true;
            }
        }
        
        // Case-insensitive search on Windows (if file system is case-insensitive)
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $base_path = __DIR__ . '/phpspreadsheet/src/';
            if (is_dir($base_path)) {
                // Find the actual directory name (could be phpspreadsheet or PhpSpreadsheet)
                $dirs = scandir($base_path);
                foreach ($dirs as $dir) {
                    if ($dir !== '.' && $dir !== '..' && is_dir($base_path . $dir)) {
                        $file = $base_path . $dir . DIRECTORY_SEPARATOR . $class_path . '.php';
                        if (file_exists($file)) {
                            require_once $file;
                            return true;
                        }
                    }
                }
            }
        }
        
        return false;
    }
    
    // Register the autoloader
    spl_autoload_register('phpspreadsheet_autoload');
}
