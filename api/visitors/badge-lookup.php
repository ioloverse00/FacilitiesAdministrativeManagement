<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
requireMethod('GET');
$user = currentApiUser();
VisitorPolicy::require($user, 'visitors.checkout');
try {
    jsonResponse(true, 'Active badge assignment retrieved.', ['item' => visitorService()->badgeLookup((string)($_GET['badge_number'] ?? ''))]);
} catch (Throwable $e) {
    visitorValidation($e);
}
