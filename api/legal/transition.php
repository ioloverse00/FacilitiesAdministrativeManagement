<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('POST');
$user = currentApiUser();
requireCsrfToken();
try {
    $body = readJsonBody();
    $action = strtolower(trim((string) ($body['action'] ?? '')));
    $required = match ($action) {
        'close' => 'legal.close',
        'cancel' => 'legal.manage',
        default => 'legal.resolve',
    };
    LegalPolicy::requireAnyPermission($user, [$required, 'legal.manage']);
    $item = legalMatterService()->transition(idParam(), $body, $user);
    if ($item === null) {
        jsonResponse(false, 'Legal matter not found.', [], 404);
    }
    jsonResponse(true, 'Legal matter updated.', ['item' => $item]);
} catch (Throwable $e) {
    validationResponse($e);
}
