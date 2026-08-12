<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('POST');
$user = currentApiUser();
ReservationPolicy::requirePermission($user, 'reservations.approve');
requireCsrfToken();
try {
    $body = readJsonBody();
    $item = reservationService()->reject(reservationIdentifier(), $body['reason'] ?? $body['remarks'] ?? null, $user);
    if ($item === null) jsonResponse(false, 'Reservation not found.', [], 404);
    jsonResponse(true, 'Reservation rejected.', ['item' => $item]);
} catch (Throwable $e) { validationResponse($e); }
