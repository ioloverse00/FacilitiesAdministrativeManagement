<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_bootstrap.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Notifications' . DIRECTORY_SEPARATOR . 'NotificationService.php';

requireMethod('GET');

$user = currentEmployeeUser();
$data = (new NotificationService(Database::connection()))->listForUser((int) $user['id'], $_GET);

jsonResponse(true, 'Notifications loaded.', $data + [
    'csrf_token' => ensureCsrfToken(),
]);
