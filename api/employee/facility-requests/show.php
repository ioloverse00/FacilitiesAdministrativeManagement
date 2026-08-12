<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_bootstrap.php';

requireMethod('GET');

$user = currentEmployeeUser();
$item = employeeFacilityRequestService()->details(employeeFacilityRequestIdentifier());

if ($item === null || !employeeOwnsFacilityRequest($item, $user)) {
    jsonResponse(false, 'Facility request not found.', [], 404);
}

jsonResponse(true, 'Facility request retrieved.', [
    'item' => employeeVisibleFacilityRequest($item),
]);

