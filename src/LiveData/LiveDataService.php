<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Reservations' . DIRECTORY_SEPARATOR . 'ReservationService.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Visitors' . DIRECTORY_SEPARATOR . 'VisitorService.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Documents' . DIRECTORY_SEPARATOR . 'DocumentService.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'RecordsRetention' . DIRECTORY_SEPARATOR . 'RetentionService.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Contracts' . DIRECTORY_SEPARATOR . 'ContractService.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Legal' . DIRECTORY_SEPARATOR . 'LegalMatterService.php';

final class LiveDataService
{
    public function __construct(private readonly PDO $pdo) {}

    public function dashboard(): array
    {
        $reservations = new ReservationService($this->pdo);
        $visitors = new VisitorService($this->pdo);
        $documents = new DocumentService($this->pdo);
        $retention = new RetentionService($this->pdo);
        $contracts = new ContractService($this->pdo);
        $legal = new LegalMatterService($this->pdo);
        $reservationSummary = $reservations->dashboardSummary();
        $visitorSummary = $visitors->dashboardSummary();
        $documentSummary = $documents->dashboardSummary();
        $retentionAttentionCount = RetentionService::countRecordsRequiringReview($this->pdo);
        $contractSummary = $contracts->dashboardSummary();
        $legalSummary = $legal->dashboardSummary();
        return [
            'generatedAt' => date('c'),
            'kpis' => [
                'reservationsToday' => $reservationSummary['today'] ?? 0,
                'reservationsPendingApproval' => $reservationSummary['pending_approval'] ?? 0,
                'visitorsCheckedIn' => $visitorSummary['checked_in'] ?? 0,
                'visitorsToday' => $visitorSummary['today'] ?? 0,
                'activeDocuments' => $documentSummary['active'] ?? 0,
                'recentDocuments' => $documentSummary['recent'] ?? 0,
                'recordsDispositionDue' => $retentionAttentionCount,
                'contractsPendingReviewApproval' => $contractSummary['pendingReviewApproval'] ?? 0,
                'contractsActive' => $contractSummary['active'] ?? 0,
                'contractsExpiringSoon' => $contractSummary['expiringSoon'] ?? 0,
                'legalOpen' => $legalSummary['open'] ?? 0,
                'legalCritical' => $legalSummary['critical'] ?? 0,
            ],
            'charts' => [
                'reservation_activity' => $reservations->lastSevenDaysActivity(),
                'operational_overview' => [
                    'labels' => ['Pending Reservations', 'Visitors Currently Inside', 'Retention Attention', 'Open Legal Matters'],
                    'values' => [
                        $reservationSummary['pending_approval'] ?? 0,
                        $visitorSummary['checked_in'] ?? 0,
                        $retentionAttentionCount,
                        $legalSummary['open'] ?? 0,
                    ],
                ],
            ],
            'todaySchedule' => $reservations->todayOperationalSchedule(),
            'retentionAttention' => $retention->attentionQueue(),
            'recentActivities' => $this->recentActivities(),
        ];
    }

    public function reportsOverview(): array
    {
        return [
            'generatedAt' => date('c'),
            'facilityRequestsByStatus' => $this->groupRows('facility_request', 'status'),
            'facilityRequestsByPriority' => $this->groupRows('facility_request', 'priority'),
            'maintenanceByStatus' => $this->groupRows('maintenance_work_order', 'status'),
            'maintenanceByType' => $this->groupRows('maintenance_work_order', 'maintenance_type'),
            'assetsByCondition' => $this->groupRows('asset', 'condition_status'),
            'assetsByLifecycle' => $this->groupRows('asset', 'lifecycle_status'),
            'reservationsByStatus' => $this->groupRows('facility_reservation', 'status'),
            'procurementByStatus' => $this->groupRows('procurement_request', 'status'),
            'procurementByIntegration' => $this->groupRows('procurement_request', 'integration_status'),
            'recordsByStatus' => $this->groupRows('record', 'record_status'),
            'sla' => [
                ['label' => 'On Track', 'value' => (int) $this->scalar("SELECT COUNT(*) FROM sla_tracking st INNER JOIN facility_request fr ON fr.facility_request_id=st.facility_request_id WHERE fr.deleted_at IS NULL AND st.resolution_breached=0")],
                ['label' => 'Overdue', 'value' => (int) $this->scalar("SELECT COUNT(*) FROM sla_tracking st INNER JOIN facility_request fr ON fr.facility_request_id=st.facility_request_id WHERE fr.deleted_at IS NULL AND st.resolution_breached=1")]
            ]
        ];
    }

    public function maintenanceOptions(): array
    {
        return [
            'types' => $this->distinct('maintenance_work_order', 'maintenance_type'),
            'statuses' => $this->distinct('maintenance_work_order', 'status'),
            'priorities' => $this->distinct('maintenance_work_order', 'priority'),
            'assignees' => $this->employees(),
            'facility_spaces' => $this->spaces(),
            'assets' => $this->assetOptions()
        ];
    }

