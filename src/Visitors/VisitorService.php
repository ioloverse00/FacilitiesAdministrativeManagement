<?php

declare(strict_types=1);

final class VisitorService
{
    public const TYPES = ['APPLICANT','GUEST','VENDOR','CONTRACTOR','DELIVERY','WALK_IN','OTHER'];
    public const STATUSES = ['PRE_REGISTERED','PENDING_REVIEW','APPROVED','REJECTED','ARRIVED','CHECKED_IN','CHECKED_OUT','NO_SHOW','CANCELLED','EXPIRED'];
    public const APPROVALS = ['PENDING','APPROVED','REJECTED','NOT_REQUIRED'];
    public const SOURCES = ['WALK_IN','PUBLIC_PRE_REGISTRATION','ADMIN_PRE_REGISTRATION'];
    public const ID_TYPES = ['NONE','GOVERNMENT_ID','SCHOOL_ID','COMPANY_ID','PASSPORT','DRIVER_LICENSE','OTHER'];

    public function __construct(private readonly PDO $pdo) {}

    public function list(array $q): array
    {
        [$where,$params] = $this->filters($q);
        $sorts = ['visitor_reference_number'=>'vi.visit_number','full_name'=>'v.last_name','visitor_type'=>'COALESCE(vi.visitor_type,v.visitor_type)','scheduled_start_at'=>'vi.scheduled_arrival','visit_status'=>'vi.visit_status','approval_status'=>'vi.approval_status','created_at'=>'vi.created_at'];
        $sort = $sorts[(string)($q['sort'] ?? '')] ?? 'vi.scheduled_arrival';
        $dir = strtolower((string)($q['direction'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
        $page = max(1, (int)($q['page'] ?? 1));
        $per = min(100, max(1, (int)($q['per_page'] ?? 10)));
        $base = $this->baseSql().' WHERE '.implode(' AND ', $where);
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM ('.$base.') x');
        $count->execute($params);
        $total = (int)$count->fetchColumn();
        $stmt = $this->pdo->prepare($base." ORDER BY $sort $dir LIMIT :limit OFFSET :offset");
        foreach($params as $k=>$v) $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        $stmt->bindValue('limit', $per, PDO::PARAM_INT);
        $stmt->bindValue('offset', ($page - 1) * $per, PDO::PARAM_INT);
        $stmt->execute();
        return ['items'=>array_map(fn($r)=>$this->shape($r), $stmt->fetchAll()), 'pagination'=>['page'=>$page,'per_page'=>$per,'total'=>$total,'total_pages'=>(int)ceil($total / max(1,$per))], 'summary'=>$this->summary()];
    }

    public function show(int $id): ?array
    {
        $stmt = $this->pdo->prepare($this->baseSql().' WHERE vi.visit_id=:id AND vi.deleted_at IS NULL LIMIT 1');
        $stmt->execute(['id'=>$id]);
        $row = $stmt->fetch();
        if (!is_array($row)) return null;
        $item = $this->shape($row) + [
            'visit_description'=>$row['visit_description'] ?? null,
            'identity_verification'=>['verified'=>(bool)$row['identity_verified'],'verified_at'=>$row['identity_verified_at'],'verified_by'=>$row['verified_by_username'],'identification_type'=>$row['id_type'],'identification_last4'=>$row['identification_last4']],
            'remarks'=>$row['remarks'],
            'history'=>$this->history($id),
            'recent_activity'=>$this->activity($id),
        ];
        return $item;
    }

    public function scanLookup(string $token, array $user): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{32,128}$/', $token)) {
            throw new DomainException('Unsupported or malformed visitor QR code.');
        }
        return $this->scannerResult($this->findByQrToken($token), $user, true);
    }

    public function manualLookup(string $query, array $user): array
    {
        $query = trim($query);
        if ($query === '') {
            throw new InvalidArgumentException(json_encode(['query' => 'Enter a Visitor Reference or QR Token.']));
        }
        if (preg_match('/^VIS-\d{4}-\d{4,}$/i', $query)) {
            return $this->scannerResult($this->findByReference(strtoupper($query)), $user, false);
        }
        if (preg_match('/^[A-Za-z0-9_-]{32,128}$/', $query)) {
            return $this->scannerResult($this->findByQrToken($query), $user, true);
        }
        throw new DomainException('Visitor pass or reference was not found.');
    }

    public function options(array $user): array
    {
        return ['visitor_types'=>self::TYPES,'visit_statuses'=>self::STATUSES,'approval_statuses'=>self::APPROVALS,'registration_sources'=>self::SOURCES,'identity_document_types'=>self::ID_TYPES,'departments'=>$this->rows("SELECT department_reference_id id, department_code code, department_name name FROM department_reference WHERE status='ACTIVE' ORDER BY department_name"),'host_employees'=>$this->rows("SELECT employee_reference_id id, employee_number, full_name, position_title FROM employee_reference WHERE employment_status='ACTIVE' AND deleted_at IS NULL ORDER BY full_name"),'facility_spaces'=>$this->rows("SELECT fs.facility_space_id id, fs.space_code code, fs.space_name name, b.building_name FROM facility_space fs LEFT JOIN building b ON b.building_id=fs.building_id WHERE fs.status='ACTIVE' AND fs.deleted_at IS NULL ORDER BY b.building_name, fs.space_name"),'available_badges'=>$this->rows("SELECT visitor_badge_id id, badge_number FROM visitor_badge WHERE badge_status='AVAILABLE' ORDER BY badge_number"),'permissions'=>$user['permissions'] ?? []];
    }

    public function createWalkIn(array $data, array $user): array
    {
        $errors = $this->validate($data, true);
        if ($errors) throw new InvalidArgumentException(json_encode($errors));
        $this->pdo->beginTransaction();
        try {
            $this->assertNoActiveVisitForEmail($this->blankNull($data['email_address'] ?? null));
            $visitorId = $this->createVisitor($data);
            $reference = $this->nextReference();
            $status = (($data['approval_status'] ?? '') === 'PENDING') ? 'PENDING_REVIEW' : 'ARRIVED';
            $approval = $status === 'PENDING_REVIEW' ? 'PENDING' : 'NOT_REQUIRED';
            $stmt = $this->pdo->prepare("INSERT INTO visit (visit_number, visitor_id, visitor_type, host_employee_reference_id, destination_department_reference_id, destination_space_id, purpose, visit_description, scheduled_arrival, scheduled_departure, actual_time_in, actual_time_out, visit_status, approval_status, registration_source, applicant_reference, company_or_school, remarks, created_by_user_id, updated_by_user_id, created_at, updated_at) VALUES (:ref,:visitor_id,:visitor_type,:host,:department,:space,:purpose,:description,:start,:end,NULL,NULL,:status,:approval,'WALK_IN',:applicant_reference,:company,:remarks,:created_by,:updated_by,NOW(),NOW())");
            $stmt->execute(['ref'=>$reference,'visitor_id'=>$visitorId,'visitor_type'=>$data['visitor_type'],'host'=>$this->nullableInt($data['host_employee_reference_id'] ?? null) ?? (int)$user['employee_id'],'department'=>$this->nullableInt($data['destination_department_reference_id'] ?? null),'space'=>$this->nullableInt($data['facility_space_id'] ?? null),'purpose'=>trim((string)$data['visit_purpose']),'description'=>$this->blankNull($data['visit_description'] ?? null),'start'=>$this->dateValue($data['scheduled_start_at'] ?? null) ?? date('Y-m-d H:i:s'),'end'=>$this->dateValue($data['scheduled_end_at'] ?? null),'status'=>$status,'approval'=>$approval,'applicant_reference'=>$this->blankNull($data['applicant_reference'] ?? null),'company'=>$this->blankNull($data['company_or_school'] ?? $data['organization_name'] ?? null),'remarks'=>$this->blankNull($data['remarks'] ?? null),'created_by'=>(int)$user['id'],'updated_by'=>(int)$user['id']]);
            $id = (int)$this->pdo->lastInsertId();
            $this->historyInsert($id, null, $status, 'VISITOR_WALKIN_REGISTERED', $data['remarks'] ?? 'Walk-in registered.', $user);
            $this->pdo->commit();
            $this->telemetry('VISITOR_WALKIN_REGISTERED', $id, $reference, $user, 'Walk-in visitor registered.');
            return $this->show($id) ?? ['id'=>$id, 'visitor_reference_number'=>$reference];
        } catch (Throwable $e) { $this->pdo->rollBack(); throw $e; }
    }

    public function review(int $id, string $action, ?string $remarks, array $user): array
    {
        $map = ['APPROVE'=>['APPROVED','APPROVED','VISITOR_APPROVED'],'REJECT'=>['REJECTED','REJECTED','VISITOR_REJECTED'],'MARK_NO_SHOW'=>['NO_SHOW','PENDING','VISITOR_MARKED_NO_SHOW'],'CANCEL'=>['CANCELLED','PENDING','VISITOR_CANCELLED']];
        if (!isset($map[$action])) throw new InvalidArgumentException(json_encode(['action'=>'Unsupported review action.']));
        [$status,$approval,$event] = $map[$action];
        return $this->transition($id, $status, $approval, $event, $remarks, $user, ['PRE_REGISTERED','PENDING_REVIEW','APPROVED','ARRIVED']);
    }

    public function checkIn(int $id, array $data, array $user): array
    {
        $this->pdo->beginTransaction();
        try {
            $row = $this->lockedVisit($id);
            if (!$row) throw new RuntimeException('NOT_FOUND');
            if (!in_array($row['visit_status'], ['APPROVED','ARRIVED'], true)) throw new DomainException('Visit is not eligible for check-in.');
            $badgeId = $this->nullableInt($data['badge_id'] ?? null);
            if ($badgeId) $this->issueBadge($badgeId, $id, $user);
            $this->pdo->prepare("UPDATE visitor SET id_type=COALESCE(:id_type,id_type), identification_last4=COALESCE(:last4,identification_last4), updated_at=NOW() WHERE visitor_id=:visitor_id")->execute(['id_type'=>$this->blankNull($data['identification_type'] ?? null),'last4'=>$this->blankNull($data['identification_last4'] ?? null),'visitor_id'=>(int)$row['visitor_id']]);
            $stmt = $this->pdo->prepare("UPDATE visit SET visit_status='CHECKED_IN', approval_status=IF(approval_status='PENDING','APPROVED',approval_status), actual_time_in=NOW(), identity_verified=:verified, identity_verified_at=IF(:verified_for_at=1,NOW(),identity_verified_at), identity_verified_by_user_id=IF(:verified_for_user=1,:verified_user_id,identity_verified_by_user_id), visitor_badge_id=:badge_id, updated_by_user_id=:updated_by_user_id, updated_at=NOW() WHERE visit_id=:id");
            $stmt->execute(['verified'=>!empty($data['identity_verified'])?1:0,'verified_for_at'=>!empty($data['identity_verified'])?1:0,'verified_for_user'=>!empty($data['identity_verified'])?1:0,'verified_user_id'=>(int)$user['id'],'updated_by_user_id'=>(int)$user['id'],'badge_id'=>$badgeId,'id'=>$id]);
            $this->historyInsert($id, $row['visit_status'], 'CHECKED_IN', 'VISITOR_CHECKED_IN', $data['remarks'] ?? 'Visitor checked in.', $user);
            $this->pdo->commit();
            $this->telemetry('VISITOR_CHECKED_IN', $id, $row['visit_number'], $user, 'Visitor checked in.');
            return $this->show($id) ?? [];
        } catch (Throwable $e) { $this->pdo->rollBack(); throw $e; }
    }

    public function checkOut(int $id, ?string $remarks, array $user): array
    {
        $this->pdo->beginTransaction();
        try {
            $row = $this->lockedVisit($id);
            if (!$row) throw new RuntimeException('NOT_FOUND');
            if ($row['visit_status'] !== 'CHECKED_IN') throw new DomainException('Visit is not currently checked in.');
            if (!empty($row['visitor_badge_id'])) $this->returnBadge((int)$row['visitor_badge_id'], $user);
            $this->pdo->prepare("UPDATE visit SET visit_status='CHECKED_OUT', actual_time_out=NOW(), updated_by_user_id=:user_id, updated_at=NOW() WHERE visit_id=:id")->execute(['user_id'=>(int)$user['id'],'id'=>$id]);
            $this->historyInsert($id, 'CHECKED_IN', 'CHECKED_OUT', 'VISITOR_CHECKED_OUT', $remarks ?? 'Visitor checked out.', $user);
            $this->pdo->commit();
            $this->telemetry('VISITOR_CHECKED_OUT', $id, $row['visit_number'], $user, 'Visitor checked out.');
            return $this->show($id) ?? [];
        } catch (Throwable $e) { $this->pdo->rollBack(); throw $e; }
    }

    public function update(int $id, array $data, array $user): array
    {
        $errors = $this->validate($data, false);
        if ($errors) throw new InvalidArgumentException(json_encode($errors));
        $row = $this->findRaw($id);
        if (!$row) throw new RuntimeException('NOT_FOUND');
        if (in_array($row['visit_status'], ['CHECKED_IN','CHECKED_OUT','CANCELLED','REJECTED'], true)) throw new DomainException('Visit can no longer be edited.');
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("UPDATE visitor SET first_name=:first,last_name=:last,organization_name=:org,visitor_type=:type,email_address=:email,contact_number=:mobile,id_type=:id_type,identification_last4=:last4,updated_at=NOW() WHERE visitor_id=:visitor_id")->execute($this->visitorParams($data)+['visitor_id'=>(int)$row['visitor_id']]);
            $this->pdo->prepare("UPDATE visit SET visitor_type=:type, destination_department_reference_id=:dept, host_employee_reference_id=:host, destination_space_id=:space, purpose=:purpose, visit_description=:description, scheduled_arrival=:start, scheduled_departure=:end, applicant_reference=:applicant, company_or_school=:company, remarks=:remarks, updated_by_user_id=:user_id, updated_at=NOW() WHERE visit_id=:id")->execute(['type'=>$data['visitor_type'],'dept'=>$this->nullableInt($data['destination_department_reference_id'] ?? null),'host'=>$this->nullableInt($data['host_employee_reference_id'] ?? null),'space'=>$this->nullableInt($data['facility_space_id'] ?? null),'purpose'=>trim((string)$data['visit_purpose']),'description'=>$this->blankNull($data['visit_description'] ?? null),'start'=>$this->dateValue($data['scheduled_start_at'] ?? null) ?? $row['scheduled_arrival'],'end'=>$this->dateValue($data['scheduled_end_at'] ?? null),'applicant'=>$this->blankNull($data['applicant_reference'] ?? null),'company'=>$this->blankNull($data['company_or_school'] ?? $data['organization_name'] ?? null),'remarks'=>$this->blankNull($data['remarks'] ?? null),'user_id'=>(int)$user['id'],'id'=>$id]);
            $this->historyInsert($id, $row['visit_status'], $row['visit_status'], 'VISITOR_UPDATED', 'Visitor record updated.', $user);
            $this->pdo->commit();
            $this->telemetry('VISITOR_UPDATED', $id, $row['visit_number'], $user, 'Visitor record updated.');
            return $this->show($id) ?? [];
        } catch (Throwable $e) { $this->pdo->rollBack(); throw $e; }
    }

