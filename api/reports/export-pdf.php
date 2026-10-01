<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_bootstrap.php';

requireMethod('GET');
$user = reportsUser();

try {
    $pdf = reportsService()->pdf($_GET, $user);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $pdf['filename'] . '"');
    header('Content-Length: ' . strlen($pdf['content']));
    echo $pdf['content'];
} catch (Throwable $e) {
    reportsValidation($e);
}