    public function maintenanceList(array $q): array
    {
        $sql = "SELECT mwo.*, fs.space_name, b.building_name, a.asset_code, a.asset_name, e.full_name assigned_to_name, fr.request_number facility_request_number FROM maintenance_work_order mwo LEFT JOIN facility_space fs ON fs.facility_space_id=mwo.facility_space_id LEFT JOIN building b ON b.building_id=fs.building_id LEFT JOIN asset a ON a.asset_id=mwo.asset_id LEFT JOIN employee_reference e ON e.employee_reference_id=mwo.assigned_to_employee_reference_id LEFT JOIN facility_request fr ON fr.facility_request_id=mwo.facility_request_id";
        [$where, $params] = $this->filters('mwo', $q, ['status'=>'mwo.status','priority'=>'mwo.priority','maintenance_type'=>'mwo.maintenance_type','assigned_to'=>'mwo.assigned_to_employee_reference_id','facility_space_id'=>'mwo.facility_space_id','asset_id'=>'mwo.asset_id'], ['mwo.work_order_number','mwo.problem_description','a.asset_name','fs.space_name']);
        $sorts = ['work_order_number'=>'mwo.work_order_number','priority'=>'mwo.priority','status'=>'mwo.status','maintenance_type'=>'mwo.maintenance_type','scheduled_start_at'=>'mwo.scheduled_start_at','created_at'=>'mwo.created_at'];
        return $this->paged($sql, $where, $params, $q, $sorts, 'mwo.created_at', fn($r) => $this->shapeMaintenance($r), $this->maintenanceSummary());
    }

    public function streamMaintenanceCsv(array $q, mixed $handle): void
    {
        $sql = "SELECT mwo.*, fs.space_name, b.building_name, a.asset_code, a.asset_name, e.full_name assigned_to_name, fr.request_number facility_request_number FROM maintenance_work_order mwo LEFT JOIN facility_space fs ON fs.facility_space_id=mwo.facility_space_id LEFT JOIN building b ON b.building_id=fs.building_id LEFT JOIN asset a ON a.asset_id=mwo.asset_id LEFT JOIN employee_reference e ON e.employee_reference_id=mwo.assigned_to_employee_reference_id LEFT JOIN facility_request fr ON fr.facility_request_id=mwo.facility_request_id";
        [$where, $params] = $this->filters('mwo', $q, ['status'=>'mwo.status','priority'=>'mwo.priority','maintenance_type'=>'mwo.maintenance_type','assigned_to'=>'mwo.assigned_to_employee_reference_id','facility_space_id'=>'mwo.facility_space_id','asset_id'=>'mwo.asset_id'], ['mwo.work_order_number','mwo.problem_description','a.asset_name','fs.space_name']);
        $this->streamCsv($handle, ['Work Order No.', 'Title / Summary', 'Maintenance Type', 'Asset', 'Facility / Space', 'Priority', 'Assigned Employee', 'Scheduled Date', 'Completion Date', 'Status', 'Created', 'Updated'], $sql, $where, $params, $q, ['work_order_number'=>'mwo.work_order_number','priority'=>'mwo.priority','status'=>'mwo.status','maintenance_type'=>'mwo.maintenance_type','scheduled_start_at'=>'mwo.scheduled_start_at','created_at'=>'mwo.created_at'], 'mwo.created_at', function (array $r): array {
            return [$r['work_order_number'], $r['problem_description'], $r['maintenance_type'], $r['asset_name'], trim(($r['building_name'] ?? '') . ' / ' . ($r['space_name'] ?? ''), ' /'), $r['priority'], $r['assigned_to_name'], $r['scheduled_start_at'], $r['actual_end_at'], $r['status'], $r['created_at'], $r['updated_at']];
        });
    }

    public function maintenanceShow(int $id): ?array
    {
        $item = $this->single($this->maintenanceList(['id'=>$id, 'per_page'=>1]), $id);
        if (!$item) return null;
        $item['history'] = $this->rows('SELECT old_status,new_status,change_reason,changed_at FROM maintenance_history WHERE maintenance_work_order_id=:id ORDER BY changed_at DESC', ['id'=>$id]);
        $item['materials'] = $this->rows('SELECT item_description,quantity,unit_of_measure,total_cost,remarks FROM maintenance_material WHERE maintenance_work_order_id=:id ORDER BY maintenance_material_id', ['id'=>$id]);
        return $item;
    }

