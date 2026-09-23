<?php
// Deprecated: schema repair must be imported by an administrator through phpMyAdmin.
// The old script was publicly executable, used local credentials, and could alter live tables.
http_response_code(410);
header('Content-Type: text/plain; charset=UTF-8');
echo 'This repair endpoint has been retired. Import deployment/schema-repair.sql through phpMyAdmin.';
