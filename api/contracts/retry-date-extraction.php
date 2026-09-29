<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('POST');
$user = currentApiUser();
requireCsrfToken();
try {
    $item = contractService()->retryContractDateExtraction(idParam(), $user);
    if ($item === null) {
        jsonResponse(false, 'Contract not found.', [], 404);
    }
    jsonResponse(true, 'AI date extraction retried.', ['item' => $item]);
} catch (DomainException $e) {
    jsonResponse(false, $e->getMessage(), [], 409);
} catch (Throwable $e) {
    validationResponse($e);
}
