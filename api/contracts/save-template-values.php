<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('POST');
$user = currentApiUser();
requireCsrfToken();
$context = contractService()->templateAuthoringContext(idParam(), $user, 'contract.edit');
if ($context === null) {
    jsonResponse(false, 'Contract not found.', [], 404);
}
if (!empty($context['document_id'])) {
    requireLegalDocumentAccessIfNeeded((int) $context['document_id'], $user);
    requireConfidentialDocumentStepUp((int) $context['document_id'], empty($context['document_version_id']) ? null : (int) $context['document_version_id'], $user, 'view');
}
try {
    $item = contractService()->saveTemplateValues((int) $context['contract_id'], readJsonBody(), $user);
    jsonResponse(true, 'Contract template values saved.', ['item' => $item]);
} catch (DomainException $e) {
    jsonResponse(false, $e->getMessage(), [], 409);
} catch (Throwable $e) {
    validationResponse($e);
}
