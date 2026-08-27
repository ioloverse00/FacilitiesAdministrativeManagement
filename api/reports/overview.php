<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
requireMethod('GET');
reportsUser();
try {
    jsonResponse(true, 'Reports overview retrieved.', reportsService()->overview($_GET));
} catch (Throwable $e) {
    reportsValidation($e);
}
