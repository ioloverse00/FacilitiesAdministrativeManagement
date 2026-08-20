<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
requireMethod('POST');
$user = currentApiUser();
if (!VisitorPolicy::can($user, 'visitors.create_walkin') || !VisitorPolicy::can($user, 'visitors.checkin')) {
    jsonResponse(false, 'You do not have permission to perform this action.', [], 403);
}
requireCsrfToken();
try {
    jsonResponse(true, 'Visitor checked in from Reception Console.', ['item' => visitorService()->createAndCheckIn(visitorBody(), $user)], 201);
} catch (Throwable $e) {
    visitorValidation($e);
}
