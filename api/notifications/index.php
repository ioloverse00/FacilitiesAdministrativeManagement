<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_bootstrap.php';

requireMethod('GET');

$user = currentNotificationUser();
$data = notificationService()->listForUser((int) $user['id'], $_GET);

jsonResponse(true, 'Notifications loaded.', $data + [
    'csrf_token' => ensureCsrfToken(),
]);
