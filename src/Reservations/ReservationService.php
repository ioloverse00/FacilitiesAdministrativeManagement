<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Notifications' . DIRECTORY_SEPARATOR . 'NotificationService.php';

final class ReservationPolicy
{
    public static function hasPermission(array $user, string $permission): bool
    {
        return in_array($permission, $user['permissions'] ?? [], true);
    }

    public static function requirePermission(array $user, string $permission): void
    {
        if (!self::hasPermission($user, $permission)) {
            jsonResponse(false, 'You do not have permission to perform this action.', [], 403);
        }
    }

    public static function requireAnyPermission(array $user, array $permissions): void
    {
        foreach ($permissions as $permission) {
            if (self::hasPermission($user, $permission)) return;
        }
        jsonResponse(false, 'You do not have permission to perform this action.', [], 403);
    }
}

final class ReservationService
{
    private const STATUSES = ['SUBMITTED','APPROVED','REJECTED','CANCELLED','CHECKED_IN','COMPLETED','NO_SHOW'];
    private const LEGACY_STATUSES = ['DRAFT','PENDING','PENDING_APPROVAL','CHECKED_OUT'];
    private const TERMINAL = ['REJECTED','CANCELLED','COMPLETED','NO_SHOW'];
    private const APPROVAL_STATUSES = ['PENDING','APPROVED','REJECTED','CANCELLED'];
    private const DEFAULT_SETUP_BUFFER_MINUTES = 0;
    private const DEFAULT_CLEANUP_BUFFER_MINUTES = 0;

    public function __construct(private readonly PDO $pdo) {}

