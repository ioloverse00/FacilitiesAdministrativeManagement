<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_bootstrap.php';
requireMethod('GET');
$user = currentApiUser();
FacilityRequestPolicy::requirePermission($user, 'facility_requests.view');
$id = requestIdentifier();
$row = facilityRequestService()->find($id);
if ($row === null) jsonResponse(false, 'Facility request not found.', [], 404);
jsonResponse(true, 'Facility request history retrieved.', ['items'=>facilityRequestService()->timeline((int)$row['facility_request_id'])]);