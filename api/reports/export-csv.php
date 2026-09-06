<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_bootstrap.php';

requireMethod('GET');
$user = reportsUser();

try {
    $report = preg_replace('/[^a-z0-9_]+/', '', (string) ($_GET['report'] ?? 'report'));
    $filename = ($report !== '' ? $report : 'report') . '-report-' . date('Ymd-His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    reportsService()->streamCsv($_GET, $user);
} catch (Throwable $e) {
    reportsValidation($e);
}
