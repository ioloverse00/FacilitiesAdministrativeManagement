<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_bootstrap.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Organization' . DIRECTORY_SEPARATOR . 'FamEmployeeEligibilityService.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Contracts' . DIRECTORY_SEPARATOR . 'ContractService.php';

function employeeApprovalTaskId(): int
{
    $value = $_GET['task_id'] ?? $_GET['id'] ?? null;
    if ($value === null || !ctype_digit((string) $value)) {
        jsonResponse(false, 'A valid task_id is required.', [], 422);
    }

    return (int) $value;
}

function employeeContractService(): ContractService
{
    $connection = Database::connection();
    return new ContractService($connection, new FamEmployeeEligibilityService($connection));
}

function employeeApprovalList(array $user, bool $includeHistory = false): array
{
    $pdo = Database::connection();
    $historyFilter = $includeHistory ? '' : <<<SQL
  AND wt.task_status IN ('PENDING','IN_PROGRESS')
  AND ar.approval_status = 'PENDING'
  AND ar.current_step_number = s.step_number
  AND s.step_status = 'PENDING'
  AND s.decision = 'PENDING'
  AND c.contract_status = 'FOR_APPROVAL'
SQL;
    $orderBy = $includeHistory
        ? "CASE WHEN wt.task_status IN ('PENDING','IN_PROGRESS') AND ar.approval_status = 'PENDING' AND ar.current_step_number = s.step_number AND s.step_status = 'PENDING' AND s.decision = 'PENDING' AND c.contract_status = 'FOR_APPROVAL' THEN 0 ELSE 1 END ASC, COALESCE(wt.completed_at, s.decided_at, s.assigned_at, wt.updated_at, wt.created_at) DESC, wt.workflow_task_id DESC"
        : 'COALESCE(s.assigned_at, wt.created_at) ASC, wt.workflow_task_id ASC';
    $statement = $pdo->prepare(<<<SQL
SELECT
    wt.workflow_task_id,
    wt.approval_request_id,
    wt.approval_step_id,
    wt.module_code,
    wt.entity_type,
    wt.entity_id,
    wt.entity_reference,
    wt.task_title,
    wt.task_status,
    wt.created_at task_created_at,
    wt.updated_at task_updated_at,
    wt.completed_at task_completed_at,
    ar.submitted_at,
    ar.approval_status,
    ar.current_step_number,
    s.step_number,
    s.step_name,
    s.step_status,
    s.decision,
    s.assigned_at,
    s.decided_at,
    c.contract_number,
    c.contract_title,
    c.current_amount,
    c.currency_code,
    c.contract_status,
    c.counterparty_name,
    supplier.supplier_name,
    dept.department_name,
    requester.full_name requested_by_name
FROM workflow_task wt
INNER JOIN approval_request ar ON ar.approval_request_id = wt.approval_request_id
INNER JOIN approval_step s ON s.approval_step_id = wt.approval_step_id
INNER JOIN contract c ON c.contract_id = wt.entity_id
LEFT JOIN supplier_reference supplier ON supplier.supplier_reference_id = c.supplier_reference_id
LEFT JOIN department_reference dept ON dept.department_reference_id = c.owning_department_reference_id
LEFT JOIN employee_reference requester ON requester.employee_reference_id = ar.requested_by_employee_reference_id
WHERE wt.module_code = 'contract_management'
  AND wt.entity_type = 'contract'
  AND wt.task_type = 'CONTRACT_APPROVAL'
{$historyFilter}
  AND c.deleted_at IS NULL
  AND (
      wt.assigned_to_user_id = :task_user_id
      OR wt.assigned_to_employee_reference_id = :task_employee_id
      OR s.approver_user_id = :step_user_id
      OR s.approver_employee_reference_id = :step_employee_id
  )
ORDER BY {$orderBy}
LIMIT 50
SQL);
    $statement->execute([
        'task_user_id' => (int) $user['id'],
        'task_employee_id' => (int) $user['employee_id'],
        'step_user_id' => (int) $user['id'],
        'step_employee_id' => (int) $user['employee_id'],
    ]);

    $rows = $statement->fetchAll();
    if (!$includeHistory) {
        $service = employeeContractService();
        $rows = array_values(array_filter($rows, static function (array $row) use ($service, $user): bool {
            try {
                $service->employeeApprovalTask((int) $row['workflow_task_id'], $user);
                return true;
            } catch (Throwable) {
                return false;
            }
        }));
    }

    $canViewFinancials = in_array('budget.approve', $user['permissions'] ?? [], true)
        || in_array('contract.manage', $user['permissions'] ?? [], true);

    return array_map(static function (array $row) use ($canViewFinancials): array {
        $actionable = in_array((string) $row['task_status'], ['PENDING','IN_PROGRESS'], true)
            && (string) $row['approval_status'] === 'PENDING'
            && (int) $row['current_step_number'] === (int) $row['step_number']
            && (string) $row['step_status'] === 'PENDING'
            && (string) $row['decision'] === 'PENDING'
            && (string) $row['contract_status'] === 'FOR_APPROVAL';

        $contract = [
            'id' => (int) $row['entity_id'],
            'number' => (string) $row['contract_number'],
            'title' => (string) $row['contract_title'],
            'counterparty' => ['name' => trim((string) ($row['counterparty_name'] ?? '')) !== '' ? (string) $row['counterparty_name'] : (string) ($row['supplier_name'] ?? '')],
            'supplier' => (string) ($row['supplier_name'] ?? ''),
            'owning_department' => (string) ($row['department_name'] ?? ''),
            'status' => (string) $row['contract_status'],
        ];
        if ($canViewFinancials) {
            $contract['amount'] = $row['current_amount'] === null ? null : (float) $row['current_amount'];
            $contract['currency'] = (string) ($row['currency_code'] ?? '');
        }

        return [
            'task_id' => (int) $row['workflow_task_id'],
            'approval_request_id' => (int) $row['approval_request_id'],
            'approval_step_id' => (int) $row['approval_step_id'],
            'module' => (string) $row['module_code'],
            'entity_type' => (string) $row['entity_type'],
            'entity_id' => (int) $row['entity_id'],
            'entity_reference' => (string) $row['entity_reference'],
            'title' => (string) $row['task_title'],
            'step_name' => (string) $row['step_name'],
            'step_number' => (int) $row['step_number'],
            'status' => (string) $row['task_status'],
            'step_status' => (string) $row['step_status'],
            'decision' => (string) $row['decision'],
            'approval_status' => (string) $row['approval_status'],
            'is_actionable' => $actionable,
            'assigned_at' => $row['assigned_at'] ?? $row['task_created_at'],
            'completed_at' => $row['task_completed_at'] ?? $row['decided_at'],
            'submitted_at' => $row['submitted_at'],
            'requested_by' => (string) ($row['requested_by_name'] ?? ''),
            'contract' => $contract,
        ];
    }, $rows);
}