    public function assetsOptions(): array { return ['categories'=>$this->query("SELECT asset_category_id id, category_code code, category_name name FROM asset_category WHERE status='ACTIVE' ORDER BY category_name"),'conditions'=>$this->distinct('asset','condition_status'),'lifecycles'=>$this->distinct('asset','lifecycle_status'),'facility_spaces'=>$this->spaces(),'custodians'=>$this->employees()]; }
    public function assetsList(array $q): array
    {
        $sql = "SELECT a.*, ac.category_name, fs.space_name, b.building_name, e.full_name custodian_name, s.supplier_name FROM asset a INNER JOIN asset_category ac ON ac.asset_category_id=a.asset_category_id LEFT JOIN facility_space fs ON fs.facility_space_id=a.facility_space_id LEFT JOIN building b ON b.building_id=fs.building_id LEFT JOIN employee_reference e ON e.employee_reference_id=a.custodian_employee_reference_id LEFT JOIN supplier_reference s ON s.supplier_reference_id=a.supplier_reference_id";
        [$where, $params] = $this->filters('a', $q, ['category_id'=>'a.asset_category_id','condition_status'=>'a.condition_status','lifecycle_status'=>'a.lifecycle_status','facility_space_id'=>'a.facility_space_id','custodian_id'=>'a.custodian_employee_reference_id'], ['a.asset_code','a.property_number','a.asset_name','a.serial_number','fs.space_name','e.full_name']);
        if (($q['maintenance_due'] ?? '') !== '') $this->maintenanceDueFilter($where, $params, (string)$q['maintenance_due']);
        $sorts = ['asset_code'=>'a.asset_code','asset_name'=>'a.asset_name','category'=>'ac.category_name','condition_status'=>'a.condition_status','lifecycle_status'=>'a.lifecycle_status','next_maintenance_date'=>'a.next_maintenance_date','created_at'=>'a.created_at'];
        return $this->paged($sql, $where, $params, $q, $sorts, 'a.created_at', fn($r) => $this->shapeAsset($r), $this->assetSummary());
    }

    public function streamAssetsCsv(array $q, mixed $handle): void
    {
        $sql = "SELECT a.*, ac.category_name, fs.space_name, b.building_name, e.full_name custodian_name, s.supplier_name FROM asset a INNER JOIN asset_category ac ON ac.asset_category_id=a.asset_category_id LEFT JOIN facility_space fs ON fs.facility_space_id=a.facility_space_id LEFT JOIN building b ON b.building_id=fs.building_id LEFT JOIN employee_reference e ON e.employee_reference_id=a.custodian_employee_reference_id LEFT JOIN supplier_reference s ON s.supplier_reference_id=a.supplier_reference_id";
        [$where, $params] = $this->filters('a', $q, ['category_id'=>'a.asset_category_id','condition_status'=>'a.condition_status','lifecycle_status'=>'a.lifecycle_status','facility_space_id'=>'a.facility_space_id','custodian_id'=>'a.custodian_employee_reference_id'], ['a.asset_code','a.property_number','a.asset_name','a.serial_number','fs.space_name','e.full_name']);
        if (($q['maintenance_due'] ?? '') !== '') $this->maintenanceDueFilter($where, $params, (string)$q['maintenance_due']);
        $this->streamCsv($handle, ['Asset No./Tag', 'Property No.', 'Asset Name', 'Category', 'Location / Facility Space', 'Custodian', 'Condition', 'Lifecycle / Status', 'Acquisition Date', 'Maintenance Due Date', 'Created', 'Updated'], $sql, $where, $params, $q, ['asset_code'=>'a.asset_code','asset_name'=>'a.asset_name','category'=>'ac.category_name','condition_status'=>'a.condition_status','lifecycle_status'=>'a.lifecycle_status','next_maintenance_date'=>'a.next_maintenance_date','created_at'=>'a.created_at'], 'a.created_at', function (array $r): array {
            return [$r['asset_code'], $r['property_number'], $r['asset_name'], $r['category_name'], trim(($r['building_name'] ?? '') . ' / ' . ($r['space_name'] ?? ''), ' /'), $r['custodian_name'], $r['condition_status'], $r['lifecycle_status'], $r['acquisition_date'], $r['next_maintenance_date'], $r['created_at'], $r['updated_at']];
        });
    }
    public function assetShow(int $id): ?array { $item=$this->findAsset($id); if(!$item)return null; $item['history']=$this->rows('SELECT event_type,old_condition_status,new_condition_status,old_lifecycle_status,new_lifecycle_status,remarks,changed_at FROM asset_history WHERE asset_id=:id ORDER BY changed_at DESC',['id'=>$id]); $item['work_orders']=$this->rows('SELECT work_order_number,status,priority,created_at FROM maintenance_work_order WHERE asset_id=:id AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 10',['id'=>$id]); return $item; }