    public function history(int $id): array
    {
        return $this->rows("SELECT h.changed_at timestamp,h.event_type,h.old_status,h.new_status,h.remarks,u.username actor FROM visitor_visit_history h LEFT JOIN user_account u ON u.user_account_id=h.changed_by_user_id WHERE h.visit_id=:id ORDER BY h.changed_at DESC,h.visitor_visit_history_id DESC", ['id'=>$id]);
    }

    private function validate(array $d, bool $creating): array
    {
        $e=[]; if (trim((string)($d['full_name'] ?? ''))==='') $e['full_name']='Full name is required.'; if (!in_array((string)($d['visitor_type'] ?? ''), self::TYPES, true)) $e['visitor_type']='Valid visitor type is required.'; if (trim((string)($d['visit_purpose'] ?? ''))==='') $e['visit_purpose']='Purpose is required.';
        if (($d['email_address'] ?? '') !== '' && !filter_var((string)$d['email_address'], FILTER_VALIDATE_EMAIL)) $e['email_address']='Enter a valid email address.';
        if (($d['mobile_number'] ?? '') !== '' && !preg_match('/^[0-9+() .-]{7,30}$/', (string)$d['mobile_number'])) $e['mobile_number']='Enter a valid mobile number.';
        if (($d['identification_last4'] ?? '') !== '' && !preg_match('/^[A-Za-z0-9-]{1,16}$/', (string)$d['identification_last4'])) $e['identification_last4']='Use up to 16 safe ID characters.';
        $start=$this->dateValue($d['scheduled_start_at'] ?? null); $end=$this->dateValue($d['scheduled_end_at'] ?? null); if ($start && $end && strtotime($end) <= strtotime($start)) $e['scheduled_end_at']='End must be after start.';
        foreach(['destination_department_reference_id'=>['department_reference','department_reference_id'],'host_employee_reference_id'=>['employee_reference','employee_reference_id'],'facility_space_id'=>['facility_space','facility_space_id']] as $field=>$target) if (($d[$field] ?? '') !== '' && !$this->exists($target[0], $target[1], (int)$d[$field])) $e[$field]='Selected value is invalid.';
        if (($d['visitor_type'] ?? '') === 'APPLICANT' && empty($d['destination_department_reference_id']) && empty($d['host_employee_reference_id'])) $e['destination_department_reference_id']='Applicant visitors require a destination department or host.';
        return $e;
    }

