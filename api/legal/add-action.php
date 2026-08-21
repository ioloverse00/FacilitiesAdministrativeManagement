<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('POST');
$user = currentApiUser();
LegalPolicy::requirePermission($user, 'legal.manage');
requireCsrfToken();
try {
    $matterId = idParam();
    $result = legalMatterActionService()->addAction($matterId, readJsonBody(), $user);
    if ($result === null) {
        jsonResponse(false, 'Legal matter not found.', [], 404);
    }
    jsonResponse(true, 'Legal action added.', ['item' => legalMatterService()->show($matterId)], 201);
} catch (Throwable $e) {
    validationResponse($e);
}