    public function reservationsOptions(): array { return ['statuses'=>$this->distinct('facility_reservation','status'),'approval_statuses'=>$this->distinct('facility_reservation','approval_status'),'reservation_types'=>$this->distinct('facility_reservation','reservation_type'),'facility_spaces'=>$this->reservableSpaces(),'buildings'=>$this->buildings(),'requesters'=>$this->employees()]; }
    public function reservationsList(array $q): array
    {
        $sql = "SELECT r.*, fs.space_name, fs.space_type, fs.floor_number, b.building_id, b.building_name, e.full_name requester_name, e.employee_number requester_number, d.department_name FROM facility_reservation r INNER JOIN facility_space fs ON fs.facility_space_id=r.facility_space_id LEFT JOIN building b ON b.building_id=fs.building_id LEFT JOIN employee_reference e ON e.employee_reference_id=r.requested_by_employee_reference_id LEFT JOIN department_reference d ON d.department_reference_id=r.department_reference_id";
        $filterQuery = $q;
        unset($filterQuery['date_from'], $filterQuery['date_to']);
        [$where,$params]=$this->filters('r',$filterQuery,['status'=>'r.status','approval_status'=>'r.approval_status','facility_space_id'=>'r.facility_space_id','building_id'=>'b.building_id','requested_by'=>'r.requested_by_employee_reference_id'],['r.reservation_number','r.purpose','fs.space_name','e.full_name','d.department_name']);
        if (($q['date_from'] ?? '') !== '') { $where[]='r.start_datetime>=:date_from'; $params['date_from']=$this->dateTime($q['date_from']); }
        if (($q['date_to'] ?? '') !== '') { $where[]='r.start_datetime<=:date_to'; $params['date_to']=$this->dateTime($q['date_to']); }
        $sorts=['reservation_number'=>'r.reservation_number','purpose'=>'r.purpose','start_datetime'=>'r.start_datetime','status'=>'r.status','approval_status'=>'r.approval_status','created_at'=>'r.created_at'];
        return $this->paged($sql,$where,$params,$q,$sorts,'r.start_datetime',fn($r)=>$this->shapeReservation($r),$this->reservationSummary());
    }
    public function reservationShow(int $id): ?array { $item=$this->single($this->reservationsList(['id'=>$id,'per_page'=>1]),$id); if(!$item)return null; $item['participants']=$this->rows('SELECT e.employee_number,e.full_name,rp.participant_role,rp.attendance_status FROM reservation_participant rp INNER JOIN employee_reference e ON e.employee_reference_id=rp.employee_reference_id WHERE rp.facility_reservation_id=:id ORDER BY e.full_name',['id'=>$id]); $item['history']=$this->rows('SELECT old_status,new_status,change_reason,changed_at FROM reservation_history WHERE facility_reservation_id=:id ORDER BY changed_at DESC',['id'=>$id]); return $item; }

    public function procurementOptions(): array { return ['statuses'=>$this->distinct('procurement_request','status'),'approval_statuses'=>$this->distinct('procurement_request','approval_status'),'priorities'=>$this->distinct('procurement_request','priority'),'integration_statuses'=>$this->distinct('procurement_request','integration_status'),'departments'=>$this->departments(),'budgets'=>$this->budgets(),'suppliers'=>$this->suppliers()]; }
    public function procurementList(array $q): array
    {
        $sql="SELECT pr.*, e.full_name requester_name, e.employee_number requester_number, d.department_name, br.budget_code, fr.request_number facility_request_number, mwo.work_order_number FROM procurement_request pr LEFT JOIN employee_reference e ON e.employee_reference_id=pr.requested_by_employee_reference_id LEFT JOIN department_reference d ON d.department_reference_id=pr.department_reference_id LEFT JOIN budget_reference br ON br.budget_reference_id=pr.budget_reference_id LEFT JOIN facility_request fr ON fr.facility_request_id=pr.facility_request_id LEFT JOIN maintenance_work_order mwo ON mwo.maintenance_work_order_id=pr.maintenance_work_order_id";
        [$where,$params]=$this->filters('pr',$q,['status'=>'pr.status','approval_status'=>'pr.approval_status','priority'=>'pr.priority','department_id'=>'pr.department_reference_id','integration_status'=>'pr.integration_status'],['pr.request_number','pr.justification','e.full_name','d.department_name']);
        $sorts=['request_number'=>'pr.request_number','priority'=>'pr.priority','status'=>'pr.status','approval_status'=>'pr.approval_status','estimated_total'=>'pr.estimated_total','created_at'=>'pr.created_at'];
        return $this->paged($sql,$where,$params,$q,$sorts,'pr.created_at',fn($r)=>$this->shapeProcurement($r),$this->procurementSummary());
    }

