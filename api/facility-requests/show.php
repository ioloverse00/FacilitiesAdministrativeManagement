<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_bootstrap.php';
requireMethod('GET');
$user = currentApiUser();
FacilityRequestPolicy::requirePermission($user, 'facility_requests.view');
$item = facilityRequestService()->details(requestIdentifier());
if ($item === null) jsonResponse(false, 'Facility request not found.', [], 404);
jsonResponse(true, 'Facility request retrieved.', ['item'=>$item]);