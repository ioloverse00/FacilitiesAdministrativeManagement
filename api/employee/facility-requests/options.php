<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_bootstrap.php';

requireMethod('GET');

$user = currentEmployeeUser();
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

