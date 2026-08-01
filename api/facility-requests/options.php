<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_bootstrap.php';
requireMethod('GET');
$user = currentApiUser();
FacilityRequestPolicy::requireAnyPermission($user, ['facility_requests.view','facility_requests.create']);
jsonResponse(true, 'Facility request options retrieved.', facilityRequestService()->options($user));