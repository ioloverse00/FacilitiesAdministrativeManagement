<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_bootstrap.php';

requireMethod('GET');

$user = currentEmployeeUser();
$pdo = Database::connection();
employeeReservationService()->reconcileExpiredApprovedReservations();

$spaceId = (int) ($_GET['facility_space_id'] ?? 0);
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));

if ($spaceId < 1 || $dateFrom === '' || $dateTo === '') {
    jsonResponse(false, 'Room and date range are required.', [], 422);
}

$start = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $dateFrom . ' 00:00:00');
$end = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $dateTo . ' 23:59:59');
if (!$start || !$end || $end < $start) {
    jsonResponse(false, 'Valid date range is required.', [], 422);
}

$roomStatement = $pdo->prepare(
    "SELECT fs.facility_space_id id, fs.space_code code, fs.space_name name, fs.space_type type,
            fs.capacity, b.building_name
       FROM facility_space fs
       INNER JOIN building b ON b.building_id = fs.building_id
      WHERE fs.facility_space_id = :id
        AND fs.status = 'ACTIVE'
        AND fs.is_reservable = 1
        AND fs.deleted_at IS NULL"
);
$roomStatement->execute(['id' => $spaceId]);
$room = $roomStatement->fetch();
if (!$room) {
    jsonResponse(false, 'Active reservable room is required.', [], 422);
}

$statement = $pdo->prepare(
    "SELECT facility_reservation_id, reservation_number, requested_by_employee_reference_id,
            purpose, status, approval_status, start_datetime, end_datetime
       FROM facility_reservation
      WHERE deleted_at IS NULL
        AND facility_space_id = :space_id
        AND status IN ('SUBMITTED','APPROVED','CHECKED_IN')
        AND start_datetime <= :date_to
        AND end_datetime >= :date_from
      ORDER BY start_datetime ASC"
);
$statement->execute([
    'space_id' => $spaceId,
    'date_from' => $start->format('Y-m-d H:i:s'),
    'date_to' => $end->format('Y-m-d H:i:s'),
]);

$employeeId = (int) $user['employee_id'];
$events = array_map(static function (array $row) use ($employeeId): array {
    $self = (int) $row['requested_by_employee_reference_id'] === $employeeId;
    $event = [
        'start' => $row['start_datetime'],
        'end' => $row['end_datetime'],
        'ownership' => $self ? 'SELF' : 'OTHER',
        'occupancy' => 'RESERVED',
    ];

    if ($self) {
        $event['reservation_id'] = (int) $row['facility_reservation_id'];
        $event['reservation_number'] = (string) $row['reservation_number'];
        $event['purpose'] = (string) $row['purpose'];
        $event['status'] = (string) $row['status'];
        $event['approval_status'] = (string) $row['approval_status'];
    }

    return $event;
}, $statement->fetchAll());

jsonResponse(true, 'Room availability calendar retrieved.', [
    'room' => [
        'id' => (int) $room['id'],
        'code' => $room['code'],
        'name' => $room['name'],
        'type' => $room['type'],
        'capacity' => $room['capacity'] === null ? null : (int) $room['capacity'],
        'building_name' => $room['building_name'],
    ],
    'range' => [
        'date_from' => $start->format('Y-m-d'),
        'date_to' => $end->format('Y-m-d'),
    ],
    'events' => $events,
]);
