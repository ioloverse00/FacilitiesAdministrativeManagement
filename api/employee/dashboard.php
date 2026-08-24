<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_bootstrap.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'approvals' . DIRECTORY_SEPARATOR . '_bootstrap.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Reservations' . DIRECTORY_SEPARATOR . 'ReservationService.php';

requireMethod('GET');

$user = currentEmployeeUser();
$pdo = Database::connection();
$reservationService = new ReservationService($pdo);
$reservationService->reconcileExpiredApprovedReservations();
$employeeId = (int) $user['employee_id'];
$userId = (int) $user['id'];

$scalar = static function (string $sql, array $params) use ($pdo): int {
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return (int) $statement->fetchColumn();
};

$upcomingReservations = $scalar(
    "SELECT COUNT(*) FROM facility_reservation WHERE requested_by_employee_reference_id = :employee_id AND deleted_at IS NULL AND start_datetime >= NOW() AND status NOT IN ('REJECTED','CANCELLED','COMPLETED','NO_SHOW')",
    ['employee_id' => $employeeId]
);

$pendingReservations = $scalar(
    "SELECT COUNT(*) FROM facility_reservation WHERE requested_by_employee_reference_id = :employee_id AND deleted_at IS NULL AND status = 'SUBMITTED' AND approval_status = 'PENDING'",
    ['employee_id' => $employeeId]
);

$unreadNotifications = $scalar(
    "SELECT COUNT(*) FROM notification WHERE recipient_user_id = :user_id AND is_read = 0 AND is_dismissed = 0",
    ['user_id' => $userId]
);

$assignedTasks = employeeApprovalList($user);

$activityStatement = $pdo->prepare(
    "SELECT
            'facility_reservation' entity_type,
            facility_reservation_id entity_id,
            CASE
                WHEN status='APPROVED' THEN 'Reservation approved'
                WHEN status='REJECTED' THEN 'Reservation rejected'
                WHEN status='CANCELLED' THEN 'Reservation cancelled'
                WHEN status='COMPLETED' THEN 'Reservation completed'
                WHEN status='NO_SHOW' THEN 'Reservation marked no-show'
                WHEN created_at=updated_at THEN 'Reservation submitted'
                ELSE 'Reservation updated'
            END event,
            reservation_number reference,
            purpose subject,
            status,
            COALESCE(checked_out_at,checked_in_at,approved_at,updated_at,created_at) occurred_at
        FROM facility_reservation
        WHERE requested_by_employee_reference_id = :employee_id AND deleted_at IS NULL
     ORDER BY occurred_at DESC
     LIMIT 6"
);
$activityStatement->execute(['employee_id' => $employeeId]);
$recentActivity = array_map(static function (array $row): array {
    return [
        'entity_type' => (string) $row['entity_type'],
        'entity_id' => (int) $row['entity_id'],
        'event' => (string) $row['event'],
        'reference' => (string) $row['reference'],
        'subject' => (string) $row['subject'],
        'status' => (string) $row['status'],
        'occurred_at' => $row['occurred_at'],
    ];
}, $activityStatement->fetchAll());

jsonResponse(true, 'Employee dashboard loaded.', [
    'dashboard' => [
        'generated_at' => date('c'),
        'counts' => [
            'upcoming_reservations' => $upcomingReservations,
            'pending_reservations' => $pendingReservations,
            'unread_notifications' => $unreadNotifications,
            'pending_tasks' => count($assignedTasks),
        ],
        'assigned_tasks' => array_slice($assignedTasks, 0, 3),
        'recent_activity' => $recentActivity,
    ],
]);