    private function filters(array $q): array
    {
        $where=['vi.deleted_at IS NULL','v.deleted_at IS NULL']; $params=[];
        $exact=['visitor_type'=>'COALESCE(vi.visitor_type,v.visitor_type)','visit_status'=>'vi.visit_status','approval_status'=>'vi.approval_status','department_id'=>'vi.destination_department_reference_id','host_employee_id'=>'vi.host_employee_reference_id','facility_space_id'=>'vi.destination_space_id','registration_source'=>'vi.registration_source'];
        foreach($exact as $k=>$col) if (($q[$k] ?? '') !== '' && ($q[$k] ?? 'all') !== 'all') { $where[]="$col=:$k"; $params[$k]=$q[$k]; }
        if (($q['currently_checked_in'] ?? '') === '1') $where[]="vi.visit_status='CHECKED_IN'";
        if (($q['date_from'] ?? '') !== '') { $where[]='vi.scheduled_arrival>=:date_from'; $params['date_from']=$this->dateValue($q['date_from']); }
        if (($q['date_to'] ?? '') !== '') { $where[]='vi.scheduled_arrival<=:date_to'; $params['date_to']=$this->dateValue($q['date_to']); }
        if (($q['search'] ?? '') !== '') { $where[]="(vi.visit_number LIKE :search OR CONCAT(v.first_name,' ',v.last_name) LIKE :search OR v.email_address LIKE :search OR v.contact_number LIKE :search OR v.organization_name LIKE :search OR vi.purpose LIKE :search OR vi.applicant_reference LIKE :search)"; $params['search']='%'.trim((string)$q['search']).'%'; }
        return [$where,$params];
    }

