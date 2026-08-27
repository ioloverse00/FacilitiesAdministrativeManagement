<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Organization' . DIRECTORY_SEPARATOR . 'FamEmployeeEligibilityService.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Documents' . DIRECTORY_SEPARATOR . 'DocumentService.php';

final class ContractPolicy
{
    public static function hasPermission(array $user, string $permission): bool
    {
        return in_array($permission, $user['permissions'] ?? [], true)
            || in_array('contract.manage', $user['permissions'] ?? [], true);
    }

    public static function requirePermission(array $user, string $permission): void
    {
        if (!self::hasPermission($user, $permission)) {
            jsonResponse(false, 'You do not have permission to perform this action.', [], 403);
        }
    }
}

final class ContractService
{
    public const STATUSES = ['DRAFT','FOR_REVIEW','FOR_APPROVAL','APPROVED','ACTIVE','EXPIRED','TERMINATED','ARCHIVED','REJECTED','CANCELLED'];
    private const EDITABLE_STATUSES = ['DRAFT'];
    private const RENEWAL_TYPES = ['NONE','MANUAL','AUTO'];
    private const RISK_LEVELS = ['LOW','MEDIUM','HIGH','CRITICAL'];
    private const CURRENCIES = ['PHP','USD','EUR'];
    private const TRANSITIONS = [
        'DRAFT' => ['FOR_REVIEW', 'CANCELLED'],
        'FOR_REVIEW' => ['DRAFT', 'FOR_APPROVAL'],
        'FOR_APPROVAL' => [],
        'APPROVED' => ['ACTIVE', 'DRAFT'],
        'ACTIVE' => ['TERMINATED', 'EXPIRED'],
        'EXPIRED' => ['ARCHIVED'],
        'TERMINATED' => ['ARCHIVED'],
        'REJECTED' => ['DRAFT'],
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly ?FamEmployeeEligibilityService $employeeEligibility = null
    ) {
    }

