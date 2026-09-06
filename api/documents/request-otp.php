<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('POST');
$user = currentApiUser();
DocumentPolicy::requirePermission($user, 'records.view');
requireCsrfToken();
$body = readJsonBody();
$documentId = (int) ($body['document_id'] ?? 0);
if ($documentId < 1) jsonResponse(false, 'A valid document is required.', [], 422);
requireLegalDocumentAccessIfNeeded($documentId, $user);
if (!documentService()->requiresStepUp($documentId)) {
    jsonResponse(true, 'Additional verification is not required.', ['step_up_required' => false]);
}
if (documentStepUpService()->hasValidStepUp($user, $documentId)) {
    jsonResponse(true, 'Additional verification is already complete.', ['step_up_required' => false]);
}
jsonResponse(true, 'Verification code sent to your registered email.', documentStepUpService()->requestChallenge($user, $documentId));
