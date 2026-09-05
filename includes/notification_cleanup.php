<?php
/**
 * Notification Cleanup Script
 * Run this periodically to clean up old notifications
 */

require_once __DIR__ . '/notification_functions.php';

// Delete notifications older than 30 days
$result = deleteOldNotifications(30);

if ($result) {
    echo "Old notifications cleaned up successfully.\n";
} else {
    echo "Failed to clean up old notifications.\n";
}
?>
