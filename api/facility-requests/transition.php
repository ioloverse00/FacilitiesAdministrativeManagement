<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_bootstrap.php';
requireMethod('POST');
$user = currentApiUser();
FacilityRequestPolicy::requireAnyPermission($user, ['facility_requests.edit','facility_requests.assign','facility_requests.approve','facility_requests.complete','facility_requests.verify','facility_requests.manage','facility_requests.create']);
requireCsrfToken();
try {
    $body = readJsonBody();
    $status = (string)($body['status'] ?? '');
    if ($status === '') throw new InvalidArgumentException(json_encode(['status'=>'Status is required.']));
    $item = facilityRequestService()->transition((int)requestIdentifier(), $status, $body['reason'] ?? null, $user);
    if ($item === null) jsonResponse(false, 'Facility request not found.', [], 404);
    jsonResponse(true, 'Facility request status updated.', ['item'=>$item]);
} catch (Throwable $e) { validationResponse($e); }