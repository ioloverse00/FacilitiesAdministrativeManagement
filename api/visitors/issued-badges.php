<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
requireMethod('GET');
$user = currentApiUser();
VisitorPolicy::require($user, 'visitors.checkout');
try {
    jsonResponse(true, 'Issued badge assignments retrieved.', ['items' => visitorService()->issuedBadgeAssignments()]);
} catch (Throwable $e) {
    visitorValidation($e);
}
