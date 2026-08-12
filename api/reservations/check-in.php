<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('POST');
$user = currentApiUser();
ReservationPolicy::requireAnyPermission($user, ['reservations.edit', 'reservations.manage']);
requireCsrfToken();
try {
    $item = reservationService()->checkIn((int)reservationIdentifier(), $user);
    if ($item === null) jsonResponse(false, 'Reservation not found.', [], 404);
    jsonResponse(true, 'Reservation checked in.', ['item' => $item]);
} catch (Throwable $e) { validationResponse($e); }
