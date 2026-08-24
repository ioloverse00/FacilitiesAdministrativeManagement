<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('POST');
$user = currentApiUser();
ContractPolicy::requirePermission($user, 'contract.edit');
DocumentPolicy::requirePermission($user, 'records.create');
requireCsrfToken();
try {
    $file = $_FILES['file'] ?? [];
    $item = contractService()->attachDocument(idParam(), $_POST, is_array($file) ? $file : [], $user);
    if ($item === null) {
        jsonResponse(false, 'Contract not found.', [], 404);
    }
    jsonResponse(true, 'Contract document attached.', ['item' => $item], 201);
} catch (Throwable $e) {
    validationResponse($e);
}
