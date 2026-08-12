<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_bootstrap.php';

requireMethod('POST');
requireCsrfToken();

$user = currentEmployeeUser();
$service = employeeFacilityRequestService();
$item = $service->details(employeeFacilityRequestIdentifier());

if ($item === null || !employeeOwnsFacilityRequest($item, $user)) {
    jsonResponse(false, 'Facility request not found.', [], 404);
}

if (!in_array((string) $item['status'], ['SUBMITTED', 'PENDING_APPROVAL'], true)) {
    jsonResponse(false, 'This request can no longer be cancelled.', [], 409);
}

$body = readJsonBody();

try {
    $updated = $service->transition((int) $item['id'], 'CANCELLED', $body['reason'] ?? 'Cancelled by requester.', $user);
    jsonResponse(true, 'Facility request cancelled.', [
        'item' => $updated === null ? null : employeeVisibleFacilityRequest($updated),
    ]);
} catch (Throwable $e) {
    employeeFacilityRequestValidation($e);
}

