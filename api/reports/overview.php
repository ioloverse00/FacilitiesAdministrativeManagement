<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
requireMethod('GET');
$user = reportsUser();
try {
    jsonResponse(true, 'Report retrieved.', reportsService()->overview($_GET, $user));
} catch (Throwable $e) {
    reportsValidation($e);
}
