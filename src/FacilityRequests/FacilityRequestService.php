<?php

declare(strict_types=1);

final class FacilityRequestPolicy
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

    public static function canEdit(array $request, array $user): bool
    {
        return self::hasPermission($user, 'facility_requests.manage')
            || (self::hasPermission($user, 'facility_requests.edit') && in_array((string) $request['status'], ['DRAFT','SUBMITTED','PENDING_APPROVAL','APPROVED'], true));
    }

    public static function canTransitionTo(array $request, array $user, string $next): bool
    {
        $map = [
            'SUBMITTED' => ['DRAFT'], 'PENDING_APPROVAL' => ['SUBMITTED'], 'APPROVED' => ['SUBMITTED','PENDING_APPROVAL'],
            'REJECTED' => ['SUBMITTED','PENDING_APPROVAL'], 'ASSIGNED' => ['SUBMITTED','APPROVED'], 'IN_PROGRESS' => ['ASSIGNED'],
            'COMPLETED' => ['IN_PROGRESS'], 'VERIFIED' => ['COMPLETED'], 'CLOSED' => ['VERIFIED'],
            'CANCELLED' => ['DRAFT','SUBMITTED','PENDING_APPROVAL','APPROVED','ASSIGNED'],
        ];
        if (!in_array((string) $request['status'], $map[$next] ?? [], true)) return false;
        return match ($next) {
            'SUBMITTED' => self::hasPermission($user, 'facility_requests.create') || self::hasPermission($user, 'facility_requests.edit'),
            'PENDING_APPROVAL','APPROVED','REJECTED' => self::hasPermission($user, 'facility_requests.approve'),
            'ASSIGNED' => self::hasPermission($user, 'facility_requests.assign'),
            'IN_PROGRESS' => self::hasPermission($user, 'facility_requests.edit') || self::hasPermission($user, 'facility_requests.assign'),
            'COMPLETED' => self::hasPermission($user, 'facility_requests.complete'),
            'VERIFIED' => self::hasPermission($user, 'facility_requests.verify'),
            'CLOSED' => self::hasPermission($user, 'facility_requests.manage') || self::hasPermission($user, 'facility_requests.verify'),
            'CANCELLED' => self::hasPermission($user, 'facility_requests.manage') || (int) $request['requested_by_employee_reference_id'] === (int) ($user['employee_id'] ?? 0),
            default => false,
        };
    }
}

final class SlaService
{
    public function __construct(private readonly PDO $pdo) {}

    public function findPolicy(int $categoryId, string $priority): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM sla_policy WHERE request_category_id=:category_id AND priority=:priority AND status='ACTIVE' AND effective_from<=CURRENT_DATE() AND (effective_to IS NULL OR effective_to>=CURRENT_DATE()) ORDER BY effective_from DESC, sla_policy_id DESC LIMIT 1");
        $stmt->execute(['category_id'=>$categoryId,'priority'=>$priority]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function initialize(int $requestId, array $policy, string $createdAt): void
    {
        $base = new DateTimeImmutable($createdAt, new DateTimeZone('Asia/Manila'));
        $due = static fn ($minutes) => $minutes === null ? null : $base->modify('+' . (int) $minutes . ' minutes')->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare('INSERT INTO sla_tracking (facility_request_id, acknowledgement_due_at, assignment_due_at, resolution_due_at, last_evaluated_at) VALUES (:id,:ack,:assign,:resolve,NOW())');
        $stmt->execute(['id'=>$requestId,'ack'=>$due($policy['acknowledgement_minutes']),'assign'=>$due($policy['assignment_minutes']),'resolve'=>$due($policy['resolution_minutes'])]);
    }

    public function markAssigned(int $requestId): void
    {
        $this->pdo->prepare('UPDATE sla_tracking SET assigned_at=NOW(), assignment_breached=CASE WHEN assignment_due_at IS NOT NULL AND NOW()>assignment_due_at THEN 1 ELSE assignment_breached END, last_evaluated_at=NOW() WHERE facility_request_id=:id')->execute(['id'=>$requestId]);
    }

    public function markResolved(int $requestId): void
    {
        $this->pdo->prepare('UPDATE sla_tracking SET resolved_at=NOW(), resolution_breached=CASE WHEN resolution_due_at IS NOT NULL AND NOW()>resolution_due_at THEN 1 ELSE resolution_breached END, last_evaluated_at=NOW() WHERE facility_request_id=:id')->execute(['id'=>$requestId]);
    }

    public static function status(?array $row, ?array $request = null): array
    {
        if ($row === null || empty($row['resolution_due_at'])) return ['status'=>'NOT_APPLICABLE','resolution_due_at'=>null,'resolution_breached'=>false,'minutes_remaining'=>null];
        $resolved = $request && in_array((string) ($request['status'] ?? ''), ['COMPLETED','VERIFIED','CLOSED','CANCELLED'], true);
        $due = new DateTimeImmutable((string) $row['resolution_due_at'], new DateTimeZone('Asia/Manila'));
        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
        $minutes = (int) floor(($due->getTimestamp() - $now->getTimestamp()) / 60);
        $threshold = max(1, (int) env('SLA_DUE_SOON_MINUTES', 480));
        $breached = (bool) $row['resolution_breached'] || (!$resolved && $minutes < 0);
        return ['status'=>$breached?'OVERDUE':($minutes <= $threshold && !$resolved ? 'DUE_SOON':'ON_TRACK'), 'resolution_due_at'=>$row['resolution_due_at'], 'resolution_breached'=>$breached, 'minutes_remaining'=>$minutes];
    }
}

final class FacilityRequestService
{
    private const PRIORITIES = ['LOW','NORMAL','MEDIUM','HIGH','CRITICAL'];
    private const STATUSES = ['DRAFT','SUBMITTED','PENDING_APPROVAL','APPROVED','ASSIGNED','IN_PROGRESS','COMPLETED','VERIFIED','CLOSED','REJECTED','CANCELLED'];
    public function __construct(private readonly PDO $pdo) {}

