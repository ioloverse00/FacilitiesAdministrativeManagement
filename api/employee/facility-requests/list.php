<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_bootstrap.php';

requireMethod('GET');

$user = currentEmployeeUser();
$payload = employeeFacilityRequestList($_GET, $user);
$payload['items'] = array_map('employeeVisibleFacilityRequest', $payload['items'] ?? []);

jsonResponse(true, 'Facility requests retrieved.', $payload);

