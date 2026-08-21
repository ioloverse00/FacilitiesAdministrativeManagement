<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'LegalMatterSummaryService.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'LegalMatterPartyService.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'LegalMatterActionService.php';

final class LegalPolicy
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
            if (self::hasPermission($user, $permission)) {
                return;
            }
        }

        jsonResponse(false, 'You do not have permission to perform this action.', [], 403);
    }
}

final class LegalMatterService
{
    public const TYPES = [
        'PROPERTY_DAMAGE',
        'FACILITY_INCIDENT',
        'VISITOR_INCIDENT',
        'CONTRACT_RELATED',
        'COMPLAINT',
        'CLAIM',
        'COMPLIANCE',
        'OTHER',
    ];

    public const PRIORITIES = ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'];
    public const STATUSES = ['OPEN', 'UNDER_REVIEW', 'IN_PROGRESS', 'RESOLVED', 'CLOSED', 'CANCELLED'];
    private const ACTIVE_STATUSES = ['OPEN', 'UNDER_REVIEW', 'IN_PROGRESS'];
    private const TRANSITIONS = [
        'OPEN' => ['UNDER_REVIEW', 'IN_PROGRESS', 'CANCELLED'],
        'UNDER_REVIEW' => ['IN_PROGRESS', 'RESOLVED', 'CANCELLED'],
        'IN_PROGRESS' => ['RESOLVED', 'CANCELLED'],
        'RESOLVED' => ['CLOSED', 'IN_PROGRESS'],
        'CLOSED' => ['IN_PROGRESS'],
        'CANCELLED' => [],
    ];

    private readonly DocumentService $documentService;
    private readonly LegalMatterPartyService $partyService;
    private readonly LegalMatterActionService $actionService;

    public function __construct(private readonly PDO $pdo, ?DocumentService $documentService = null)
    {
        $this->documentService = $documentService ?? new DocumentService($pdo);
        $this->partyService = new LegalMatterPartyService($pdo, $this->documentService);
        $this->actionService = new LegalMatterActionService($pdo);
    }

