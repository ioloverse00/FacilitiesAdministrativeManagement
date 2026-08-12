<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_bootstrap.php';

requireMethod('POST');
requireCsrfToken();

$user = currentNotificationUser();
$updated = notificationService()->markRead((int) $user['id'], notificationIdentifier());

if (!$updated) {
    jsonResponse(false, 'Notification not found.', [], 404);
}

jsonResponse(true, 'Notification marked as read.', [
    'unread_count' => notificationService()->unreadCount((int) $user['id']),
]);
