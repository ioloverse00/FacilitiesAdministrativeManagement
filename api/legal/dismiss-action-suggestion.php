<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('POST');
$user = currentApiUser();
LegalPolicy::requirePermission($user, 'legal.manage');
requireCsrfToken();
try {
    $matterId = idParam('matter_id');
    $suggestionId = idParam('suggestion_id');
    $result = legalMatterActionService()->dismissSuggestion($matterId, $suggestionId, readJsonBody(), $user);
    if ($result === null) {
        jsonResponse(false, 'Action suggestion not found.', [], 404);
    }
    jsonResponse(true, 'Action suggestion dismissed.', ['item' => legalMatterService()->show($matterId)]);
} catch (Throwable $e) {
    validationResponse($e);
}
