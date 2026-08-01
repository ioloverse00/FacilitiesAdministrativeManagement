<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'FacilityRequests' . DIRECTORY_SEPARATOR . 'FacilityRequestService.php';

function facilityRequestService(): FacilityRequestService
{
    return new FacilityRequestService(Database::connection());
}

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

function requestIdentifier(): int|string
{
    $value = $_GET['id'] ?? $_GET['request_number'] ?? null;
    if ($value === null || $value === '') {
        jsonResponse(false, 'Facility request id or request_number is required.', [], 422);
    }
    return ctype_digit((string)$value) ? (int)$value : (string)$value;
}

function validationResponse(Throwable $e): void
{
    if ($e instanceof InvalidArgumentException) {
        $errors = json_decode($e->getMessage(), true);
        jsonResponse(false, 'Validation failed.', ['errors'=>is_array($errors) ? $errors : ['request'=>$e->getMessage()]], 422);
    }
    throw $e;
}