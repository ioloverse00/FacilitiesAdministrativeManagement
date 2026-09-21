<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';

requireMethod('POST');

$user = currentApiUser();
requireCsrfToken();

try {
    $body = readJsonBody();
    $item = (new AccountProvisioningService(Database::connection(), new MailService()))
        ->reissueSetup((int) ($body['user_account_id'] ?? 0), $user);

    jsonResponse(true, 'Staff account setup email reissued.', ['item' => $item], 200);
} catch (InvalidArgumentException $exception) {
    $errors = json_decode($exception->getMessage(), true);
    jsonResponse(false, 'Validation failed.', ['errors' => is_array($errors) ? $errors : ['request' => 'Invalid request.']], 422);
} catch (RuntimeException $exception) {
    jsonResponse(false, $exception->getMessage(), [], 409);
}
