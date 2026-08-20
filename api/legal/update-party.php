<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('POST');
$user = currentApiUser();
LegalPolicy::requirePermission($user, 'legal.manage');
requireCsrfToken();
try {
    $matterId = idParam('matter_id');
    $partyId = idParam('party_id');
    $result = legalMatterPartyService()->updateParty($matterId, $partyId, readJsonBody(), $user);
    if ($result === null) {
        jsonResponse(false, 'Party not found.', [], 404);
    }
    jsonResponse(true, 'Party updated.', ['item' => legalMatterService()->show($matterId)], 200);
} catch (Throwable $e) {
    validationResponse($e);
}
