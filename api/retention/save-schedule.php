<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('POST');
$user = currentApiUser();
RetentionPolicy::requirePermission($user, 'retention.manage_schedules');
requireCsrfToken();
try {
    jsonResponse(true, 'Retention schedule saved.', retentionService()->saveSchedule($_POST, $user));
} catch (Throwable $e) {
    validationResponse($e);
}
