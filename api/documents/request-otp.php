<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('POST');
$user = currentApiUser();
requireCsrfToken();
$body = readJsonBody();
$documentId = (int) ($body['document_id'] ?? 0);
if ($documentId < 1) jsonResponse(false, 'A valid document is required.', [], 422);
$requestContext = documentRequestContext($body);
$stepUpContext = authorizeDocumentFileAccessOrDeny(
    $documentId,
    $requestContext['version_id'],
    $user,
    $requestContext['action'],
    $requestContext['contract_id'],
    $requestContext['legal_matter_id']
);
if (!documentService()->requiresStepUp($documentId)) {
    jsonResponse(true, 'Additional verification is not required.', ['step_up_required' => false]);
}
if (documentStepUpService()->hasValidStepUp($user, $documentId, $stepUpContext)) {
    jsonResponse(true, 'Additional verification is already complete.', ['step_up_required' => false]);
}
jsonResponse(true, 'Verification code sent to your registered email.', documentStepUpService()->requestChallenge($user, $documentId, $stepUpContext));
