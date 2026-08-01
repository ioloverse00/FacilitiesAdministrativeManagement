<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bootstrap.php';

requireMethod('POST');

$service = new AuthService(Database::connection());

if ($service->enforceSessionLifetime()) {
    $user = $service->currentUser();

    if ($user instanceof AuthenticatedUser) {
        requireCsrfToken();
    }
}

$service->logout();

jsonResponse(true, 'Logout successful.', []);
