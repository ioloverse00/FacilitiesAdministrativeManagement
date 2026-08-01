<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_bootstrap.php';
requireMethod('GET');
$user = currentApiUser();
FacilityRequestPolicy::requirePermission($user, 'facility_requests.view');
jsonResponse(true, 'Facility requests retrieved.', facilityRequestService()->list($_GET));