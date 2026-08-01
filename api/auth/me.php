<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bootstrap.php';

requireMethod('GET');

$service = new AuthService(Database::connection());

if (!$service->enforceSessionLifetime()) {
    jsonResponse(false, 'Authentication required.', [], 401);
}

try {
    $user = $service->currentUser();
} catch (RuntimeException) {
    clearAuthSession();
    jsonResponse(false, 'Authentication required.', [], 401);
}

if (!$user instanceof AuthenticatedUser) {
    jsonResponse(false, 'Authentication required.', [], 401);
}

$service->touchSession();

jsonResponse(true, 'Authenticated user loaded.', [
    'user' => $user->toArray(),
    'csrf_token' => ensureCsrfToken(),
    'session' => [
        'authenticated_at' => $_SESSION['authenticated_at'] ?? null,
        'last_activity_at' => $_SESSION['last_activity_at'] ?? null,
        'idle_timeout_seconds' => authSessionLifetime(),
        'expires_at' => (int) ($_SESSION['last_activity_at'] ?? time()) + authSessionLifetime(),
    ],
]);