    public function streamProcurementCsv(array $q, mixed $handle): void
    {
        $sql="SELECT pr.*, e.full_name requester_name, e.employee_number requester_number, d.department_name, br.budget_code, fr.request_number facility_request_number, mwo.work_order_number FROM procurement_request pr LEFT JOIN employee_reference e ON e.employee_reference_id=pr.requested_by_employee_reference_id LEFT JOIN department_reference d ON d.department_reference_id=pr.department_reference_id LEFT JOIN budget_reference br ON br.budget_reference_id=pr.budget_reference_id LEFT JOIN facility_request fr ON fr.facility_request_id=pr.facility_request_id LEFT JOIN maintenance_work_order mwo ON mwo.maintenance_work_order_id=pr.maintenance_work_order_id";
        [$where,$params]=$this->filters('pr',$q,['status'=>'pr.status','approval_status'=>'pr.approval_status','priority'=>'pr.priority','department_id'=>'pr.department_reference_id','integration_status'=>'pr.integration_status'],['pr.request_number','pr.justification','e.full_name','d.department_name']);
        $this->streamCsv($handle, ['Request No.', 'Request Title / Summary', 'Department', 'Requester', 'Priority', 'Budget Code', 'Estimated Amount', 'Currency', 'Status', 'Approval Status', 'Created', 'Updated'], $sql, $where, $params, $q, ['request_number'=>'pr.request_number','priority'=>'pr.priority','status'=>'pr.status','approval_status'=>'pr.approval_status','estimated_total'=>'pr.estimated_total','created_at'=>'pr.created_at'], 'pr.created_at', function (array $r): array {
            return [$r['request_number'], $r['justification'], $r['department_name'], $r['requester_name'], $r['priority'], $r['budget_code'], $r['estimated_total'], $r['currency_code'], $r['status'], $r['approval_status'], $r['created_at'], $r['updated_at']];
        });
    }
    public function procurementShow(int $id): ?array { $item=$this->single($this->procurementList(['id'=>$id,'per_page'=>1]),$id); if(!$item)return null; $item['items']=$this->rows('SELECT item_description,quantity,unit_of_measure,estimated_unit_cost,estimated_total_cost,specifications FROM procurement_request_item WHERE procurement_request_id=:id ORDER BY procurement_request_item_id',['id'=>$id]); $item['history']=$this->rows('SELECT old_status,new_status,change_reason,changed_at FROM procurement_history WHERE procurement_request_id=:id ORDER BY changed_at DESC',['id'=>$id]); $item['purchase_orders']=$this->rows('SELECT purchase_order_number,external_purchase_order_id,purchase_order_status,total_amount,currency_code,source_system,sync_status FROM purchase_order_reference WHERE procurement_request_id=:id ORDER BY purchase_order_reference_id DESC',['id'=>$id]); return $item; }

    public function recordsOptions(): array { return ['types'=>$this->distinct('record','record_type'),'statuses'=>$this->distinct('record','record_status'),'confidentiality_levels'=>$this->distinct('record','confidentiality_level'),'departments'=>$this->departments(),'retention_schedules'=>$this->query("SELECT retention_schedule_id id, schedule_code code, schedule_name name FROM retention_schedule WHERE status='ACTIVE' ORDER BY schedule_name")]; }
    public function recordsList(array $q): array
    {
        $sql="SELECT rec.*, rs.schedule_code, rs.schedule_name, d.department_name, e.full_name owner_name FROM record rec INNER JOIN retention_schedule rs ON rs.retention_schedule_id=rec.retention_schedule_id LEFT JOIN department_reference d ON d.department_reference_id=rec.originating_department_reference_id LEFT JOIN employee_reference e ON e.employee_reference_id=rec.record_owner_employee_reference_id";
        [$where,$params]=$this->filters('rec',$q,['record_type'=>'rec.record_type','record_status'=>'rec.record_status','confidentiality_level'=>'rec.confidentiality_level','department_id'=>'rec.originating_department_reference_id','retention_schedule_id'=>'rec.retention_schedule_id'],['rec.record_number','rec.record_title','rec.record_description','d.department_name','e.full_name']);
        $sorts=['record_number'=>'rec.record_number','record_title'=>'rec.record_title','record_status'=>'rec.record_status','record_date'=>'rec.record_date','scheduled_disposition_date'=>'rec.scheduled_disposition_date','created_at'=>'rec.created_at'];
        return $this->paged($sql,$where,$params,$q,$sorts,'rec.created_at',fn($r)=>$this->shapeRecord($r),$this->recordSummary());
    }
    public function recordShow(int $id): ?array { $item=$this->single($this->recordsList(['id'=>$id,'per_page'=>1]),$id); if(!$item)return null; $item['documents']=$this->rows('SELECT d.document_number,d.document_title,d.document_status,d.confidentiality_level,d.current_version_number,d.document_date,d.expiration_date,rd.is_primary_document FROM record_document rd INNER JOIN document d ON d.document_id=rd.document_id WHERE rd.record_id=:id AND d.deleted_at IS NULL ORDER BY rd.is_primary_document DESC,d.document_title',['id'=>$id]); return $item; }

    public function calendar(array $q): array { $list=$this->reservationsList(array_merge($q,['per_page'=>200,'sort'=>'start_datetime','direction'=>'asc'])); return ['items'=>$list['items']]; }

    private function filters(string $alias, array $q, array $exact, array $searchCols): array
    {
        $where=["$alias.deleted_at IS NULL"]; $params=[];
        if (isset($q['id']) && ctype_digit((string)$q['id'])) { $pk=$this->primaryKey($alias); $where[]="$alias.$pk=:id"; $params['id']=(int)$q['id']; }
        if (($q['search'] ?? '') !== '') { $parts=[]; foreach($searchCols as $i=>$col){$parts[]="$col LIKE :search";} $where[]='('.implode(' OR ',$parts).')'; $params['search']='%'.trim((string)$q['search']).'%'; }
        foreach($exact as $key=>$col){ if (($q[$key] ?? '') !== '' && ($q[$key] ?? 'all') !== 'all') { $where[]="$col=:$key"; $params[$key]=$q[$key]; } }
        if (($q['date_from'] ?? '') !== '' && !str_contains(implode(' ', $where), ':date_from')) { $where[]="$alias.created_at>=:date_from"; $params['date_from']=$this->dateTime($q['date_from']); }
        if (($q['date_to'] ?? '') !== '' && !str_contains(implode(' ', $where), ':date_to')) { $where[]="$alias.created_at<=:date_to"; $params['date_to']=$this->dateTime($q['date_to']); }
        return [$where,$params];
    }

