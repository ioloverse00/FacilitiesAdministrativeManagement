<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_bootstrap.php';
if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['POST','PATCH'], true)) jsonResponse(false, 'Method not allowed.', [], 405);
$user = currentApiUser();
FacilityRequestPolicy::requirePermission($user, 'facility_requests.edit');
requireCsrfToken();
try {
    $item = facilityRequestService()->update((int)requestIdentifier(), readJsonBody(), $user);
    if ($item === null) jsonResponse(false, 'Facility request not found.', [], 404);
    jsonResponse(true, 'Facility request updated.', ['item'=>$item]);
} catch (Throwable $e) { validationResponse($e); }