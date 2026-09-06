<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('GET');
$user = currentApiUser();
requirePermission($user, 'maintenance.view');
requirePermission($user, 'maintenance.export');
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="maintenance-export-' . date('Ymd-His') . '.csv"');
echo "\xEF\xBB\xBF";
$handle = fopen('php://output', 'w');
liveDataService()->streamMaintenanceCsv($_GET, $handle);