    private function baseSql(): string
    {
        return "SELECT vi.*, v.first_name,v.middle_name,v.last_name,v.organization_name,v.email_address,v.contact_number,v.id_type,v.identification_last4,v.status visitor_profile_status, d.department_code,d.department_name, h.employee_number host_employee_number,h.full_name host_full_name, fs.space_name, b.building_name, bg.badge_number,bg.badge_status,u.username verified_by_username FROM visit vi INNER JOIN visitor v ON v.visitor_id=vi.visitor_id LEFT JOIN department_reference d ON d.department_reference_id=vi.destination_department_reference_id LEFT JOIN employee_reference h ON h.employee_reference_id=vi.host_employee_reference_id LEFT JOIN facility_space fs ON fs.facility_space_id=vi.destination_space_id LEFT JOIN building b ON b.building_id=fs.building_id LEFT JOIN visitor_badge bg ON bg.visitor_badge_id=vi.visitor_badge_id LEFT JOIN user_account u ON u.user_account_id=vi.identity_verified_by_user_id";
    }

    private function findByQrToken(string $token): ?array
    {
        $stmt = $this->pdo->prepare($this->baseSql().' WHERE vi.qr_token_hash=:hash AND vi.deleted_at IS NULL LIMIT 1');
        $stmt->execute(['hash'=>hash('sha256', $token)]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    private function findByReference(string $reference): ?array
    {
        $stmt = $this->pdo->prepare($this->baseSql().' WHERE vi.visit_number=:reference AND vi.deleted_at IS NULL LIMIT 1');
        $stmt->execute(['reference'=>$reference]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    private function scannerResult(?array $row, array $user, bool $requiresValidQr): array
    {
        if (!$row) {
            throw new DomainException('Visitor pass or reference was not found.');
        }
        if ($requiresValidQr) {
            if (empty($row['qr_token_hash'])) throw new DomainException('Visitor pass was not found.');
            if (!empty($row['qr_token_revoked_at'])) throw new DomainException('This visitor pass has been revoked.');
            $expiresAt = strtotime((string)($row['qr_token_expires_at'] ?? ''));
            if ($expiresAt === false || $expiresAt < time()) throw new DomainException('This visitor pass has expired.');
        }
        $item = $this->shape($row);
        $status = (string)$row['visit_status'];
        $idv = ['verified'=>(bool)$row['identity_verified'],'identification_type'=>$row['id_type'],'identification_last4'=>$row['identification_last4'],'verified_by'=>$row['verified_by_username'],'verified_at'=>$row['identity_verified_at']];
        return [
            'visit'=>[
                'id'=>(int)$row['visit_id'],
                'visitor_reference_number'=>$row['visit_number'],
                'status'=>$status,
                'approval_status'=>$row['approval_status'],
                'registration_source'=>$row['registration_source'],
                'purpose'=>$row['purpose'],
                'actual_check_in_at'=>$row['actual_time_in'],
                'actual_check_out_at'=>$row['actual_time_out'],
                'scheduled_start_at'=>$row['scheduled_arrival'],
                'scheduled_end_at'=>$row['scheduled_departure'],
            ],
            'visitor'=>$item['visitor'],
            'destination'=>[
                'department_name'=>$row['department_name'],
                'facility_space_name'=>$row['space_name'],
                'host_name'=>$row['host_full_name'],
            ],
            'identity_verification'=>$idv,
            'badge'=>$item['badge'],
            'allowed_actions'=>$this->allowedScannerActions($status, $idv, $user),
            'state_message'=>$this->scannerStateMessage($status),
        ];
    }

    private function allowedScannerActions(string $status, array $idv, array $user): array
    {
        $actions = [];
        if (in_array($status, ['PENDING_REVIEW','PRE_REGISTERED'], true)) {
            if (VisitorPolicy::can($user, 'visitors.approve')) {
                $actions[] = 'APPROVE';
                $actions[] = 'REJECT';
            }
            return $actions;
        }
        if (in_array($status, ['APPROVED','ARRIVED'], true)) {
            if (VisitorPolicy::can($user, 'visitors.checkin')) {
                $actions[] = 'VERIFY_IDENTITY';
                $actions[] = 'CHECK_IN';
            }
            return $actions;
        }
        if ($status === 'CHECKED_IN' && VisitorPolicy::can($user, 'visitors.checkout')) {
            $actions[] = 'CHECK_OUT';
        }
        return $actions;
    }

    private function scannerStateMessage(string $status): string
    {
        return [
            'PENDING_REVIEW'=>'Review Required',
            'PRE_REGISTERED'=>'Review Required',
            'APPROVED'=>'Ready for Identity Verification',
            'ARRIVED'=>'Ready for Identity Verification',
            'CHECKED_IN'=>'Active Visit',
            'CHECKED_OUT'=>'Visit Completed',
            'REJECTED'=>'Entry Not Approved',
            'CANCELLED'=>'Visit Cancelled',
            'NO_SHOW'=>'No Show',
            'EXPIRED'=>'Pass Expired',
        ][$status] ?? 'Visitor Located';
    }

    private function shape(array $r): array
    {
        $full = trim(($r['first_name'] ?? '').' '.($r['middle_name'] ?? '').' '.($r['last_name'] ?? ''));
        return ['id'=>(int)$r['visit_id'],'visitor_reference_number'=>$r['visit_number'],'visitor'=>['id'=>(int)$r['visitor_id'],'full_name'=>$full,'email_address'=>$r['email_address'],'mobile_number'=>$r['contact_number'],'organization_name'=>$r['organization_name'],'visitor_type'=>$r['visitor_type'] ?: $r['visitor_type']],'visit_purpose'=>$r['purpose'],'destination_department'=>$r['destination_department_reference_id']===null?null:['id'=>(int)$r['destination_department_reference_id'],'code'=>$r['department_code'],'name'=>$r['department_name']],'host'=>$r['host_employee_reference_id']===null?null:['employee_id'=>(int)$r['host_employee_reference_id'],'employee_number'=>$r['host_employee_number'],'full_name'=>$r['host_full_name']],'facility_space'=>$r['destination_space_id']===null?null:['id'=>(int)$r['destination_space_id'],'name'=>$r['space_name'],'building_name'=>$r['building_name']],'scheduled_start_at'=>$r['scheduled_arrival'],'scheduled_end_at'=>$r['scheduled_departure'],'actual_check_in_at'=>$r['actual_time_in'],'actual_check_out_at'=>$r['actual_time_out'],'visit_status'=>$r['visit_status'],'approval_status'=>$r['approval_status'],'registration_source'=>$r['registration_source'],'applicant_reference'=>$r['applicant_reference'],'company_or_school'=>$r['company_or_school'],'badge'=>$r['visitor_badge_id']===null?null:['id'=>(int)$r['visitor_badge_id'],'badge_number'=>$r['badge_number'],'status'=>$r['badge_status']],'created_at'=>$r['created_at']];
    }

    private function createVisitor(array $d): int { $this->pdo->prepare("INSERT INTO visitor (visitor_uuid, first_name, middle_name, last_name, organization_name, visitor_type, email_address, contact_number, id_type, identification_last4, status, created_at, updated_at) VALUES (UUID(),:first,NULL,:last,:org,:type,:email,:mobile,:id_type,:last4,'ACTIVE',NOW(),NOW())")->execute($this->visitorParams($d)); return (int)$this->pdo->lastInsertId(); }
    private function assertNoActiveVisitForEmail(?string $email): void { if ($email === null) return; $s=$this->pdo->prepare("SELECT vi.visit_id FROM visit vi INNER JOIN visitor v ON v.visitor_id=vi.visitor_id WHERE LOWER(v.email_address)=:email AND vi.deleted_at IS NULL AND v.deleted_at IS NULL AND vi.visit_status IN ('PENDING_REVIEW','APPROVED','ARRIVED','CHECKED_IN') LIMIT 1 FOR UPDATE"); $s->execute(['email'=>strtolower(trim($email))]); if ($s->fetchColumn() !== false) throw new DomainException('You already have an active visitor registration. Please complete or check out from your current visit before registering another visit.'); }
    private function visitorParams(array $d): array { [$first,$last]=$this->nameParts((string)$d['full_name']); return ['first'=>$first,'last'=>$last,'org'=>$this->blankNull($d['organization_name'] ?? null),'type'=>$d['visitor_type'],'email'=>$this->blankNull($d['email_address'] ?? null),'mobile'=>$this->blankNull($d['mobile_number'] ?? null),'id_type'=>$this->blankNull($d['identification_type'] ?? null),'last4'=>$this->blankNull($d['identification_last4'] ?? null)]; }
    private function nameParts(string $name): array { $name=trim(preg_replace('/\s+/', ' ', $name)); $parts=explode(' ', $name); if(count($parts)===1) return [$name,'Visitor']; $last=array_pop($parts); return [implode(' ', $parts), $last]; }
    private function nextReference(): string { $year=(int)date('Y'); $this->pdo->exec("INSERT INTO visitor_sequence (sequence_year,last_number) VALUES ($year,0) ON DUPLICATE KEY UPDATE sequence_year=sequence_year"); $stmt=$this->pdo->query("SELECT last_number FROM visitor_sequence WHERE sequence_year=$year FOR UPDATE"); $next=(int)$stmt->fetchColumn()+1; $this->pdo->exec("UPDATE visitor_sequence SET last_number=$next WHERE sequence_year=$year"); return sprintf('VIS-%d-%04d',$year,$next); }
    private function lockedVisit(int $id): ?array { $s=$this->pdo->prepare('SELECT * FROM visit WHERE visit_id=:id AND deleted_at IS NULL FOR UPDATE'); $s->execute(['id'=>$id]); $r=$s->fetch(); return is_array($r)?$r:null; }
    private function findRaw(int $id): ?array { $s=$this->pdo->prepare('SELECT * FROM visit WHERE visit_id=:id AND deleted_at IS NULL'); $s->execute(['id'=>$id]); $r=$s->fetch(); return is_array($r)?$r:null; }
    private function transition(int $id,string $status,string $approval,string $event,?string $remarks,array $user,array $allowed): array { $row=$this->findRaw($id); if(!$row) throw new RuntimeException('NOT_FOUND'); if($row['visit_status']==='CHECKED_OUT') throw new DomainException('Checked-out visits cannot be changed.'); if(!in_array($row['visit_status'],$allowed,true)) throw new DomainException('Visit status does not allow this action.'); $this->pdo->prepare('UPDATE visit SET visit_status=:status, approval_status=:approval, updated_by_user_id=:user_id, updated_at=NOW() WHERE visit_id=:id')->execute(['status'=>$status,'approval'=>$approval,'user_id'=>(int)$user['id'],'id'=>$id]); $this->historyInsert($id,$row['visit_status'],$status,$event,$remarks,$user); $this->telemetry($event,$id,$row['visit_number'],$user,$event); return $this->show($id) ?? []; }
    private function issueBadge(int $badgeId,int $visitId,array $user): void { $s=$this->pdo->prepare("SELECT * FROM visitor_badge WHERE visitor_badge_id=:id FOR UPDATE"); $s->execute(['id'=>$badgeId]); $b=$s->fetch(); if(!is_array($b) || $b['badge_status']!=='AVAILABLE') throw new DomainException('Badge is not available.'); $this->pdo->prepare("UPDATE visitor_badge SET badge_status='ISSUED', issued_to_visit_id=:visit, issued_at=NOW(), returned_at=NULL, issued_by_user_id=:user WHERE visitor_badge_id=:id")->execute(['visit'=>$visitId,'user'=>(int)$user['id'],'id'=>$badgeId]); }
    private function returnBadge(int $badgeId,array $user): void { $this->pdo->prepare("UPDATE visitor_badge SET badge_status='AVAILABLE', issued_to_visit_id=NULL, returned_at=NOW(), returned_to_user_id=:user WHERE visitor_badge_id=:id")->execute(['user'=>(int)$user['id'],'id'=>$badgeId]); }
    private function historyInsert(int $id,?string $old,string $new,string $event,?string $remarks,array $user): void { $this->pdo->prepare('INSERT INTO visitor_visit_history (visit_id,old_status,new_status,event_type,remarks,changed_by_user_id,changed_at) VALUES (:id,:old,:new,:event,:remarks,:user,NOW())')->execute(['id'=>$id,'old'=>$old,'new'=>$new,'event'=>$event,'remarks'=>$remarks,'user'=>(int)$user['id']]); }
    private function activity(int $id): array { return $this->rows("SELECT event_type,event_title,event_description,occurred_at FROM activity_event WHERE module_code='VISITORS' AND entity_type='visit' AND entity_id=:id ORDER BY occurred_at DESC LIMIT 10", ['id'=>$id]); }
    private function summary(): array { return ['today'=>(int)$this->scalar("SELECT COUNT(*) FROM visit WHERE deleted_at IS NULL AND DATE(scheduled_arrival)=CURRENT_DATE()"),'pre_registered'=>(int)$this->scalar("SELECT COUNT(*) FROM visit WHERE deleted_at IS NULL AND visit_status='PRE_REGISTERED'"),'pending_review'=>(int)$this->scalar("SELECT COUNT(*) FROM visit WHERE deleted_at IS NULL AND visit_status='PENDING_REVIEW'"),'checked_in'=>(int)$this->scalar("SELECT COUNT(*) FROM visit WHERE deleted_at IS NULL AND visit_status='CHECKED_IN'"),'checked_out'=>(int)$this->scalar("SELECT COUNT(*) FROM visit WHERE deleted_at IS NULL AND visit_status='CHECKED_OUT'"),'applicants_today'=>(int)$this->scalar("SELECT COUNT(*) FROM visit vi JOIN visitor v ON v.visitor_id=vi.visitor_id WHERE vi.deleted_at IS NULL AND DATE(vi.scheduled_arrival)=CURRENT_DATE() AND COALESCE(vi.visitor_type,v.visitor_type)='APPLICANT'")]; }
    private function telemetry(string $event,int $id,string $ref,array $user,string $title): void
    {
        try {
            $activity = $this->pdo->prepare("INSERT INTO activity_event (event_uuid,module_code,entity_type,entity_id,entity_reference,event_type,event_title,event_description,actor_user_id,actor_employee_reference_id,visibility_scope,metadata_json,occurred_at,created_at) VALUES (:activity_uuid,'VISITORS','visit',:activity_id,:activity_ref,:activity_event,:activity_title,:activity_description,:activity_user,:activity_employee,'INTERNAL','{}',NOW(),NOW())");
            $activity->execute(['activity_uuid'=>$this->uuid(),'activity_id'=>$id,'activity_ref'=>$ref,'activity_event'=>$event,'activity_title'=>$title,'activity_description'=>$title,'activity_user'=>(int)$user['id'],'activity_employee'=>$user['employee_id'] ?? null]);
            $audit = $this->pdo->prepare("INSERT INTO audit_log (audit_uuid,actor_user_id,actor_username,action_code,module_code,entity_type,entity_id,entity_reference,result_status,request_method,request_path,metadata_json,created_at) VALUES (:audit_uuid,:audit_user,:audit_username,:audit_event,'VISITORS','visit',:audit_id,:audit_ref,'SUCCESS',:audit_method,:audit_path,'{}',NOW())");
            $audit->execute(['audit_uuid'=>$this->uuid(),'audit_user'=>(int)$user['id'],'audit_username'=>$user['username'] ?? null,'audit_event'=>$event,'audit_id'=>$id,'audit_ref'=>$ref,'audit_method'=>$_SERVER['REQUEST_METHOD'] ?? 'CLI','audit_path'=>$_SERVER['REQUEST_URI'] ?? '']);
        } catch(Throwable $e) { error_log('Visitor telemetry failed: '.$e->getMessage()); }
    }    private function exists(string $table,string $key,int $id): bool { $s=$this->pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE `$key`=:id"); $s->execute(['id'=>$id]); return (int)$s->fetchColumn()>0; }
    private function rows(string $sql,array $params=[]): array { $s=$this->pdo->prepare($sql); $s->execute($params); return $s->fetchAll(); }
    private function scalar(string $sql,array $params=[]): mixed { $s=$this->pdo->prepare($sql); $s->execute($params); return $s->fetchColumn(); }
    private function nullableInt(mixed $v): ?int { return ($v === null || $v === '' || $v === 'all') ? null : (int)$v; }
    private function blankNull(mixed $v): ?string { $v=trim((string)($v ?? '')); return $v===''?null:$v; }
    private function dateValue(mixed $v): ?string { if ($v === null || trim((string)$v)==='') return null; $t=strtotime((string)$v); return $t ? date('Y-m-d H:i:s',$t) : null; }
    private function uuid(): string { $d=random_bytes(16); $d[6]=chr((ord($d[6])&0x0f)|0x40); $d[8]=chr((ord($d[8])&0x3f)|0x80); return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($d),4)); }
}

