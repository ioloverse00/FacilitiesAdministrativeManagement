<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('POST');
$user = currentApiUser();
LegalPolicy::requirePermission($user, 'legal.manage');
DocumentPolicy::requirePermission($user, 'records.view');
requireCsrfToken();
try {
    $item = legalMatterSummaryService()->generate(idParam(), $user, false);
    if ($item === null) {
        jsonResponse(false, 'Legal matter not found.', [], 404);
    }
    jsonResponse(true, 'AI matter summary generated.', ['item' => legalMatterService()->show((int) $item['legal_matter_id'])], 200);
} catch (Throwable $e) {
    validationResponse($e);
}
