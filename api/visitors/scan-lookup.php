<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$user = currentApiUser();
VisitorPolicy::require($user, 'visitors.view');

try {
    jsonResponse(true, 'Visitor pass found.', visitorService()->scanLookup((string)($_GET['token'] ?? ''), $user));
} catch (Throwable $e) {
    visitorValidation($e);
}