    public function list(array $query): array
    {
        $this->reconcileExpiredApprovedReservations();
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($query['per_page'] ?? 10)));
        $sortMap = ['reservation_number'=>'r.reservation_number','purpose'=>'r.purpose','start_datetime'=>'r.start_datetime','status'=>'r.status','approval_status'=>'r.approval_status','created_at'=>'r.created_at'];
        $sort = (string) ($query['sort'] ?? 'start_datetime');
        $direction = strtolower((string) ($query['direction'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
        [$where, $params] = $this->filters($query);
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM facility_reservation r INNER JOIN facility_space fs ON fs.facility_space_id=r.facility_space_id LEFT JOIN building b ON b.building_id=fs.building_id LEFT JOIN employee_reference e ON e.employee_reference_id=r.requested_by_employee_reference_id LEFT JOIN department_reference d ON d.department_reference_id=r.department_reference_id ' . $where);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $stmt = $this->pdo->prepare($this->baseSelect() . ' ' . $where . ' ORDER BY ' . ($sortMap[$sort] ?? $sortMap['start_datetime']) . ' ' . $direction . ' LIMIT :limit OFFSET :offset');
        foreach ($params as $key => $value) $stmt->bindValue($key, $value);
        $stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue('offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $stmt->execute();
        return ['items'=>array_map(fn($r)=>$this->shape($r), $stmt->fetchAll()), 'pagination'=>['page'=>$page,'per_page'=>$perPage,'total'=>$total,'total_pages'=>(int)max(1, ceil($total / $perPage))], 'summary'=>$this->summary()];
    }

    public function calendar(array $query): array
    {
        return ['items'=>$this->list(array_merge($query, ['per_page'=>200,'sort'=>'start_datetime','direction'=>'asc']))['items']];
    }

    public function options(): array
    {
        return ['statuses'=>self::STATUSES,'approval_statuses'=>self::APPROVAL_STATUSES,'reservation_types'=>$this->distinct('facility_reservation','reservation_type'),'facility_spaces'=>$this->query("SELECT fs.facility_space_id id, fs.space_code code, fs.space_name name, fs.space_type type, fs.capacity, b.building_name FROM facility_space fs INNER JOIN building b ON b.building_id=fs.building_id WHERE fs.status='ACTIVE' AND fs.is_reservable=1 AND fs.deleted_at IS NULL ORDER BY b.building_name, fs.space_name"),'buildings'=>$this->query("SELECT building_id id, building_code code, building_name name FROM building WHERE status='ACTIVE' AND deleted_at IS NULL ORDER BY building_name"),'requesters'=>$this->query("SELECT employee_reference_id id, employee_number, full_name, department_reference_id FROM employee_reference WHERE employment_status='ACTIVE' AND deleted_at IS NULL ORDER BY full_name")];
    }

    public function details(int|string $idOrNumber): ?array
    {
        $this->reconcileExpiredApprovedReservations();
        $row = $this->find($idOrNumber);
        if (!$row) return null;
        $item = $this->shape($row, true);
        $id = (int) $row['facility_reservation_id'];
        $item['participants'] = $this->query('SELECT e.employee_number,e.full_name,rp.participant_role,rp.attendance_status FROM reservation_participant rp INNER JOIN employee_reference e ON e.employee_reference_id=rp.employee_reference_id WHERE rp.facility_reservation_id=:id ORDER BY e.full_name', ['id'=>$id]);
        $item['history'] = $this->query('SELECT old_status,new_status,change_reason,changed_at FROM reservation_history WHERE facility_reservation_id=:id ORDER BY changed_at DESC', ['id'=>$id]);
        return $item;
    }

    public function employeeOwns(array $item, array $user): bool
    {
        return (int)($item['requesterId'] ?? 0) === (int)($user['employee_id'] ?? 0);
    }

    public function allowedActions(array $item, array $user): array
    {
        $status = (string)($item['status'] ?? '');
        $approval = (string)($item['approval'] ?? '');
        $isOwner = $this->employeeOwns($item, $user);
        $canAdmin = ReservationPolicy::hasPermission($user, 'reservations.manage') || ReservationPolicy::hasPermission($user, 'reservations.edit');
        $now = new DateTimeImmutable('now');
        $checkInAvailable = $status === 'APPROVED' && $approval === 'APPROVED' && $this->isWithinCheckInWindow($item, $now);
        $checkInEnded = $status === 'APPROVED' && $approval === 'APPROVED' && $this->isAfterCheckInWindow($item, $now);
        return [
            'approve' => ReservationPolicy::hasPermission($user, 'reservations.approve') && $status === 'SUBMITTED',
            'reject' => ReservationPolicy::hasPermission($user, 'reservations.approve') && $status === 'SUBMITTED',
            'cancel' => ($canAdmin || $isOwner) && in_array($status, ['SUBMITTED','APPROVED'], true),
            'check_in' => $isOwner && $checkInAvailable && empty($item['lifecycle']['checked_in_at'] ?? null),
            'check_out' => $isOwner && $status === 'CHECKED_IN' && !empty($item['lifecycle']['checked_in_at'] ?? null),
            'mark_no_show' => $canAdmin && $checkInEnded && empty($item['lifecycle']['checked_in_at'] ?? null),
            'check_in_not_yet' => $isOwner && $status === 'APPROVED' && $approval === 'APPROVED' && !$checkInAvailable && !$checkInEnded,
            'check_in_ended' => $isOwner && $checkInEnded && empty($item['lifecycle']['checked_in_at'] ?? null),
        ];
    }

    public function create(array $data, array $user): array
    {
        ReservationPolicy::requirePermission($user, 'reservations.create');
        $clean = $this->validate($data, true);
        $this->pdo->beginTransaction();
        try {
            $this->assertNoConflict($clean);
            $number = $this->nextReservationNumber();
            $stmt = $this->pdo->prepare("INSERT INTO facility_reservation (reservation_number,facility_space_id,requested_by_employee_reference_id,department_reference_id,reservation_type,purpose,expected_attendees,setup_requirements,start_datetime,end_datetime,setup_buffer_minutes,cleanup_buffer_minutes,status,approval_status,created_by_user_id,updated_by_user_id,created_at,updated_at) VALUES (:number,:space,:requester,:department,:type,:purpose,:attendees,:setup,:start,:end,:setup_buffer,:cleanup_buffer,:status,:approval,:created_by_user_id,:updated_by_user_id,NOW(),NOW())");
            $stmt->execute(['number'=>$number,'space'=>$clean['facility_space_id'],'requester'=>$clean['requested_by_employee_reference_id'],'department'=>$clean['department_reference_id'],'type'=>$clean['reservation_type'],'purpose'=>$clean['purpose'],'attendees'=>$clean['expected_attendees'],'setup'=>$clean['setup_requirements'],'start'=>$clean['start_datetime'],'end'=>$clean['end_datetime'],'setup_buffer'=>$clean['setup_buffer_minutes'],'cleanup_buffer'=>$clean['cleanup_buffer_minutes'],'status'=>$clean['status'],'approval'=>$clean['approval_status'],'created_by_user_id'=>(int)$user['id'],'updated_by_user_id'=>(int)$user['id']]);
            $id = (int)$this->pdo->lastInsertId();
            $this->addHistory($id, null, (string)$clean['status'], (int)$user['id'], 'Reservation created');
            $this->pdo->commit();
        } catch (Throwable $e) { $this->pdo->rollBack(); throw $e; }
        $row = $this->find($id);
        if ($row) $this->afterChange($user, $row, 'RESERVATION_CREATED', 'Reservation created');
        return $this->details($id) ?? ['id'=>$id,'reservationNo'=>$number];
    }

    public function submit(int $id, array $user): ?array { return $this->transition($id, 'SUBMITTED', 'Reservation submitted', $user); }
    public function approve(int $id, ?string $remarks, array $user): ?array
    {
        ReservationPolicy::requirePermission($user, 'reservations.approve');
        $before = $this->find($id); if (!$before) return null;
        $this->assertTransition((string)$before['status'], 'APPROVED');
        $this->pdo->beginTransaction();
        try {
            $this->assertNoConflict($this->cleanFromRow($before), $id);
            $this->pdo->prepare("UPDATE facility_reservation SET status='APPROVED', approval_status='APPROVED', approved_by_employee_reference_id=:employee_id, approved_at=NOW(), remarks=COALESCE(:remarks,remarks), updated_by_user_id=:user_id, updated_at=NOW() WHERE facility_reservation_id=:id AND deleted_at IS NULL")->execute(['employee_id'=>$user['employee_id'] ?? null,'remarks'=>$remarks,'user_id'=>(int)$user['id'],'id'=>$id]);
            $this->addHistory($id, (string)$before['status'], 'APPROVED', (int)$user['id'], $remarks ?: 'Reservation approved');
            $this->pdo->commit();
        } catch (Throwable $e) { $this->pdo->rollBack(); throw $e; }
        $after = $this->find($id); if ($after) $this->afterChange($user, $after, 'RESERVATION_APPROVED', 'Reservation approved');
        return $this->details($id);
    }

    public function reject(int $id, ?string $reason, array $user): ?array
    {
        ReservationPolicy::requirePermission($user, 'reservations.approve');
        return $this->transition($id, 'REJECTED', $reason ?: 'Reservation rejected', $user, ['approval_status'=>'REJECTED','remarks'=>$reason ?: 'Reservation rejected']);
    }

    public function cancel(int $id, ?string $reason, array $user): ?array
    {
        return $this->transition($id, 'CANCELLED', $reason ?: 'Reservation cancelled', $user, ['approval_status'=>'CANCELLED','cancellation_reason'=>$reason ?: 'Reservation cancelled']);
    }

    public function cancelOwn(int $id, ?string $reason, array $user): ?array
    {
        $item = $this->details($id);
        if (!$item || !$this->employeeOwns($item, $user)) return null;
        if (!$this->allowedActions($item, $user)['cancel']) throw new InvalidArgumentException(json_encode(['status'=>'This reservation can no longer be cancelled.']));
        return $this->cancelAsOwner($id, $reason ?: 'Cancelled by requester.', $user);
    }

    public function checkInOwn(int $id, array $user): ?array
    {
        $item = $this->details($id);
        if (!$item || !$this->employeeOwns($item, $user)) return null;
        if ((string)($item['status'] ?? '') !== 'APPROVED' || (string)($item['approval'] ?? '') !== 'APPROVED') throw new InvalidArgumentException(json_encode(['status'=>'Only approved reservations can be checked in.']));
        if (!empty($item['lifecycle']['checked_in_at'] ?? null)) throw new InvalidArgumentException(json_encode(['status'=>'This reservation is already checked in.']));
        $this->ensureCheckInWindow($this->find($id) ?: []);
        return $this->transition($id, 'CHECKED_IN', 'Reservation checked in by requester', $user, ['checked_in_at'=>true], false);
    }

    public function checkOutOwn(int $id, array $user): ?array
    {
        $item = $this->details($id);
        if (!$item || !$this->employeeOwns($item, $user)) return null;
        if ((string)($item['status'] ?? '') !== 'CHECKED_IN') throw new InvalidArgumentException(json_encode(['status'=>'Only checked-in reservations can be checked out.']));
        if (empty($item['lifecycle']['checked_in_at'] ?? null)) throw new InvalidArgumentException(json_encode(['status'=>'This reservation has not been checked in.']));
        if (!empty($item['lifecycle']['checked_out_at'] ?? null)) throw new InvalidArgumentException(json_encode(['status'=>'This reservation has already been checked out.']));
        return $this->transition($id, 'COMPLETED', 'Reservation completed by requester check-out', $user, ['checked_out_at'=>true], false);
    }

    public function checkIn(int $id, array $user): ?array
    {
        ReservationPolicy::requireAnyPermission($user, ['reservations.edit','reservations.manage']);
        $before = $this->find($id); if (!$before) return null;
        if ((string)$before['status'] !== 'APPROVED') throw new InvalidArgumentException(json_encode(['status'=>'Only approved reservations can be checked in.']));
        if ((string)$before['approval_status'] !== 'APPROVED') throw new InvalidArgumentException(json_encode(['approval_status'=>'Reservation approval is required before check-in.']));
        if (!empty($before['checked_in_at'])) throw new InvalidArgumentException(json_encode(['status'=>'This reservation is already checked in.']));
        $this->ensureCheckInWindow($before);
        return $this->transition($id, 'CHECKED_IN', 'Reservation checked in', $user, ['checked_in_at'=>true]);
    }

    public function checkOut(int $id, array $user): ?array
    {
        ReservationPolicy::requireAnyPermission($user, ['reservations.edit','reservations.manage']);
        $before = $this->find($id); if (!$before) return null;
        if ((string)$before['status'] !== 'CHECKED_IN') throw new InvalidArgumentException(json_encode(['status'=>'Only checked-in reservations can be checked out.']));
        if (!empty($before['checked_out_at'])) throw new InvalidArgumentException(json_encode(['status'=>'This reservation has already been checked out.']));
        return $this->transition($id, 'COMPLETED', 'Reservation completed by check-out', $user, ['checked_out_at'=>true]);
    }

    public function complete(int $id, ?string $remarks, array $user): ?array
    {
        ReservationPolicy::requireAnyPermission($user, ['reservations.complete','reservations.manage']);
        return $this->transition($id, 'COMPLETED', $remarks ?: 'Reservation completed', $user);
    }

    public function markNoShow(int $id, ?string $reason, array $user): ?array
    {
        ReservationPolicy::requireAnyPermission($user, ['reservations.edit','reservations.manage']);
        $before = $this->find($id); if (!$before) return null;
        if ((string)$before['status'] !== 'APPROVED' || (string)$before['approval_status'] !== 'APPROVED') throw new InvalidArgumentException(json_encode(['status'=>'Only approved reservations can be marked as no-show.']));
        if (!empty($before['checked_in_at'])) throw new InvalidArgumentException(json_encode(['status'=>'Checked-in reservations cannot be marked as no-show.']));
        if (!$this->isAfterCheckInWindow($before)) throw new InvalidArgumentException(json_encode(['schedule'=>'No-show is only available after the check-in period has ended.']));
        return $this->transition($id, 'NO_SHOW', $reason ?: 'Reservation marked as no-show', $user, ['remarks'=>$reason ?: 'Reservation marked as no-show']);
    }

    public function reconcileExpiredApprovedReservations(int $limit = 50): int
    {
        $limit = max(1, min(200, $limit));
        $stmt = $this->pdo->prepare($this->baseSelect() . " WHERE r.deleted_at IS NULL AND r.status='APPROVED' AND r.approval_status='APPROVED' AND r.end_datetime<NOW() AND r.checked_in_at IS NULL ORDER BY r.end_datetime ASC LIMIT :limit");
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        $count = 0;
        foreach ($rows as $row) {
            if ($this->markNoShowAutomatically($row)) $count++;
        }
        return $count;
    }

    public function transition(int $id, string $next, ?string $reason, array $user, array $extra = [], bool $enforceCancellationPermission = true): ?array
    {
        $before = $this->find($id); if (!$before) return null;
        $next = strtoupper($next);
        $this->assertTransition((string)$before['status'], $next);
        if ($next === 'CANCELLED' && $enforceCancellationPermission) ReservationPolicy::requireAnyPermission($user, ['reservations.edit','reservations.manage']);
        $this->pdo->beginTransaction();
        try {
            $fields = ['status=:status','updated_by_user_id=:user_id','updated_at=NOW()'];
            $params = ['status'=>$next,'user_id'=>(int)$user['id'],'id'=>$id];
            if (isset($extra['approval_status'])) { $fields[]='approval_status=:approval_status'; $params['approval_status']=$extra['approval_status']; }
            if (isset($extra['cancellation_reason'])) { $fields[]='cancellation_reason=:cancellation_reason'; $params['cancellation_reason']=$extra['cancellation_reason']; }
            if (isset($extra['remarks'])) { $fields[]='remarks=:remarks'; $params['remarks']=$extra['remarks']; }
            if (!empty($extra['checked_in_at'])) $fields[]='checked_in_at=COALESCE(checked_in_at,NOW())';
            if (!empty($extra['checked_out_at'])) $fields[]='checked_out_at=COALESCE(checked_out_at,NOW())';
            $this->pdo->prepare('UPDATE facility_reservation SET '.implode(', ', $fields).' WHERE facility_reservation_id=:id AND deleted_at IS NULL')->execute($params);
            $this->addHistory($id, (string)$before['status'], $next, (int)$user['id'], $reason ?: 'Status changed');
            $this->pdo->commit();
        } catch (Throwable $e) { $this->pdo->rollBack(); throw $e; }
        $after = $this->find($id); if ($after) $this->afterChange($user, $after, 'RESERVATION_'.$next, $reason ?: 'Reservation status changed');
        return $this->details($id);
    }

    public function conflicts(array $data, ?int $ignoreId = null): array
    {
        $clean = $this->validate($data, false, true);
        return $this->findConflicts($clean, $ignoreId);
    }

    private function cancelAsOwner(int $id, ?string $reason, array $user): ?array
    {
        return $this->transition($id, 'CANCELLED', $reason ?: 'Cancelled by requester.', $user, ['approval_status'=>'CANCELLED','cancellation_reason'=>$reason ?: 'Cancelled by requester.'], false);
    }

    private function markNoShowAutomatically(array $before): bool
    {
        $id = (int)$before['facility_reservation_id'];
        $reason = 'Reservation automatically marked as no-show after the check-in window ended.';
        $startedTransaction = !$this->pdo->inTransaction();
        if ($startedTransaction) $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("UPDATE facility_reservation SET status='NO_SHOW', remarks=COALESCE(NULLIF(remarks,''), :remarks), updated_by_user_id=NULL, updated_at=NOW() WHERE facility_reservation_id=:id AND deleted_at IS NULL AND status='APPROVED' AND approval_status='APPROVED' AND end_datetime<NOW() AND checked_in_at IS NULL");
            $stmt->execute(['remarks'=>$reason,'id'=>$id]);
            if ($stmt->rowCount() !== 1) {
                if ($startedTransaction) $this->pdo->rollBack();
                return false;
            }
            $this->addSystemHistory($id, 'APPROVED', 'NO_SHOW', $reason);
            if ($startedTransaction) $this->pdo->commit();
        } catch (Throwable $e) {
            if ($startedTransaction) $this->pdo->rollBack();
            throw $e;
        }
        $after = $this->find($id);
        if ($after) {
            $this->safeSystemActivity($id, (string)$after['reservation_number'], 'RESERVATION_NO_SHOW', $reason);
            $this->safeSystemAudit($id, (string)$after['reservation_number'], 'RESERVATION_NO_SHOW');
            $this->safeSystemNoShowNotification($after);
        }
        return true;
    }

    private function validate(array $data, bool $creating, bool $availabilityOnly = false): array
    {
        $errors = [];
        $space = (int)($data['facility_space_id'] ?? 0);
        if ($space < 1 || !$this->exists('facility_space','facility_space_id',$space,"status='ACTIVE' AND is_reservable=1 AND deleted_at IS NULL")) $errors['facility_space_id'] = 'Active reservable room is required.';
        $requester = (int)($data['requested_by_employee_reference_id'] ?? $data['requester_employee_reference_id'] ?? 0);
        if (!$availabilityOnly && ($requester < 1 || !$this->exists('employee_reference','employee_reference_id',$requester,"employment_status='ACTIVE' AND deleted_at IS NULL"))) $errors['requested_by_employee_reference_id'] = 'Active requester is required.';
        $department = $data['department_reference_id'] ?? null; $department = $department === '' || $department === null ? null : (int)$department;
        $purpose = trim((string)($data['purpose'] ?? ''));
        if (!$availabilityOnly && $purpose === '') $errors['purpose'] = 'Purpose is required.';
        $type = trim((string)($data['reservation_type'] ?? 'MEETING'));
        $attendees = max(1, (int)($data['expected_attendees'] ?? 1));
        if (!$availabilityOnly && $space > 0) {
            $capacity = $this->scalar('SELECT capacity FROM facility_space WHERE facility_space_id=:id', ['id'=>$space]);
            if ($capacity !== false && $capacity !== null && (int)$capacity > 0 && $attendees > (int)$capacity) $errors['expected_attendees'] = 'Attendees cannot exceed room capacity.';
        }
        $setup = max(0, min(240, (int)($data['setup_buffer_minutes'] ?? self::DEFAULT_SETUP_BUFFER_MINUTES)));
        $cleanup = max(0, min(240, (int)($data['cleanup_buffer_minutes'] ?? self::DEFAULT_CLEANUP_BUFFER_MINUTES)));
        $start = $this->nullableDateTime($data['start_datetime'] ?? null);
        $end = $this->nullableDateTime($data['end_datetime'] ?? null);
        if (!$start) $errors['start_datetime'] = 'Start date and time is required.';
        if (!$end) $errors['end_datetime'] = 'End date and time is required.';
        if ($start && $end) {
            $minutes = (strtotime($end) - strtotime($start)) / 60;
            if ($minutes < 15) $errors['end_datetime'] = 'Reservation must be at least 15 minutes.';
            if ($minutes > 1440) $errors['end_datetime'] = 'Reservation cannot exceed 24 hours.';
            if ($creating && strtotime($start) < time()) $errors['start_datetime'] = 'Reservation schedule cannot be in the past.';
        }
        $status = strtoupper((string)($data['status'] ?? 'SUBMITTED'));
        $status = match ($status) {
            'DRAFT' => $creating ? 'SUBMITTED' : 'DRAFT',
            'PENDING', 'PENDING_APPROVAL' => 'SUBMITTED',
            'CHECKED_OUT' => 'COMPLETED',
            default => $status,
        };
        if (!in_array($status, array_merge(self::STATUSES, self::LEGACY_STATUSES), true)) $errors['status'] = 'Reservation status is invalid.';
        $approval = strtoupper((string)($data['approval_status'] ?? 'PENDING'));
        if (!in_array($approval, self::APPROVAL_STATUSES, true)) $errors['approval_status'] = 'Approval status is invalid.';
        if ($errors) throw new InvalidArgumentException(json_encode($errors));
        return ['facility_space_id'=>$space,'requested_by_employee_reference_id'=>$requester,'department_reference_id'=>$department,'reservation_type'=>$type,'purpose'=>$purpose,'expected_attendees'=>$attendees,'setup_requirements'=>trim((string)($data['setup_requirements'] ?? '')) ?: null,'start_datetime'=>$start,'end_datetime'=>$end,'setup_buffer_minutes'=>$setup,'cleanup_buffer_minutes'=>$cleanup,'status'=>$status,'approval_status'=>$approval];
    }

    private function assertTransition(string $current, string $next): void
    {
        $current = match ($current) {
            'PENDING', 'PENDING_APPROVAL' => 'SUBMITTED',
            'CHECKED_OUT' => 'COMPLETED',
            default => $current,
        };
        $map = ['DRAFT'=>['SUBMITTED','CANCELLED'],'SUBMITTED'=>['APPROVED','REJECTED','CANCELLED'],'APPROVED'=>['CHECKED_IN','CANCELLED','NO_SHOW'],'CHECKED_IN'=>['COMPLETED']];
        if (!in_array($next, $map[$current] ?? [], true)) throw new InvalidArgumentException(json_encode(['status'=>"Cannot move reservation from $current to $next."]));
    }

    private function assertNoConflict(array $clean, ?int $ignoreId = null): void
    {
        if ($this->findConflicts($clean, $ignoreId)) throw new InvalidArgumentException(json_encode(['schedule'=>'The selected room is already reserved during the requested schedule.']));
    }

    private function findConflicts(array $clean, ?int $ignoreId = null): array
    {
        $effectiveStart = (new DateTimeImmutable($clean['start_datetime']))->modify('-'.(int)$clean['setup_buffer_minutes'].' minutes')->format('Y-m-d H:i:s');
        $effectiveEnd = (new DateTimeImmutable($clean['end_datetime']))->modify('+'.(int)$clean['cleanup_buffer_minutes'].' minutes')->format('Y-m-d H:i:s');
        $sql = "SELECT facility_reservation_id id,reservation_number,start_datetime,end_datetime,status FROM facility_reservation WHERE deleted_at IS NULL AND facility_space_id=:space AND status IN ('SUBMITTED','APPROVED','CHECKED_IN') AND DATE_SUB(start_datetime, INTERVAL setup_buffer_minutes MINUTE) < :effective_end AND DATE_ADD(end_datetime, INTERVAL cleanup_buffer_minutes MINUTE) > :effective_start";
        $params = ['space'=>$clean['facility_space_id'],'effective_start'=>$effectiveStart,'effective_end'=>$effectiveEnd];
        if ($ignoreId !== null) { $sql .= ' AND facility_reservation_id<>:ignore_id'; $params['ignore_id']=$ignoreId; }
        $stmt = $this->pdo->prepare($sql . ' FOR UPDATE');
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    private function ensureCheckInWindow(array $reservation): void
    {
        if (!$this->isWithinCheckInWindow($reservation)) throw new InvalidArgumentException(json_encode(['schedule'=>'Reservation check-in is only allowed from 30 minutes before start until the scheduled end.']));
    }

    private function isWithinCheckInWindow(array $reservation, ?DateTimeImmutable $now = null): bool
    {
        $startValue = $reservation['start_datetime'] ?? $reservation['start'] ?? null;
        $endValue = $reservation['end_datetime'] ?? $reservation['end'] ?? null;
        if (empty($startValue) || empty($endValue)) return false;
        $start = new DateTimeImmutable((string)$startValue);
        $end = new DateTimeImmutable((string)$endValue);
        $now ??= new DateTimeImmutable('now');
        return $now >= $start->modify('-30 minutes') && $now <= $end;
    }

    private function isAfterCheckInWindow(array $reservation, ?DateTimeImmutable $now = null): bool
    {
        $endValue = $reservation['end_datetime'] ?? $reservation['end'] ?? null;
        if (empty($endValue)) return false;
        $end = new DateTimeImmutable((string)$endValue);
        $now ??= new DateTimeImmutable('now');
        return $now > $end;
    }

    private function nextReservationNumber(): string
    {
        $year = date('Y'); $prefix = 'RR-'.$year.'-';
        $stmt = $this->pdo->prepare("SELECT reservation_number FROM facility_reservation WHERE reservation_number LIKE :prefix ORDER BY reservation_number DESC LIMIT 1 FOR UPDATE");
        $stmt->execute(['prefix'=>$prefix.'%']);
        $latest = (string)($stmt->fetchColumn() ?: '');
        $sequence = preg_match('/^RR-'.preg_quote($year, '/').'-(\d{4})$/', $latest, $m) ? (int)$m[1] + 1 : 1;
        return $prefix . str_pad((string)$sequence, 4, '0', STR_PAD_LEFT);
    }

    private function find(int|string $idOrNumber): ?array
    {
        $where = is_int($idOrNumber) ? 'r.facility_reservation_id=:value' : 'r.reservation_number=:value';
        $stmt = $this->pdo->prepare($this->baseSelect() . ' WHERE r.deleted_at IS NULL AND ' . $where . ' LIMIT 1');
        $stmt->execute(['value'=>$idOrNumber]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    private function baseSelect(): string
    {
        return "SELECT r.*, fs.space_code, fs.space_name, fs.space_type, fs.floor_number, fs.capacity, b.building_id, b.building_name, e.full_name requester_name, e.employee_number requester_number, d.department_name FROM facility_reservation r INNER JOIN facility_space fs ON fs.facility_space_id=r.facility_space_id LEFT JOIN building b ON b.building_id=fs.building_id LEFT JOIN employee_reference e ON e.employee_reference_id=r.requested_by_employee_reference_id LEFT JOIN department_reference d ON d.department_reference_id=r.department_reference_id";
    }

    private function filters(array $q): array
    {
        $where = ['r.deleted_at IS NULL']; $params = [];
        if (isset($q['id']) && ctype_digit((string)$q['id'])) { $where[]='r.facility_reservation_id=:id'; $params['id']=(int)$q['id']; }
        if (($q['search'] ?? '') !== '') { $where[]='(r.reservation_number LIKE :search OR r.purpose LIKE :search OR fs.space_name LIKE :search OR e.full_name LIKE :search OR d.department_name LIKE :search)'; $params['search']='%'.trim((string)$q['search']).'%'; }
        foreach (['status'=>'r.status','approval_status'=>'r.approval_status','facility_space_id'=>'r.facility_space_id','building_id'=>'b.building_id','requested_by'=>'r.requested_by_employee_reference_id'] as $key=>$column) if (($q[$key] ?? '') !== '' && ($q[$key] ?? 'all') !== 'all') { $where[]="$column=:$key"; $params[$key]=$q[$key]; }
        if (($q['date_from'] ?? '') !== '') { $where[]='r.start_datetime>=:date_from'; $params['date_from']=$this->dateTime((string)$q['date_from']); }
        if (($q['date_to'] ?? '') !== '') { $where[]='r.start_datetime<=:date_to'; $params['date_to']=$this->dateTime((string)$q['date_to']); }
        return [' WHERE '.implode(' AND ', $where), $params];
    }

    private function shape(array $r, bool $details = false): array
    {
        $item = ['id'=>(int)$r['facility_reservation_id'],'requesterId'=>(int)$r['requested_by_employee_reference_id'],'reservationNo'=>$r['reservation_number'],'purpose'=>$r['purpose'],'room'=>$r['space_name'],'roomType'=>$r['space_type'],'building'=>$r['building_name'],'floor'=>$r['floor_number'],'capacity'=>$r['capacity']===null?null:(int)$r['capacity'],'requester'=>$r['requester_name'],'employeeNumber'=>$r['requester_number'],'department'=>$r['department_name'],'attendees'=>(int)$r['expected_attendees'],'approval'=>$r['approval_status'],'status'=>$r['status'],'start'=>$r['start_datetime'],'end'=>$r['end_datetime'],'createdAt'=>$r['created_at']];
        if ($details) $item['lifecycle'] = ['setup_requirements'=>$r['setup_requirements'],'setup_buffer_minutes'=>(int)$r['setup_buffer_minutes'],'cleanup_buffer_minutes'=>(int)$r['cleanup_buffer_minutes'],'approved_at'=>$r['approved_at'],'checked_in_at'=>$r['checked_in_at'],'checked_out_at'=>$r['checked_out_at'],'cancellation_reason'=>$r['cancellation_reason'],'remarks'=>$r['remarks']];
        $item['allowed_actions'] = $this->allowedActions($item, ['employee_id'=>$item['requesterId'], 'permissions'=>[]]);
        return $item;
    }

    private function cleanFromRow(array $row): array
    {
        return ['facility_space_id'=>(int)$row['facility_space_id'],'start_datetime'=>$row['start_datetime'],'end_datetime'=>$row['end_datetime'],'setup_buffer_minutes'=>(int)$row['setup_buffer_minutes'],'cleanup_buffer_minutes'=>(int)$row['cleanup_buffer_minutes']];
    }

    public function dashboardSummary(): array
    {
        $this->reconcileExpiredApprovedReservations();
        return [
            'today' => (int) $this->scalar("SELECT COUNT(*) FROM facility_reservation WHERE deleted_at IS NULL AND DATE(start_datetime)=CURRENT_DATE() AND status NOT IN ('REJECTED','CANCELLED','NO_SHOW')"),
            'pending_approval' => (int) $this->scalar("SELECT COUNT(*) FROM facility_reservation WHERE deleted_at IS NULL AND status='SUBMITTED' AND approval_status='PENDING'"),
        ];
    }

    public function todayOperationalSchedule(int $limit = 5): array
    {
        $this->reconcileExpiredApprovedReservations();
        $limit = max(1, min(20, $limit));
        return $this->query("SELECT r.reservation_number reference, r.purpose title, r.start_datetime startsAt, r.end_datetime endsAt, r.status, fs.space_name room FROM facility_reservation r INNER JOIN facility_space fs ON fs.facility_space_id=r.facility_space_id WHERE r.deleted_at IS NULL AND DATE(r.start_datetime)=CURRENT_DATE() AND r.status NOT IN ('REJECTED','CANCELLED','NO_SHOW') ORDER BY r.start_datetime LIMIT $limit");
    }

    public function lastSevenDaysActivity(): array
    {
        $this->reconcileExpiredApprovedReservations();
        $timezone = new DateTimeZone('Asia/Manila');
        $today = new DateTimeImmutable('today', $timezone);
        $start = $today->modify('-6 days');
        $rows = $this->query(
            "SELECT DATE(start_datetime) activity_date, COUNT(*) total
             FROM facility_reservation
             WHERE deleted_at IS NULL
               AND DATE(start_datetime) BETWEEN :start AND :end
               AND status NOT IN ('REJECTED','CANCELLED','NO_SHOW')
             GROUP BY DATE(start_datetime)",
            ['start' => $start->format('Y-m-d'), 'end' => $today->format('Y-m-d')]
        );
        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['activity_date']] = (int) $row['total'];
        }
        $labels = [];
        $values = [];
        for ($day = 0; $day < 7; $day++) {
            $date = $start->modify("+$day days");
            $key = $date->format('Y-m-d');
            $labels[] = $date->format('M d');
            $values[] = $counts[$key] ?? 0;
        }
        return ['labels' => $labels, 'values' => $values];
    }

    private function summary(): array
    {
        return ['active'=>(int)$this->scalar("SELECT COUNT(*) FROM facility_reservation WHERE deleted_at IS NULL AND status IN ('SUBMITTED','APPROVED','CHECKED_IN')"),'today'=>(int)$this->scalar("SELECT COUNT(*) FROM facility_reservation WHERE deleted_at IS NULL AND DATE(start_datetime)=CURRENT_DATE()"),'pending_approval'=>(int)$this->scalar("SELECT COUNT(*) FROM facility_reservation WHERE deleted_at IS NULL AND status='SUBMITTED' AND approval_status='PENDING'"),'conflicts'=>0];
    }

    private function addHistory(int $id, ?string $old, string $new, int $userId, ?string $reason): void
    {
        $this->pdo->prepare('INSERT INTO reservation_history (facility_reservation_id,old_status,new_status,changed_by_user_id,change_reason,changed_at) VALUES (:id,:old,:new,:user_id,:reason,NOW())')->execute(['id'=>$id,'old'=>$old,'new'=>$new,'user_id'=>$userId,'reason'=>$reason]);
    }

    private function addSystemHistory(int $id, ?string $old, string $new, ?string $reason): void
    {
        $this->pdo->prepare('INSERT INTO reservation_history (facility_reservation_id,old_status,new_status,changed_by_user_id,change_reason,changed_at) VALUES (:id,:old,:new,NULL,:reason,NOW())')->execute(['id'=>$id,'old'=>$old,'new'=>$new,'reason'=>$reason]);
    }

    private function afterChange(array $user, array $row, string $event, string $description): void
    {
        $id = (int)$row['facility_reservation_id']; $ref = (string)$row['reservation_number'];
        $this->safeActivity($user, $id, $ref, $event, $description);
        $this->safeAudit($user, $id, $ref, $event);
        $this->safeNotify($user, $row, $event, $description);
    }

    private function safeNotify(array $user, array $row, string $event, string $description): void
    {
        try {
            $service = new NotificationService($this->pdo);
            $id = (int)$row['facility_reservation_id'];
            $reference = (string)$row['reservation_number'];
            $requester = (string)($row['requester_name'] ?? 'an employee');
            $base = ['event_code'=>$event,'module_code'=>'RESERVATIONS','notification_type'=>'IN_APP','title'=>'Room Reservation Update','message'=>$description.' '.$reference,'priority'=>'NORMAL','related_entity_type'=>'facility_reservation','related_entity_id'=>$id,'related_reference'=>$reference,'metadata'=>['resulting_status'=>$row['status'] ?? null]];
            $actorIsRequester = (int)($user['employee_id'] ?? 0) === (int)$row['requested_by_employee_reference_id'];
            if ($event === 'RESERVATION_CREATED') {
                $base['event_code'] = 'ROOM_RESERVATION_SUBMITTED';
                $base['title'] = 'New Room Reservation';
                $base['message'] = $reference . ' was submitted by ' . $requester . '.';
                $base['action_url'] = 'pages/room-reservations.html?reservation=' . $id;
                $service->createForUsers($this->reservationAdminRecipients((int)($user['id'] ?? 0), (int)$row['requested_by_employee_reference_id']), $base);
                return;
            }
            if ($event === 'RESERVATION_CANCELLED' && $actorIsRequester) {
                $base['event_code'] = 'ROOM_RESERVATION_CANCELLED_BY_REQUESTER';
                $base['title'] = 'Room Reservation Cancelled';
                $base['message'] = $reference . ' was cancelled by ' . $requester . '.';
                $base['action_url'] = 'pages/room-reservations.html?reservation=' . $id;
                $service->createForUsers($this->reservationAdminRecipients((int)($user['id'] ?? 0), (int)$row['requested_by_employee_reference_id']), $base);
                return;
            }
            if ($actorIsRequester && in_array($event, ['RESERVATION_CHECKED_IN','RESERVATION_COMPLETED'], true)) {
                return;
            }
            $stmt = $this->pdo->prepare("SELECT user_account_id FROM user_account WHERE employee_reference_id=:employee_id AND account_status='ACTIVE' AND deleted_at IS NULL LIMIT 1");
            $stmt->execute(['employee_id'=>(int)$row['requested_by_employee_reference_id']]);
            $userId = $stmt->fetchColumn();
            if ($userId) {
                $base['title'] = 'Room Reservation Update';
                $base['action_url'] = 'pages/employee/room-reservations.html?reservation=' . $id;
                $service->createForUser((int)$userId, $base);
            }
        } catch (Throwable $e) { error_log('Reservation notification failed: '.$e->getMessage()); }
    }

    private function safeSystemNoShowNotification(array $row): void
    {
        try {
            $stmt = $this->pdo->prepare("SELECT user_account_id FROM user_account WHERE employee_reference_id=:employee_id AND account_status='ACTIVE' AND deleted_at IS NULL LIMIT 1");
            $stmt->execute(['employee_id'=>(int)$row['requested_by_employee_reference_id']]);
            $userId = $stmt->fetchColumn();
            if (!$userId) return;
            $id = (int)$row['facility_reservation_id'];
            $reference = (string)$row['reservation_number'];
            (new NotificationService($this->pdo))->createForUser((int)$userId, [
                'event_code' => 'RESERVATION_NO_SHOW',
                'module_code' => 'RESERVATIONS',
                'notification_type' => 'IN_APP',
                'title' => 'Room Reservation Marked No Show',
                'message' => 'Your reservation ' . $reference . ' was marked as no-show because no check-in was recorded before the reservation ended.',
                'priority' => 'NORMAL',
                'related_entity_type' => 'facility_reservation',
                'related_entity_id' => $id,
                'related_reference' => $reference,
                'action_url' => 'pages/employee/room-reservations.html?reservation=' . $id,
                'metadata' => ['resulting_status' => 'NO_SHOW'],
            ]);
        } catch (Throwable $e) { error_log('Reservation no-show notification failed: '.$e->getMessage()); }
    }

    private function reservationAdminRecipients(int $excludeUserId = 0, int $excludeEmployeeId = 0): array
    {
        $rows = $this->query("SELECT DISTINCT ua.user_account_id FROM user_account ua INNER JOIN user_role ur ON ur.user_account_id=ua.user_account_id INNER JOIN role r ON r.role_id=ur.role_id LEFT JOIN role_permission rp ON rp.role_id=r.role_id LEFT JOIN permission p ON p.permission_id=rp.permission_id WHERE ua.account_status='ACTIVE' AND ua.deleted_at IS NULL AND (ur.expires_at IS NULL OR ur.expires_at>NOW()) AND (p.permission_code IN ('reservations.manage','reservations.approve') OR r.role_code IN ('FAM_ADMIN','SYSTEM_ADMIN','RESERVATION_OFFICER')) AND (:exclude_user_id_zero=0 OR ua.user_account_id<>:exclude_user_id_value) AND (:exclude_employee_id_zero=0 OR ua.employee_reference_id IS NULL OR ua.employee_reference_id<>:exclude_employee_id_value) ORDER BY ua.user_account_id", ['exclude_user_id_zero'=>$excludeUserId,'exclude_user_id_value'=>$excludeUserId,'exclude_employee_id_zero'=>$excludeEmployeeId,'exclude_employee_id_value'=>$excludeEmployeeId]);
        return array_map(static fn(array $row): int => (int)$row['user_account_id'], $rows);
    }

    private function safeActivity(array $user, int $id, string $reference, string $event, string $description): void
    {
        try { $this->pdo->prepare("INSERT INTO activity_event (event_uuid,module_code,entity_type,entity_id,entity_reference,event_type,event_title,event_description,actor_user_id,actor_employee_reference_id,visibility_scope,metadata_json,occurred_at,created_at) VALUES (:uuid,'RESERVATIONS','facility_reservation',:id,:reference,:event,:title,:description,:user_id,:employee_id,'INTERNAL','{}',NOW(),NOW())")->execute(['uuid'=>$this->uuid(),'id'=>$id,'reference'=>$reference,'event'=>$event,'title'=>$description,'description'=>$description,'user_id'=>(int)$user['id'],'employee_id'=>$user['employee_id'] ?? null]); } catch (Throwable $e) { error_log('Reservation activity failed: '.$e->getMessage()); }
    }

    private function safeSystemActivity(int $id, string $reference, string $event, string $description): void
    {
        try { $this->pdo->prepare("INSERT INTO activity_event (event_uuid,module_code,entity_type,entity_id,entity_reference,event_type,event_title,event_description,actor_user_id,actor_employee_reference_id,visibility_scope,metadata_json,occurred_at,created_at) VALUES (:uuid,'RESERVATIONS','facility_reservation',:id,:reference,:event,:title,:description,NULL,NULL,'INTERNAL','{}',NOW(),NOW())")->execute(['uuid'=>$this->uuid(),'id'=>$id,'reference'=>$reference,'event'=>$event,'title'=>$description,'description'=>$description]); } catch (Throwable $e) { error_log('Reservation system activity failed: '.$e->getMessage()); }
    }

    private function safeAudit(array $user, int $id, string $reference, string $event): void
    {
        try { $this->pdo->prepare("INSERT INTO audit_log (audit_uuid,actor_user_id,actor_username,action_code,module_code,entity_type,entity_id,entity_reference,result_status,request_method,request_path,metadata_json,created_at) VALUES (:uuid,:user_id,:username,:event,'RESERVATIONS','facility_reservation',:id,:reference,'SUCCESS',:method,:path,'{}',NOW())")->execute(['uuid'=>$this->uuid(),'user_id'=>(int)$user['id'],'username'=>$user['username'] ?? null,'event'=>$event,'id'=>$id,'reference'=>$reference,'method'=>$_SERVER['REQUEST_METHOD'] ?? 'CLI','path'=>$_SERVER['REQUEST_URI'] ?? '']); } catch (Throwable $e) { error_log('Reservation audit failed: '.$e->getMessage()); }
    }

    private function safeSystemAudit(int $id, string $reference, string $event): void
    {
        try { $this->pdo->prepare("INSERT INTO audit_log (audit_uuid,actor_user_id,actor_username,action_code,module_code,entity_type,entity_id,entity_reference,result_status,request_method,request_path,metadata_json,created_at) VALUES (:uuid,NULL,NULL,:event,'RESERVATIONS','facility_reservation',:id,:reference,'SUCCESS',:method,:path,'{}',NOW())")->execute(['uuid'=>$this->uuid(),'event'=>$event,'id'=>$id,'reference'=>$reference,'method'=>$_SERVER['REQUEST_METHOD'] ?? 'CLI','path'=>$_SERVER['REQUEST_URI'] ?? '']); } catch (Throwable $e) { error_log('Reservation system audit failed: '.$e->getMessage()); }
    }

    private function exists(string $table, string $column, int $id, string $extra): bool { $stmt=$this->pdo->prepare("SELECT 1 FROM $table WHERE $column=:id AND $extra LIMIT 1"); $stmt->execute(['id'=>$id]); return (bool)$stmt->fetchColumn(); }
    private function query(string $sql, array $params=[]): array { $stmt=$this->pdo->prepare($sql); $stmt->execute($params); return $stmt->fetchAll(); }
    private function scalar(string $sql, array $params=[]): mixed { $stmt=$this->pdo->prepare($sql); $stmt->execute($params); return $stmt->fetchColumn(); }
    private function distinct(string $table, string $column): array { return array_values(array_filter(array_map(fn($r)=>$r[$column], $this->query("SELECT DISTINCT `$column` FROM `$table` WHERE `$column` IS NOT NULL ORDER BY `$column`")))); }
    private function dateTime(string $value): string { return (new DateTimeImmutable($value, new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s'); }
    private function nullableDateTime(mixed $value): ?string { return $value === null || $value === '' ? null : $this->dateTime((string)$value); }
    private function uuid(): string { $d=random_bytes(16); $d[6]=chr((ord($d[6])&0x0f)|0x40); $d[8]=chr((ord($d[8])&0x3f)|0x80); return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4)); }
}
