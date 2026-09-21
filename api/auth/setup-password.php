<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bootstrap.php';

$service = new AccountProvisioningService(Database::connection(), new MailService());

requireMethod('POST');

try {
    $body = readJsonBody();
    if (($body['action'] ?? '') === 'status') {
        jsonResponse(true, 'Setup token checked.', $service->tokenStatus(trim((string) ($body['token'] ?? ''))));
    }

    $result = $service->completeSetup(
        trim((string) ($body['token'] ?? '')),
        (string) ($body['password'] ?? ''),
        (string) ($body['confirm_password'] ?? '')
    );

    jsonResponse(true, 'Password setup complete. You can now sign in.', $result);
} catch (InvalidArgumentException $exception) {
    $errors = json_decode($exception->getMessage(), true);
    jsonResponse(false, 'Validation failed.', ['errors' => is_array($errors) ? $errors : ['request' => 'Invalid request.']], 422);
} catch (RuntimeException) {
    jsonResponse(false, 'Setup link is invalid or expired.', [], 410);
}
