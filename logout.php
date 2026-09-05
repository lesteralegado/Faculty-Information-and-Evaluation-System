<?php
session_start();
session_unset();
session_destroy();

// Prevent caching of previous pages
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Logging out...</title>
    <script>
        // Push a dummy state to history to block back button
        history.pushState(null, null, location.href);
        window.onpopstate = function () {
            history.pushState(null, null, location.href);
        };

        // Redirect to index after small delay
        setTimeout(function() {
            window.location.href = "login.php";
        }, 100);
    </script>
</head>
<body>
</body>
</html>
