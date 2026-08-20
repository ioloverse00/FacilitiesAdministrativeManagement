<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('POST');
$user = currentApiUser();
LegalPolicy::requirePermission($user, 'legal.manage');
requireCsrfToken();
try {
    $result = legalMatterPartyService()->dismissSuggestion(idParam('suggestion_id'), $user);
    if ($result === null) {
        jsonResponse(false, 'Party suggestion not found.', [], 404);
    }
    $matterId = (int) ($_GET['matter_id'] ?? 0);
    jsonResponse(true, 'Party suggestion dismissed.', ['item' => $matterId > 0 ? legalMatterService()->show($matterId) : $result], 200);
} catch (Throwable $e) {
    validationResponse($e);
}
