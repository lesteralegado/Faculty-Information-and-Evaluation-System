<?php
/** Shared last-resort response for uncaught failures; details stay in the server log. */
function app_render_failure(string $reference, ?Throwable $error = null): void
{
    $json = strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false
        || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
    foreach (headers_list() as $header) {
        if (stripos($header, 'Content-Type: application/json') === 0) {
            $json = true;
        }
    }

    // Discard partial HTML, JSON, or download contents when output is still buffered.
    while (ob_get_level() > 0) {
        if (!@ob_end_clean()) {
            break;
        }
    }
    if (!headers_sent()) {
        http_response_code(500);
        header_remove('Content-Disposition');
        header_remove('Content-Length');
        header_remove('Location');
        header('Cache-Control: no-store');
        header('Content-Type: ' . ($json ? 'application/json' : 'text/html') . '; charset=UTF-8');
    }
    $message = 'The request could not be completed. Please try again or contact the administrator.';
    // Authenticated administrators need the actual database/dependency reason while diagnosing setup.
    // Public visitors continue to receive only the generic message.
    if ($error !== null && (($_SESSION['role'] ?? '') === 'admin')) {
        $message .= ' Diagnostic: ' . get_class($error) . ': ' . $error->getMessage();
    }
    if ($json) {
        echo json_encode(['success' => false, 'error' => $message, 'message' => $message, 'reference' => $reference]);
        return;
    }
    echo '<div role="alert" style="position:relative;z-index:10000;margin:32px auto;padding:24px;max-width:640px;background:#fff;color:#222;border:1px solid #ddd;font:16px/1.5 sans-serif">';
    echo '<h1 style="font-size:24px">Unable to complete this request</h1><p>' . $message . '</p>';
    echo '<p>Reference: ' . htmlspecialchars($reference, ENT_QUOTES, 'UTF-8') . '</p>';
    echo '<p><a href="/login.php">Return to sign in</a></p></div>';
}

function app_handle_exception(Throwable $error): void
{
    $reference = uniqid('ERR-');
    error_log('[' . $reference . '] ' . get_class($error) . ': ' . $error->getMessage()
        . ' in ' . $error->getFile() . ':' . $error->getLine());
    app_render_failure($reference, $error);
    exit(1);
}

set_exception_handler('app_handle_exception');

// Reserve a little memory so a memory-limit failure can still produce a response.
$GLOBALS['app_error_memory'] = str_repeat('x', 32768);
register_shutdown_function(static function (): void {
    $GLOBALS['app_error_memory'] = null;
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $reference = uniqid('ERR-');
        error_log('[' . $reference . '] ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);
        app_render_failure($reference);
    }
});
