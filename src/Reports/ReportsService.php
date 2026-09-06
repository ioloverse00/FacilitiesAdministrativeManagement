<?php

declare(strict_types=1);

final class ReportsService
{
    private const REPORTS = [
        'facility_requests' => [
            'title' => 'Facility Requests Report',
            'permission' => 'facility_requests.view',
            'date_semantics' => 'Date range uses facility request created_at.',
        ],
        'documents_records' => [
            'title' => 'Documents & Records Report',
            'permission' => 'records.view',
            'date_semantics' => 'Date range uses document created_at and record created_at within their own metadata sets.',
        ],
        'contracts' => [
            'title' => 'Contracts Report',
            'description' => 'Contract lifecycle, type, and expiry analysis',
            'permission' => 'contract.view',
            'date_semantics' => '',
        ],
        'legal_management' => [
            'title' => 'Legal Matter Report',
            'description' => 'Legal matter type, status, and activity analysis',
            'permission' => 'legal.view',
            'date_semantics' => 'Date range uses legal matter opened_at.',
        ],
    ];

    public function __construct(private readonly PDO $pdo) {}

    public function overview(array $query, array $user): array
    {
        $report = $this->reportKey($query['report'] ?? 'contracts');
        $this->authorize($user, 'reports.view');
        $this->authorize($user, self::REPORTS[$report]['permission']);
        $filters = $this->filters($query);
        return array_merge(self::REPORTS[$report], [
            'key' => $report,
            'filters' => $filters,
            'available_reports' => $this->availableReports($user),
            'filter_options' => $this->filterOptions($report),
            'export' => [
                'csv' => $this->csvAvailable($report),
                'pdf' => false,
                'pdf_note' => 'PDF export is not enabled because no Composer PDF package is installed.',
            ],
        ], $this->{$this->methodName($report)}($filters));
    }

    public function csv(array $query, array $user): array
    {
        $report = $this->reportKey($query['report'] ?? '');
        $this->authorize($user, 'reports.view');
        $this->authorize($user, 'reports.export');
        $this->authorize($user, self::REPORTS[$report]['permission']);
        if (!$this->csvAvailable($report)) {
            throw new InvalidArgumentException('CSV export is not available for this report yet.');
        }
        $data = $this->overview($query, $user);
        return [
            'filename' => $report . '-report-' . date('Ymd-His') . '.csv',
            'csv' => $this->makeCsv($data['columns'], $data['rows']),
        ];
    }

    public function streamCsv(array $query, array $user): void
    {
        $report = $this->reportKey($query['report'] ?? '');
        $this->authorize($user, 'reports.view');
        $this->authorize($user, 'reports.export');
        $this->authorize($user, self::REPORTS[$report]['permission']);
        if (!$this->csvAvailable($report)) {
            throw new InvalidArgumentException('CSV export is not available for this report yet.');
        }
        $handle = fopen('php://output', 'w');
        fwrite($handle, "\xEF\xBB\xBF");
        if ($report === 'contracts') {
            $this->streamContractsCsv($this->filters($query), $handle);
            return;
        }
        if ($report === 'documents_records') {
            $this->streamDocumentsRecordsCsv($this->filters($query), $handle);
            return;
        }
        $data = $this->overview($query, $user);
        fputcsv($handle, $data['columns']);
        foreach ($data['rows'] as $row) {
            fputcsv($handle, array_map(fn($value) => $this->csvCell($value), $row));
        }
    }

    public function availableReports(array $user): array
    {
        $this->authorize($user, 'reports.view');
        $reports = [];
        foreach (self::REPORTS as $key => $config) {
            $reports[] = [
                'key' => $key,
                'title' => $config['title'],
                'available' => $this->can($user, $config['permission']),
                'permission' => $config['permission'],
            ];
        }
        return $reports;
    }