    private function paged(string $base, array $where, array $params, array $q, array $sorts, string $defaultSort, callable $shape, array $summary): array
    {
        $page=max(1,(int)($q['page']??1)); $per=min(100,max(1,(int)($q['per_page']??10)));
        $whereSql=' WHERE '.implode(' AND ',$where);
        $count=$this->pdo->prepare('SELECT COUNT(*) FROM ('.$base.$whereSql.') x'); $count->execute($params); $total=(int)$count->fetchColumn();
        $sort=$sorts[(string)($q['sort']??'')] ?? $defaultSort; $dir=strtolower((string)($q['direction']??'desc'))==='asc'?'ASC':'DESC';
        $stmt=$this->pdo->prepare($base.$whereSql." ORDER BY $sort $dir LIMIT :limit OFFSET :offset");
        foreach($params as $k=>$v) $stmt->bindValue($k,$v,is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR);
        $stmt->bindValue('limit',$per,PDO::PARAM_INT); $stmt->bindValue('offset',($page-1)*$per,PDO::PARAM_INT); $stmt->execute();
        return ['items'=>array_map($shape,$stmt->fetchAll()),'pagination'=>['page'=>$page,'per_page'=>$per,'total'=>$total,'total_pages'=>(int)ceil($total/max(1,$per))],'summary'=>$summary];
    }

