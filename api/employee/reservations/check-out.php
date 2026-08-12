<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_bootstrap.php';

requireMethod('POST');
requireCsrfToken();
$user = currentEmployeeUser();

try {
    $item = employeeReservationService()->checkOutOwn((int) employeeReservationIdentifier(), $user);
    if ($item === null) jsonResponse(false, 'Reservation not found.', [], 404);
    jsonResponse(true, 'Reservation completed.', ['item' => employeeVisibleReservation($item, $user)]);
} catch (Throwable $e) { employeeReservationValidation($e); }
