<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'LiveData' . DIRECTORY_SEPARATOR . 'LiveDataService.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Reservations' . DIRECTORY_SEPARATOR . 'ReservationService.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Documents' . DIRECTORY_SEPARATOR . 'DocumentService.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'RecordsRetention' . DIRECTORY_SEPARATOR . 'RetentionService.php';

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

function reservationService(): ReservationService
{
    return new ReservationService(Database::connection());
}

function documentService(): DocumentService
{
    return new DocumentService(Database::connection());
}

function retentionService(): RetentionService
{
    return new RetentionService(Database::connection());
}

function idParam(string $name = 'id'): int
{
    $value = $_GET[$name] ?? null;
    if ($value === null || !ctype_digit((string) $value)) {
        jsonResponse(false, 'A valid id is required.', [], 422);
    }
    return (int) $value;
}

function reservationIdentifier(): int|string
{
    $value = $_GET['id'] ?? $_GET['reservation_number'] ?? null;
    if ($value === null || $value === '') {
        jsonResponse(false, 'Reservation id or reservation_number is required.', [], 422);
    }
    return ctype_digit((string) $value) ? (int) $value : (string) $value;
}

function validationResponse(Throwable $e): void
{
    if ($e instanceof InvalidArgumentException) {
        $errors = json_decode($e->getMessage(), true);
        jsonResponse(false, 'Validation failed.', ['errors' => is_array($errors) ? $errors : ['request' => $e->getMessage()]], 422);
    }
    throw $e;
}
