<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_bootstrap.php';

requireMethod('GET');

$user = currentEmployeeUser();
if (!in_array('facility_requests.view', $user['permissions'] ?? [], true) && !in_array('facility_requests.view_own', $user['permissions'] ?? [], true)) {
    jsonResponse(false, 'You do not have permission to view facility requests.', [], 403);
}
$payload = employeeFacilityRequestList($_GET, $user);
$payload['items'] = array_map('employeeVisibleFacilityRequest', $payload['items'] ?? []);

jsonResponse(true, 'Facility requests retrieved.', $payload);
