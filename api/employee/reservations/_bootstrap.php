<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_bootstrap.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Reservations' . DIRECTORY_SEPARATOR . 'ReservationService.php';

function employeeReservationService(): ReservationService
{
    return new ReservationService(Database::connection());
}

function employeeReservationIdentifier(): int|string
{
    $value = $_GET['id'] ?? $_GET['reservation_number'] ?? null;
    if ($value === null || $value === '') {
        jsonResponse(false, 'Reservation id or reservation_number is required.', [], 422);
    }
    return ctype_digit((string)$value) ? (int)$value : (string)$value;
}

function employeeReservationValidation(Throwable $e): void
{
    if ($e instanceof InvalidArgumentException) {
        $errors = json_decode($e->getMessage(), true);
        jsonResponse(false, 'Validation failed.', ['errors' => is_array($errors) ? $errors : ['request' => $e->getMessage()]], 422);
    }
    throw $e;
}

function employeeVisibleReservation(array $item, array $user): array
{
    return [
        'id' => $item['id'] ?? null,
        'reservationNo' => $item['reservationNo'] ?? null,
        'purpose' => $item['purpose'] ?? null,
        'reservationType' => $item['reservationType'] ?? null,
        'ai_request_summary' => $item['ai_request_summary'] ?? null,
        'request_letter' => $item['request_letter'] ?? null,
        'room' => $item['room'] ?? null,
        'roomType' => $item['roomType'] ?? null,
        'building' => $item['building'] ?? null,
        'floor' => $item['floor'] ?? null,
        'capacity' => $item['capacity'] ?? null,
        'department' => $item['department'] ?? null,
        'attendees' => $item['attendees'] ?? null,
        'approval' => $item['approval'] ?? null,
        'status' => $item['status'] ?? null,
        'start' => $item['start'] ?? null,
        'end' => $item['end'] ?? null,
        'createdAt' => $item['createdAt'] ?? null,
        'lifecycle' => [
            'setup_requirements' => $item['lifecycle']['setup_requirements'] ?? null,
            'setup_buffer_minutes' => $item['lifecycle']['setup_buffer_minutes'] ?? 0,
            'cleanup_buffer_minutes' => $item['lifecycle']['cleanup_buffer_minutes'] ?? 0,
            'approved_at' => $item['lifecycle']['approved_at'] ?? null,
            'checked_in_at' => $item['lifecycle']['checked_in_at'] ?? null,
            'checked_out_at' => $item['lifecycle']['checked_out_at'] ?? null,
            'cancellation_reason' => $item['lifecycle']['cancellation_reason'] ?? null,
            'remarks' => $item['lifecycle']['remarks'] ?? null,
        ],
        'history' => array_map(static fn(array $row): array => [
            'old_status' => $row['old_status'] ?? null,
            'new_status' => $row['new_status'] ?? null,
            'change_reason' => $row['change_reason'] ?? null,
            'changed_at' => $row['changed_at'] ?? null,
        ], $item['history'] ?? []),
        'allowed_actions' => employeeReservationService()->allowedActions($item, $user),
    ];
}
