<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Notifications' . DIRECTORY_SEPARATOR . 'NotificationService.php';

function currentNotificationUser(): array
{
    $auth = new AuthService(Database::connection());

    if (!$auth->enforceSessionLifetime()) {
        jsonResponse(false, 'Authentication required.', [], 401);
    }

    try {
        $user = $auth->currentUser();
    } catch (RuntimeException) {
        clearAuthSession();
        jsonResponse(false, 'Authentication required.', [], 401);
    }

    if (!$user instanceof AuthenticatedUser) {
        jsonResponse(false, 'Authentication required.', [], 401);
    }

    $auth->touchSession();
    return $user->toArray();
}

function notificationService(): NotificationService
{
    return new NotificationService(Database::connection());
}

function notificationIdentifier(): int
{
    $id = (int) ($_GET['id'] ?? 0);
    if ($id < 1) {
        jsonResponse(false, 'Notification id is required.', [], 422);
    }
    return $id;
}
