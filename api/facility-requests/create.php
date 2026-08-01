<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_bootstrap.php';
requireMethod('POST');
$user = currentApiUser();
FacilityRequestPolicy::requirePermission($user, 'facility_requests.create');
requireCsrfToken();
try {
    $item = facilityRequestService()->create(readJsonBody(), $user);
    jsonResponse(true, 'Facility request created.', ['item'=>$item], 201);
} catch (Throwable $e) { validationResponse($e); }