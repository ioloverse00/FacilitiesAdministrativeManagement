<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_bootstrap.php';

requireMethod('GET');

$user = currentEmployeeUser();
if (!in_array('facility_requests.create', $user['permissions'] ?? [], true) && !in_array('facility_requests.view', $user['permissions'] ?? [], true) && !in_array('facility_requests.view_own', $user['permissions'] ?? [], true)) {
    jsonResponse(false, 'You do not have permission to use facility requests.', [], 403);
}
$options = employeeFacilityRequestService()->options($user);

unset($options['assignees']);

jsonResponse(true, 'Facility request options retrieved.', [
    'categories' => $options['categories'],
    'departments' => $options['departments'],
    'facility_spaces' => $options['facility_spaces'],
    'priorities' => $options['priorities'],
    'statuses' => $options['statuses'],
    'current_employee' => $options['current_employee'],
]);