    public function list(array $query, array $user): array
    {
        ContractPolicy::requirePermission($user, 'contract.view');
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($query['per_page'] ?? 10)));
        $sortMap = [
            'contract_number' => 'c.contract_number',
            'title' => 'c.contract_title',
            'status' => 'c.contract_status',
            'end_date' => 'c.end_date',
            'current_amount' => 'c.current_amount',
            'updated_at' => 'c.updated_at',
        ];
        $sort = $sortMap[(string) ($query['sort'] ?? '')] ?? 'c.updated_at';
        $direction = strtolower((string) ($query['direction'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
        [$where, $params] = $this->filters($query);

        $count = $this->pdo->prepare($this->baseSelect('COUNT(DISTINCT c.contract_id)') . $where);
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $sql = $this->baseSelect($this->selectColumns()) . $where . " GROUP BY c.contract_id ORDER BY $sort $direction, c.contract_id DESC LIMIT :limit OFFSET :offset";
        $statement = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->bindValue('limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue('offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $statement->execute();

        return [
            'items' => array_map(fn (array $row): array => $this->shape($row, $user, false), $statement->fetchAll()),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) max(1, ceil($total / max(1, $perPage))),
            ],
            'summary' => $this->summary(),
        ];
    }

    public function dashboardSummary(): array
    {
        return $this->summary();
    }

    public function show(int|string $idOrNumber, array $user): ?array
    {
        ContractPolicy::requirePermission($user, 'contract.view');
        $where = is_int($idOrNumber) ? 'c.contract_id = :value' : 'c.contract_number = :value';
        $statement = $this->pdo->prepare($this->baseSelect($this->selectColumns()) . " WHERE c.deleted_at IS NULL AND $where GROUP BY c.contract_id LIMIT 1");
        $statement->execute(['value' => $idOrNumber]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }
        $item = $this->shape($row, $user, true);
        $item['documents'] = $this->documentsForContract((int) $row['contract_id']);
        $item['history'] = $this->history((int) $row['contract_id']);
        return $item;
    }

    public function documents(int $id, array $user): ?array
    {
        ContractPolicy::requirePermission($user, 'contract.view');
        $contract = $this->row('SELECT contract_id FROM contract WHERE contract_id = :id AND deleted_at IS NULL LIMIT 1', ['id' => $id]);
        if ($contract === null) {
            return null;
        }
        return ['items' => $this->documentsForContract($id)];
    }

    public function attachDocument(int $id, array $data, array $file, array $user): ?array
    {
        ContractPolicy::requirePermission($user, 'contract.edit');
        $contract = $this->row('SELECT contract_id, contract_number, contract_title FROM contract WHERE contract_id = :id AND deleted_at IS NULL LIMIT 1', ['id' => $id]);
        if ($contract === null) {
            return null;
        }

        $categoryId = (int) $this->scalar("SELECT document_category_id FROM document_category WHERE category_code = 'DOC-CON' AND status = 'ACTIVE' LIMIT 1");
        if ($categoryId < 1) {
            throw new InvalidArgumentException(json_encode(['document_category_id' => 'Contract document category is not configured.'], JSON_THROW_ON_ERROR));
        }
        $isPrimary = !empty($data['is_primary_document']);
        $metadata = [
            'title' => $this->text($data['title'] ?? $data['document_title'] ?? ($contract['contract_title'] . ' Document'), 255),
            'description' => $this->nullableText($data['description'] ?? $data['document_description'] ?? '', 4000),
            'document_category_id' => $categoryId,
            'confidentiality_level' => strtoupper($this->text($data['confidentiality_level'] ?? 'CONFIDENTIAL', 30)),
            'status' => 'ACTIVE',
            'document_date' => $data['document_date'] ?? date('Y-m-d'),
            'change_summary' => $data['change_summary'] ?? 'Initial contract document upload',
            'related_module' => 'CONTRACT_MANAGEMENT',
            'related_reference' => (string) $contract['contract_number'],
        ];

        $document = (new DocumentService($this->pdo))->create($metadata, $file, $user);
        $documentId = (int) ($document['id'] ?? 0);
        if ($documentId > 0) {
            $this->pdo->prepare("UPDATE record r INNER JOIN record_document rd ON rd.record_id = r.record_id SET r.source_entity_id = :contract_id, rd.is_primary_document = IF(:primary_flag = 1, TRUE, rd.is_primary_document) WHERE rd.document_id = :document_id AND r.source_module = 'contract_management' AND r.source_entity_type = :contract_number")->execute([
                'contract_id' => $id,
                'primary_flag' => $isPrimary ? 1 : 0,
                'document_id' => $documentId,
                'contract_number' => (string) $contract['contract_number'],
            ]);
            if ($isPrimary) {
                $this->pdo->prepare("UPDATE record_document rd INNER JOIN record r ON r.record_id = rd.record_id SET rd.is_primary_document = IF(rd.document_id = :document_id, TRUE, FALSE) WHERE r.source_module = 'contract_management' AND r.source_entity_id = :contract_id")->execute(['document_id' => $documentId, 'contract_id' => $id]);
            }
            $this->historyEvent($id, 'DOCUMENT_ATTACHED', 'Contract document attached.', null, null, (int) $user['id'], ['document_id' => $documentId, 'is_primary' => $isPrimary]);
        }

        return $this->show($id, $user);
    }

    public function create(array $data, array $user): array
    {
        ContractPolicy::requirePermission($user, 'contract.create');
        $clean = $this->validateContract($data, true);
        $this->pdo->beginTransaction();
        try {
            $number = $this->nextContractNumber();
            $statement = $this->pdo->prepare('INSERT INTO contract (contract_number, contract_type_id, contract_title, contract_description, supplier_reference_id, budget_reference_id, procurement_request_id, purchase_order_reference_id, contract_owner_employee_reference_id, owning_department_reference_id, fam_handler_employee_reference_id, start_date, executed_date, effective_date, end_date, termination_date, termination_reason, original_amount, current_amount, currency_code, contract_status, notice_period_days, renewal_type, renewal_decision_date, renewed_from_contract_id, risk_level, created_by_user_id, updated_by_user_id, created_at, updated_at) VALUES (:number, :type_id, :title, :description, :supplier_id, :budget_id, :procurement_request_id, :purchase_order_id, :owner_id, :department_id, :handler_id, :start_date, NULL, :effective_date, :end_date, NULL, NULL, :original_amount, :current_amount, :currency, \'DRAFT\', :notice_days, :renewal_type, :renewal_decision_date, :renewed_from_id, :risk_level, :created_by, :updated_by, NOW(), NOW())');
            $statement->execute($clean + ['number' => $number, 'created_by' => (int) $user['id'], 'updated_by' => (int) $user['id']]);
            $id = (int) $this->pdo->lastInsertId();
            $this->historyEvent($id, 'CREATED', "Contract $number created.", null, 'DRAFT', (int) $user['id'], ['contract_number' => $number]);
            $this->pdo->commit();
            return $this->show($id, $user) ?? ['id' => $id, 'contractNo' => $number];
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            if ($exception instanceof PDOException && $exception->getCode() === '23000') {
                throw new RuntimeException('Contract number conflict. Please retry.');
            }
            throw $exception;
        }
    }

    public function update(int $id, array $data, array $user): ?array
    {
        ContractPolicy::requirePermission($user, 'contract.edit');
        $before = $this->row('SELECT * FROM contract WHERE contract_id = :id AND deleted_at IS NULL LIMIT 1', ['id' => $id]);
        if ($before === null) {
            return null;
        }
        if (!in_array((string) $before['contract_status'], self::EDITABLE_STATUSES, true)) {
            throw new DomainException('This contract status is read-only for direct metadata edits.');
        }
        $clean = $this->validateContract(array_merge($before, $data), false);
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare('UPDATE contract SET contract_type_id = :type_id, contract_title = :title, contract_description = :description, supplier_reference_id = :supplier_id, budget_reference_id = :budget_id, procurement_request_id = :procurement_request_id, purchase_order_reference_id = :purchase_order_id, contract_owner_employee_reference_id = :owner_id, owning_department_reference_id = :department_id, fam_handler_employee_reference_id = :handler_id, start_date = :start_date, effective_date = :effective_date, end_date = :end_date, original_amount = :original_amount, current_amount = :current_amount, currency_code = :currency, notice_period_days = :notice_days, renewal_type = :renewal_type, renewal_decision_date = :renewal_decision_date, renewed_from_contract_id = :renewed_from_id, risk_level = :risk_level, updated_by_user_id = :user_id, updated_at = NOW() WHERE contract_id = :id AND deleted_at IS NULL');
            $statement->execute($clean + ['id' => $id, 'user_id' => (int) $user['id']]);
            $this->historyEvent($id, 'UPDATED', 'Contract draft metadata updated.', null, null, (int) $user['id']);
            $this->pdo->commit();
            return $this->show($id, $user);
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function transition(int $id, array $data, array $user): ?array
    {
        $target = strtoupper(trim((string) ($data['target_status'] ?? $data['status'] ?? '')));
        if (!in_array($target, self::STATUSES, true)) {
            throw new InvalidArgumentException(json_encode(['target_status' => 'Choose a supported contract lifecycle status.'], JSON_THROW_ON_ERROR));
        }
        $this->pdo->beginTransaction();
        try {
            $current = $this->row('SELECT * FROM contract WHERE contract_id = :id AND deleted_at IS NULL FOR UPDATE', ['id' => $id]);
            if ($current === null) {
                $this->pdo->rollBack();
                return null;
            }
            $from = (string) $current['contract_status'];
            $this->assertTransition($current, $target, $data, $user);
            $approvalRequestId = null;
            if ($target === 'FOR_APPROVAL') {
                $approvalRequestId = $this->createApprovalWorkflow($current, $user);
            }
            $fields = ['contract_status = :status', 'updated_by_user_id = :user_id', 'updated_at = NOW()'];
            $params = ['status' => $target, 'user_id' => (int) $user['id'], 'id' => $id];
            if ($target === 'ACTIVE') {
                $fields[] = 'executed_date = :executed_date';
                $fields[] = 'effective_date = :effective_date';
                $params['executed_date'] = $this->requiredDate($data['executed_date'] ?? null, 'executed_date');
                $params['effective_date'] = $this->date($data['effective_date'] ?? $current['effective_date'] ?? $current['start_date']);
            }
            if ($target === 'TERMINATED') {
                $fields[] = 'termination_date = :termination_date';
                $fields[] = 'termination_reason = :termination_reason';
                $params['termination_date'] = $this->requiredDate($data['termination_date'] ?? null, 'termination_date');
                $params['termination_reason'] = $this->requiredText($data['termination_reason'] ?? $data['reason'] ?? '', 2000, 'termination_reason');
            }
            $this->pdo->prepare('UPDATE contract SET ' . implode(', ', $fields) . ' WHERE contract_id = :id AND deleted_at IS NULL')->execute($params);
            [$event, $description] = $this->transitionEvent($from, $target, $current, $data);
            $metadata = $this->transitionMetadata($target, $data);
            if ($approvalRequestId !== null) {
                $metadata['approval_request_id'] = $approvalRequestId;
            }
            $this->historyEvent($id, $event, $description, $from, $target, (int) $user['id'], $metadata);
            $this->pdo->commit();
            return $this->show($id, $user);
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function approvalAction(int $id, array $data, array $user): ?array
    {
        $action = strtoupper(trim((string) ($data['action'] ?? '')));
        if (!in_array($action, ['APPROVE','REJECT','RETURN'], true)) {
            throw new InvalidArgumentException(json_encode(['action' => 'Choose approve, reject, or return.'], JSON_THROW_ON_ERROR));
        }
        ContractPolicy::requirePermission($user, 'contract.approve');
        $comment = $this->nullableText($data['comment'] ?? $data['reason'] ?? '', 2000);
        if (in_array($action, ['REJECT','RETURN'], true) && $comment === null) {
            throw new InvalidArgumentException(json_encode(['reason' => 'Reason is required.'], JSON_THROW_ON_ERROR));
        }

        $this->pdo->beginTransaction();
        try {
            $contract = $this->row('SELECT * FROM contract WHERE contract_id = :id AND deleted_at IS NULL FOR UPDATE', ['id' => $id]);
            if ($contract === null) {
                $this->pdo->rollBack();
                return null;
            }
            if ((string) $contract['contract_status'] !== 'FOR_APPROVAL') {
                throw new DomainException('Contract is not awaiting approval.');
            }
            $request = $this->currentApprovalRequest($id, true);
            if ($request === null) {
                throw new DomainException('No active approval workflow exists for this contract.');
            }
            $step = $this->currentApprovalStep((int) $request['approval_request_id'], true);
            if ($step === null || (string) $step['step_status'] !== 'PENDING' || (string) $step['decision'] !== 'PENDING') {
                throw new DomainException('This approval step is no longer actionable.');
            }
            if (!$this->userCanActOnStep($user, $step)) {
                throw new DomainException('You are not authorized for the current approval step.');
            }

            if ($action === 'APPROVE') {
                $this->approveCurrentStep($contract, $request, $step, $comment, $user);
            } elseif ($action === 'REJECT') {
                $this->terminalApprovalAction($contract, $request, $step, 'REJECTED', 'REJECTED', 'APPROVAL_STEP_REJECTED', 'REJECTED', $comment, $user);
            } else {
                $this->terminalApprovalAction($contract, $request, $step, 'RETURNED', 'CANCELLED', 'RETURNED_FOR_CHANGES', 'DRAFT', $comment, $user);
            }

            $this->pdo->commit();
            return $this->show($id, $user);
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function employeeApprovalTask(int $taskId, array $user): array
    {
        if ($taskId < 1) {
            throw new InvalidArgumentException(json_encode(['task_id' => 'A valid approval task is required.'], JSON_THROW_ON_ERROR));
        }

        $context = $this->employeeApprovalViewContext($taskId, $user);

        return $this->employeeApprovalPayload($context, $user);
    }

    public function employeeApprovalAction(int $taskId, array $data, array $user): array
    {
        if ($taskId < 1) {
            throw new InvalidArgumentException(json_encode(['task_id' => 'A valid approval task is required.'], JSON_THROW_ON_ERROR));
        }
        $action = strtoupper(trim((string) ($data['action'] ?? '')));
        if (!in_array($action, ['APPROVE','REJECT','RETURN'], true)) {
            throw new InvalidArgumentException(json_encode(['action' => 'Choose approve, reject, or return.'], JSON_THROW_ON_ERROR));
        }
        $comment = $this->nullableText($data['comment'] ?? $data['reason'] ?? '', 2000);
        if (in_array($action, ['REJECT','RETURN'], true) && $comment === null) {
            throw new InvalidArgumentException(json_encode(['reason' => 'Reason is required.'], JSON_THROW_ON_ERROR));
        }

        $this->pdo->beginTransaction();
        try {
            $context = $this->employeeApprovalContext($taskId, $user, true);
            if (!$this->userHasStepAuthority($user, $context['contract'], $context['step'])) {
                throw new DomainException('You are not authorized for the current approval step.');
            }

            if ($action === 'APPROVE') {
                $this->approveCurrentStep($context['contract'], $context['request'], $context['step'], $comment, $user);
            } elseif ($action === 'REJECT') {
                $this->terminalApprovalAction($context['contract'], $context['request'], $context['step'], 'REJECTED', 'REJECTED', 'APPROVAL_STEP_REJECTED', 'REJECTED', $comment, $user);
            } else {
                $this->terminalApprovalAction($context['contract'], $context['request'], $context['step'], 'RETURNED', 'CANCELLED', 'RETURNED_FOR_CHANGES', 'DRAFT', $comment, $user);
            }

            $result = $this->approvalActionResult((int) $context['contract']['contract_id'], (int) $context['request']['approval_request_id']);
            $this->pdo->commit();
            return $result;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function options(array $user): array
    {
        ContractPolicy::requirePermission($user, 'contract.view');
        return [
            'contract_types' => $this->rows("SELECT contract_type_id id, type_code code, type_name name FROM contract_type WHERE status = 'ACTIVE' AND type_code IN ('SERVICE','SUPPLY','LEASE','MAINTENANCE') ORDER BY FIELD(type_code,'SERVICE','SUPPLY','LEASE','MAINTENANCE')"),
            'statuses' => self::STATUSES,
            'renewal_types' => self::RENEWAL_TYPES,
            'risk_levels' => self::RISK_LEVELS,
            'currencies' => self::CURRENCIES,
            'departments' => $this->rows("SELECT department_reference_id id, department_code code, department_name name FROM department_reference WHERE status = 'ACTIVE' ORDER BY department_name"),
            'contract_owners' => $this->eligibility()->famInternalHandlers(),
            'contract_administrators' => $this->eligibility()->famInternalHandlers(),
            'fam_handlers' => $this->eligibility()->famInternalHandlers(),
            'suppliers' => $this->rows("SELECT supplier_reference_id id, supplier_code code, supplier_name name FROM supplier_reference WHERE supplier_status = 'ACTIVE' ORDER BY supplier_name"),
            'budgets' => $this->rows("SELECT budget_reference_id id, budget_code code, budget_name name, fiscal_year, available_amount, currency_code FROM budget_reference WHERE status = 'ACTIVE' ORDER BY fiscal_year DESC, budget_name"),
            'procurement_requests' => $this->rows("SELECT procurement_request_id id, request_number number, status, approval_status, estimated_total, currency_code FROM procurement_request WHERE deleted_at IS NULL ORDER BY created_at DESC LIMIT 200"),
            'purchase_orders' => $this->rows("SELECT purchase_order_reference_id id, purchase_order_number number, procurement_request_id procurementRequestId, total_amount, currency_code, purchase_order_status status FROM purchase_order_reference ORDER BY order_date DESC, purchase_order_reference_id DESC LIMIT 200"),
        ];
    }

    private function assertTransition(array $current, string $target, array $data, array $user): void
    {
        $from = (string) $current['contract_status'];
        if (!in_array($target, self::TRANSITIONS[$from] ?? [], true)) {
            throw new DomainException("Contract cannot move from $from to $target.");
        }
        if ($from === 'FOR_APPROVAL' && $target === 'APPROVED') {
            throw new DomainException('Contracts awaiting approval must be approved through the approval workflow.');
        }
        ContractPolicy::requirePermission($user, $this->permissionForTransition($target));
        if (in_array($target, ['CANCELLED', 'REJECTED', 'DRAFT'], true) && $from !== 'APPROVED') {
            $this->requiredText($data['reason'] ?? '', 1000, 'reason');
        }
        if ($target === 'FOR_REVIEW') {
            $this->validateReadyForReview($current);
        }
        if ($target === 'FOR_APPROVAL') {
            $this->validateReadyForReview($current);
        }
        if ($target === 'ACTIVE') {
            $this->validateActivation($current, $data);
        }
        if ($target === 'TERMINATED') {
            $date = $this->requiredDate($data['termination_date'] ?? null, 'termination_date');
            $this->requiredText($data['termination_reason'] ?? $data['reason'] ?? '', 2000, 'termination_reason');
            $start = (string) ($current['effective_date'] ?: $current['start_date']);
            if ($date < $start) {
                throw new InvalidArgumentException(json_encode(['termination_date' => 'Termination date cannot be before the effective/start date.'], JSON_THROW_ON_ERROR));
            }
        }
        if ($target === 'EXPIRED') {
            if ((string) $current['end_date'] >= date('Y-m-d')) {
                throw new DomainException('Active contracts can only be expired after the contractual end date has passed.');
            }
        }
    }

    private function permissionForTransition(string $target): string
    {
        return match ($target) {
            'FOR_REVIEW', 'CANCELLED' => 'contract.edit',
            'FOR_APPROVAL', 'DRAFT' => 'contract.review',
            'REJECTED' => 'contract.approve',
            'ACTIVE' => 'contract.activate',
            'TERMINATED' => 'contract.terminate',
            'EXPIRED', 'ARCHIVED' => 'contract.archive',
            default => 'contract.manage',
        };
    }

    private function validateReadyForReview(array $contract): void
    {
        $errors = [];
        foreach (['contract_type_id','owning_department_reference_id','contract_owner_employee_reference_id','start_date','end_date','currency_code'] as $field) {
            if (empty($contract[$field])) {
                $errors[$field] = 'This field is required before review.';
            }
        }
        if ($errors) {
            throw new InvalidArgumentException(json_encode($errors, JSON_THROW_ON_ERROR));
        }
    }

    private function createApprovalWorkflow(array $contract, array $user): int
    {
        if ($this->currentApprovalRequest((int) $contract['contract_id'], true) !== null) {
            throw new DomainException('An active approval workflow already exists for this contract.');
        }
        $route = $this->approvalRoute($contract);
        if ($route === []) {
            throw new DomainException('No valid approval route is configured for this contract.');
        }

        $statement = $this->pdo->prepare("INSERT INTO approval_request (module_code, entity_type, entity_id, request_reference, requested_by_user_id, requested_by_employee_reference_id, approval_status, current_step_number, total_steps, submitted_at, remarks, created_at, updated_at) VALUES ('contract_management', 'contract', :contract_id, :reference, :user_id, :employee_id, 'PENDING', 1, :total_steps, NOW(), :remarks, NOW(), NOW())");
        $statement->execute([
            'contract_id' => (int) $contract['contract_id'],
            'reference' => (string) $contract['contract_number'],
            'user_id' => (int) $user['id'],
            'employee_id' => (int) ($user['employee_id'] ?? 0) ?: null,
            'total_steps' => count($route),
            'remarks' => 'Contract approval workflow created.',
        ]);
        $requestId = (int) $this->pdo->lastInsertId();
        foreach ($route as $index => $step) {
            $stepNumber = $index + 1;
            $this->pdo->prepare("INSERT INTO approval_step (approval_request_id, step_number, step_name, approver_role_id, approver_user_id, approver_employee_reference_id, is_required, step_status, decision, assigned_at, created_at, updated_at) VALUES (:request_id, :step_number, :step_name, :role_id, :user_id, :employee_id, 1, 'PENDING', 'PENDING', :assigned_at, NOW(), NOW())")->execute([
                'request_id' => $requestId,
                'step_number' => $stepNumber,
                'step_name' => $step['name'],
                'role_id' => $step['role_id'],
                'user_id' => $step['user_id'],
                'employee_id' => $step['employee_id'],
                'assigned_at' => $stepNumber === 1 ? date('Y-m-d H:i:s') : null,
            ]);
            $stepId = (int) $this->pdo->lastInsertId();
            if ($stepNumber === 1) {
                $taskId = $this->createWorkflowTask($contract, $requestId, $stepId, $step);
                $this->notifyApprover($contract, $step, 'CONTRACT_APPROVAL_READY', 'Contract approval ready', 'A contract approval step is ready for your action.', $taskId);
            }
        }
        return $requestId;
    }

    private function approvalRoute(array $contract): array
    {
        $route = [$this->departmentHeadStep($contract)];
        if (!empty($contract['procurement_request_id']) || !empty($contract['purchase_order_reference_id'])) {
            $route[] = $this->roleStep('Procurement Approval', 'procurement.approve', 'Procurement approval authority is not configured.');
        }
        if (!empty($contract['budget_reference_id'])) {
            $route[] = $this->roleStep('Finance/Budget Approval', 'budget.approve', 'Finance approval authority is not configured.');
        }
        if (in_array((string) ($contract['risk_level'] ?? ''), ['HIGH','CRITICAL'], true)) {
            $route[] = $this->roleStep('Legal Approval', 'legal.manage', 'Legal approval authority is not configured.');
        }
        $route[] = $this->roleStep('FAM Contract Approval', 'contract.approve', 'FAM contract approval authority is not configured.');
        return $route;
    }

    private function departmentHeadStep(array $contract): array
    {
        $row = $this->row("SELECT d.department_head_employee_reference_id employee_id, e.full_name approver_name, ua.user_account_id user_id FROM department_reference d INNER JOIN employee_reference e ON e.employee_reference_id = d.department_head_employee_reference_id AND e.employment_status = 'ACTIVE' AND e.deleted_at IS NULL INNER JOIN user_account ua ON ua.employee_reference_id = e.employee_reference_id AND ua.account_status = 'ACTIVE' AND ua.deleted_at IS NULL WHERE d.department_reference_id = :department_id AND d.status = 'ACTIVE' LIMIT 1", ['department_id' => (int) $contract['owning_department_reference_id']]);
        if ($row === null) {
            throw new DomainException('Owning department has no active approval authority configured.');
        }
        return [
            'name' => 'Owning Department Head Approval',
            'employee_id' => (int) $row['employee_id'],
            'user_id' => (int) $row['user_id'],
            'role_id' => null,
            'display' => (string) $row['approver_name'],
        ];
    }

    private function roleStep(string $name, string $permission, string $message): array
    {
        $row = $this->row("SELECT ua.user_account_id user_id, e.employee_reference_id employee_id, e.full_name approver_name, r.role_id role_id FROM user_account ua INNER JOIN employee_reference e ON e.employee_reference_id = ua.employee_reference_id AND e.employment_status = 'ACTIVE' AND e.deleted_at IS NULL INNER JOIN user_role ur ON ur.user_account_id = ua.user_account_id AND (ur.expires_at IS NULL OR ur.expires_at > NOW()) INNER JOIN role r ON r.role_id = ur.role_id AND r.status = 'ACTIVE' INNER JOIN role_permission rp ON rp.role_id = r.role_id INNER JOIN permission p ON p.permission_id = rp.permission_id AND p.permission_code = :permission WHERE ua.account_status = 'ACTIVE' AND ua.deleted_at IS NULL ORDER BY r.role_code = 'SYSTEM_ADMIN', e.full_name LIMIT 1", ['permission' => $permission]);
        if ($row === null) {
            throw new DomainException($message);
        }
        return [
            'name' => $name,
            'employee_id' => (int) $row['employee_id'],
            'user_id' => (int) $row['user_id'],
            'role_id' => (int) $row['role_id'],
            'display' => (string) $row['approver_name'],
        ];
    }

    private function approveCurrentStep(array $contract, array $request, array $step, ?string $comment, array $user): void
    {
        $requestId = (int) $request['approval_request_id'];
        $stepNumber = (int) $step['step_number'];
        $this->completeStep((int) $step['approval_step_id'], 'APPROVED', $comment);
        $this->closeWorkflowTask((int) $step['approval_step_id'], (int) $user['id'], $comment);
        $this->historyEvent((int) $contract['contract_id'], 'APPROVAL_STEP_APPROVED', (string) $step['step_name'] . ' approved.', 'FOR_APPROVAL', 'FOR_APPROVAL', (int) $user['id'], ['approval_request_id' => $requestId, 'approval_step_id' => (int) $step['approval_step_id'], 'comment' => $comment]);

        if ($stepNumber >= (int) $request['total_steps']) {
            $this->pdo->prepare("UPDATE approval_request SET approval_status = 'APPROVED', completed_at = NOW(), decided_at = NOW(), updated_at = NOW() WHERE approval_request_id = :id")->execute(['id' => $requestId]);
            $this->pdo->prepare("UPDATE contract SET contract_status = 'APPROVED', updated_by_user_id = :user_id, updated_at = NOW() WHERE contract_id = :contract_id AND contract_status = 'FOR_APPROVAL'")->execute(['user_id' => (int) $user['id'], 'contract_id' => (int) $contract['contract_id']]);
            $this->historyEvent((int) $contract['contract_id'], 'APPROVED', 'Contract ' . (string) $contract['contract_number'] . ' approved by completed workflow.', 'FOR_APPROVAL', 'APPROVED', (int) $user['id'], ['approval_request_id' => $requestId]);
            $this->auditApproval($user, 'CONTRACT_APPROVED', $contract, $requestId, (int) $step['approval_step_id'], 'SUCCESS', $comment);
            return;
        }

        $nextNumber = $stepNumber + 1;
        $next = $this->row('SELECT * FROM approval_step WHERE approval_request_id = :request_id AND step_number = :step_number FOR UPDATE', ['request_id' => $requestId, 'step_number' => $nextNumber]);
        if ($next === null) {
            throw new DomainException('Next approval step is missing.');
        }
        $this->pdo->prepare('UPDATE approval_request SET current_step_number = :step_number, updated_at = NOW() WHERE approval_request_id = :id')->execute(['step_number' => $nextNumber, 'id' => $requestId]);
        $this->pdo->prepare('UPDATE approval_step SET assigned_at = COALESCE(assigned_at, NOW()), updated_at = NOW() WHERE approval_step_id = :id')->execute(['id' => (int) $next['approval_step_id']]);
        $nextRoute = ['name' => (string) $next['step_name'], 'employee_id' => $next['approver_employee_reference_id'], 'user_id' => $next['approver_user_id'], 'role_id' => $next['approver_role_id']];
        $taskId = $this->createWorkflowTask($contract, $requestId, (int) $next['approval_step_id'], $nextRoute);
        $this->notifyApprover($contract, $nextRoute, 'CONTRACT_APPROVAL_READY', 'Contract approval ready', 'A contract approval step is ready for your action.', $taskId);
        $this->auditApproval($user, 'CONTRACT_APPROVAL_STEP_APPROVED', $contract, $requestId, (int) $step['approval_step_id'], 'SUCCESS', $comment);
    }

    private function terminalApprovalAction(array $contract, array $request, array $step, string $requestStatus, string $stepDecision, string $event, string $targetStatus, ?string $comment, array $user): void
    {
        $requestId = (int) $request['approval_request_id'];
        $this->completeStep((int) $step['approval_step_id'], $stepDecision, $comment);
        $this->closeWorkflowTask((int) $step['approval_step_id'], (int) $user['id'], $comment);
        $this->pdo->prepare('UPDATE approval_step SET step_status = :status, decision = :decision, updated_at = NOW() WHERE approval_request_id = :request_id AND step_status = \'PENDING\' AND approval_step_id <> :step_id')->execute(['status' => $requestStatus, 'decision' => $stepDecision, 'request_id' => $requestId, 'step_id' => (int) $step['approval_step_id']]);
        $this->pdo->prepare('UPDATE approval_request SET approval_status = :status, decided_at = NOW(), completed_at = NOW(), cancelled_at = IF(:cancel_status = \'RETURNED\', NOW(), cancelled_at), remarks = :remarks, updated_at = NOW() WHERE approval_request_id = :id')->execute(['status' => $requestStatus, 'cancel_status' => $requestStatus, 'remarks' => $comment, 'id' => $requestId]);
        $this->pdo->prepare("UPDATE workflow_task SET task_status = 'CANCELLED', completed_at = NOW(), completed_by_user_id = :user_id, completion_notes = :notes, updated_at = NOW() WHERE approval_request_id = :request_id AND task_status IN ('PENDING','IN_PROGRESS')")->execute(['user_id' => (int) $user['id'], 'notes' => $comment, 'request_id' => $requestId]);
        $this->pdo->prepare('UPDATE contract SET contract_status = :status, updated_by_user_id = :user_id, updated_at = NOW() WHERE contract_id = :contract_id AND contract_status = \'FOR_APPROVAL\'')->execute(['status' => $targetStatus, 'user_id' => (int) $user['id'], 'contract_id' => (int) $contract['contract_id']]);
        $this->historyEvent((int) $contract['contract_id'], $event, (string) $step['step_name'] . ' ' . strtolower(str_replace('_', ' ', $requestStatus)) . '.', 'FOR_APPROVAL', $targetStatus, (int) $user['id'], ['approval_request_id' => $requestId, 'approval_step_id' => (int) $step['approval_step_id'], 'reason' => $comment]);
        $this->auditApproval($user, 'CONTRACT_APPROVAL_' . $requestStatus, $contract, $requestId, (int) $step['approval_step_id'], 'SUCCESS', $comment);
    }

    private function completeStep(int $stepId, string $decision, ?string $comment): void
    {
        $this->pdo->prepare('UPDATE approval_step SET step_status = :status, decision = :decision, decision_comments = :comments, decided_at = NOW(), updated_at = NOW() WHERE approval_step_id = :id AND step_status = \'PENDING\' AND decision = \'PENDING\'')->execute([
            'status' => $decision,
            'decision' => $decision,
            'comments' => $comment,
            'id' => $stepId,
        ]);
    }

    private function currentApprovalRequest(int $contractId, bool $forUpdate = false): ?array
    {
        return $this->row("SELECT * FROM approval_request WHERE module_code = 'contract_management' AND entity_type = 'contract' AND entity_id = :id AND approval_status = 'PENDING' ORDER BY approval_request_id DESC LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : ''), ['id' => $contractId]);
    }

    private function currentApprovalStep(int $requestId, bool $forUpdate = false): ?array
    {
        return $this->row("SELECT s.* FROM approval_request r INNER JOIN approval_step s ON s.approval_request_id = r.approval_request_id AND s.step_number = r.current_step_number WHERE r.approval_request_id = :id LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : ''), ['id' => $requestId]);
    }

    private function userCanActOnStep(array $user, array $step): bool
    {
        if (!ContractPolicy::hasPermission($user, 'contract.approve')) {
            return false;
        }
        if (!empty($step['approver_user_id']) && (int) $step['approver_user_id'] === (int) $user['id']) {
            return true;
        }
        return !empty($step['approver_employee_reference_id']) && (int) $step['approver_employee_reference_id'] === (int) ($user['employee_id'] ?? 0);
    }

    private function approvalActionResult(int $contractId, int $requestId): array
    {
        $contract = $this->row('SELECT contract_id, contract_number, contract_status FROM contract WHERE contract_id = :id LIMIT 1', ['id' => $contractId]);
        $request = $this->row('SELECT approval_request_id, approval_status, current_step_number, total_steps FROM approval_request WHERE approval_request_id = :id LIMIT 1', ['id' => $requestId]);

        return [
            'taskCompleted' => true,
            'contract_id' => $contractId,
            'contract_number' => (string) ($contract['contract_number'] ?? ''),
            'contract_status' => (string) ($contract['contract_status'] ?? ''),
            'approval_status' => (string) ($request['approval_status'] ?? ''),
            'current_step_number' => isset($request['current_step_number']) ? (int) $request['current_step_number'] : null,
            'total_steps' => isset($request['total_steps']) ? (int) $request['total_steps'] : null,
        ];
    }

    private function employeeApprovalContext(int $taskId, array $user, bool $forUpdate): array
    {
        $lock = $forUpdate ? ' FOR UPDATE' : '';
        $task = $this->row("SELECT * FROM workflow_task WHERE workflow_task_id = :id AND module_code = 'contract_management' AND entity_type = 'contract' AND task_type = 'CONTRACT_APPROVAL' AND task_status IN ('PENDING','IN_PROGRESS')$lock", ['id' => $taskId]);
        if ($task === null || !$this->taskBelongsToUser($task, $user)) {
            throw new DomainException('Approval task not found.');
        }

        $request = $this->row("SELECT * FROM approval_request WHERE approval_request_id = :id AND module_code = 'contract_management' AND entity_type = 'contract' AND approval_status = 'PENDING'$lock", ['id' => (int) $task['approval_request_id']]);
        if ($request === null) {
            throw new DomainException('Approval task is no longer active.');
        }

        $step = $this->row("SELECT * FROM approval_step WHERE approval_step_id = :id AND approval_request_id = :request_id$lock", [
            'id' => (int) $task['approval_step_id'],
            'request_id' => (int) $request['approval_request_id'],
        ]);
        if ($step === null || (int) $step['step_number'] !== (int) $request['current_step_number'] || (string) $step['step_status'] !== 'PENDING' || (string) $step['decision'] !== 'PENDING') {
            throw new DomainException('This approval step is no longer actionable.');
        }
        if (!$this->taskBelongsToStep($task, $step)) {
            throw new DomainException('Approval task is not assigned to the current approval step.');
        }

        $contract = $this->row("SELECT * FROM contract WHERE contract_id = :id AND deleted_at IS NULL$lock", ['id' => (int) $request['entity_id']]);
        if ($contract === null || (int) $contract['contract_id'] !== (int) $task['entity_id']) {
            throw new DomainException('Contract approval task is invalid.');
        }
        if ((string) $contract['contract_status'] !== 'FOR_APPROVAL') {
            throw new DomainException('Contract is not awaiting approval.');
        }

        return ['task' => $task, 'request' => $request, 'step' => $step, 'contract' => $contract];
    }

    private function employeeApprovalViewContext(int $taskId, array $user): array
    {
        $task = $this->row("SELECT * FROM workflow_task WHERE workflow_task_id = :id AND module_code = 'contract_management' AND entity_type = 'contract' AND task_type = 'CONTRACT_APPROVAL'", ['id' => $taskId]);
        if ($task === null || !$this->taskBelongsToUser($task, $user)) {
            throw new DomainException('Approval task not found.');
        }

        $request = $this->row("SELECT * FROM approval_request WHERE approval_request_id = :id AND module_code = 'contract_management' AND entity_type = 'contract'", ['id' => (int) $task['approval_request_id']]);
        if ($request === null) {
            throw new DomainException('Approval task is no longer available.');
        }

        $step = $this->row('SELECT * FROM approval_step WHERE approval_step_id = :id AND approval_request_id = :request_id', [
            'id' => (int) $task['approval_step_id'],
            'request_id' => (int) $request['approval_request_id'],
        ]);
        if ($step === null || !$this->taskBelongsToStep($task, $step)) {
            throw new DomainException('Approval task assignment is invalid.');
        }

        $contract = $this->row('SELECT * FROM contract WHERE contract_id = :id AND deleted_at IS NULL', ['id' => (int) $request['entity_id']]);
        if ($contract === null || (int) $contract['contract_id'] !== (int) $task['entity_id']) {
            throw new DomainException('Contract approval task is invalid.');
        }

        return ['task' => $task, 'request' => $request, 'step' => $step, 'contract' => $contract];
    }

    private function taskBelongsToUser(array $task, array $user): bool
    {
        return (!empty($task['assigned_to_user_id']) && (int) $task['assigned_to_user_id'] === (int) $user['id'])
            || (!empty($task['assigned_to_employee_reference_id']) && (int) $task['assigned_to_employee_reference_id'] === (int) ($user['employee_id'] ?? 0));
    }

    private function taskBelongsToStep(array $task, array $step): bool
    {
        return (!empty($step['approver_user_id']) && (int) $step['approver_user_id'] === (int) $task['assigned_to_user_id'])
            || (!empty($step['approver_employee_reference_id']) && (int) $step['approver_employee_reference_id'] === (int) $task['assigned_to_employee_reference_id']);
    }

    private function userHasStepAuthority(array $user, array $contract, array $step): bool
    {
        if (!empty($step['approver_user_id']) && (int) $step['approver_user_id'] !== (int) $user['id']) {
            return false;
        }
        if (!empty($step['approver_employee_reference_id']) && (int) $step['approver_employee_reference_id'] !== (int) ($user['employee_id'] ?? 0)) {
            return false;
        }

        $stepName = (string) $step['step_name'];
        if ($stepName === 'Owning Department Head Approval') {
            $headId = (int) $this->scalar('SELECT COALESCE(department_head_employee_reference_id, 0) FROM department_reference WHERE department_reference_id = :id AND status = \'ACTIVE\'', ['id' => (int) $contract['owning_department_reference_id']]);
            return $headId > 0 && $headId === (int) ($user['employee_id'] ?? 0);
        }

        $permission = match ($stepName) {
            'Procurement Approval' => 'procurement.approve',
            'Finance/Budget Approval' => 'budget.approve',
            'Legal Approval' => 'legal.manage',
            'FAM Contract Approval' => 'contract.approve',
            default => '',
        };

        if ($permission === 'contract.approve') {
            return ContractPolicy::hasPermission($user, $permission);
        }

        return $permission !== '' && $this->hasUserPermission($user, $permission);
    }

    private function hasUserPermission(array $user, string $permission): bool
    {
        if (in_array($permission, $user['permissions'] ?? [], true)) {
            return true;
        }
        [$module] = explode('.', $permission, 2);
        return in_array($module . '.manage', $user['permissions'] ?? [], true);
    }

    private function employeeApprovalPayload(array $context, array $user): array
    {
        $contractRow = $this->row($this->baseSelect($this->selectColumns()) . ' WHERE c.contract_id = :id AND c.deleted_at IS NULL GROUP BY c.contract_id LIMIT 1', ['id' => (int) $context['contract']['contract_id']]);
        if ($contractRow === null) {
            throw new DomainException('Contract approval task is invalid.');
        }

        $steps = $this->approvalSteps((int) $context['request']['approval_request_id']);
        $contract = $this->shape($contractRow, $user, false);

        $isActionable = in_array((string) $context['task']['task_status'], ['PENDING','IN_PROGRESS'], true)
            && (string) $context['request']['approval_status'] === 'PENDING'
            && (int) $context['request']['current_step_number'] === (int) $context['step']['step_number']
            && (string) $context['step']['step_status'] === 'PENDING'
            && (string) $context['step']['decision'] === 'PENDING'
            && (string) $context['contract']['contract_status'] === 'FOR_APPROVAL'
            && $this->userHasStepAuthority($user, $context['contract'], $context['step']);

        return [
            'task' => [
                'id' => (int) $context['task']['workflow_task_id'],
                'number' => (string) $context['task']['task_number'],
                'title' => (string) $context['task']['task_title'],
                'status' => (string) $context['task']['task_status'],
                'assigned_at' => $context['step']['assigned_at'] ?? $context['task']['created_at'],
                'completed_at' => $context['task']['completed_at'],
                'is_actionable' => $isActionable,
            ],
            'contract' => [
                'id' => $contract['id'],
                'number' => $contract['contractNo'],
                'title' => $contract['title'],
                'description' => $contract['description'],
                'type' => $contract['type'],
                'supplier' => $contract['supplier'],
                'owning_department' => $contract['owningDepartment'],
                'contract_owner' => $contract['owner'],
                'contract_administrator' => $contract['famHandler'],
                'financial' => $contract['financial'],
                'dates' => $contract['dates'],
                'risk_level' => $contract['riskLevel'],
                'status' => $contract['status'],
            ],
            'approval' => [
                'request_id' => (int) $context['request']['approval_request_id'],
                'status' => (string) $context['request']['approval_status'],
                'current_step_number' => (int) $context['request']['current_step_number'],
                'total_steps' => (int) $context['request']['total_steps'],
                'current_step' => [
                    'id' => (int) $context['step']['approval_step_id'],
                    'name' => (string) $context['step']['step_name'],
                    'status' => (string) $context['step']['step_status'],
                    'decision' => (string) $context['step']['decision'],
                    'assigned_at' => $context['step']['assigned_at'],
                    'decided_at' => $context['step']['decided_at'],
                    'approver' => (string) ($user['full_name'] ?? $user['username'] ?? 'Assigned approver'),
                ],
                'steps' => $steps,
                'available_actions' => $isActionable ? ['approve', 'reject', 'return'] : [],
            ],
        ];
    }

    private function approvalSummary(int $contractId, array $user): array
    {
        $request = $this->row("SELECT * FROM approval_request WHERE module_code = 'contract_management' AND entity_type = 'contract' AND entity_id = :id ORDER BY approval_request_id DESC LIMIT 1", ['id' => $contractId]);
        if ($request === null) {
            return [
                'required' => false,
                'status' => 'NOT_SUBMITTED',
                'requestId' => null,
                'currentStep' => null,
                'totalSteps' => 0,
                'completedSteps' => 0,
                'canApproveCurrentStep' => false,
                'canRejectCurrentStep' => false,
                'canReturnCurrentStep' => false,
                'steps' => [],
                'previousRequests' => [],
            ];
        }
        $steps = $this->approvalSteps((int) $request['approval_request_id']);
        $current = null;
        foreach ($steps as $step) {
            if ((int) $step['sequence'] === (int) $request['current_step_number']) {
                $current = $step;
                break;
            }
        }
        $canAct = $current !== null && (string) $request['approval_status'] === 'PENDING' && $this->userCanActOnStep($user, [
            'approver_user_id' => $current['approverUserId'],
            'approver_employee_reference_id' => $current['approverEmployeeId'],
        ]);
        return [
            'required' => true,
            'requestId' => (int) $request['approval_request_id'],
            'status' => (string) $request['approval_status'],
            'currentStep' => $current,
            'totalSteps' => (int) $request['total_steps'],
            'completedSteps' => count(array_filter($steps, static fn (array $step): bool => in_array((string) $step['status'], ['APPROVED','REJECTED','RETURNED','CANCELLED'], true))),
            'canApproveCurrentStep' => $canAct,
            'canRejectCurrentStep' => $canAct,
            'canReturnCurrentStep' => $canAct,
            'steps' => $steps,
            'submittedAt' => (string) $request['submitted_at'],
            'completedAt' => $request['completed_at'],
            'previousRequests' => $this->previousApprovalRequests($contractId, (int) $request['approval_request_id']),
        ];
    }

    private function approvalSteps(int $requestId): array
    {
        return array_map(fn (array $row): array => [
            'id' => (int) $row['approval_step_id'],
            'sequence' => (int) $row['step_number'],
            'name' => (string) $row['step_name'],
            'approverDisplay' => (string) ($row['approver_name'] ?? $row['role_name'] ?? 'Configured approver'),
            'approverEmployeeId' => $row['approver_employee_reference_id'] === null ? null : (int) $row['approver_employee_reference_id'],
            'approverUserId' => $row['approver_user_id'] === null ? null : (int) $row['approver_user_id'],
            'status' => (string) $row['step_status'],
            'decision' => (string) ($row['decision'] ?? 'PENDING'),
            'actedBy' => null,
            'actedAt' => $row['decided_at'],
            'comment' => $row['decision_comments'],
            'assignedAt' => $row['assigned_at'],
        ], $this->rows("SELECT s.*, e.full_name approver_name, r.role_name FROM approval_step s LEFT JOIN employee_reference e ON e.employee_reference_id = s.approver_employee_reference_id LEFT JOIN role r ON r.role_id = s.approver_role_id WHERE s.approval_request_id = :id ORDER BY s.step_number", ['id' => $requestId]));
    }

    private function previousApprovalRequests(int $contractId, int $currentRequestId): array
    {
        return array_map(static fn (array $row): array => [
            'requestId' => (int) $row['approval_request_id'],
            'status' => (string) $row['approval_status'],
            'submittedAt' => (string) $row['submitted_at'],
            'completedAt' => $row['completed_at'],
            'totalSteps' => (int) $row['total_steps'],
        ], $this->rows("SELECT approval_request_id, approval_status, submitted_at, completed_at, total_steps FROM approval_request WHERE module_code = 'contract_management' AND entity_type = 'contract' AND entity_id = :id AND approval_request_id <> :current_id ORDER BY approval_request_id DESC LIMIT 10", ['id' => $contractId, 'current_id' => $currentRequestId]));
    }

    private function createWorkflowTask(array $contract, int $requestId, int $stepId, array $step): int
    {
        $existing = $this->row("SELECT workflow_task_id FROM workflow_task WHERE approval_step_id = :step_id AND task_status IN ('PENDING','IN_PROGRESS') ORDER BY workflow_task_id DESC LIMIT 1", ['step_id' => $stepId]);
        if ($existing !== null) {
            return (int) $existing['workflow_task_id'];
        }
        $this->pdo->prepare("INSERT INTO workflow_task (task_number, module_code, entity_type, entity_id, entity_reference, approval_request_id, approval_step_id, task_type, task_title, task_description, assigned_to_user_id, assigned_to_employee_reference_id, assigned_to_role_id, priority, task_status, created_at, updated_at) VALUES (:task_number, 'contract_management', 'contract', :contract_id, :reference, :request_id, :step_id, 'CONTRACT_APPROVAL', :title, :description, :user_id, :employee_id, :role_id, 'HIGH', 'PENDING', NOW(), NOW())")->execute([
            'task_number' => $this->nextTaskNumber(),
            'contract_id' => (int) $contract['contract_id'],
            'reference' => (string) $contract['contract_number'],
            'request_id' => $requestId,
            'step_id' => $stepId,
            'title' => (string) $step['name'],
            'description' => 'Approve contract ' . (string) $contract['contract_number'] . '.',
            'user_id' => $step['user_id'],
            'employee_id' => $step['employee_id'],
            'role_id' => $step['role_id'],
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private function closeWorkflowTask(int $stepId, int $userId, ?string $notes): void
    {
        $this->pdo->prepare("UPDATE workflow_task SET task_status = 'COMPLETED', completed_at = NOW(), completed_by_user_id = :user_id, completion_notes = :notes, updated_at = NOW() WHERE approval_step_id = :step_id AND task_status IN ('PENDING','IN_PROGRESS')")->execute(['user_id' => $userId, 'notes' => $notes, 'step_id' => $stepId]);
    }

    private function notifyApprover(array $contract, array $step, string $event, string $title, string $message, ?int $taskId = null): void
    {
        if (empty($step['user_id'])) {
            return;
        }
        try {
            $actionUrl = $this->approverHasContractApprovalPermission((int) $step['user_id'])
                ? 'pages/contract-management.html'
                : 'pages/employee/tasks.html' . ($taskId !== null ? '?task=' . $taskId : '');
            $this->pdo->prepare("INSERT INTO notification (recipient_user_id, event_code, module_code, notification_type, title, message, priority, related_entity_type, related_entity_id, related_reference, action_url, metadata_json, created_at) VALUES (:user_id, :event, 'contract_management', 'IN_APP', :title, :message, 'HIGH', 'contract', :contract_id, :reference, :url, :metadata, NOW())")->execute([
                'user_id' => (int) $step['user_id'],
                'event' => $event,
                'title' => $title,
                'message' => $message . ' ' . (string) $contract['contract_number'],
                'contract_id' => (int) $contract['contract_id'],
                'reference' => (string) $contract['contract_number'],
                'url' => $actionUrl,
                'metadata' => json_encode(['step' => $step['name'], 'workflow_task_id' => $taskId], JSON_THROW_ON_ERROR),
            ]);
        } catch (Throwable) {
            // Notifications are helpful, but approval evidence remains authoritative.
        }
    }

    private function approverHasContractApprovalPermission(int $userId): bool
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM user_account ua INNER JOIN user_role ur ON ur.user_account_id = ua.user_account_id AND (ur.expires_at IS NULL OR ur.expires_at > NOW()) INNER JOIN role r ON r.role_id = ur.role_id AND r.status = 'ACTIVE' INNER JOIN role_permission rp ON rp.role_id = r.role_id INNER JOIN permission p ON p.permission_id = rp.permission_id WHERE ua.user_account_id = :user_id AND ua.account_status = 'ACTIVE' AND ua.deleted_at IS NULL AND p.permission_code IN ('contract.approve','contract.manage')", ['user_id' => $userId]) > 0;
    }

    private function auditApproval(array $user, string $action, array $contract, int $requestId, int $stepId, string $status, ?string $comment): void
    {
        try {
            $this->pdo->prepare("INSERT INTO audit_log (audit_uuid, actor_user_id, actor_username, action_code, module_code, entity_type, entity_id, entity_reference, result_status, result_message, metadata_json, created_at) VALUES (UUID(), :user_id, :username, :action, 'contract_management', 'contract', :contract_id, :reference, :status, :message, :metadata, NOW())")->execute([
                'user_id' => (int) $user['id'],
                'username' => (string) ($user['username'] ?? ''),
                'action' => $action,
                'contract_id' => (int) $contract['contract_id'],
                'reference' => (string) $contract['contract_number'],
                'status' => $status,
                'message' => $comment,
                'metadata' => json_encode(['approval_request_id' => $requestId, 'approval_step_id' => $stepId], JSON_THROW_ON_ERROR),
            ]);
        } catch (Throwable) {
            // Audit logging should not split approval state if the generic audit table is unavailable.
        }
    }

    private function nextTaskNumber(): string
    {
        return 'TASK-' . date('Ymd-His') . '-' . random_int(1000, 9999);
    }

    private function validateActivation(array $contract, array $data): void
    {
        $executed = $this->requiredDate($data['executed_date'] ?? null, 'executed_date');
        $effective = $this->date($data['effective_date'] ?? $contract['effective_date'] ?? $contract['start_date']);
        if ($effective === null) {
            throw new InvalidArgumentException(json_encode(['effective_date' => 'Effective date is required to activate.'], JSON_THROW_ON_ERROR));
        }
        if ($effective > date('Y-m-d')) {
            throw new InvalidArgumentException(json_encode(['effective_date' => 'Future-effective contracts must remain approved until activation is valid.'], JSON_THROW_ON_ERROR));
        }
        if ($executed > date('Y-m-d')) {
            throw new InvalidArgumentException(json_encode(['executed_date' => 'Executed date cannot be in the future.'], JSON_THROW_ON_ERROR));
        }
        if ((string) $contract['end_date'] < $effective) {
            throw new InvalidArgumentException(json_encode(['end_date' => 'End date cannot be before effective date.'], JSON_THROW_ON_ERROR));
        }
        $this->validateReadyForReview($contract);
    }

    private function validateContract(array $data, bool $creating): array
    {
        $errors = [];
        if (!$creating && isset($data['contract_status']) && !in_array((string) $data['contract_status'], self::STATUSES, true)) {
            $errors['contract_status'] = 'Unsupported contract lifecycle status.';
        }
        $typeId = $this->id($data['contract_type_id'] ?? null);
        if ($typeId === null || !$this->exists('contract_type', 'contract_type_id', $typeId, "status='ACTIVE' AND type_code IN ('SERVICE','SUPPLY','LEASE','MAINTENANCE')")) {
            $errors['contract_type_id'] = 'Choose an active canonical contract type.';
        }
        $title = $this->text($data['contract_title'] ?? $data['title'] ?? '', 255);
        if ($title === '') {
            $errors['contract_title'] = 'Enter a contract title.';
        }
        $departmentId = $this->id($data['owning_department_reference_id'] ?? null);
        if ($departmentId === null || !$this->exists('department_reference', 'department_reference_id', $departmentId, "status='ACTIVE'")) {
            $errors['owning_department_reference_id'] = 'Choose an active owning department.';
        }
        $ownerId = $this->id($data['contract_owner_employee_reference_id'] ?? $data['contract_administrator_employee_reference_id'] ?? null);
        if ($ownerId === null) {
            $errors['contract_owner_employee_reference_id'] = 'Choose an active Contract Administrator.';
        } else {
            try {
                $this->eligibility()->assertFamInternalHandler($ownerId, 'contract_owner_employee_reference_id');
            } catch (InvalidArgumentException $exception) {
                $decoded = json_decode($exception->getMessage(), true);
                $errors += is_array($decoded) ? $decoded : ['contract_owner_employee_reference_id' => 'Choose an active FAM Contract Administrator.'];
            }
        }
        $handlerId = $this->id($data['fam_handler_employee_reference_id'] ?? null) ?? $ownerId;
        if ($handlerId !== null) {
            try {
                $this->eligibility()->assertFamInternalHandler($handlerId, 'fam_handler_employee_reference_id');
            } catch (InvalidArgumentException $exception) {
                $decoded = json_decode($exception->getMessage(), true);
                $errors += is_array($decoded) ? $decoded : ['fam_handler_employee_reference_id' => 'Choose an eligible FAM handler.'];
            }
        }
        $startDate = $this->requiredDate($data['start_date'] ?? null, 'start_date', $errors);
        $effectiveDate = $this->date($data['effective_date'] ?? null);
        $endDate = $this->requiredDate($data['end_date'] ?? null, 'end_date', $errors);
        if ($startDate !== null && $endDate !== null && $endDate < ($effectiveDate ?? $startDate)) {
            $errors['end_date'] = 'End date cannot be before effective/start date.';
        }
        $originalAmount = $this->amount($data['original_amount'] ?? 0, 'original_amount', $errors);
        $currentAmount = $this->amount($data['current_amount'] ?? $originalAmount, 'current_amount', $errors);
        $currency = strtoupper($this->text($data['currency_code'] ?? 'PHP', 3));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            $errors['currency_code'] = 'Currency code must use three uppercase letters.';
        }
        $supplierId = $this->id($data['supplier_reference_id'] ?? null);
        if ($supplierId !== null && !$this->exists('supplier_reference', 'supplier_reference_id', $supplierId, "supplier_status='ACTIVE'")) {
            $errors['supplier_reference_id'] = 'Choose an active supplier.';
        }
        $budgetId = $this->id($data['budget_reference_id'] ?? null);
        if ($budgetId !== null && !$this->exists('budget_reference', 'budget_reference_id', $budgetId, "status='ACTIVE'")) {
            $errors['budget_reference_id'] = 'Choose an active budget reference.';
        }
        $procurementId = $this->id($data['procurement_request_id'] ?? null);
        if ($procurementId !== null && !$this->exists('procurement_request', 'procurement_request_id', $procurementId, 'deleted_at IS NULL')) {
            $errors['procurement_request_id'] = 'Choose a valid procurement request.';
        }
        $poId = $this->id($data['purchase_order_reference_id'] ?? null);
        if ($poId !== null && !$this->exists('purchase_order_reference', 'purchase_order_reference_id', $poId)) {
            $errors['purchase_order_reference_id'] = 'Choose a valid purchase order.';
        }
        $noticeDays = $this->nullableInt($data['notice_period_days'] ?? null);
        if ($noticeDays !== null && $noticeDays < 0) {
            $errors['notice_period_days'] = 'Notice period must be zero or greater.';
        }
        $renewalType = strtoupper($this->text($data['renewal_type'] ?? 'NONE', 20));
        if (!in_array($renewalType, self::RENEWAL_TYPES, true)) {
            $errors['renewal_type'] = 'Choose a supported renewal type.';
        }
        $renewalDecisionDate = $this->date($data['renewal_decision_date'] ?? null);
        if ($renewalDecisionDate !== null && $endDate !== null && $renewalDecisionDate > $endDate) {
            $errors['renewal_decision_date'] = 'Renewal decision date cannot be after end date.';
        }
        $risk = $this->nullableUpper($data['risk_level'] ?? null, 20);
        if ($risk !== null && !in_array($risk, self::RISK_LEVELS, true)) {
            $errors['risk_level'] = 'Choose a supported risk level.';
        }
        $renewedFromId = $this->id($data['renewed_from_contract_id'] ?? null);
        if ($renewedFromId !== null && !$this->exists('contract', 'contract_id', $renewedFromId, 'deleted_at IS NULL')) {
            $errors['renewed_from_contract_id'] = 'Choose a valid prior contract.';
        }
        if ($errors) {
            throw new InvalidArgumentException(json_encode($errors, JSON_THROW_ON_ERROR));
        }
        return [
            'type_id' => $typeId,
            'title' => $title,
            'description' => $this->nullableText($data['contract_description'] ?? $data['description'] ?? '', 4000),
            'supplier_id' => $supplierId,
            'budget_id' => $budgetId,
            'procurement_request_id' => $procurementId,
            'purchase_order_id' => $poId,
            'owner_id' => $ownerId,
            'department_id' => $departmentId,
            'handler_id' => $handlerId,
            'start_date' => $startDate,
            'effective_date' => $effectiveDate,
            'end_date' => $endDate,
            'original_amount' => $originalAmount,
            'current_amount' => $currentAmount,
            'currency' => $currency,
            'notice_days' => $noticeDays,
            'renewal_type' => $renewalType,
            'renewal_decision_date' => $renewalDecisionDate,
            'renewed_from_id' => $renewedFromId,
            'risk_level' => $risk,
        ];
    }

    private function nextContractNumber(): string
    {
        $year = date('Y');
        $prefix = "CTR-$year-";
        $statement = $this->pdo->prepare('SELECT contract_number FROM contract WHERE contract_number LIKE :prefix ORDER BY contract_number DESC LIMIT 1 FOR UPDATE');
        $statement->execute(['prefix' => $prefix . '%']);
        $last = (string) ($statement->fetchColumn() ?: '');
        $next = preg_match('/^CTR-\d{4}-(\d{4})$/', $last, $matches) ? ((int) $matches[1]) + 1 : 1;
        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    private function derived(array $row): array
    {
        $status = (string) $row['contract_status'];
        $today = new DateTimeImmutable('today');
        $end = new DateTimeImmutable((string) $row['end_date']);
        $days = (int) $today->diff($end)->format('%r%a');
        $notice = $row['notice_period_days'] === null ? 0 : (int) $row['notice_period_days'];
        $isActiveFuture = $status === 'ACTIVE' && !empty($row['effective_date']) && (string) $row['effective_date'] > $today->format('Y-m-d');
        $renewalDue = $status === 'ACTIVE' && !empty($row['renewal_decision_date']) && (string) $row['renewal_decision_date'] <= $today->format('Y-m-d');
        $overdue = (int) ($row['overdue_obligation_count'] ?? 0) > 0;
        $expiryState = match (true) {
            $status === 'TERMINATED' => 'TERMINATED',
            $status === 'EXPIRED' || ($status === 'ACTIVE' && $days < 0) => 'EXPIRED',
            $status === 'ACTIVE' && $days >= 0 && $days <= $notice => 'EXPIRING_SOON',
            $status === 'ACTIVE' => 'NORMAL',
            default => 'NOT_APPLICABLE',
        };
        return [
            'daysToExpiry' => $status === 'ACTIVE' ? $days : null,
            'isExpiringSoon' => $expiryState === 'EXPIRING_SOON',
            'expiryState' => $expiryState,
            'isPendingEffective' => $isActiveFuture,
            'isRenewalDue' => $renewalDue,
            'hasOverdueObligations' => $overdue,
        ];
    }

    private function summary(): array
    {
        $row = $this->row("SELECT COUNT(*) total, SUM(contract_status = 'ACTIVE') active, SUM(contract_status IN ('FOR_REVIEW','FOR_APPROVAL')) pending_review_approval, SUM(contract_status = 'ACTIVE' AND end_date >= CURRENT_DATE() AND DATEDIFF(end_date, CURRENT_DATE()) <= COALESCE(notice_period_days, 0)) expiring_soon FROM contract WHERE deleted_at IS NULL");
        return [
            'total' => (int) ($row['total'] ?? 0),
            'active' => (int) ($row['active'] ?? 0),
            'expiringSoon' => (int) ($row['expiring_soon'] ?? 0),
            'pendingReviewApproval' => (int) ($row['pending_review_approval'] ?? 0),
        ];
    }

    private function allowedActions(array $row, array $user): array
    {
        $status = (string) $row['contract_status'];
        $map = [
            'DRAFT' => ['edit' => 'contract.edit', 'submit_review' => 'contract.edit', 'cancel' => 'contract.edit'],
            'FOR_REVIEW' => ['return_draft' => 'contract.review', 'submit_approval' => 'contract.review'],
            'FOR_APPROVAL' => [],
            'APPROVED' => ['activate' => 'contract.activate', 'return_draft' => 'contract.review'],
            'ACTIVE' => ['terminate' => 'contract.terminate'],
            'EXPIRED' => ['archive' => 'contract.archive'],
            'TERMINATED' => ['archive' => 'contract.archive'],
            'REJECTED' => ['return_draft' => 'contract.review'],
        ];
        $actions = [];
        foreach ($map[$status] ?? [] as $action => $permission) {
            if (ContractPolicy::hasPermission($user, $permission)) {
                $actions[] = $action;
            }
        }
        return $actions;
    }

    private function shape(array $row, array $user, bool $details): array
    {
        $item = [
            'id' => (int) $row['contract_id'],
            'contractNo' => (string) $row['contract_number'],
            'title' => (string) $row['contract_title'],
            'description' => (string) ($row['contract_description'] ?? ''),
            'type' => ['id' => (int) $row['contract_type_id'], 'code' => (string) $row['type_code'], 'name' => (string) $row['type_name']],
            'supplier' => $row['supplier_reference_id'] === null ? null : ['id' => (int) $row['supplier_reference_id'], 'code' => (string) $row['supplier_code'], 'name' => (string) $row['supplier_name']],
            'budget' => $row['budget_reference_id'] === null ? null : ['id' => (int) $row['budget_reference_id'], 'code' => (string) $row['budget_code'], 'name' => (string) $row['budget_name']],
            'procurementRequest' => $row['procurement_request_id'] === null ? null : ['id' => (int) $row['procurement_request_id'], 'number' => (string) $row['request_number']],
            'purchaseOrder' => $row['purchase_order_reference_id'] === null ? null : ['id' => (int) $row['purchase_order_reference_id'], 'number' => (string) ($row['purchase_order_number'] ?? '')],
            'owningDepartment' => $row['owning_department_reference_id'] === null ? null : ['id' => (int) $row['owning_department_reference_id'], 'code' => (string) $row['department_code'], 'name' => (string) $row['department_name']],
            'owner' => ['id' => (int) $row['contract_owner_employee_reference_id'], 'employeeNo' => (string) $row['owner_employee_number'], 'name' => (string) $row['owner_name']],
            'famHandler' => $row['fam_handler_employee_reference_id'] === null ? null : ['id' => (int) $row['fam_handler_employee_reference_id'], 'employeeNo' => (string) $row['handler_employee_number'], 'name' => (string) $row['handler_name']],
            'dates' => ['startDate' => (string) $row['start_date'], 'executedDate' => $row['executed_date'], 'effectiveDate' => $row['effective_date'], 'endDate' => (string) $row['end_date'], 'terminationDate' => $row['termination_date']],
            'financial' => ['originalAmount' => (float) $row['original_amount'], 'currentAmount' => (float) $row['current_amount'], 'currencyCode' => (string) $row['currency_code']],
            'status' => (string) $row['contract_status'],
            'noticePeriodDays' => $row['notice_period_days'] === null ? null : (int) $row['notice_period_days'],
            'renewalType' => (string) $row['renewal_type'],
            'renewalDecisionDate' => $row['renewal_decision_date'],
            'riskLevel' => $row['risk_level'],
            'derived' => $this->derived($row),
            'allowedActions' => $this->allowedActions($row, $user),
            'createdAt' => (string) $row['created_at'],
            'updatedAt' => (string) $row['updated_at'],
        ];
        if ($details) {
            $item['terminationReason'] = $row['termination_reason'];
            $item['summary'] = ['overdueObligationCount' => (int) ($row['overdue_obligation_count'] ?? 0)];
            $item['approval'] = $this->approvalSummary((int) $row['contract_id'], $user);
        }
        return $item;
    }

    private function filters(array $query): array
    {
        $where = ['c.deleted_at IS NULL'];
        $params = [];
        if (($query['search'] ?? '') !== '') {
            $where[] = '(c.contract_number LIKE :search_number OR c.contract_title LIKE :search_title OR s.supplier_name LIKE :search_supplier)';
            $needle = '%' . trim((string) $query['search']) . '%';
            $params['search_number'] = $needle;
            $params['search_title'] = $needle;
            $params['search_supplier'] = $needle;
        }
        foreach (['contract_status' => 'c.contract_status', 'contract_type_id' => 'c.contract_type_id', 'owning_department_reference_id' => 'c.owning_department_reference_id', 'fam_handler_employee_reference_id' => 'c.fam_handler_employee_reference_id', 'supplier_reference_id' => 'c.supplier_reference_id', 'risk_level' => 'c.risk_level'] as $key => $column) {
            if (($query[$key] ?? '') !== '') {
                $where[] = "$column = :$key";
                $params[$key] = ctype_digit((string) $query[$key]) ? (int) $query[$key] : strtoupper((string) $query[$key]);
            }
        }
        if (($query['expiry_state'] ?? '') === 'expiring_soon') {
            $where[] = "c.contract_status = 'ACTIVE' AND c.end_date >= CURRENT_DATE() AND DATEDIFF(c.end_date, CURRENT_DATE()) <= COALESCE(c.notice_period_days, 0)";
        }
        return [' WHERE ' . implode(' AND ', $where), $params];
    }

    private function baseSelect(string $columns): string
    {
        return "SELECT $columns FROM contract c INNER JOIN contract_type ct ON ct.contract_type_id = c.contract_type_id LEFT JOIN supplier_reference s ON s.supplier_reference_id = c.supplier_reference_id LEFT JOIN budget_reference b ON b.budget_reference_id = c.budget_reference_id LEFT JOIN procurement_request pr ON pr.procurement_request_id = c.procurement_request_id LEFT JOIN purchase_order_reference po ON po.purchase_order_reference_id = c.purchase_order_reference_id LEFT JOIN department_reference d ON d.department_reference_id = c.owning_department_reference_id INNER JOIN employee_reference owner ON owner.employee_reference_id = c.contract_owner_employee_reference_id LEFT JOIN employee_reference handler ON handler.employee_reference_id = c.fam_handler_employee_reference_id LEFT JOIN (SELECT contract_id, COUNT(*) overdue_obligation_count FROM contract_obligation WHERE deleted_at IS NULL AND status = 'PENDING' AND due_date < CURRENT_DATE() GROUP BY contract_id) overdue ON overdue.contract_id = c.contract_id";
    }

    private function selectColumns(): string
    {
        return 'c.*, ct.type_code, ct.type_name, s.supplier_code, s.supplier_name, b.budget_code, b.budget_name, pr.request_number, po.purchase_order_number, d.department_code, d.department_name, owner.employee_number owner_employee_number, owner.full_name owner_name, handler.employee_number handler_employee_number, handler.full_name handler_name, COALESCE(overdue.overdue_obligation_count, 0) overdue_obligation_count';
    }

    private function history(int $contractId): array
    {
        return array_map(fn (array $row): array => [
            'type' => (string) $row['event_type'],
            'description' => (string) $row['event_description'],
            'fromStatus' => (string) ($row['from_status'] ?? ''),
            'toStatus' => (string) ($row['to_status'] ?? ''),
            'actor' => (string) ($row['actor_name'] ?? 'System'),
            'eventAt' => (string) $row['event_at'],
        ], $this->rows("SELECT h.*, actor.full_name actor_name FROM contract_history h LEFT JOIN user_account u ON u.user_account_id = h.actor_user_id LEFT JOIN employee_reference actor ON actor.employee_reference_id = u.employee_reference_id WHERE h.contract_id = :id ORDER BY h.event_at DESC, h.contract_history_id DESC LIMIT 50", ['id' => $contractId]));
    }

    private function documentsForContract(int $contractId): array
    {
        return array_map(fn (array $row): array => [
            'id' => (int) $row['document_id'],
            'documentNo' => (string) $row['document_number'],
            'title' => (string) $row['document_title'],
            'category' => (string) $row['category_name'],
            'status' => (string) $row['document_status'],
            'version' => 'v' . (int) $row['current_version_number'],
            'uploadedAt' => (string) ($row['uploaded_at'] ?? $row['created_at']),
            'expirationDate' => $row['expiration_date'],
            'isPrimary' => (bool) $row['is_primary_document'],
            'fileName' => (string) ($row['file_name'] ?? ''),
        ], $this->rows("SELECT d.*, dc.category_name, rd.is_primary_document, dv.file_name, dv.uploaded_at FROM record r INNER JOIN record_document rd ON rd.record_id = r.record_id INNER JOIN document d ON d.document_id = rd.document_id AND d.deleted_at IS NULL INNER JOIN document_category dc ON dc.document_category_id = d.document_category_id LEFT JOIN document_version dv ON dv.document_id = d.document_id AND dv.is_current = TRUE AND dv.deleted_at IS NULL WHERE r.deleted_at IS NULL AND r.source_module = 'contract_management' AND (r.source_entity_id = :id OR r.source_entity_type = (SELECT contract_number FROM contract WHERE contract_id = :id_ref)) ORDER BY rd.is_primary_document DESC, d.updated_at DESC", ['id' => $contractId, 'id_ref' => $contractId]));
    }

    private function historyEvent(int $id, string $event, string $description, ?string $from, ?string $to, ?int $userId, array $metadata = []): void
    {
        $this->pdo->prepare('INSERT INTO contract_history (contract_id, event_type, event_description, from_status, to_status, actor_user_id, event_at, metadata_json, created_at) VALUES (:id, :event, :description, :from_status, :to_status, :user_id, NOW(), :metadata, NOW())')->execute([
            'id' => $id,
            'event' => $event,
            'description' => $description,
            'from_status' => $from,
            'to_status' => $to,
            'user_id' => $userId,
            'metadata' => $metadata ? json_encode($metadata, JSON_THROW_ON_ERROR) : null,
        ]);
    }

    private function transitionEvent(string $from, string $to, array $contract, array $data): array
    {
        $number = (string) $contract['contract_number'];
        return match ($to) {
            'FOR_REVIEW' => ['SUBMITTED_FOR_REVIEW', "Contract $number submitted for review."],
            'FOR_APPROVAL' => ['SUBMITTED_FOR_APPROVAL', "Contract $number submitted for lifecycle approval."],
            'APPROVED' => ['APPROVED', "Contract $number approved through Phase 2 lifecycle approval."],
            'REJECTED' => ['REJECTED', "Contract $number rejected."],
            'ACTIVE' => ['ACTIVATED', "Contract $number activated."],
            'TERMINATED' => ['TERMINATED', "Contract $number terminated."],
            'EXPIRED' => ['EXPIRED', "Contract $number marked expired."],
            'ARCHIVED' => ['ARCHIVED', "Contract $number archived."],
            'CANCELLED' => ['CANCELLED', "Contract $number cancelled."],
            'DRAFT' => ['RETURNED_FOR_CHANGES', "Contract $number returned to draft."],
            default => ['STATUS_CHANGED', "Contract $number moved from $from to $to."],
        };
    }

    private function transitionMetadata(string $target, array $data): array
    {
        $keys = ['reason', 'executed_date', 'effective_date', 'termination_date'];
        $metadata = [];
        foreach ($keys as $key) {
            if (isset($data[$key]) && $data[$key] !== '') {
                $metadata[$key] = $data[$key];
            }
        }
        return $metadata;
    }

    private function eligibility(): FamEmployeeEligibilityService
    {
        return $this->employeeEligibility ?? new FamEmployeeEligibilityService($this->pdo);
    }

    private function exists(string $table, string $column, int $id, string $extra = '1=1'): bool
    {
        $statement = $this->pdo->prepare("SELECT 1 FROM $table WHERE $column = :id AND $extra LIMIT 1");
        $statement->execute(['id' => $id]);
        return (bool) $statement->fetchColumn();
    }

    private function id(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 'null') return null;
        if (!ctype_digit((string) $value)) return null;
        $id = (int) $value;
        return $id > 0 ? $id : null;
    }

    private function requiredDate(mixed $value, string $field, ?array &$errors = null): ?string
    {
        if ($value === null || $value === '') {
            if (is_array($errors)) {
                $errors[$field] = 'Enter a valid date.';
                return null;
            }
            throw new InvalidArgumentException(json_encode([$field => 'Enter a valid date.'], JSON_THROW_ON_ERROR));
        }
        return $this->date($value);
    }

    private function date(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        try {
            return (new DateTimeImmutable((string) $value))->format('Y-m-d');
        } catch (Throwable) {
            throw new InvalidArgumentException(json_encode(['date' => 'Enter a valid date.'], JSON_THROW_ON_ERROR));
        }
    }

    private function amount(mixed $value, string $field, array &$errors): string
    {
        if (!is_numeric($value) || (float) $value < 0) {
            $errors[$field] = 'Amount must be zero or greater.';
            return '0.00';
        }
        return number_format((float) $value, 2, '.', '');
    }

    private function requiredText(mixed $value, int $max, string $field): string
    {
        $text = $this->text($value, $max);
        if ($text === '') {
            throw new InvalidArgumentException(json_encode([$field => 'This field is required.'], JSON_THROW_ON_ERROR));
        }
        return $text;
    }

    private function nullableText(mixed $value, int $max): ?string
    {
        $text = $this->text($value, $max);
        return $text === '' ? null : $text;
    }

    private function text(mixed $value, int $max): string
    {
        return mb_substr(trim((string) $value), 0, $max);
    }

    private function nullableUpper(mixed $value, int $max): ?string
    {
        $text = strtoupper($this->text($value ?? '', $max));
        return $text === '' ? null : $text;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') return null;
        return is_numeric($value) ? (int) $value : null;
    }

    private function rows(string $sql, array $params = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll();
    }

    private function row(string $sql, array $params = []): ?array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    private function scalar(string $sql, array $params = []): mixed
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement->fetchColumn();
    }
}
