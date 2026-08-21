<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('POST');
$user = currentApiUser();
LegalPolicy::requirePermission($user, 'legal.manage');
requireCsrfToken();
try {
    $matterId = idParam();
    $result = legalMatterActionExtractionService()->analyze($matterId, $user);
    if ($result === null) {
        jsonResponse(false, 'Legal matter not found.', [], 404);
    }
    jsonResponse(true, 'AI action suggestions updated.', ['item' => legalMatterService()->show($matterId)]);
} catch (Throwable $e) {
    validationResponse($e);
}
