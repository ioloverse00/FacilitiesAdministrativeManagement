<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_bootstrap.php';

requireMethod('GET');
$user = currentEmployeeUser();
$service = employeeReservationService();
$item = $service->details(employeeReservationIdentifier());
if ($item === null || !$service->employeeOwns($item, $user)) {
    jsonResponse(false, 'Reservation not found.', [], 404);
}
jsonResponse(true, 'Reservation retrieved.', ['item' => employeeVisibleReservation($item, $user)]);
