<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_bootstrap.php';

requireMethod('POST');
requireCsrfToken();

$user = currentNotificationUser();
$updated = notificationService()->markAllRead((int) $user['id']);

jsonResponse(true, 'Notifications marked as read.', [
    'updated' => $updated,
    'unread_count' => notificationService()->unreadCount((int) $user['id']),
]);
