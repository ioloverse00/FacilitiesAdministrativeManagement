<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('POST');
$user = currentApiUser();
ContractPolicy::requirePermission($user, 'contract.review');
requireCsrfToken();
try {
    $item = contractService()->verifySignedContract(idParam(), $user);
    if ($item === null) {
        jsonResponse(false, 'Contract not found.', [], 404);
    }
    jsonResponse(true, 'Signed contract verified.', ['item' => $item]);
} catch (ContractWorkflowException $e) {
    jsonResponse(false, $e->getMessage(), ['code' => $e->errorCode(), 'details' => $e->details()], 409);
} catch (DomainException $e) {
    jsonResponse(false, $e->getMessage(), [], 409);
} catch (Throwable $e) {
    validationResponse($e);
}
