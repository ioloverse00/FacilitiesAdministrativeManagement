<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('POST');
$user = currentApiUser();
ReservationPolicy::requirePermission($user, 'reservations.approve');
requireCsrfToken();
try {
    $item = reservationService()->approve((int)reservationIdentifier(), null, $user);
    if ($item === null) jsonResponse(false, 'Reservation not found.', [], 404);
    jsonResponse(true, 'Reservation approved.', ['item' => $item]);
} catch (Throwable $e) { validationResponse($e); }
