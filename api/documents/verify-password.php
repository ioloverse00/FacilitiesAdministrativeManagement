<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';

requireMethod('POST');
$user = currentApiUser();
requireCsrfToken();

$input = readJsonBody();
$documentId = isset($input['document_id']) && ctype_digit((string) $input['document_id']) ? (int) $input['document_id'] : 0;
$password = isset($input['password']) && is_string($input['password']) ? $input['password'] : '';
if ($documentId < 1 || $password === '') {
    jsonResponse(false, 'Password verification failed.', [], 422);
}

$requestContext = documentRequestContext($input);
$stepUpContext = authorizeDocumentFileAccessOrDeny(
    $documentId,
    $requestContext['version_id'],
    $user,
    $requestContext['action'],
    $requestContext['contract_id'],
    $requestContext['legal_matter_id']
);

if (!documentService()->requiresStepUp($documentId)) {
    jsonResponse(true, 'Additional verification is not required.', [
        'step_up_required' => false,
    ]);
}

if (documentStepUpService()->hasValidStepUp($user, $documentId, $stepUpContext)) {
    jsonResponse(true, 'Additional verification is already complete.', [
        'step_up_required' => false,
    ]);
}

$grant = documentStepUpService()->verifyPasswordAndGrantStepUp($user, $documentId, $password, $stepUpContext);

jsonResponse(true, 'Password verification complete.', $grant);
