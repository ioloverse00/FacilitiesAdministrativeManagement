<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('POST');
$user = currentApiUser();
ReservationPolicy::requirePermission($user, 'reservations.create');
requireCsrfToken();
try {
    $body = str_starts_with((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data')
        ? $_POST
        : readJsonBody();
    $file = $_FILES['request_letter'] ?? null;
    if (empty($body['requested_by_employee_reference_id']) && !empty($user['employee_id'])) {
        $body['requested_by_employee_reference_id'] = (int) $user['employee_id'];
    }
    $body['status'] = strtoupper((string) ($body['status'] ?? 'SUBMITTED'));
    $body['approval_status'] = strtoupper((string) ($body['approval_status'] ?? 'PENDING'));
    $item = reservationService()->create($body, $user, is_array($file) ? $file : []);
    jsonResponse(true, 'Reservation created.', ['item' => $item], 201);
} catch (Throwable $e) { validationResponse($e); }
