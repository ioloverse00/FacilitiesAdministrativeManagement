<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('POST');
$user = currentApiUser();
LegalPolicy::requirePermission($user, 'legal.manage');
DocumentPolicy::requirePermission($user, 'records.view');
requireCsrfToken();
try {
    $result = legalMatterPartyService()->analyze(idParam(), $user);
    if ($result === null) {
        jsonResponse(false, 'Legal matter not found.', [], 404);
    }
    jsonResponse(true, 'AI-extracted parties updated.', ['item' => legalMatterService()->show(idParam())], 200);
} catch (Throwable $e) {
    validationResponse($e);
}