    private function facilityRequestsReport(array $filters): array
    {
        [$where, $params] = $this->baseWhere('fr.deleted_at IS NULL', 'fr.created_at', $filters);
        $this->exact($where, $params, 'status', 'fr.status', $filters);
        $this->exact($where, $params, 'priority', 'fr.priority', $filters);
        $this->exact($where, $params, 'department_id', 'fr.department_reference_id', $filters);
        $this->exact($where, $params, 'category_id', 'fr.request_category_id', $filters);
        $this->search($where, $params, 'facility_search', $filters['search'], ['fr.request_number', 'fr.subject', 'fr.description']);
        $rows = $this->rows("SELECT fr.request_number, fr.subject, rc.category_name, d.department_name, fs.space_name, fr.priority, fr.status, fr.approval_status, fr.created_at, fr.requested_completion_at
            FROM facility_request fr
            INNER JOIN request_category rc ON rc.request_category_id = fr.request_category_id
            LEFT JOIN department_reference d ON d.department_reference_id = fr.department_reference_id
            LEFT JOIN facility_space fs ON fs.facility_space_id = fr.facility_space_id
            WHERE " . implode(' AND ', $where) . " ORDER BY fr.created_at DESC LIMIT 500", $params);
        return [
            'kpis' => [
                'Total Requests' => count($rows),
                'Open' => $this->countRows($rows, 'status', ['SUBMITTED','PENDING_APPROVAL','APPROVED','ASSIGNED']),
                'In Progress' => $this->countRows($rows, 'status', ['IN_PROGRESS']),
                'Completed' => $this->countRows($rows, 'status', ['COMPLETED','VERIFIED','CLOSED']),
                'Due Soon / Past Due' => $this->scalar("SELECT COUNT(*) FROM facility_request fr WHERE " . implode(' AND ', $where) . " AND fr.requested_completion_at IS NOT NULL AND fr.status NOT IN ('COMPLETED','VERIFIED','CLOSED','CANCELLED','REJECTED') AND fr.requested_completion_at <= DATE_ADD(NOW(), INTERVAL 3 DAY)", $params),
            ],
            'charts' => [
                'Status' => $this->group('facility_request fr', 'fr.status', $where, $params),
                'Priority' => $this->group('facility_request fr', 'fr.priority', $where, $params),
                'Request Activity Over Time' => $this->facilityRequestActivityTimeline($where, $params),
            ],
            'columns' => ['Request No.', 'Subject', 'Category', 'Department', 'Facility', 'Priority', 'Status', 'Approval', 'Created', 'Requested Completion'],
            'rows' => array_map(fn($r) => [$r['request_number'], $r['subject'], $r['category_name'], $r['department_name'], $r['space_name'], $r['priority'], $r['status'], $r['approval_status'], $r['created_at'], $r['requested_completion_at']], $rows),
        ];
    }

    private function facilityRequestActivityTimeline(array $where, array $params): array
    {
        return $this->rows("SELECT DATE_FORMAT(fr.created_at, '%b %Y') label, DATE_FORMAT(fr.created_at, '%Y-%m') sort_key, COUNT(*) value
            FROM facility_request fr
            WHERE " . implode(' AND ', $where) . "
            GROUP BY DATE_FORMAT(fr.created_at, '%Y-%m'), DATE_FORMAT(fr.created_at, '%b %Y')
            ORDER BY sort_key ASC", $params);
    }

    private function documentsRecordsReport(array $filters): array
    {
        [$docWhere, $docParams] = $this->documentWhere($filters);
        [$recWhere, $recParams] = $this->recordWhere($filters);
        $documents = $this->documentRows($docWhere, $docParams, 500);
        $records = $this->recordRows($recWhere, $recParams, 500);
        return [
            'kpis' => [],
            'source' => $filters['source'] === 'records' ? 'records' : 'documents',
            'date_semantics' => $filters['source'] === 'records'
                ? 'Date range filters records by record date.'
                : 'Date range filters documents by creation date.',
            'description' => $filters['source'] === 'records'
                ? 'Retention schedule, state, and disposition analysis'
                : 'Document category, confidentiality, and activity analysis',
            'documents' => [
                'count' => $this->scalar("SELECT COUNT(*) FROM document d WHERE " . implode(' AND ', $docWhere), $docParams),
                'charts' => [
                    'Documents by Category' => ['type' => 'horizontalBar', 'rows' => $this->group('document d INNER JOIN document_category dc ON dc.document_category_id = d.document_category_id', 'dc.category_name', $docWhere, $docParams)],
                    'Confidentiality Distribution' => ['type' => 'doughnut', 'rows' => $this->group('document d', 'd.confidentiality_level', $docWhere, $docParams)],
                    'Document Activity Over Time' => ['type' => 'line', 'wide' => true, 'rows' => $this->documentActivityTimeline($docWhere, $docParams)],
                ],
                'columns' => $this->documentColumns(),
                'rows' => array_map(fn($r) => $this->shapeDocumentRow($r), $documents),
            ],
            'records' => [
                'count' => $this->scalar($this->recordSelectSql($recWhere, 'COUNT(DISTINCT rec.record_id)'), $recParams),
                'charts' => [
                    'Records by Schedule' => ['type' => 'horizontalBar', 'rows' => $this->group('record rec LEFT JOIN retention_schedule rs ON rs.retention_schedule_id = rec.retention_schedule_id', "COALESCE(rs.schedule_name, 'Unassigned')", $recWhere, $recParams)],
                    'Records by Retention State' => ['type' => 'doughnut', 'rows' => $this->recordRetentionStateGroup($recWhere, $recParams)],
                    'Upcoming Disposition Timeline' => ['type' => 'bar', 'rows' => $this->recordDispositionTimeline($recWhere, $recParams)],
                ],
                'columns' => $this->recordColumns(),
                'rows' => array_map(fn($r) => $this->shapeRecordRow($r), $records),
            ],
        ];
    }

    private function documentWhere(array $filters): array
    {
        [$where, $params] = $this->baseWhere('d.deleted_at IS NULL', 'd.created_at', $filters);
        $where[] = 'NOT EXISTS (SELECT 1 FROM document_template_version dtv WHERE dtv.document_id = d.document_id)';
        $this->exact($where, $params, 'status', 'd.document_status', $filters);
        $this->exact($where, $params, 'confidentiality', 'd.confidentiality_level', $filters);
        $this->exact($where, $params, 'category_id', 'd.document_category_id', $filters);
        $this->search($where, $params, 'document_search', $filters['search'], ['d.document_number', 'd.document_title', 'd.document_description']);
        return [$where, $params];
    }

    private function recordWhere(array $filters): array
    {
        [$where, $params] = $this->baseWhere('rec.deleted_at IS NULL', 'rec.record_date', $filters);
        $this->exact($where, $params, 'status', 'rec.record_status', $filters);
        $this->exact($where, $params, 'department_id', 'rec.originating_department_reference_id', $filters);
        $this->exact($where, $params, 'retention_schedule_id', 'rec.retention_schedule_id', $filters);
        $this->exact($where, $params, 'legal_hold_status', 'rec.legal_hold_status', $filters);
        $this->search($where, $params, 'record_search', $filters['search'], ['rec.record_number', 'rec.record_title', 'rec.record_description', 'rec.record_type', 'rs.schedule_name', 'd.department_name']);
        if ($filters['retention_state'] !== '') {
            $where[] = $this->recordRetentionStateSql($filters['retention_state']);
        }
        return [$where, $params];
    }

    private function documentRows(array $where, array $params, ?int $limit): array
    {
        $sql = "SELECT d.document_number, d.document_title, dc.category_name, d.confidentiality_level, d.document_status, owner.full_name owner_name, d.created_at, d.updated_at
            FROM document d
            INNER JOIN document_category dc ON dc.document_category_id = d.document_category_id
            LEFT JOIN employee_reference owner ON owner.employee_reference_id = d.owner_employee_reference_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY d.created_at DESC, d.document_id DESC";
        if ($limit !== null) {
            $sql .= " LIMIT $limit";
        }
        return $this->rows($sql, $params);
    }

    private function recordRows(array $where, array $params, ?int $limit): array
    {
        $sql = $this->recordSelectSql($where, 'rec.*, rs.schedule_name, rs.retention_period_unit, rs.disposition_action, d.department_name') . " ORDER BY rec.record_date DESC, rec.record_id DESC";
        if ($limit !== null) {
            $sql .= " LIMIT $limit";
        }
        return $this->rows($sql, $params);
    }

    private function recordSelectSql(array $where, string $columns): string
    {
        return "SELECT $columns
            FROM record rec
            LEFT JOIN retention_schedule rs ON rs.retention_schedule_id = rec.retention_schedule_id
            LEFT JOIN department_reference d ON d.department_reference_id = rec.originating_department_reference_id
            WHERE " . implode(' AND ', $where);
    }

    private function documentActivityTimeline(array $where, array $params): array
    {
        return $this->rows("SELECT DATE_FORMAT(d.created_at, '%b %Y') label, DATE_FORMAT(d.created_at, '%Y-%m') sort_key, COUNT(*) value
            FROM document d
            WHERE " . implode(' AND ', $where) . "
            GROUP BY DATE_FORMAT(d.created_at, '%Y-%m'), DATE_FORMAT(d.created_at, '%b %Y')
            ORDER BY sort_key ASC", $params);
    }

    private function recordDispositionTimeline(array $where, array $params): array
    {
        $timelineWhere = $where;
        $timelineWhere[] = $this->validReportDateSql('rec.scheduled_disposition_date');
        return $this->rows("SELECT DATE_FORMAT(rec.scheduled_disposition_date, '%b %Y') label, DATE_FORMAT(rec.scheduled_disposition_date, '%Y-%m') sort_key, COUNT(*) value
            FROM record rec
            LEFT JOIN retention_schedule rs ON rs.retention_schedule_id = rec.retention_schedule_id
            LEFT JOIN department_reference d ON d.department_reference_id = rec.originating_department_reference_id
            WHERE " . implode(' AND ', $timelineWhere) . "
            GROUP BY DATE_FORMAT(rec.scheduled_disposition_date, '%Y-%m'), DATE_FORMAT(rec.scheduled_disposition_date, '%b %Y')
            ORDER BY sort_key ASC", $params);
    }

    private function recordRetentionStateGroup(array $where, array $params): array
    {
        return $this->rows("SELECT " . $this->recordRetentionStateCaseSql() . " label, COUNT(*) value
            FROM record rec
            LEFT JOIN retention_schedule rs ON rs.retention_schedule_id = rec.retention_schedule_id
            LEFT JOIN department_reference d ON d.department_reference_id = rec.originating_department_reference_id
            WHERE " . implode(' AND ', $where) . "
            GROUP BY " . $this->recordRetentionStateCaseSql() . "
            ORDER BY value DESC, label", $params);
    }

    private function documentColumns(): array
    {
        return ['Document No.', 'Title', 'Category', 'Confidentiality', 'Status', 'Owner', 'Created', 'Last Updated'];
    }

    private function recordColumns(): array
    {
        return ['Record No.', 'Title / Description', 'Record Type', 'Department', 'Retention Schedule', 'Retention State', 'Record Date', 'Disposition Date', 'Status'];
    }

    private function shapeDocumentRow(array $row): array
    {
        return [
            $row['document_number'],
            $row['document_title'],
            $row['category_name'],
            $this->statusLabel($row['confidentiality_level']),
            $this->statusLabel($row['document_status']),
            $row['owner_name'] ?: 'Unassigned',
            $this->reportDate($row['created_at']),
            $this->reportDate($row['updated_at']),
        ];
    }

    private function shapeRecordRow(array $row): array
    {
        return [
            $row['record_number'],
            $row['record_title'] ?: $row['record_description'],
            $row['record_type'],
            $row['department_name'] ?: 'Unassigned',
            $row['schedule_name'] ?: 'Unassigned',
            $this->statusLabel($this->recordRetentionStateFromRow($row)),
            $this->reportDate($row['record_date']),
            $this->reportDate($row['scheduled_disposition_date']),
            $this->statusLabel($row['record_status']),
        ];
    }

    private function streamDocumentsRecordsCsv(array $filters, mixed $handle): void
    {
        if ($filters['source'] === 'records') {
            [$where, $params] = $this->recordWhere($filters);
            fputcsv($handle, $this->recordColumns());
            $statement = $this->pdo->prepare($this->recordSelectSql($where, 'rec.*, rs.schedule_name, rs.retention_period_unit, rs.disposition_action, d.department_name') . ' ORDER BY rec.record_date DESC, rec.record_id DESC');
            $statement->execute($params);
            while ($row = $statement->fetch()) {
                fputcsv($handle, array_map(fn($value) => $this->csvCell($value), $this->shapeRecordRow($row)));
            }
            return;
        }
        [$where, $params] = $this->documentWhere($filters);
        fputcsv($handle, $this->documentColumns());
        $statement = $this->pdo->prepare("SELECT d.document_number, d.document_title, dc.category_name, d.confidentiality_level, d.document_status, owner.full_name owner_name, d.created_at, d.updated_at
            FROM document d
            INNER JOIN document_category dc ON dc.document_category_id = d.document_category_id
            LEFT JOIN employee_reference owner ON owner.employee_reference_id = d.owner_employee_reference_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY d.created_at DESC, d.document_id DESC");
        $statement->execute($params);
        while ($row = $statement->fetch()) {
            fputcsv($handle, array_map(fn($value) => $this->csvCell($value), $this->shapeDocumentRow($row)));
        }
    }

    private function contractsReport(array $filters): array
    {
        [$where, $params, $dateColumn] = $this->contractWhere($filters);
        $rows = $this->contractRows($where, $params, $dateColumn, 500);
        return [
            'kpis' => [],
            'record_count' => $this->scalar("SELECT COUNT(*) FROM contract c WHERE " . implode(' AND ', $where), $params),
            'charts' => [
                'Contracts by Lifecycle Status' => [
                    'type' => 'doughnut',
                    'rows' => $this->group('contract c', 'c.contract_status', $where, $params),
                ],
                'Contracts by Type' => [
                    'type' => 'horizontalBar',
                    'rows' => $this->group('contract c INNER JOIN contract_type ct ON ct.contract_type_id = c.contract_type_id', 'ct.type_name', $where, $params),
                ],
                'Contract Expiry Timeline' => [
                    'type' => 'line',
                    'wide' => true,
                    'rows' => $this->contractExpiryTimeline($where, $params),
                ],
            ],
            'columns' => $this->contractColumns(),
            'rows' => array_map(fn($r) => $this->shapeContractRow($r), $rows),
        ];
    }

    private function legalManagementReport(array $filters): array
    {
        [$where, $params] = $this->legalWhere($filters);
        $rows = $this->legalRows($where, $params, 500);
        return [
            'kpis' => [],
            'record_count' => $this->scalar("SELECT COUNT(*) FROM legal_matter lm WHERE " . implode(' AND ', $where), $params),
            'charts' => [
                'Legal Matters by Type' => ['type' => 'horizontalBar', 'rows' => $this->group('legal_matter lm', 'lm.matter_type', $where, $params)],
                'Legal Matter Status Distribution' => ['type' => 'doughnut', 'rows' => $this->group('legal_matter lm', 'lm.status', $where, $params)],
                'Legal Matter Activity Over Time' => ['type' => 'line', 'rows' => $this->legalActivityTimeline($where, $params)],
            ],
            'columns' => $this->legalColumns(),
            'rows' => array_map(fn($r) => $this->shapeLegalRow($r), $rows),
        ];
    }

    private function legalWhere(array $filters): array
    {
        [$where, $params] = $this->baseWhere('lm.deleted_at IS NULL', 'lm.opened_at', $filters);
        $this->exact($where, $params, 'status', 'lm.status', $filters);
        $this->exact($where, $params, 'priority', 'lm.priority', $filters);
        $this->exact($where, $params, 'type', 'lm.matter_type', $filters);
        $this->exact($where, $params, 'department_id', 'lm.department_reference_id', $filters);
        $this->exact($where, $params, 'assignee_id', 'lm.assigned_employee_reference_id', $filters);
        return [$where, $params];
    }

    private function legalRows(array $where, array $params, ?int $limit): array
    {
        $sql = "SELECT lm.matter_number, lm.title, lm.matter_type, lm.priority, lm.status, d.department_name, assignee.full_name assigned_name, lm.reported_at, lm.opened_at, lm.updated_at
            FROM legal_matter lm
            LEFT JOIN department_reference d ON d.department_reference_id = lm.department_reference_id
            LEFT JOIN employee_reference assignee ON assignee.employee_reference_id = lm.assigned_employee_reference_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY lm.opened_at DESC, lm.legal_matter_id DESC";
        if ($limit !== null) {
            $sql .= " LIMIT $limit";
        }
        return $this->rows($sql, $params);
    }

    private function legalActivityTimeline(array $where, array $params): array
    {
        return $this->rows("SELECT DATE_FORMAT(lm.opened_at, '%b %Y') label, DATE_FORMAT(lm.opened_at, '%Y-%m') sort_key, COUNT(*) value
            FROM legal_matter lm
            WHERE " . implode(' AND ', $where) . "
            GROUP BY DATE_FORMAT(lm.opened_at, '%Y-%m'), DATE_FORMAT(lm.opened_at, '%b %Y')
            ORDER BY sort_key ASC", $params);
    }

    private function legalColumns(): array
    {
        return ['Matter No.', 'Title', 'Matter Type', 'Department', 'Assignee', 'Priority', 'Status', 'Reported', 'Opened', 'Last Updated'];
    }

    private function shapeLegalRow(array $row): array
    {
        return [
            $row['matter_number'],
            $row['title'],
            $this->statusLabel($row['matter_type']),
            $row['department_name'] ?: 'Unassigned',
            $row['assigned_name'] ?: 'Unassigned',
            $this->statusLabel($row['priority']),
            $this->statusLabel($row['status']),
            $this->reportDate($row['reported_at']),
            $this->reportDate($row['opened_at']),
            $this->reportDate($row['updated_at']),
        ];
    }

    private function csvAvailable(string $report): bool
    {
        return $report !== 'legal_management';
    }

    private function filterOptions(string $report): array
    {
        return [
            'statuses' => match ($report) {
                'documents_records' => $this->distinct('document', 'document_status'),
                'contracts' => $this->distinct('contract', 'contract_status'),
                'legal_management' => $this->distinct('legal_matter', 'status'),
                default => $this->distinct('facility_request', 'status'),
            },
            'priorities' => $report === 'facility_requests' ? $this->distinct('facility_request', 'priority') : ($report === 'legal_management' ? $this->distinct('legal_matter', 'priority') : []),
            'types' => match ($report) {
                'legal_management' => $this->distinct('legal_matter', 'matter_type'),
                default => [],
            },
            'departments' => in_array($report, ['facility_requests', 'documents_records', 'contracts', 'legal_management'], true) ? $this->rows("SELECT department_reference_id id, department_name name FROM department_reference WHERE status = 'ACTIVE' ORDER BY department_name") : [],
            'categories' => match ($report) {
                'documents_records' => $this->rows("SELECT document_category_id id, category_name name FROM document_category WHERE status = 'ACTIVE' ORDER BY category_name"),
                default => [],
            },
            'contract_types' => $report === 'contracts' ? $this->rows("SELECT contract_type_id id, type_name name FROM contract_type WHERE status = 'ACTIVE' ORDER BY type_name") : [],
            'sources' => $report === 'documents_records' ? [
                ['id' => 'documents', 'name' => 'Documents'],
                ['id' => 'records', 'name' => 'Records Retention'],
            ] : [],
            'retention_schedules' => $report === 'documents_records' ? $this->rows("SELECT retention_schedule_id id, schedule_name name FROM retention_schedule WHERE status = 'ACTIVE' ORDER BY schedule_name") : [],
            'retention_states' => $report === 'documents_records' ? ['WAITING_FOR_TRIGGER', 'ACTIVE', 'DUE_FOR_REVIEW', 'OVERDUE', 'ON_HOLD', 'ARCHIVED', 'DISPOSED', 'PERMANENT'] : [],
            'date_basises' => $report === 'contracts' ? [
                ['id' => 'effective_start', 'name' => 'Effective / Start Date'],
                ['id' => 'end_expiry', 'name' => 'End / Expiry Date'],
            ] : [],
            'assignees' => $report === 'legal_management' ? $this->rows("SELECT employee_reference_id id, full_name name FROM employee_reference WHERE employment_status = 'ACTIVE' AND deleted_at IS NULL ORDER BY full_name") : [],
            'document_statuses' => $report === 'documents_records' ? $this->distinct('document', 'document_status') : [],
            'record_statuses' => $report === 'documents_records' ? $this->distinct('record', 'record_status') : [],
            'confidentiality_levels' => $report === 'documents_records' ? $this->distinct('document', 'confidentiality_level') : [],
        ];
    }

    private function filters(array $query): array
    {
        return [
            'date_from' => $this->date($query['date_from'] ?? ''),
            'date_to' => $this->date($query['date_to'] ?? ''),
            'search' => $this->clean($query['search'] ?? ''),
            'status' => $this->clean($query['status'] ?? ''),
            'priority' => $this->clean($query['priority'] ?? ''),
            'type' => $this->clean($query['type'] ?? ''),
            'confidentiality' => $this->clean($query['confidentiality'] ?? ''),
            'department_id' => $this->intString($query['department_id'] ?? ''),
            'category_id' => $this->intString($query['category_id'] ?? ''),
            'type_id' => $this->intString($query['type_id'] ?? ''),
            'assignee_id' => $this->intString($query['assignee_id'] ?? ''),
            'date_basis' => $this->dateBasis($query['date_basis'] ?? 'effective_start'),
            'source' => in_array(($query['source'] ?? 'documents'), ['documents', 'records'], true) ? (string) ($query['source'] ?? 'documents') : 'documents',
            'retention_schedule_id' => $this->intString($query['retention_schedule_id'] ?? ''),
            'retention_state' => $this->clean($query['retention_state'] ?? ''),
            'legal_hold_status' => $this->clean($query['legal_hold_status'] ?? ''),
            'expiry_state' => $this->clean($query['expiry_state'] ?? ''),
        ];
    }

    private function contractWhere(array $filters): array
    {
        $dateColumn = $filters['date_basis'] === 'end_expiry' ? 'c.end_date' : 'COALESCE(c.effective_date, c.start_date)';
        [$where, $params] = $this->baseWhere('c.deleted_at IS NULL', $dateColumn, $filters);
        if ($filters['date_basis'] === 'end_expiry' && ($filters['date_from'] !== '' || $filters['date_to'] !== '')) {
            $where[] = $this->validContractDateSql('c.end_date');
        }
        $this->exact($where, $params, 'status', 'c.contract_status', $filters);
        $this->exact($where, $params, 'type_id', 'c.contract_type_id', $filters);
        $this->exact($where, $params, 'department_id', 'c.owning_department_reference_id', $filters);
        $this->search($where, $params, 'contract_search', $filters['search'], ['c.contract_number', 'c.contract_title', 'c.counterparty_name']);
        if ($filters['expiry_state'] === 'expiring_soon') {
            $where[] = $this->validContractDateSql('c.end_date');
            $where[] = "c.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)";
            $where[] = "c.contract_status IN ('APPROVED','ACTIVE')";
        }
        return [$where, $params, $dateColumn];
    }

    private function contractRows(array $where, array $params, string $dateColumn, ?int $limit): array
    {
        $sql = $this->contractSelectSql($where) . " ORDER BY $dateColumn DESC, c.contract_id DESC";
        if ($limit !== null) {
            $sql .= " LIMIT $limit";
        }
        return $this->rows($sql, $params);
    }

    private function contractSelectSql(array $where): string
    {
        return "SELECT c.contract_number, c.contract_title, c.counterparty_name, ct.type_name, d.department_name, s.supplier_name, c.start_date, c.effective_date, c.end_date, c.contract_status
            FROM contract c
            INNER JOIN contract_type ct ON ct.contract_type_id = c.contract_type_id
            LEFT JOIN department_reference d ON d.department_reference_id = c.owning_department_reference_id
            LEFT JOIN supplier_reference s ON s.supplier_reference_id = c.supplier_reference_id
            WHERE " . implode(' AND ', $where);
    }

    private function contractExpiryTimeline(array $where, array $params): array
    {
        $timelineWhere = $where;
        $timelineWhere[] = $this->validContractDateSql('c.end_date');
        $rows = $this->rows("SELECT DATE_FORMAT(c.end_date, '%b %Y') label, DATE_FORMAT(c.end_date, '%Y-%m') sort_key, COUNT(*) value
            FROM contract c
            WHERE " . implode(' AND ', $timelineWhere) . "
            GROUP BY DATE_FORMAT(c.end_date, '%Y-%m'), DATE_FORMAT(c.end_date, '%b %Y')
            ORDER BY sort_key ASC", $params);
        return $this->fillMonthlyTimeline($rows);
    }

    private function contractColumns(): array
    {
        return ['Contract No.', 'Contract Title', 'Counterparty', 'Contract Type', 'Department', 'Effective Date', 'End Date', 'Status'];
    }

    private function shapeContractRow(array $row): array
    {
        return [
            $row['contract_number'],
            $row['contract_title'],
            $row['counterparty_name'] ?: $row['supplier_name'],
            $row['type_name'],
            $row['department_name'],
            $this->reportDate($row['effective_date'] ?: $row['start_date']),
            $this->reportDate($row['end_date']),
            $this->statusLabel($row['contract_status']),
        ];
    }

    private function streamContractsCsv(array $filters, mixed $handle): void
    {
        [$where, $params, $dateColumn] = $this->contractWhere($filters);
        fputcsv($handle, $this->contractColumns());
        $statement = $this->pdo->prepare($this->contractSelectSql($where) . " ORDER BY $dateColumn DESC, c.contract_id DESC");
        $statement->execute($params);
        while ($row = $statement->fetch()) {
            fputcsv($handle, array_map(fn($value) => $this->csvCell($value), $this->shapeContractRow($row)));
        }
    }

    private function baseWhere(string $base, string $dateColumn, array $filters): array
    {
        $where = [$base];
        $params = [];
        if ($filters['date_from'] !== '') {
            $where[] = "$dateColumn >= :date_from";
            $params['date_from'] = $filters['date_from'] . ' 00:00:00';
        }
        if ($filters['date_to'] !== '') {
            $where[] = "$dateColumn <= :date_to";
            $params['date_to'] = $filters['date_to'] . ' 23:59:59';
        }
        return [$where, $params];
    }

    private function validContractDateSql(string $column): string
    {
        return "$column IS NOT NULL AND $column NOT IN ('0000-00-00','1000-01-01','9999-12-31') AND $column > '1000-01-01' AND $column < '9999-12-31'";
    }

    private function validReportDateSql(string $column): string
    {
        return "$column IS NOT NULL AND $column NOT IN ('0000-00-00','1000-01-01','9999-12-31') AND $column > '1000-01-01' AND $column < '9999-12-31'";
    }

    private function recordRetentionStateSql(string $state): string
    {
        $state = strtoupper($state);
        if (in_array($state, ['ARCHIVED', 'DISPOSED'], true)) {
            return "rec.record_status = '$state'";
        }
        if ($state === 'ON_HOLD') {
            return "(COALESCE(rec.legal_hold_status, 'NONE') = 'ACTIVE' OR rec.record_status = 'ON_HOLD')";
        }
        if ($state === 'PERMANENT') {
            return "(UPPER(COALESCE(rs.retention_period_unit, '')) = 'PERMANENT' OR UPPER(COALESCE(rs.disposition_action, '')) = 'PERMANENT')";
        }
        if ($state === 'WAITING_FOR_TRIGGER') {
            return "(rec.retention_trigger_state = 'WAITING_FOR_TRIGGER' OR (UPPER(COALESCE(rec.retention_trigger_basis, rs.retention_trigger_basis, '')) IN ('RECORD_CLOSURE','WORK_COMPLETION','ASSET_DISPOSAL','FINAL_PAYMENT','CONTRACT_EXPIRATION') AND rec.retention_trigger_date IS NULL))";
        }
        if ($state === 'OVERDUE') {
            return "(rec.scheduled_disposition_date IS NOT NULL AND rec.scheduled_disposition_date < CURDATE() AND rec.record_status NOT IN ('ARCHIVED','DISPOSED','ON_HOLD') AND COALESCE(rec.legal_hold_status, 'NONE') <> 'ACTIVE')";
        }
        if ($state === 'DUE_FOR_REVIEW') {
            return "(rec.scheduled_disposition_date IS NOT NULL AND rec.scheduled_disposition_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND rec.record_status NOT IN ('ARCHIVED','DISPOSED','ON_HOLD') AND COALESCE(rec.legal_hold_status, 'NONE') <> 'ACTIVE')";
        }
        return "(rec.record_status NOT IN ('ARCHIVED','DISPOSED','ON_HOLD') AND COALESCE(rec.legal_hold_status, 'NONE') <> 'ACTIVE' AND (rec.scheduled_disposition_date IS NULL OR rec.scheduled_disposition_date > DATE_ADD(CURDATE(), INTERVAL 30 DAY)))";
    }

    private function recordRetentionStateCaseSql(): string
    {
        return "CASE
            WHEN rec.record_status = 'ARCHIVED' THEN 'ARCHIVED'
            WHEN rec.record_status = 'DISPOSED' THEN 'DISPOSED'
            WHEN COALESCE(rec.legal_hold_status, 'NONE') = 'ACTIVE' OR rec.record_status = 'ON_HOLD' THEN 'ON_HOLD'
            WHEN UPPER(COALESCE(rs.retention_period_unit, '')) = 'PERMANENT' OR UPPER(COALESCE(rs.disposition_action, '')) = 'PERMANENT' THEN 'PERMANENT'
            WHEN rec.retention_trigger_state = 'WAITING_FOR_TRIGGER' OR (UPPER(COALESCE(rec.retention_trigger_basis, rs.retention_trigger_basis, '')) IN ('RECORD_CLOSURE','WORK_COMPLETION','ASSET_DISPOSAL','FINAL_PAYMENT','CONTRACT_EXPIRATION') AND rec.retention_trigger_date IS NULL) THEN 'WAITING_FOR_TRIGGER'
            WHEN rec.scheduled_disposition_date IS NOT NULL AND rec.scheduled_disposition_date < CURDATE() THEN 'OVERDUE'
            WHEN rec.scheduled_disposition_date IS NOT NULL AND rec.scheduled_disposition_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 'DUE_FOR_REVIEW'
            ELSE 'ACTIVE'
        END";
    }

    private function recordRetentionStateFromRow(array $row): string
    {
        $status = strtoupper((string) ($row['record_status'] ?? 'ACTIVE'));
        if (in_array($status, ['ARCHIVED', 'DISPOSED'], true)) {
            return $status;
        }
        if (strtoupper((string) ($row['legal_hold_status'] ?? 'NONE')) === 'ACTIVE' || $status === 'ON_HOLD') {
            return 'ON_HOLD';
        }
        if (strtoupper((string) ($row['retention_period_unit'] ?? '')) === 'PERMANENT' || strtoupper((string) ($row['disposition_action'] ?? '')) === 'PERMANENT') {
            return 'PERMANENT';
        }
        $basis = strtoupper((string) ($row['retention_trigger_basis'] ?? $row['schedule_trigger_basis'] ?? ''));
        if (strtoupper((string) ($row['retention_trigger_state'] ?? '')) === 'WAITING_FOR_TRIGGER' || (in_array($basis, ['RECORD_CLOSURE','WORK_COMPLETION','ASSET_DISPOSAL','FINAL_PAYMENT','CONTRACT_EXPIRATION'], true) && empty($row['retention_trigger_date']))) {
            return 'WAITING_FOR_TRIGGER';
        }
        $date = (string) ($row['scheduled_disposition_date'] ?? '');
        if ($date !== '' && $date < date('Y-m-d')) {
            return 'OVERDUE';
        }
        if ($date !== '' && $date <= date('Y-m-d', strtotime('+30 days'))) {
            return 'DUE_FOR_REVIEW';
        }
        return 'ACTIVE';
    }

    private function fillMonthlyTimeline(array $rows): array
    {
        if (count($rows) < 2) {
            return $rows;
        }
        $first = DateTimeImmutable::createFromFormat('!Y-m', (string) $rows[0]['sort_key']);
        $last = DateTimeImmutable::createFromFormat('!Y-m', (string) $rows[count($rows) - 1]['sort_key']);
        if (!$first || !$last) {
            return $rows;
        }
        $monthCount = ((int) $first->diff($last)->format('%y') * 12) + (int) $first->diff($last)->format('%m') + 1;
        if ($monthCount > 36) {
            return $rows;
        }
        $byMonth = [];
        foreach ($rows as $row) {
            $byMonth[(string) $row['sort_key']] = (int) $row['value'];
        }
        $filled = [];
        for ($cursor = $first; $cursor <= $last; $cursor = $cursor->modify('+1 month')) {
            $key = $cursor->format('Y-m');
            $filled[] = ['label' => $cursor->format('M Y'), 'sort_key' => $key, 'value' => $byMonth[$key] ?? 0];
        }
        return $filled;
    }

    private function reportDate(mixed $value): string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '' || in_array($value, ['0000-00-00','1000-01-01','9999-12-31'], true)) {
            return 'Not Applicable';
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));
        if (!$date || $date->format('Y-m-d') <= '1000-01-01' || $date->format('Y-m-d') >= '9999-12-31') {
            return 'Not Applicable';
        }
        return $date->format('M j, Y');
    }

    private function reportDateTime(mixed $value): string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '' || in_array(substr($value, 0, 10), ['0000-00-00','1000-01-01','9999-12-31'], true)) {
            return 'Not Applicable';
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', substr($value, 0, 19))
            ?: DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', substr($value, 0, 16));
        if (!$date || $date->format('Y-m-d') <= '1000-01-01' || $date->format('Y-m-d') >= '9999-12-31') {
            return 'Not Applicable';
        }
        return $date->format('M j, Y g:i A');
    }

    private function statusLabel(mixed $value): string
    {
        return ucwords(strtolower(str_replace('_', ' ', (string) $value)));
    }

    private function exact(array &$where, array &$params, string $key, string $column, array $filters): void
    {
        if (($filters[$key] ?? '') === '') {
            return;
        }
        $param = str_replace('.', '_', $key);
        $where[] = "$column = :$param";
        $params[$param] = $filters[$key];
    }

    private function search(array &$where, array &$params, string $param, string $term, array $columns): void
    {
        $term = trim($term);
        if ($term === '') {
            return;
        }
        $clauses = [];
        foreach ($columns as $index => $column) {
            $key = $param . '_' . $index;
            $clauses[] = "$column LIKE :$key";
            $params[$key] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
        }
        $where[] = '(' . implode(' OR ', $clauses) . ')';
    }

    private function group(string $from, string $column, array $where, array $params): array
    {
        return $this->rows("SELECT COALESCE($column, 'Unspecified') label, COUNT(*) value FROM $from WHERE " . implode(' AND ', $where) . " GROUP BY COALESCE($column, 'Unspecified') ORDER BY value DESC, label LIMIT 12", $params);
    }

    private function rowsToGroup(array $rows, string $key): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $label = (string) ($row[$key] ?? 'Unspecified');
            $grouped[$label] = ($grouped[$label] ?? 0) + 1;
        }
        arsort($grouped);
        return array_map(fn($label, $value) => ['label' => $label, 'value' => $value], array_keys($grouped), array_values($grouped));
    }

    private function makeCsv(array $columns, array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, $columns);
        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn($value) => $this->csvCell($value), $row));
        }
        rewind($handle);
        return (string) stream_get_contents($handle);
    }

    private function csvCell(mixed $value): string
    {
        $text = (string) ($value ?? '');
        return preg_match('/^[=+\-@]/', $text) ? "'" . $text : $text;
    }

    private function reportKey(mixed $value): string
    {
        $key = (string) $value;
        if (!array_key_exists($key, self::REPORTS)) {
            throw new InvalidArgumentException('Unsupported report.');
        }
        return $key;
    }

    private function methodName(string $report): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $report)))) . 'Report';
    }

    private function authorize(array $user, string $permission): void
    {
        if (!$this->can($user, $permission)) {
            jsonResponse(false, 'You do not have permission to view this report.', [], 403);
        }
    }

    private function can(array $user, string $permission): bool
    {
        return in_array($permission, $user['permissions'] ?? [], true)
            || in_array(strtok($permission, '.') . '.manage', $user['permissions'] ?? [], true);
    }

    private function countRows(array $rows, string $key, array $values): int
    {
        $allowed = array_flip($values);
        return count(array_filter($rows, fn($row) => isset($allowed[strtoupper((string) ($row[$key] ?? ''))])));
    }

    private function countRowsNotIn(array $rows, string $key, array $values): int
    {
        $blocked = array_flip($values);
        return count(array_filter($rows, fn($row) => !isset($blocked[strtoupper((string) ($row[$key] ?? ''))])));
    }

    private function clean(mixed $value): string
    {
        $value = trim((string) $value);
        return preg_match('/^[A-Za-z0-9 _-]{1,100}$/', $value) ? $value : '';
    }

    private function intString(mixed $value): string
    {
        $value = (string) $value;
        return ctype_digit($value) ? $value : '';
    }

    private function date(mixed $value): string
    {
        $value = trim((string) $value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
    }

    private function dateBasis(mixed $value): string
    {
        return in_array($value, ['effective_start', 'end_expiry'], true) ? (string) $value : 'effective_start';
    }

    private function distinct(string $table, string $column): array
    {
        return array_map(fn($r) => $r['value'], $this->rows("SELECT DISTINCT `$column` value FROM `$table` WHERE `$column` IS NOT NULL ORDER BY `$column`"));
    }

    private function scalar(string $sql, array $params = []): int|float
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    private function rows(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
