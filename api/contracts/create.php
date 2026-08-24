<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('POST');
$user = currentApiUser();
requireCsrfToken();
try {
    $item = contractService()->create(readJsonBody(), $user);
    jsonResponse(true, 'Contract created.', ['item' => $item], 201);
} catch (Throwable $e) {
    validationResponse($e);
}