    public function list(array $query): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($query['per_page'] ?? 10)));
        $sortMap = ['request_number'=>'fr.request_number','subject'=>'fr.subject','priority'=>'fr.priority','status'=>'fr.status','created_at'=>'fr.created_at','requested_completion_at'=>'fr.requested_completion_at','resolution_due_at'=>'st.resolution_due_at'];
        $sort = (string) ($query['sort'] ?? 'created_at');
        $direction = strtolower((string) ($query['direction'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
        [$where, $params] = $this->filters($query);
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM facility_request fr LEFT JOIN sla_tracking st ON st.facility_request_id=fr.facility_request_id ' . $where);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $sql = $this->baseSelect() . ' ' . $where . ' ORDER BY ' . ($sortMap[$sort] ?? $sortMap['created_at']) . ' ' . $direction . ' LIMIT :limit OFFSET :offset';
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key=>$value) $stmt->bindValue($key, $value);
        $stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue('offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $stmt->execute();
        return ['items'=>array_map(fn($r)=>$this->shape($r), $stmt->fetchAll()), 'pagination'=>['page'=>$page,'per_page'=>$perPage,'total'=>$total,'total_pages'=>(int)ceil($total/$perPage)], 'summary'=>$this->summary($where, $params), 'filters'=>['search'=>$query['search']??'', 'status'=>$query['status']??null]];
    }


    private function summary(string $where, array $params): array
    {
        $sql = "SELECT
            SUM(CASE WHEN fr.status IN ('DRAFT','SUBMITTED','PENDING_APPROVAL','APPROVED','ASSIGNED','IN_PROGRESS') THEN 1 ELSE 0 END) AS `open_requests`,
            SUM(CASE WHEN fr.assigned_to_employee_reference_id IS NULL AND fr.status IN ('SUBMITTED','APPROVED') THEN 1 ELSE 0 END) AS `pending_assignment`,
            SUM(CASE WHEN fr.priority IN ('HIGH','CRITICAL') THEN 1 ELSE 0 END) AS `high_priority`,
            SUM(CASE WHEN (st.resolution_breached=1 OR (fr.status NOT IN ('COMPLETED','VERIFIED','CLOSED','CANCELLED') AND st.resolution_due_at<NOW())) THEN 1 ELSE 0 END) AS `overdue`
            FROM facility_request fr LEFT JOIN sla_tracking st ON st.facility_request_id=fr.facility_request_id " . $where;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch() ?: [];
        return [
            'open_requests' => (int) ($row['open_requests'] ?? 0),
            'pending_assignment' => (int) ($row['pending_assignment'] ?? 0),
            'high_priority' => (int) ($row['high_priority'] ?? 0),
            'overdue' => (int) ($row['overdue'] ?? 0),
        ];
    }
    private function filters(array $query): array
    {
        $where = ['fr.deleted_at IS NULL']; $params = [];
        if (($query['search'] ?? '') !== '') { $where[] = '(fr.request_number LIKE :search OR fr.subject LIKE :search OR fr.description LIKE :search)'; $params['search'] = '%' . trim((string)$query['search']) . '%'; }
        foreach (['status'=>'fr.status','priority'=>'fr.priority','category_id'=>'fr.request_category_id','department_id'=>'fr.department_reference_id','assigned_to'=>'fr.assigned_to_employee_reference_id'] as $key=>$column) {
            if (($query[$key] ?? '') !== '') { $where[] = "$column = :$key"; $params[$key] = $query[$key]; }
        }
        if (($query['date_from'] ?? '') !== '') { $where[] = 'fr.created_at >= :date_from'; $params['date_from'] = $this->dateTime((string)$query['date_from']); }
        if (($query['date_to'] ?? '') !== '') { $where[] = 'fr.created_at <= :date_to'; $params['date_to'] = $this->dateTime((string)$query['date_to']); }
        if (($query['sla_status'] ?? '') !== '') {
            $threshold = max(1, (int) env('SLA_DUE_SOON_MINUTES', 480)); $sla = strtoupper((string)$query['sla_status']);
            if ($sla === 'NOT_APPLICABLE') $where[] = '(st.sla_tracking_id IS NULL OR st.resolution_due_at IS NULL)';
            if ($sla === 'OVERDUE') $where[] = "(st.resolution_breached=1 OR (fr.status NOT IN ('COMPLETED','VERIFIED','CLOSED','CANCELLED') AND st.resolution_due_at<NOW()))";
            if ($sla === 'DUE_SOON') $where[] = "(st.resolution_due_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL $threshold MINUTE) AND st.resolution_breached=0 AND fr.status NOT IN ('COMPLETED','VERIFIED','CLOSED','CANCELLED'))";
            if ($sla === 'ON_TRACK') $where[] = "(st.resolution_due_at > DATE_ADD(NOW(), INTERVAL $threshold MINUTE) AND st.resolution_breached=0)";
        }
        return [' WHERE ' . implode(' AND ', $where), $params];
    }

    private function baseSelect(): string
    {
        return "SELECT fr.*, rc.category_code, rc.category_name, fs.space_code, fs.space_name, fs.building_id, b.building_name, req.employee_number requester_employee_number, req.full_name requester_full_name, d.department_code, d.department_name, ass.employee_number assignee_employee_number, ass.full_name assignee_full_name, st.sla_tracking_id, st.acknowledgement_due_at, st.assignment_due_at, st.resolution_due_at, st.acknowledged_at sla_acknowledged_at, st.assigned_at sla_assigned_at, st.resolved_at sla_resolved_at, st.acknowledgement_breached, st.assignment_breached, st.resolution_breached, st.last_evaluated_at FROM facility_request fr INNER JOIN request_category rc ON rc.request_category_id=fr.request_category_id INNER JOIN employee_reference req ON req.employee_reference_id=fr.requested_by_employee_reference_id LEFT JOIN department_reference d ON d.department_reference_id=fr.department_reference_id LEFT JOIN facility_space fs ON fs.facility_space_id=fr.facility_space_id LEFT JOIN building b ON b.building_id=fs.building_id LEFT JOIN employee_reference ass ON ass.employee_reference_id=fr.assigned_to_employee_reference_id LEFT JOIN sla_tracking st ON st.facility_request_id=fr.facility_request_id";
    }

    public function find(int|string $idOrNumber): ?array
    {
        $where = is_int($idOrNumber) ? 'fr.facility_request_id=:value' : 'fr.request_number=:value';
        $stmt = $this->pdo->prepare($this->baseSelect() . ' WHERE fr.deleted_at IS NULL AND ' . $where . ' LIMIT 1');
        $stmt->execute(['value'=>$idOrNumber]); $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function details(int|string $idOrNumber): ?array
    {
        $row = $this->find($idOrNumber); if (!$row) return null;
        $item = $this->shape($row, true); $id = (int)$row['facility_request_id'];
        $item['history'] = $this->timeline($id);
        $item['workflow_tasks'] = $this->rows("SELECT task_number,task_type,task_title,task_status,priority,due_at,created_at FROM workflow_task WHERE module_code='facility_requests' AND entity_type='facility_request' AND entity_id=:id ORDER BY created_at DESC", ['id'=>$id]);
        $item['approval_summary'] = $this->rows("SELECT approval_request_id,approval_status,current_step_number,total_steps,submitted_at,decided_at,completed_at FROM approval_request WHERE module_code='facility_requests' AND entity_type='facility_request' AND entity_id=:id ORDER BY created_at DESC", ['id'=>$id]);
        $item['ai_recommendations'] = $this->rows("SELECT recommendation_uuid,feature_type,suggested_category,suggested_priority,suggested_team,generated_summary,confidence_score,recommendation_status,created_at FROM ai_recommendation WHERE module_code='facility_requests' AND entity_type='facility_request' AND entity_id=:id ORDER BY created_at DESC LIMIT 5", ['id'=>$id]);
        return $item;
    }

    private function shape(array $r, bool $details = false): array
    {
        $sla = $r['sla_tracking_id'] === null ? null : $r;
        $item = ['id'=>(int)$r['facility_request_id'],'request_number'=>$r['request_number'],'subject'=>$r['subject'],'description'=>$r['description'],'category'=>['id'=>(int)$r['request_category_id'],'code'=>$r['category_code'],'name'=>$r['category_name']],'priority'=>$r['priority'],'status'=>$r['status'],'approval_status'=>$r['approval_status'],'location'=>$r['facility_space_id']===null?null:['space_id'=>(int)$r['facility_space_id'],'space_code'=>$r['space_code'],'space_name'=>$r['space_name'],'building_id'=>$r['building_id']===null?null:(int)$r['building_id'],'building_name'=>$r['building_name']],'requested_by'=>['employee_id'=>(int)$r['requested_by_employee_reference_id'],'employee_number'=>$r['requester_employee_number'],'full_name'=>$r['requester_full_name'],'department'=>$r['department_reference_id']===null?null:['id'=>(int)$r['department_reference_id'],'code'=>$r['department_code'],'name'=>$r['department_name']]],'assigned_to'=>$r['assigned_to_employee_reference_id']===null?null:['employee_id'=>(int)$r['assigned_to_employee_reference_id'],'employee_number'=>$r['assignee_employee_number'],'full_name'=>$r['assignee_full_name']],'sla'=>SlaService::status($sla,$r),'created_at'=>$r['created_at'],'updated_at'=>$r['updated_at']];
        if ($details) $item['lifecycle'] = ['requested_completion_at'=>$r['requested_completion_at'],'acknowledged_at'=>$r['acknowledged_at'],'assigned_at'=>$r['assigned_at'],'started_at'=>$r['started_at'],'completed_at'=>$r['completed_at'],'verified_at'=>$r['verified_at'],'closed_at'=>$r['closed_at'],'cancelled_at'=>$r['cancelled_at'],'cancellation_reason'=>$r['cancellation_reason'],'resolution_summary'=>$r['resolution_summary']];
        return $item;
    }

    public function options(array $user): array
    {
        return ['categories'=>$this->query("SELECT request_category_id id, category_code code, category_name name, default_priority FROM request_category WHERE status='ACTIVE' ORDER BY category_name"),'departments'=>$this->query("SELECT department_reference_id id, department_code code, department_name name FROM department_reference WHERE status='ACTIVE' ORDER BY department_name"),'facility_spaces'=>$this->query("SELECT fs.facility_space_id id, fs.space_code code, fs.space_name name, fs.space_type type, b.building_name FROM facility_space fs INNER JOIN building b ON b.building_id=fs.building_id WHERE fs.status='ACTIVE' AND fs.deleted_at IS NULL ORDER BY b.building_name, fs.space_name"),'assignees'=>$this->assignees(),'priorities'=>self::PRIORITIES,'statuses'=>self::STATUSES,'current_employee'=>['employee_id'=>$user['employee_id'],'employee_number'=>$user['employee_number'],'full_name'=>$user['full_name'],'department'=>$user['department']]];
    }

    public function create(array $data, array $user): array
    {
        $clean = $this->validate($data, true);
        $policy = (new SlaService($this->pdo))->findPolicy((int)$clean['request_category_id'], (string)$clean['priority']);
        $this->pdo->beginTransaction();
        try {
            $number = $this->nextRequestNumber();
            $stmt = $this->pdo->prepare("INSERT INTO facility_request (request_number, requested_by_employee_reference_id, department_reference_id, facility_space_id, request_category_id, sla_policy_id, subject, description, priority, source_channel, status, approval_status, requested_completion_at, created_by_user_id, updated_by_user_id, created_at, updated_at) VALUES (:request_number,:requested_by,:department_id,:space_id,:category_id,:sla_policy_id,:subject,:description,:priority,:source_channel,:status,:approval_status,:requested_completion_at,:created_by_user_id,:updated_by_user_id,NOW(),NOW())");
            $stmt->execute(['request_number'=>$number,'requested_by'=>(int)($user['employee_id'] ?? 0),'department_id'=>$clean['department_reference_id'],'space_id'=>$clean['facility_space_id'],'category_id'=>$clean['request_category_id'],'sla_policy_id'=>$policy['sla_policy_id'] ?? null,'subject'=>$clean['subject'],'description'=>$clean['description'],'priority'=>$clean['priority'],'source_channel'=>$clean['source_channel'],'status'=>$clean['status'],'approval_status'=>$clean['approval_status'],'requested_completion_at'=>$clean['requested_completion_at'],'created_by_user_id'=>(int)$user['id'],'updated_by_user_id'=>(int)$user['id']]);
            $id = (int)$this->pdo->lastInsertId();
            $this->addHistory($id, null, (string)$clean['status'], (int)$user['id'], 'Request created');
            if ($policy) (new SlaService($this->pdo))->initialize($id, $policy, date('Y-m-d H:i:s'));
            $this->pdo->commit();
        } catch (Throwable $e) { $this->pdo->rollBack(); throw $e; }
        $row = $this->find($id);
        $this->safeAudit($user, 'FACILITY_REQUEST_CREATED', 'SUCCESS', $id, $number, null, $row ? $this->shape($row, true) : []);
        $this->safeActivity($user, $id, $number, 'FACILITY_REQUEST_CREATED', 'Facility request created', (string)$clean['subject']);
        return $this->details($id) ?? ['id'=>$id,'request_number'=>$number];
    }

    public function update(int $id, array $data, array $user): ?array
    {
        $before = $this->find($id); if (!$before) return null;
        if (!FacilityRequestPolicy::canEdit($before, $user)) jsonResponse(false, 'You do not have permission to edit this request.', [], 403);
        $clean = $this->validate(array_merge($before, $data), false);
        $policy = (new SlaService($this->pdo))->findPolicy((int)$clean['request_category_id'], (string)$clean['priority']);
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("UPDATE facility_request SET department_reference_id=:department_id, facility_space_id=:space_id, request_category_id=:category_id, sla_policy_id=:sla_policy_id, subject=:subject, description=:description, priority=:priority, source_channel=:source_channel, requested_completion_at=:requested_completion_at, updated_by_user_id=:user_id, updated_at=NOW() WHERE facility_request_id=:id AND deleted_at IS NULL");
            $stmt->execute(['department_id'=>$clean['department_reference_id'],'space_id'=>$clean['facility_space_id'],'category_id'=>$clean['request_category_id'],'sla_policy_id'=>$policy['sla_policy_id'] ?? null,'subject'=>$clean['subject'],'description'=>$clean['description'],'priority'=>$clean['priority'],'source_channel'=>$clean['source_channel'],'requested_completion_at'=>$clean['requested_completion_at'],'created_by_user_id'=>(int)$user['id'],'updated_by_user_id'=>(int)$user['id'],'id'=>$id]);
            $this->pdo->commit();
        } catch (Throwable $e) { $this->pdo->rollBack(); throw $e; }
        $after = $this->find($id);
        $this->safeAudit($user, 'FACILITY_REQUEST_UPDATED', 'SUCCESS', $id, (string)$before['request_number'], $this->shape($before, true), $after ? $this->shape($after, true) : []);
        $this->safeActivity($user, $id, (string)$before['request_number'], 'FACILITY_REQUEST_UPDATED', 'Facility request updated', (string)$clean['subject']);
        return $this->details($id);
    }

    public function assign(int $id, int $employeeId, ?string $note, array $user): ?array
    {
        $before = $this->find($id); if (!$before) return null;
        FacilityRequestPolicy::requirePermission($user, 'facility_requests.assign');
        if (!$this->exists('employee_reference','employee_reference_id',$employeeId,"employment_status='ACTIVE' AND deleted_at IS NULL")) throw new InvalidArgumentException(json_encode(['assigned_to_employee_reference_id'=>'Active employee assignee is required.']));
        $this->pdo->beginTransaction();
        try {
            $old = (string)$before['status'];
            $new = in_array($old, ['SUBMITTED','APPROVED'], true) ? 'ASSIGNED' : $old;
            $stmt = $this->pdo->prepare("UPDATE facility_request SET assigned_to_employee_reference_id=:employee_id, status=:status, assigned_at=COALESCE(assigned_at,NOW()), updated_by_user_id=:user_id, updated_at=NOW() WHERE facility_request_id=:id AND deleted_at IS NULL");
            $stmt->execute(['employee_id'=>$employeeId,'status'=>$new,'user_id'=>(int)$user['id'],'id'=>$id]);
            if ($new !== $old) $this->addHistory($id, $old, $new, (int)$user['id'], $note ?: 'Assigned request');
            (new SlaService($this->pdo))->markAssigned($id);
            $this->createWorkflowTask($id, (string)$before['request_number'], $employeeId, (string)$before['priority'], (string)$before['subject']);
            $this->pdo->commit();
        } catch (Throwable $e) { $this->pdo->rollBack(); throw $e; }
        $after = $this->find($id);
        $this->safeAudit($user, 'FACILITY_REQUEST_ASSIGNED', 'SUCCESS', $id, (string)$before['request_number'], $this->shape($before, true), $after ? $this->shape($after, true) : []);
        $this->safeActivity($user, $id, (string)$before['request_number'], 'FACILITY_REQUEST_ASSIGNED', 'Facility request assigned', (string)$before['subject']);
        $this->notifyEmployee($employeeId, 'FACILITY_REQUEST_ASSIGNED', 'Facility request assigned', (string)$before['subject'], $id, (string)$before['request_number']);
        return $this->details($id);
    }

    public function transition(int $id, string $status, ?string $reason, array $user): ?array
    {
        $before = $this->find($id); if (!$before) return null;
        $next = strtoupper(trim($status));
        if (!in_array($next, self::STATUSES, true)) throw new InvalidArgumentException(json_encode(['status'=>'Unsupported facility request status.']));
        if (!FacilityRequestPolicy::canTransitionTo($before, $user, $next)) jsonResponse(false, 'You do not have permission to move this request to the requested status.', [], 403);
        $this->pdo->beginTransaction();
        try {
            $fields = ['status=:status','updated_by_user_id=:user_id','updated_at=NOW()'];
            $params = ['status'=>$next,'user_id'=>(int)$user['id'],'id'=>$id];
            if ($next === 'IN_PROGRESS') $fields[] = 'started_at=COALESCE(started_at,NOW())';
            if ($next === 'COMPLETED') { $fields[] = 'completed_at=COALESCE(completed_at,NOW())'; $fields[] = 'resolution_summary=:reason'; $params['reason']=$reason; (new SlaService($this->pdo))->markResolved($id); $this->completeWorkflowTask($id, (int)$user['id'], $reason); }
            if ($next === 'VERIFIED') $fields[] = 'verified_at=COALESCE(verified_at,NOW())';
            if ($next === 'CLOSED') $fields[] = 'closed_at=COALESCE(closed_at,NOW())';
            if ($next === 'CANCELLED') { $fields[] = 'cancelled_at=COALESCE(cancelled_at,NOW())'; $fields[]='cancellation_reason=:reason'; $params['reason']=$reason; }
            if ($next === 'REJECTED') { $fields[]='rejection_reason=:reason'; $params['reason']=$reason; }
            $this->pdo->prepare('UPDATE facility_request SET '.implode(', ',$fields).' WHERE facility_request_id=:id AND deleted_at IS NULL')->execute($params);
            $this->addHistory($id, (string)$before['status'], $next, (int)$user['id'], $reason ?: 'Status changed');
            $this->pdo->commit();
        } catch (Throwable $e) { $this->pdo->rollBack(); throw $e; }
        $after = $this->find($id);
        $this->safeAudit($user, 'FACILITY_REQUEST_STATUS_CHANGED', 'SUCCESS', $id, (string)$before['request_number'], $this->shape($before, true), $after ? $this->shape($after, true) : []);
        $this->safeActivity($user, $id, (string)$before['request_number'], 'FACILITY_REQUEST_STATUS_CHANGED', 'Facility request status changed', (string)$before['status'].' to '.$next);
        return $this->details($id);
    }

    public function timeline(int $id): array
    {
        return $this->rows("SELECT h.facility_request_history_id id,h.old_status,h.new_status,h.change_reason,h.changed_at,u.username changed_by_username FROM facility_request_history h LEFT JOIN user_account u ON u.user_account_id=h.changed_by_user_id WHERE h.facility_request_id=:id ORDER BY h.changed_at DESC,h.facility_request_history_id DESC", ['id'=>$id]);
    }

    private function validate(array $data, bool $creating): array
    {
        $errors = [];
        $subject = trim((string)($data['subject'] ?? ''));
        if ($subject === '') $errors['subject'] = 'Subject is required.';
        $categoryId = (int)($data['request_category_id'] ?? $data['category_id'] ?? 0);
        if ($categoryId < 1 || !$this->exists('request_category','request_category_id',$categoryId,"status='ACTIVE'")) $errors['request_category_id']='Active request category is required.';
        $departmentId = (int)($data['department_reference_id'] ?? $data['department_id'] ?? 0);
        if ($departmentId < 1 || !$this->exists('department_reference','department_reference_id',$departmentId,"status='ACTIVE'")) $errors['department_reference_id']='Active department is required.';
        $spaceId = $data['facility_space_id'] ?? $data['space_id'] ?? null; $spaceId = $spaceId === '' || $spaceId === null ? null : (int)$spaceId;
        if ($spaceId !== null && !$this->exists('facility_space','facility_space_id',$spaceId,"status='ACTIVE' AND deleted_at IS NULL")) $errors['facility_space_id']='Active facility space is required.';
        $priority = strtoupper((string)($data['priority'] ?? 'NORMAL'));
        if (!in_array($priority, self::PRIORITIES, true)) $errors['priority']='Priority is invalid.';
        $status = strtoupper((string)($data['status'] ?? 'SUBMITTED'));
        if (!in_array($status, self::STATUSES, true)) $errors['status']='Status is invalid.';
        if ($errors) throw new InvalidArgumentException(json_encode($errors));
        return ['subject'=>$subject,'description'=>trim((string)($data['description'] ?? '')),'request_category_id'=>$categoryId,'department_reference_id'=>$departmentId,'facility_space_id'=>$spaceId,'priority'=>$priority,'status'=>$creating ? $status : (string)($data['status'] ?? 'SUBMITTED'),'approval_status'=>(string)($data['approval_status'] ?? 'NOT_REQUIRED'),'source_channel'=>(string)($data['source_channel'] ?? 'PORTAL'),'requested_completion_at'=>$this->nullableDateTime($data['requested_completion_at'] ?? null)];
    }

    private function nextRequestNumber(): string
    {
        $year = date('Y');
        $prefix = 'FR-' . $year . '-';

        // Generate the yearly sequence inside the active transaction so the INSERT
        // and number selection are committed together. The sequence resets per year
        // and does not depend on the table auto-increment id.
        $stmt = $this->pdo->prepare("SELECT request_number FROM facility_request WHERE request_number LIKE :prefix ORDER BY request_number DESC LIMIT 1 FOR UPDATE");
        $stmt->execute(['prefix' => $prefix . '%']);
        $latest = (string) ($stmt->fetchColumn() ?: '');
        $sequence = preg_match('/^FR-' . preg_quote($year, '/') . '-(\d{4})$/', $latest, $matches) ? (int) $matches[1] + 1 : 1;

        return $prefix . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    private function createWorkflowTask(int $id, string $reference, int $assigneeId, string $priority, string $subject): void
    {
        $taskNumber = 'WT-' . date('Ymd-His') . '-' . random_int(100,999);
        $this->pdo->prepare("INSERT INTO workflow_task (task_number,module_code,entity_type,entity_id,entity_reference,task_type,task_title,task_description,assigned_to_employee_reference_id,priority,task_status,created_at,updated_at) VALUES (:task_number,'facility_requests','facility_request',:id,:reference,'FULFILLMENT',:title,:description,:assignee,:priority,'OPEN',NOW(),NOW())")->execute(['task_number'=>$taskNumber,'id'=>$id,'reference'=>$reference,'title'=>'Fulfill '.$reference,'description'=>$subject,'assignee'=>$assigneeId,'priority'=>$priority]);
    }

    private function completeWorkflowTask(int $id, int $userId, ?string $notes): void
    {
        $this->pdo->prepare("UPDATE workflow_task SET task_status='COMPLETED', completed_at=NOW(), completed_by_user_id=:user_id, completion_notes=:notes, updated_at=NOW() WHERE module_code='facility_requests' AND entity_type='facility_request' AND entity_id=:id AND task_status NOT IN ('COMPLETED','CANCELLED')")->execute(['user_id'=>$userId,'notes'=>$notes,'id'=>$id]);
    }

    private function addHistory(int $id, ?string $old, string $new, int $userId, ?string $reason): void
    {
        $this->pdo->prepare('INSERT INTO facility_request_history (facility_request_id, old_status, new_status, changed_by_user_id, change_reason, changed_at) VALUES (:id,:old,:new,:user_id,:reason,NOW())')->execute(['id'=>$id,'old'=>$old,'new'=>$new,'user_id'=>$userId,'reason'=>$reason]);
    }

    private function assignees(): array
    {
        return $this->query("SELECT employee_reference_id id, employee_number, full_name, position_title, email_address FROM employee_reference WHERE employment_status='ACTIVE' AND deleted_at IS NULL ORDER BY full_name");
    }

    private function exists(string $table, string $column, int $id, string $extra = '1=1'): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM $table WHERE $column=:id AND $extra LIMIT 1"); $stmt->execute(['id'=>$id]); return (bool)$stmt->fetchColumn();
    }

    private function query(string $sql, array $params = []): array { $stmt=$this->pdo->prepare($sql); $stmt->execute($params); return $stmt->fetchAll(); }
    private function rows(string $sql, array $params = []): array { return $this->query($sql, $params); }
    private function dateTime(string $value): string { return (new DateTimeImmutable($value))->format('Y-m-d H:i:s'); }
    private function nullableDateTime(mixed $value): ?string { return $value === null || $value === '' ? null : $this->dateTime((string)$value); }

    private function safeAudit(array $user, string $action, string $result, int $id, string $reference, ?array $old, ?array $new): void
    {
        try {
            $this->pdo->prepare('INSERT INTO audit_log (audit_uuid,actor_user_id,actor_username,action_code,module_code,entity_type,entity_id,entity_reference,result_status,ip_address,user_agent,request_method,request_path,old_values_json,new_values_json,metadata_json) VALUES (:uuid,:user_id,:username,:action,:module,:entity_type,:entity_id,:reference,:result,:ip,:agent,:method,:path,:old_json,:new_json,:metadata)')->execute(['uuid'=>$this->uuid(),'user_id'=>(int)$user['id'],'username'=>$user['username'] ?? null,'action'=>$action,'module'=>'FACILITY_REQUESTS','entity_type'=>'facility_request','entity_id'=>$id,'reference'=>$reference,'result'=>$result,'ip'=>$_SERVER['REMOTE_ADDR'] ?? null,'agent'=>substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''),0,255),'method'=>$_SERVER['REQUEST_METHOD'] ?? null,'path'=>$_SERVER['REQUEST_URI'] ?? null,'old_json'=>$old===null?null:json_encode($old, JSON_UNESCAPED_SLASHES),'new_json'=>$new===null?null:json_encode($new, JSON_UNESCAPED_SLASHES),'metadata'=>json_encode(['safe'=>true])]);
        } catch (Throwable $e) { error_log('Facility request audit failed: '.get_class($e).': '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine()); }
    }

    private function safeActivity(array $user, int $id, string $reference, string $type, string $title, string $description): void
    {
        try {
            $this->pdo->prepare("INSERT INTO activity_event (event_uuid,module_code,entity_type,entity_id,entity_reference,event_type,event_title,event_description,actor_user_id,actor_employee_reference_id,visibility_scope,metadata_json,occurred_at,created_at) VALUES (:uuid,'FACILITY_REQUESTS','facility_request',:id,:reference,:type,:title,:description,:user_id,:employee_id,'INTERNAL',:metadata,NOW(),NOW())")->execute(['uuid'=>$this->uuid(),'id'=>$id,'reference'=>$reference,'type'=>$type,'title'=>$title,'description'=>$description,'user_id'=>(int)$user['id'],'employee_id'=>$user['employee_id'] ?? null,'metadata'=>json_encode(['source'=>'api'])]);
        } catch (Throwable $e) { error_log('Facility request activity failed: '.get_class($e).': '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine()); }
    }

    private function notifyEmployee(int $employeeId, string $event, string $title, string $message, int $id, string $reference): void
    {
        try {
            $stmt=$this->pdo->prepare('SELECT user_account_id FROM user_account WHERE employee_reference_id=:employee_id AND account_status=\'ACTIVE\' AND deleted_at IS NULL LIMIT 1');
            $stmt->execute(['employee_id'=>$employeeId]); $userId=$stmt->fetchColumn(); if (!$userId) return;
            $this->pdo->prepare("INSERT INTO notification (recipient_user_id,event_code,module_code,notification_type,title,message,priority,related_entity_type,related_entity_id,related_reference,metadata_json,created_at) VALUES (:user_id,:event,'FACILITY_REQUESTS','IN_APP',:title,:message,'NORMAL','facility_request',:id,:reference,:metadata,NOW())")->execute(['user_id'=>(int)$userId,'event'=>$event,'title'=>$title,'message'=>$message,'id'=>$id,'reference'=>$reference,'metadata'=>json_encode(['source'=>'facility_requests_api'])]);
        } catch (Throwable $e) { error_log('Facility request notification failed: '.get_class($e).': '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine()); }
    }

    private function uuid(): string
    {
        $data=random_bytes(16); $data[6]=chr((ord($data[6]) & 0x0f) | 0x40); $data[8]=chr((ord($data[8]) & 0x3f) | 0x80); return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
