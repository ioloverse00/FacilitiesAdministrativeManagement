<?php
declare(strict_types=1);
require_once __DIR__ . DIRECTORY_SEPARATOR . '_bootstrap.php';
requireMethod('GET');
$user = currentApiUser();
requirePermission($user, 'visitors.view');
requirePermission($user, 'visitors.export');
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="visitor-management-export-' . date('Ymd-His') . '.csv"');
echo "\xEF\xBB\xBF";
$handle = fopen('php://output', 'w');
visitorService()->streamCsv($_GET, $handle);