    public function list(array $query): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($query['per_page'] ?? 10)));
        $sortMap = [
            'matter_number' => 'lm.matter_number',
            'title' => 'lm.title',
            'matter_type' => 'lm.matter_type',
            'priority' => 'lm.priority',
            'status' => 'lm.status',
            'department' => 'd.department_name',
            'assigned_to' => 'assignee.full_name',
            'updated_at' => 'lm.updated_at',
        ];
        $sort = $sortMap[(string) ($query['sort'] ?? '')] ?? 'lm.updated_at';
        $direction = strtolower((string) ($query['direction'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
        [$where, $params] = $this->filters($query);

        $count = $this->pdo->prepare($this->baseSelect('COUNT(DISTINCT lm.legal_matter_id)') . $where);
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $sql = $this->baseSelect($this->selectColumns()) . $where . " GROUP BY lm.legal_matter_id ORDER BY $sort $direction, lm.legal_matter_id DESC LIMIT :limit OFFSET :offset";
        $statement = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->bindValue('limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue('offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $statement->execute();

        return [
            'items' => array_map(fn (array $row): array => $this->shape($row), $statement->fetchAll()),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) max(1, ceil($total / max(1, $perPage))),
            ],
            'summary' => $this->summary(),
        ];
    }

    public function show(int $id): ?array
    {
        $statement = $this->pdo->prepare($this->baseSelect($this->selectColumns()) . ' WHERE lm.deleted_at IS NULL AND lm.legal_matter_id = :id GROUP BY lm.legal_matter_id LIMIT 1');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }
        $item = $this->shape($row);
        $item['supportingDocuments'] = $this->documentService->listRelated('LEGAL_MANAGEMENT', $item['matterNo']);
        $item['parties'] = $this->partyService->partiesForMatter($id);
        $item['partySuggestions'] = [];
        $item['actions'] = $this->actionService->actionsForMatter($id);
        $item['actionSuggestions'] = $this->actionService->suggestionsForMatter($id);
        $item['history'] = $this->history($id);
        return $item;
    }

    public function options(): array
    {
        return [
            'types' => self::TYPES,
            'priorities' => self::PRIORITIES,
            'statuses' => self::STATUSES,
            'departments' => array_map(fn (array $row): array => [
                'id' => (int) $row['department_reference_id'],
                'name' => (string) $row['department_name'],
            ], $this->rows("SELECT department_reference_id, department_name FROM department_reference WHERE status = 'ACTIVE' ORDER BY department_name")),
            'employees' => array_map(fn (array $row): array => [
                'id' => (int) $row['employee_reference_id'],
                'name' => (string) $row['full_name'],
                'employeeNo' => (string) $row['employee_number'],
                'department' => (string) ($row['department_name'] ?? ''),
            ], $this->rows("SELECT e.employee_reference_id, e.employee_number, e.full_name, d.department_name FROM employee_reference e LEFT JOIN department_reference d ON d.department_reference_id = e.department_reference_id WHERE e.employment_status = 'ACTIVE' ORDER BY e.full_name")),
            'party_roles' => LegalMatterPartyService::PARTY_ROLES,
            'party_types' => LegalMatterPartyService::PARTY_TYPES,
            'action_types' => LegalMatterActionService::ACTION_TYPES,
            'action_statuses' => LegalMatterActionService::STATUSES,
            'visitors' => array_map(fn (array $row): array => [
                'id' => (int) $row['visitor_id'],
                'name' => trim((string) $row['full_name']),
                'organization' => (string) ($row['organization_name'] ?? ''),
            ], $this->rows("SELECT visitor_id, TRIM(CONCAT_WS(' ', first_name, middle_name, last_name)) full_name, organization_name FROM visitor WHERE deleted_at IS NULL ORDER BY updated_at DESC, visitor_id DESC LIMIT 200")),
        ];
    }

    public function create(array $data, array $user): array
    {
        $clean = $this->validateMatter($data, true);
        $userId = (int) $user['id'];
        $this->pdo->beginTransaction();
        try {
            $number = $this->nextMatterNumber();
            $statement = $this->pdo->prepare('INSERT INTO legal_matter (matter_number, title, matter_type, summary, initial_note, priority, status, department_reference_id, assigned_employee_reference_id, reported_at, opened_at, created_by_user_id, created_at, updated_at) VALUES (:matter_number, :title, :matter_type, :summary, :initial_note, :priority, \'OPEN\', :department_id, NULL, :reported_at, NOW(), :user_id, NOW(), NOW())');
            $statement->execute([
                'matter_number' => $number,
                'title' => $clean['title'],
                'matter_type' => $clean['matter_type'],
                'summary' => $clean['summary'],
                'initial_note' => $clean['initial_note'],
                'priority' => $clean['priority'],
                'department_id' => $clean['department_reference_id'],
                'reported_at' => $clean['reported_at'],
                'user_id' => $userId,
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $this->historyEvent($id, 'LEGAL_MATTER_CREATED', null, 'OPEN', "Legal matter $number created.", [], $userId);
            $this->activity('LEGAL_MATTER_CREATED', 'Legal Matter Created', "Legal matter $number created.", $id, $number, $user);
            $this->pdo->commit();
            return $this->show($id) ?? ['id' => $id, 'matterNo' => $number];
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function attachSupportingDocument(int $id, array $data, array $file, array $user, bool $markAiSummaryStale = true): ?array
    {
        $matter = $this->show($id);
        if ($matter === null) {
            return null;
        }
        $this->assertNotClosed($matter);

        $this->documentService->validateLegalEvidenceFile($file);
        $legalDefaults = $this->documentService->legalCategoryDefaults();
        $metadata = [
            'title' => $this->text($data['title'] ?? '', 255) ?: $this->fileTitle($file),
            'description' => $this->text($data['description'] ?? '', 4000),
            'document_category_id' => $legalDefaults['document_category_id'],
            'confidentiality_level' => $legalDefaults['confidentiality_level'],
            'status' => 'ACTIVE',
            'document_date' => $data['document_date'] ?? date('Y-m-d'),
            'change_summary' => $data['change_summary'] ?? 'Initial legal supporting document upload',
            'related_module' => 'LEGAL_MANAGEMENT',
            'related_reference' => $matter['matterNo'],
        ];

        $document = $this->documentService->create($metadata, $file, $user);
        $documentNo = (string) ($document['documentNo'] ?? 'document');
        $this->historyEvent($id, 'LEGAL_DOCUMENT_ATTACHED', null, null, "Supporting document $documentNo attached.", ['document_id' => $document['id'] ?? null, 'document_number' => $documentNo], (int) $user['id']);
        $this->activity('LEGAL_DOCUMENT_ATTACHED', 'Legal Document Attached', "Supporting document $documentNo attached.", $id, $matter['matterNo'], $user);
        if ($markAiSummaryStale) {
            (new LegalMatterSummaryService($this->pdo, $this->documentService))->markStaleIfReady($id, $user);
        }

        return $this->show($id);
    }

    public function update(int $id, array $data, array $user): ?array
    {
        $before = $this->show($id);
        if ($before === null) {
            return null;
        }
        if ($before['status'] === 'CLOSED') {
            throw new InvalidArgumentException(json_encode(['status' => 'This legal matter is closed and is read-only.'], JSON_THROW_ON_ERROR));
        }
        if ($before['status'] !== 'OPEN') {
            throw new InvalidArgumentException(json_encode(['status' => 'This legal matter is already under formal review. General matter details can no longer be edited through the pre-review correction workflow.'], JSON_THROW_ON_ERROR));
        }
        $clean = $this->validateMatter($data, false);
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('UPDATE legal_matter SET title = :title, matter_type = :matter_type, summary = :summary, initial_note = :initial_note, priority = :priority, department_reference_id = :department_id, reported_at = :reported_at, updated_at = NOW() WHERE legal_matter_id = :id AND deleted_at IS NULL')->execute([
                'title' => $clean['title'],
                'matter_type' => $clean['matter_type'],
                'summary' => $clean['summary'],
                'initial_note' => $clean['initial_note'],
                'priority' => $clean['priority'],
                'department_id' => $clean['department_reference_id'],
                'reported_at' => $clean['reported_at'],
                'id' => $id,
            ]);
            $this->historyEvent($id, 'LEGAL_MATTER_UPDATED', null, null, 'Legal matter metadata updated.', [], (int) $user['id']);
            $this->activity('LEGAL_MATTER_UPDATED', 'Legal Matter Updated', 'Legal matter updated.', $id, $before['matterNo'], $user);
            $this->pdo->commit();
            return $this->show($id);
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function assign(int $id, array $data, array $user): ?array
    {
        $before = $this->show($id);
        if ($before === null) {
            return null;
        }
        $this->assertNotClosed($before);
        if ($before['status'] === 'CANCELLED') {
            throw new InvalidArgumentException(json_encode(['status' => 'Cancelled matters cannot be reassigned.'], JSON_THROW_ON_ERROR));
        }
        $employeeId = $this->optionalId($data['assigned_employee_reference_id'] ?? null);
        if ($employeeId !== null) {
            $this->assertEmployee($employeeId);
        }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('UPDATE legal_matter SET assigned_employee_reference_id = :employee_id, updated_at = NOW() WHERE legal_matter_id = :id AND deleted_at IS NULL')->execute(['employee_id' => $employeeId, 'id' => $id]);
            $this->historyEvent($id, 'LEGAL_MATTER_ASSIGNED', null, null, $employeeId === null ? 'Legal matter unassigned.' : 'Legal matter assigned.', ['from' => $before['assignedEmployeeId'], 'to' => $employeeId], (int) $user['id']);
            $this->activity('LEGAL_MATTER_ASSIGNED', 'Legal Matter Assigned', 'Legal matter assigned.', $id, $before['matterNo'], $user);
            $this->pdo->commit();
            return $this->show($id);
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function transition(int $id, array $data, array $user): ?array
    {
        $item = $this->show($id);
        if ($item === null) {
            return null;
        }
        $action = strtolower(trim((string) ($data['action'] ?? '')));
        $current = $item['status'];
        [$next, $event, $title, $description, $requiredField] = match ($action) {
            'start_review' => ['UNDER_REVIEW', 'LEGAL_STATUS_CHANGED', 'Legal Matter Under Review', 'Legal matter moved to Under Review.', null],
            'start_processing' => ['IN_PROGRESS', 'LEGAL_STATUS_CHANGED', 'Legal Matter In Progress', 'Legal matter moved to In Progress.', null],
            'resolve' => ['RESOLVED', 'LEGAL_MATTER_RESOLVED', 'Legal Matter Resolved', 'Legal matter resolved.', 'resolution_summary'],
            'close' => ['CLOSED', 'LEGAL_MATTER_CLOSED', 'Legal Matter Closed', 'Legal matter closed.', null],
            'cancel' => ['CANCELLED', 'LEGAL_MATTER_CANCELLED', 'Legal Matter Cancelled', 'Legal matter cancelled.', 'cancellation_reason'],
            'reopen' => ['IN_PROGRESS', 'LEGAL_MATTER_REOPENED', 'Legal Matter Reopened', 'Legal matter reopened.', 'reopen_reason'],
            default => throw new InvalidArgumentException(json_encode(['action' => 'Choose a valid lifecycle action.'], JSON_THROW_ON_ERROR)),
        };
        if (!in_array($next, self::TRANSITIONS[$current] ?? [], true)) {
            throw new InvalidArgumentException(json_encode(['status' => "Cannot move matter from $current to $next."], JSON_THROW_ON_ERROR));
        }
        if ($action === 'start_processing' && ($item['assignedEmployeeId'] ?? null) === null) {
            throw new InvalidArgumentException(json_encode(['assigned_employee_reference_id' => 'Assign a responsible handler before beginning processing.'], JSON_THROW_ON_ERROR));
        }
        $reason = $requiredField !== null ? $this->text($data[$requiredField] ?? '', 2000) : $this->text($data['remarks'] ?? '', 1000);
        if ($requiredField !== null && $reason === '') {
            throw new InvalidArgumentException(json_encode([$requiredField => 'Enter the required reason or summary.'], JSON_THROW_ON_ERROR));
        }
        if ($next === 'CLOSED' && $current !== 'RESOLVED') {
            throw new InvalidArgumentException(json_encode(['status' => 'Only resolved matters can be closed.'], JSON_THROW_ON_ERROR));
        }
        if (in_array($next, ['RESOLVED', 'CLOSED'], true) && $this->actionService->hasOpenActions($id)) {
            throw new InvalidArgumentException(json_encode(['actions' => 'Complete or cancel all open legal actions before resolving this matter.'], JSON_THROW_ON_ERROR));
        }

        $this->pdo->beginTransaction();
        try {
            $sets = ['status = :status', 'updated_at = NOW()'];
            $params = ['status' => $next, 'id' => $id];
            if ($next === 'RESOLVED') {
                $sets[] = 'resolved_at = NOW()';
                $sets[] = 'resolved_by_user_id = :user_id';
                $sets[] = 'resolution_summary = :resolution_summary';
                $params['user_id'] = (int) $user['id'];
                $params['resolution_summary'] = $reason;
            } elseif ($next === 'CLOSED') {
                $sets[] = 'closed_at = NOW()';
                $sets[] = 'closed_by_user_id = :user_id';
                $params['user_id'] = (int) $user['id'];
            } elseif ($next === 'CANCELLED') {
                $sets[] = 'cancelled_at = NOW()';
                $sets[] = 'cancelled_by_user_id = :user_id';
                $sets[] = 'cancellation_reason = :cancellation_reason';
                $params['user_id'] = (int) $user['id'];
                $params['cancellation_reason'] = $reason;
            } elseif ($action === 'reopen') {
                $sets[] = 'resolved_at = NULL';
                $sets[] = 'resolved_by_user_id = NULL';
                $sets[] = 'closed_at = NULL';
                $sets[] = 'closed_by_user_id = NULL';
            }
            $this->pdo->prepare('UPDATE legal_matter SET ' . implode(', ', $sets) . ' WHERE legal_matter_id = :id AND deleted_at IS NULL')->execute($params);
            $metadata = $reason !== '' ? ['reason' => $reason] : [];
            $this->historyEvent($id, $event, $current, $next, $description, $metadata, (int) $user['id']);
            $this->activity($event, $title, $description, $id, $item['matterNo'], $user);
            $this->pdo->commit();
            return $this->show($id);
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    private function validateMatter(array $data, bool $creating): array
    {
        $errors = [];
        $title = $this->text($data['title'] ?? '', 255);
        $initialNote = $this->text($data['initial_note'] ?? ($data['summary'] ?? ''), 5000);
        $summary = $initialNote;
        $type = strtoupper($this->text($data['matter_type'] ?? '', 50));
        $priority = strtoupper($this->text($data['priority'] ?? 'MEDIUM', 30));
        $departmentId = $this->optionalId($data['department_reference_id'] ?? null);
        $assignedId = null;
        $reportedAt = $this->date($data['reported_at'] ?? null);
        if ($title === '') $errors['title'] = 'Enter a legal matter title.';
        if (!in_array($type, self::TYPES, true)) $errors['matter_type'] = 'Choose a valid matter type.';
        if (!in_array($priority, self::PRIORITIES, true)) $errors['priority'] = 'Choose a valid priority.';
        if ($departmentId !== null) $this->assertDepartment($departmentId);
        if ($errors) {
            throw new InvalidArgumentException(json_encode($errors, JSON_THROW_ON_ERROR));
        }
        return [
            'title' => $title,
            'summary' => $summary,
            'initial_note' => $initialNote,
            'matter_type' => $type,
            'priority' => $priority,
            'department_reference_id' => $departmentId,
            'assigned_employee_reference_id' => $assignedId,
            'reported_at' => $reportedAt,
        ];
    }

    private function filters(array $query): array
    {
        $where = ['lm.deleted_at IS NULL'];
        $params = [];
        if (trim((string) ($query['search'] ?? '')) !== '') {
            $search = '%' . trim((string) $query['search']) . '%';
            $where[] = '(lm.matter_number LIKE :search_number OR lm.title LIKE :search_title)';
            $params['search_number'] = $search;
            $params['search_title'] = $search;
        }
        $map = [
            'matter_type' => 'lm.matter_type',
            'priority' => 'lm.priority',
            'status' => 'lm.status',
            'department' => 'lm.department_reference_id',
            'assigned_employee' => 'lm.assigned_employee_reference_id',
        ];
        foreach ($map as $key => $column) {
            $value = $query[$key] ?? '';
            if ($value !== '' && $value !== 'all') {
                $where[] = "$column = :$key";
                $params[$key] = in_array($key, ['department', 'assigned_employee'], true) ? (int) $value : strtoupper((string) $value);
            }
        }
        return [' WHERE ' . implode(' AND ', $where), $params];
    }

    private function summary(): array
    {
        return [
            'open' => (int) $this->scalar("SELECT COUNT(*) FROM legal_matter WHERE deleted_at IS NULL AND status IN ('OPEN','UNDER_REVIEW','IN_PROGRESS')"),
            'critical' => (int) $this->scalar("SELECT COUNT(*) FROM legal_matter WHERE deleted_at IS NULL AND priority = 'CRITICAL' AND status IN ('OPEN','UNDER_REVIEW','IN_PROGRESS')"),
            'underReview' => (int) $this->scalar("SELECT COUNT(*) FROM legal_matter WHERE deleted_at IS NULL AND status = 'UNDER_REVIEW'"),
            'resolvedThisPeriod' => (int) $this->scalar("SELECT COUNT(*) FROM legal_matter WHERE deleted_at IS NULL AND status IN ('RESOLVED','CLOSED') AND resolved_at >= DATE_FORMAT(CURRENT_DATE(), '%Y-%m-01')"),
        ];
    }

    private function shape(array $row): array
    {
        $status = (string) ($row['status'] ?? 'OPEN');
        return [
            'id' => (int) $row['legal_matter_id'],
            'matterNo' => (string) $row['matter_number'],
            'title' => (string) $row['title'],
            'matterType' => (string) $row['matter_type'],
            'summary' => (string) $row['summary'],
            'initialNote' => (string) ($row['initial_note'] ?? $row['summary'] ?? ''),
            'aiSummary' => (string) ($row['ai_summary'] ?? ''),
            'aiSummaryStatus' => (string) ($row['ai_summary_status'] ?? 'NOT_REQUESTED'),
            'aiSummaryGeneratedAt' => (string) ($row['ai_summary_generated_at'] ?? ''),
            'aiSummaryProvider' => (string) ($row['ai_summary_provider'] ?? ''),
            'aiSummaryModel' => (string) ($row['ai_summary_model'] ?? ''),
            'aiSummarySourceFingerprint' => (string) ($row['ai_summary_source_fingerprint'] ?? ''),
            'priority' => (string) $row['priority'],
            'status' => $status,
            'departmentId' => $row['department_reference_id'] === null ? null : (int) $row['department_reference_id'],
            'department' => (string) ($row['department_name'] ?? ''),
            'assignedEmployeeId' => $row['assigned_employee_reference_id'] === null ? null : (int) $row['assigned_employee_reference_id'],
            'assignedTo' => (string) ($row['assigned_name'] ?? ''),
            'reportedAt' => (string) ($row['reported_at'] ?? ''),
            'openedAt' => (string) ($row['opened_at'] ?? ''),
            'resolvedAt' => (string) ($row['resolved_at'] ?? ''),
            'resolvedBy' => (string) ($row['resolved_by_name'] ?? ''),
            'resolutionSummary' => (string) ($row['resolution_summary'] ?? ''),
            'closedAt' => (string) ($row['closed_at'] ?? ''),
            'closedBy' => (string) ($row['closed_by_name'] ?? ''),
            'cancelledAt' => (string) ($row['cancelled_at'] ?? ''),
            'cancelledBy' => (string) ($row['cancelled_by_name'] ?? ''),
            'cancellationReason' => (string) ($row['cancellation_reason'] ?? ''),
            'createdBy' => (string) ($row['created_by_name'] ?? ''),
            'createdAt' => (string) ($row['created_at'] ?? ''),
            'updatedAt' => (string) ($row['updated_at'] ?? ''),
            'allowedActions' => $this->allowedActions($status),
        ];
    }

    private function allowedActions(string $status): array
    {
        return match ($status) {
            'OPEN' => ['view', 'edit', 'assign', 'start_review', 'cancel'],
            'UNDER_REVIEW' => ['view', 'assign', 'start_processing', 'cancel'],
            'IN_PROGRESS' => ['view', 'assign', 'resolve', 'cancel'],
            'RESOLVED' => ['view', 'assign', 'close', 'reopen'],
            'CLOSED' => ['view', 'reopen'],
            'CANCELLED' => ['view'],
            default => ['view'],
        };
    }

    private function assertNotClosed(array $matter): void
    {
        if (($matter['status'] ?? '') === 'CLOSED') {
            throw new InvalidArgumentException(json_encode(['status' => 'This legal matter is closed and is read-only.'], JSON_THROW_ON_ERROR));
        }
    }

    private function history(int $id): array
    {
        return array_map(fn (array $row): array => [
            'type' => (string) $row['event_type'],
            'fromStatus' => (string) ($row['from_status'] ?? ''),
            'toStatus' => (string) ($row['to_status'] ?? ''),
            'description' => (string) $row['description'],
            'actor' => (string) ($row['actor_name'] ?? 'System'),
            'createdAt' => (string) $row['created_at'],
        ], $this->rows("SELECT h.*, actor.full_name actor_name FROM legal_matter_history h LEFT JOIN user_account u ON u.user_account_id = h.actor_user_id LEFT JOIN employee_reference actor ON actor.employee_reference_id = u.employee_reference_id WHERE h.legal_matter_id = :id ORDER BY h.created_at DESC, h.legal_matter_history_id DESC", ['id' => $id]));
    }

    private function nextMatterNumber(): string
    {
        $year = date('Y');
        $prefix = "LM-$year-";
        $statement = $this->pdo->prepare('SELECT matter_number FROM legal_matter WHERE matter_number LIKE :prefix ORDER BY matter_number DESC LIMIT 1 FOR UPDATE');
        $statement->execute(['prefix' => $prefix . '%']);
        $last = (string) ($statement->fetchColumn() ?: '');
        $next = 1;
        if (preg_match('/^LM-\d{4}-(\d{4})$/', $last, $matches)) {
            $next = (int) $matches[1] + 1;
        }
        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    private function baseSelect(string $columns): string
    {
        return "SELECT $columns FROM legal_matter lm LEFT JOIN department_reference d ON d.department_reference_id = lm.department_reference_id LEFT JOIN employee_reference assignee ON assignee.employee_reference_id = lm.assigned_employee_reference_id LEFT JOIN user_account creator ON creator.user_account_id = lm.created_by_user_id LEFT JOIN employee_reference created_by ON created_by.employee_reference_id = creator.employee_reference_id LEFT JOIN user_account resolver ON resolver.user_account_id = lm.resolved_by_user_id LEFT JOIN employee_reference resolved_by ON resolved_by.employee_reference_id = resolver.employee_reference_id LEFT JOIN user_account closer ON closer.user_account_id = lm.closed_by_user_id LEFT JOIN employee_reference closed_by ON closed_by.employee_reference_id = closer.employee_reference_id LEFT JOIN user_account canceller ON canceller.user_account_id = lm.cancelled_by_user_id LEFT JOIN employee_reference cancelled_by ON cancelled_by.employee_reference_id = canceller.employee_reference_id ";
    }

    private function selectColumns(): string
    {
        return 'lm.*, d.department_name, assignee.full_name assigned_name, created_by.full_name created_by_name, resolved_by.full_name resolved_by_name, closed_by.full_name closed_by_name, cancelled_by.full_name cancelled_by_name';
    }

    private function historyEvent(int $id, string $type, ?string $from, ?string $to, string $description, array $metadata, ?int $userId): void
    {
        $statement = $this->pdo->prepare('INSERT INTO legal_matter_history (legal_matter_id, event_type, from_status, to_status, description, metadata_json, actor_user_id, created_at) VALUES (:id, :type, :from_status, :to_status, :description, :metadata, :user_id, NOW())');
        $statement->execute([
            'id' => $id,
            'type' => $type,
            'from_status' => $from,
            'to_status' => $to,
            'description' => $description,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'user_id' => $userId,
        ]);
    }

    private function activity(string $eventType, string $title, string $description, int $id, string $reference, array $user): void
    {
        try {
            $statement = $this->pdo->prepare("INSERT INTO activity_event (event_uuid, module_code, entity_type, entity_id, entity_reference, event_type, event_title, event_description, actor_user_id, actor_employee_reference_id, visibility_scope, metadata_json, occurred_at, created_at) VALUES (:uuid, 'LEGAL_MANAGEMENT', 'legal_matter', :id, :reference, :event_type, :title, :description, :user_id, :employee_id, 'INTERNAL', '{}', NOW(), NOW())");
            $statement->execute([
                'uuid' => self::uuidV4(),
                'id' => $id,
                'reference' => $reference,
                'event_type' => $eventType,
                'title' => $title,
                'description' => $description,
                'user_id' => (int) $user['id'],
                'employee_id' => $user['employee_id'] ?? null,
            ]);
        } catch (Throwable $exception) {
            error_log('Legal activity logging failed: ' . $exception::class);
        }
    }

    private function assertDepartment(int $id): void
    {
        if ((int) $this->scalar("SELECT COUNT(*) FROM department_reference WHERE department_reference_id = :id AND status = 'ACTIVE'", ['id' => $id]) < 1) {
            throw new InvalidArgumentException(json_encode(['department_reference_id' => 'Choose a valid department.'], JSON_THROW_ON_ERROR));
        }
    }

    private function assertEmployee(int $id): void
    {
        if ((int) $this->scalar("SELECT COUNT(*) FROM employee_reference WHERE employee_reference_id = :id AND employment_status = 'ACTIVE'", ['id' => $id]) < 1) {
            throw new InvalidArgumentException(json_encode(['assigned_employee_reference_id' => 'Choose a valid employee.'], JSON_THROW_ON_ERROR));
        }
    }

    private function optionalId(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 'all') return null;
        if (!ctype_digit((string) $value)) {
            throw new InvalidArgumentException(json_encode(['id' => 'Choose a valid reference.'], JSON_THROW_ON_ERROR));
        }
        return (int) $value;
    }

    private function date(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        return (new DateTimeImmutable((string) $value))->format('Y-m-d');
    }

    private function text(mixed $value, int $max): string
    {
        return mb_substr(trim((string) $value), 0, $max);
    }

    private function fileTitle(array $file): string
    {
        $name = trim((string) ($file['name'] ?? 'Supporting Document'));
        $withoutExtension = preg_replace('/\.[^.]+$/', '', $name) ?: $name;
        return $this->text($withoutExtension, 255) ?: 'Supporting Document';
    }

    private function rows(string $sql, array $params = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll();
    }

    private function scalar(string $sql, array $params = []): mixed
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement->fetchColumn();
    }

    private static function uuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
