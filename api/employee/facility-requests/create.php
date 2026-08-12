<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_bootstrap.php';

requireMethod('POST');
requireCsrfToken();

$user = currentEmployeeUser();
$body = readJsonBody();

$body['requested_by_employee_reference_id'] = (int) $user['employee_id'];
$body['department_reference_id'] = (int) ($user['department']['id'] ?? $body['department_reference_id'] ?? 0);
$body['source_channel'] = 'EMPLOYEE_PORTAL';
$body['status'] = 'SUBMITTED';
$body['approval_status'] = 'NOT_REQUIRED';

try {
    $item = employeeFacilityRequestService()->create($body, $user);
    jsonResponse(true, 'Facility request submitted.', [
        'item' => employeeVisibleFacilityRequest($item),
    ], 201);
} catch (Throwable $e) {
    employeeFacilityRequestValidation($e);
}

