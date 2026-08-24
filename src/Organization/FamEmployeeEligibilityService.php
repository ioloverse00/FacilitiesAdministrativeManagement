<?php

declare(strict_types=1);

final class FamEmployeeEligibilityService
{
    private const FAM_HANDLER_PERMISSIONS = ['legal.assign', 'legal.manage'];
    private const EXCLUDED_HANDLER_ROLES = ['SYSTEM_ADMIN'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function famInternalHandlers(): array
    {
        return $this->rows($this->employeeSelect() . "
            INNER JOIN user_account ua ON ua.employee_reference_id = e.employee_reference_id
                AND ua.account_status = 'ACTIVE'
                AND ua.deleted_at IS NULL
            INNER JOIN user_role ur ON ur.user_account_id = ua.user_account_id
                AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
            INNER JOIN role r ON r.role_id = ur.role_id
                AND r.status = 'ACTIVE'
                AND r.role_code NOT IN ('" . implode("','", self::EXCLUDED_HANDLER_ROLES) . "')
            INNER JOIN role_permission rp ON rp.role_id = r.role_id
            INNER JOIN permission p ON p.permission_id = rp.permission_id
                AND p.permission_code IN ('" . implode("','", self::FAM_HANDLER_PERMISSIONS) . "')
            WHERE " . $this->activeEmployeeWhere() . "
            GROUP BY e.employee_reference_id
            ORDER BY d.department_name, e.full_name
        ");
    }

    public function crossDepartmentContacts(): array
    {
        $handlers = $this->famInternalHandlers();
        $handlerIds = array_map(static fn (array $row): int => (int) $row['id'], $handlers);
        $heads = $this->departmentHeads($handlerIds);

        return [
            'handlers' => $handlers,
            'departmentHeads' => $heads,
            'all' => array_values(array_merge($handlers, $heads)),
        ];
    }

    public function isFamInternalHandler(?int $employeeId): bool
    {
        if ($employeeId === null) {
            return true;
        }

        return (int) $this->scalar("SELECT COUNT(DISTINCT e.employee_reference_id)
            FROM employee_reference e
            INNER JOIN user_account ua ON ua.employee_reference_id = e.employee_reference_id
                AND ua.account_status = 'ACTIVE'
                AND ua.deleted_at IS NULL
            INNER JOIN user_role ur ON ur.user_account_id = ua.user_account_id
                AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
            INNER JOIN role r ON r.role_id = ur.role_id
                AND r.status = 'ACTIVE'
                AND r.role_code NOT IN ('" . implode("','", self::EXCLUDED_HANDLER_ROLES) . "')
            INNER JOIN role_permission rp ON rp.role_id = r.role_id
            INNER JOIN permission p ON p.permission_id = rp.permission_id
                AND p.permission_code IN ('" . implode("','", self::FAM_HANDLER_PERMISSIONS) . "')
            WHERE " . $this->activeEmployeeWhere() . "
                AND e.employee_reference_id = :id", ['id' => $employeeId]) > 0;
    }

    public function isCrossDepartmentContact(?int $employeeId): bool
    {
        if ($employeeId === null) {
            return true;
        }
        if ($this->isFamInternalHandler($employeeId)) {
            return true;
        }

        return (int) $this->scalar("SELECT COUNT(*)
            FROM department_reference d
            INNER JOIN employee_reference e ON e.employee_reference_id = d.department_head_employee_reference_id
            WHERE d.status = 'ACTIVE'
                AND d.department_head_employee_reference_id = :id
                AND " . $this->activeEmployeeWhere(), ['id' => $employeeId]) > 0;
    }

    public function assertFamInternalHandler(?int $employeeId, string $field = 'assigned_employee_reference_id'): void
    {
        if (!$this->isFamInternalHandler($employeeId)) {
            throw new InvalidArgumentException(json_encode([$field => 'Choose an active authorized FAM handler.'], JSON_THROW_ON_ERROR));
        }
    }

    public function assertCrossDepartmentContact(?int $employeeId, string $field = 'assigned_employee_reference_id'): void
    {
        if (!$this->isCrossDepartmentContact($employeeId)) {
            throw new InvalidArgumentException(json_encode([$field => 'Choose an active FAM handler or authorized department head.'], JSON_THROW_ON_ERROR));
        }
    }

    private function departmentHeads(array $excludeIds): array
    {
        $params = [];
        $excludeSql = '';
        if ($excludeIds !== []) {
            $placeholders = [];
            foreach ($excludeIds as $index => $id) {
                $key = 'exclude_' . $index;
                $placeholders[] = ':' . $key;
                $params[$key] = $id;
            }
            $excludeSql = ' AND e.employee_reference_id NOT IN (' . implode(',', $placeholders) . ')';
        }

        return $this->rows($this->employeeSelect() . "
            INNER JOIN department_reference owner_department ON owner_department.department_head_employee_reference_id = e.employee_reference_id
                AND owner_department.status = 'ACTIVE'
            WHERE " . $this->activeEmployeeWhere() . $excludeSql . "
            GROUP BY e.employee_reference_id
            ORDER BY d.department_name, e.full_name
        ", $params);
    }

    private function employeeSelect(): string
    {
        return "SELECT
                e.employee_reference_id id,
                e.employee_number employeeNo,
                e.full_name name,
                e.position_title position,
                d.department_reference_id departmentId,
                d.department_name department
            FROM employee_reference e
            LEFT JOIN department_reference d ON d.department_reference_id = e.department_reference_id";
    }

    private function activeEmployeeWhere(): string
    {
        return "e.employment_status = 'ACTIVE' AND e.deleted_at IS NULL";
    }

    private function rows(string $sql, array $params = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'employeeNo' => (string) ($row['employeeNo'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'position' => (string) ($row['position'] ?? ''),
            'departmentId' => $row['departmentId'] === null ? null : (int) $row['departmentId'],
            'department' => (string) ($row['department'] ?? ''),
        ], $statement->fetchAll());
    }

    private function scalar(string $sql, array $params = []): mixed
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement->fetchColumn();
    }
}
