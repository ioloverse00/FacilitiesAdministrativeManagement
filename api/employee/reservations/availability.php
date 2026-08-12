<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_bootstrap.php';

requireMethod('POST');
requireCsrfToken();
$user = currentEmployeeUser();
$body = readJsonBody();
$body['requested_by_employee_reference_id'] = (int)$user['employee_id'];
$body['department_reference_id'] = (int)($user['department']['id'] ?? 0) ?: null;
$body['status'] = 'SUBMITTED';
$body['approval_status'] = 'PENDING';

try {
    $conflicts = employeeReservationService()->conflicts($body);
    jsonResponse(true, 'Availability checked.', [
        'available' => count($conflicts) === 0,
        'message' => count($conflicts) === 0 ? 'Available' : 'The selected room is unavailable during this schedule. Choose another time or room.',
    ]);
} catch (Throwable $e) { employeeReservationValidation($e); }
