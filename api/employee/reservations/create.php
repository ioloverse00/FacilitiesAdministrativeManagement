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
    $item = employeeReservationService()->create($body, $user);
    jsonResponse(true, 'Reservation request submitted.', ['item' => employeeVisibleReservation($item, $user)], 201);
} catch (Throwable $e) { employeeReservationValidation($e); }
