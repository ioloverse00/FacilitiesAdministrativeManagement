<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_bootstrap.php';

requireMethod('POST');
requireCsrfToken();
$user = currentEmployeeUser();
$body = readJsonBody();
try {
    $item = employeeReservationService()->cancelOwn((int)employeeReservationIdentifier(), $body['reason'] ?? 'Cancelled by requester.', $user);
    if ($item === null) jsonResponse(false, 'Reservation not found.', [], 404);
    jsonResponse(true, 'Reservation cancelled.', ['item' => employeeVisibleReservation($item, $user)]);
} catch (Throwable $e) { employeeReservationValidation($e); }
