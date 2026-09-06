<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('GET');
$user = currentApiUser();
$context = contractService()->templateAuthoringContext(idParam(), $user, 'contract.view');
if ($context === null) {
    jsonResponse(false, 'Contract not found.', [], 404);
}
if (!empty($context['document_id'])) {
    requireLegalDocumentAccessIfNeeded((int) $context['document_id'], $user);
    requireConfidentialDocumentStepUp((int) $context['document_id'], empty($context['document_version_id']) ? null : (int) $context['document_version_id'], $user, 'view');
}
$item = contractService()->templateAuthoringData((int) $context['contract_id'], $user);
jsonResponse(true, 'Contract template authoring data retrieved.', ['item' => $item]);