    private function streamCsv(mixed $handle, array $columns, string $base, array $where, array $params, array $q, array $sorts, string $defaultSort, callable $shape): void
    {
        fputcsv($handle, $columns);
        $whereSql = ' WHERE ' . implode(' AND ', $where);
        $sort = $sorts[(string)($q['sort'] ?? '')] ?? $defaultSort;
        $dir = strtolower((string)($q['direction'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
        $statement = $this->pdo->prepare($base . $whereSql . " ORDER BY $sort $dir");
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->execute();
        while ($row = $statement->fetch()) {
            fputcsv($handle, array_map(fn($value) => $this->csvCell($value), $shape($row)));
        }
    }

    private function csvCell(mixed $value): string
    {
        $cell = trim((string)($value ?? ''));
        return $cell !== '' && preg_match('/^[=+\-@]/', $cell) === 1 ? "'" . $cell : $cell;
    }

    private function primaryKey(string $alias): string { return ['mwo'=>'maintenance_work_order_id','a'=>'asset_id','r'=>'facility_reservation_id','pr'=>'procurement_request_id','rec'=>'record_id'][$alias] ?? 'record_id'; }
    private function single(array $list, int $id): ?array { return $list['items'][0] ?? null; }
    private function count(string $table): int { return (int)$this->scalar("SELECT COUNT(*) FROM `$table` WHERE deleted_at IS NULL"); }
    private function scalar(string $sql, array $params=[]): mixed { $s=$this->pdo->prepare($sql); $s->execute($params); return $s->fetchColumn(); }
    private function rows(string $sql, array $params=[]): array { $s=$this->pdo->prepare($sql); $s->execute($params); return $s->fetchAll(); }
    private function query(string $sql, array $params=[]): array { return $this->rows($sql,$params); }
    private function distinct(string $table, string $column): array { return array_values(array_filter(array_map(fn($r)=>$r[$column], $this->rows("SELECT DISTINCT `$column` FROM `$table` WHERE `$column` IS NOT NULL ORDER BY `$column`")))); }
    private function groupRows(string $table, string $column): array { $deleted=in_array($table,['workflow_task','notification','activity_event','sla_tracking'],true)?'1=1':'deleted_at IS NULL'; return array_map(fn($r)=>['label'=>$r['label'],'value'=>(int)$r['value']],$this->rows("SELECT `$column` label, COUNT(*) value FROM `$table` WHERE $deleted GROUP BY `$column` ORDER BY `$column`")); }
    private function dateTime(string $v): string { return (new DateTimeImmutable($v, new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s'); }

    private function employees(): array { return $this->query("SELECT employee_reference_id id, employee_number, full_name, department_reference_id FROM employee_reference WHERE employment_status='ACTIVE' AND deleted_at IS NULL ORDER BY full_name"); }
    private function departments(): array { return $this->query("SELECT department_reference_id id, department_code code, department_name name FROM department_reference WHERE status='ACTIVE' ORDER BY department_name"); }
    private function spaces(): array { return $this->query("SELECT fs.facility_space_id id, fs.space_code code, fs.space_name name, fs.space_type type, fs.building_id, b.building_name FROM facility_space fs INNER JOIN building b ON b.building_id=fs.building_id WHERE fs.status='ACTIVE' AND fs.deleted_at IS NULL ORDER BY b.building_name, fs.space_name"); }
    private function reservableSpaces(): array { return $this->query("SELECT fs.facility_space_id id, fs.space_code code, fs.space_name name, fs.space_type type, fs.building_id, b.building_name FROM facility_space fs INNER JOIN building b ON b.building_id=fs.building_id WHERE fs.status='ACTIVE' AND fs.is_reservable=1 AND fs.deleted_at IS NULL ORDER BY b.building_name, fs.space_name"); }
    private function buildings(): array { return $this->query("SELECT building_id id, building_code code, building_name name FROM building WHERE status='ACTIVE' AND deleted_at IS NULL ORDER BY building_name"); }
    private function assetOptions(): array { return $this->query("SELECT asset_id id, asset_code code, asset_name name FROM asset WHERE deleted_at IS NULL ORDER BY asset_name"); }
    private function budgets(): array { return $this->query("SELECT budget_reference_id id, budget_code code, budget_name name, fiscal_year, available_amount, currency_code FROM budget_reference WHERE status='ACTIVE' ORDER BY fiscal_year DESC, budget_name"); }
    private function suppliers(): array { return $this->query("SELECT supplier_reference_id id, supplier_code code, supplier_name name FROM supplier_reference WHERE supplier_status='ACTIVE' ORDER BY supplier_name"); }

    private function maintenanceDueFilter(array &$where, array &$params, string $value): void { if($value==='overdue')$where[]='a.next_maintenance_date<CURRENT_DATE()'; if($value==='today')$where[]='a.next_maintenance_date=CURRENT_DATE()'; if($value==='7')$where[]='a.next_maintenance_date BETWEEN CURRENT_DATE() AND DATE_ADD(CURRENT_DATE(), INTERVAL 7 DAY)'; if($value==='30')$where[]='a.next_maintenance_date BETWEEN CURRENT_DATE() AND DATE_ADD(CURRENT_DATE(), INTERVAL 30 DAY)'; if($value==='none')$where[]='a.next_maintenance_date IS NULL'; }

    private function maintenanceSummary(): array { return ['open'=>(int)$this->scalar("SELECT COUNT(*) FROM maintenance_work_order WHERE deleted_at IS NULL AND status NOT IN ('COMPLETED','VERIFIED','CANCELLED')"),'awaiting_verification'=>(int)$this->scalar("SELECT COUNT(*) FROM maintenance_work_order WHERE deleted_at IS NULL AND status='COMPLETED'"),'overdue'=>(int)$this->scalar("SELECT COUNT(*) FROM maintenance_work_order WHERE deleted_at IS NULL AND scheduled_end_at<NOW() AND status NOT IN ('COMPLETED','VERIFIED','CANCELLED')"),'unassigned'=>(int)$this->scalar("SELECT COUNT(*) FROM maintenance_work_order WHERE deleted_at IS NULL AND assigned_to_employee_reference_id IS NULL")]; }
    private function assetSummary(): array { return ['total'=>$this->count('asset'),'available'=>(int)$this->scalar("SELECT COUNT(*) FROM asset WHERE deleted_at IS NULL AND lifecycle_status='AVAILABLE'"),'under_maintenance'=>(int)$this->scalar("SELECT COUNT(*) FROM asset WHERE deleted_at IS NULL AND lifecycle_status='UNDER_MAINTENANCE'"),'maintenance_due'=>(int)$this->scalar("SELECT COUNT(*) FROM asset WHERE deleted_at IS NULL AND next_maintenance_date IS NOT NULL AND next_maintenance_date<=DATE_ADD(CURRENT_DATE(), INTERVAL 30 DAY)"),'needs_attention'=>(int)$this->scalar("SELECT COUNT(*) FROM asset WHERE deleted_at IS NULL AND condition_status IN ('POOR','CRITICAL','FOR_INSPECTION')")]; }
    private function reservationSummary(): array { return ['active'=>(int)$this->scalar("SELECT COUNT(*) FROM facility_reservation WHERE deleted_at IS NULL AND status IN ('SUBMITTED','PENDING_APPROVAL','APPROVED','CHECKED_IN')"),'today'=>(int)$this->scalar("SELECT COUNT(*) FROM facility_reservation WHERE deleted_at IS NULL AND DATE(start_datetime)=CURRENT_DATE()"),'pending_approval'=>(int)$this->scalar("SELECT COUNT(*) FROM facility_reservation WHERE deleted_at IS NULL AND approval_status='PENDING'"),'conflicts'=>0]; }
    private function procurementSummary(): array { return ['open'=>(int)$this->scalar("SELECT COUNT(*) FROM procurement_request WHERE deleted_at IS NULL AND status NOT IN ('COMPLETED','CANCELLED','REJECTED')"),'pending_approval'=>(int)$this->scalar("SELECT COUNT(*) FROM procurement_request WHERE deleted_at IS NULL AND approval_status='PENDING'"),'integration_issues'=>(int)$this->scalar("SELECT COUNT(*) FROM procurement_request WHERE deleted_at IS NULL AND integration_status IN ('FAILED','ERROR')"),'estimated_total'=>(float)$this->scalar("SELECT COALESCE(SUM(estimated_total),0) FROM procurement_request WHERE deleted_at IS NULL")]; }
    private function recordSummary(): array { return ['total'=>$this->count('record'),'active'=>(int)$this->scalar("SELECT COUNT(*) FROM record WHERE deleted_at IS NULL AND record_status='ACTIVE'"),'disposition_due'=>RetentionService::countRecordsDueForReview($this->pdo),'restricted'=>(int)$this->scalar("SELECT COUNT(*) FROM record WHERE deleted_at IS NULL AND confidentiality_level = 'CONFIDENTIAL'")]; }

    private function recentActivities(): array { return $this->rows("SELECT event_title activity, module_code module, entity_reference reference, occurred_at time, event_type status FROM activity_event WHERE module_code IN ('RESERVATIONS','VISITORS','documents','retention','contract_management','LEGAL_MANAGEMENT') ORDER BY occurred_at DESC LIMIT 10"); }

    private function shapeMaintenance(array $r): array { return ['id'=>(int)$r['maintenance_work_order_id'],'workOrderNo'=>$r['work_order_number'],'title'=>$r['problem_description'],'asset'=>$r['asset_name'] ?: null,'space'=>$r['space_name'],'building'=>$r['building_name'],'priority'=>$r['priority'],'status'=>$r['status'],'maintenanceType'=>$r['maintenance_type'],'assignedTo'=>$r['assigned_to_name'],'scheduledStart'=>$r['scheduled_start_at'],'scheduledEnd'=>$r['scheduled_end_at'],'createdAt'=>$r['created_at'],'facilityRequest'=>$r['facility_request_number']]; }
    private function shapeAsset(array $r): array { return ['id'=>(int)$r['asset_id'],'assetCode'=>$r['asset_code'],'propertyNumber'=>$r['property_number'],'assetName'=>$r['asset_name'],'category'=>$r['category_name'],'brand'=>$r['brand'],'model'=>$r['model'],'serialNumber'=>$r['serial_number'],'location'=>trim(($r['building_name']??'').' / '.($r['space_name']??''),' /'),'custodian'=>$r['custodian_name'],'condition'=>$r['condition_status'],'lifecycle'=>$r['lifecycle_status'],'nextMaintenance'=>$r['next_maintenance_date'],'supplier'=>$r['supplier_name'],'createdAt'=>$r['created_at']]; }
    private function findAsset(int $id): ?array { $list=$this->assetsList(['id'=>$id,'per_page'=>1]); return $list['items'][0]??null; }
    private function shapeReservation(array $r): array { return ['id'=>(int)$r['facility_reservation_id'],'reservationNo'=>$r['reservation_number'],'purpose'=>$r['purpose'],'room'=>$r['space_name'],'roomType'=>$r['space_type'],'building'=>$r['building_name'],'floor'=>$r['floor_number'],'requester'=>$r['requester_name'],'employeeNumber'=>$r['requester_number'],'department'=>$r['department_name'],'attendees'=>(int)$r['expected_attendees'],'approval'=>$r['approval_status'],'status'=>$r['status'],'start'=>$r['start_datetime'],'end'=>$r['end_datetime'],'createdAt'=>$r['created_at']]; }
    private function shapeProcurement(array $r): array { return ['id'=>(int)$r['procurement_request_id'],'requestNo'=>$r['request_number'],'justification'=>$r['justification'],'origin'=>$r['facility_request_number'] ? 'Facility Request' : ($r['work_order_number'] ? 'Maintenance' : 'Direct Request'),'originRef'=>$r['facility_request_number'] ?: $r['work_order_number'],'priority'=>$r['priority'],'status'=>$r['status'],'approval'=>$r['approval_status'],'integration'=>$r['integration_status'],'estimatedCost'=>(float)$r['estimated_total'],'currency'=>$r['currency_code'],'requestedBy'=>$r['requester_name'],'employeeNumber'=>$r['requester_number'],'department'=>$r['department_name'],'budget'=>$r['budget_code'],'createdAt'=>$r['created_at']]; }
    private function shapeRecord(array $r): array { return ['id'=>(int)$r['record_id'],'documentNo'=>$r['record_number'],'title'=>$r['record_title'],'description'=>$r['record_description'],'type'=>$r['record_type'],'category'=>$r['schedule_name'],'owner'=>$r['owner_name'],'department'=>$r['department_name'],'recordDate'=>$r['record_date'],'reviewDate'=>$r['scheduled_disposition_date'],'status'=>$r['record_status'],'confidentiality'=>$r['confidentiality_level'],'sourceModule'=>$r['source_module'],'createdAt'=>$r['created_at']]; }
}

