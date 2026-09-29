<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('POST');
$user = currentApiUser();
requireCsrfToken();
$body = readJsonBody();
$documentId = (int) ($body['document_id'] ?? 0);
$challengeId = trim((string) ($body['challenge_id'] ?? ''));
$otp = trim((string) ($body['otp'] ?? ''));
if ($documentId < 1 || $challengeId === '' || $otp === '') jsonResponse(false, 'Verification failed. Request a new code and try again.', [], 422);
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
jsonResponse(true, 'Verification complete.', documentStepUpService()->verifyChallenge($user, $documentId, $challengeId, $otp, $stepUpContext));
