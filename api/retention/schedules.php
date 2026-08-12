<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('GET');
$user = currentApiUser();
RetentionPolicy::requirePermission($user, 'retention.view');
jsonResponse(true, 'Retention schedules retrieved.', ['items' => retentionService()->schedules()]);
