<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('POST');
$user = currentApiUser();
ContractPolicy::requirePermission($user, 'contract.review');
requireCsrfToken();
try {
    $file = $_FILES['file'] ?? [];
    $item = contractService()->uploadSignedContract(idParam(), $_POST, is_array($file) ? $file : [], $user);
    if ($item === null) {
        jsonResponse(false, 'Contract not found.', [], 404);
    }
    jsonResponse(true, 'Signed contract uploaded.', ['item' => $item], 201);
} catch (DomainException $e) {
    jsonResponse(false, $e->getMessage(), [], 409);
} catch (Throwable $e) {
    validationResponse($e);
}
