<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_bootstrap.php';

requireMethod('GET');
$user = currentEmployeeUser();
$query = $_GET;
$query['requested_by'] = (int)$user['employee_id'];
$query['sort'] = $query['sort'] ?? 'start_datetime';
$query['direction'] = $query['direction'] ?? 'asc';
$payload = employeeReservationService()->list($query);
$payload['items'] = array_map(static fn(array $item): array => employeeVisibleReservation($item, $user), $payload['items'] ?? []);
jsonResponse(true, 'Reservations retrieved.', $payload);
