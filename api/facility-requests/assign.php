<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_bootstrap.php';
requireMethod('POST');
$user = currentApiUser();
FacilityRequestPolicy::requirePermission($user, 'facility_requests.assign');
requireCsrfToken();
try {
    $body = readJsonBody();
    $employeeId = (int)($body['assigned_to_employee_reference_id'] ?? $body['employee_id'] ?? 0);
    if ($employeeId < 1) throw new InvalidArgumentException(json_encode(['assigned_to_employee_reference_id'=>'Assignee is required.']));
    $item = facilityRequestService()->assign((int)requestIdentifier(), $employeeId, $body['note'] ?? null, $user);
    if ($item === null) jsonResponse(false, 'Facility request not found.', [], 404);
    jsonResponse(true, 'Facility request assigned.', ['item'=>$item]);
} catch (Throwable $e) { validationResponse($e); }