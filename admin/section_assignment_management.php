<?php
/**
 * Legacy URL: teacher assignments now live under Section Management → Teacher assignments.
 */
$q = ['sm_tab' => 'assignments'];
if (!empty($_GET['section_id'])) {
    $q['section_id'] = (int)$_GET['section_id'];
}
header('Location: section_management.php?' . http_build_query($q));
exit;
