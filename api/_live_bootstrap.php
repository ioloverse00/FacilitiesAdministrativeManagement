<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'LiveData' . DIRECTORY_SEPARATOR . 'LiveDataService.php';

function currentApiUser(): array
{
    $auth = new AuthService(Database::connection());
    $auth->enforceSessionLifetime();
    $user = $auth->currentUser();
    if ($user === null) {
        jsonResponse(false, 'Authentication required.', [], 401);
    }
    $auth->touchSession();
    return $user->toArray();
}

function requirePermission(array $user, string $permission): void
{
    if (!in_array($permission, $user['permissions'] ?? [], true)) {
        jsonResponse(false, 'You do not have permission to perform this action.', [], 403);
    }
}

function liveDataService(): LiveDataService
{
    return new LiveDataService(Database::connection());
}

function idParam(string $name = 'id'): int
{
    $value = $_GET[$name] ?? null;
    if ($value === null || !ctype_digit((string) $value)) {
        jsonResponse(false, 'A valid id is required.', [], 422);
    }
    return (int) $value;
}