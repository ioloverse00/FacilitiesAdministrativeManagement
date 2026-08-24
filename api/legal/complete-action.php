<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('POST');
$user = currentApiUser();
LegalPolicy::requirePermission($user, 'legal.manage');
requireCsrfToken();
try {
    $matterId = idParam('matter_id');
    $actionId = idParam('action_id');
    $result = legalMatterActionService()->completeAction($matterId, $actionId, readJsonBody(), $user);
    if ($result === null) {
        jsonResponse(false, 'Legal action not found.', [], 404);
    }
    jsonResponse(true, 'Legal action completed.', ['item' => legalMatterService()->show($matterId)]);
} catch (Throwable $e) {
    validationResponse($e);
}
