<?php

declare(strict_types=1);

final class PersonaService
{
    public const SUPER_ADMIN = 'SUPER_ADMIN';
    public const FAM_ADMIN = 'FAM_ADMIN';
    public const FAM_OPERATIONAL = 'FAM_OPERATIONAL';
    public const DEPARTMENT_HEAD_EMPLOYEE = 'DEPARTMENT_HEAD_EMPLOYEE';
    public const UNAUTHORIZED_OR_UNRESOLVED = 'UNAUTHORIZED_OR_UNRESOLVED';

    private const FAM_DEPARTMENT_CODE = 'DEP-FAC';
    private const FAM_OPERATIONAL_ROLES = ['FACILITY_MANAGER', 'ASSET_CUSTODIAN', 'RESERVATION_OFFICER', 'RECORDS_OFFICER', 'TECHNICIAN', 'MAINTENANCE_SUPERVISOR'];
    private const EXTERNAL_HEAD_DEPARTMENT_CODES = ['DEP-FIN', 'DEP-HR', 'DEP-SCM'];

    public static function classify(PDO $pdo, array $profile, array $roles, array $permissions): array
    {
        $roleCodes = array_map(static fn (array $role): string => strtoupper((string) $role['code']), $roles);
        $departmentCode = strtoupper((string) ($profile['department']['code'] ?? ''));
        $employeeId = (int) ($profile['employee_id'] ?? 0);

        if (in_array('SYSTEM_ADMIN', $roleCodes, true)) {
            return self::result(self::SUPER_ADMIN, 'fam', 'SYSTEM_ADMIN compatibility role.');
        }
        if (in_array('FAM_ADMIN', $roleCodes, true)) {
            return self::result(self::FAM_ADMIN, 'fam', 'FAM_ADMIN role.');
        }
        if ($departmentCode === self::FAM_DEPARTMENT_CODE || count(array_intersect($roleCodes, self::FAM_OPERATIONAL_ROLES)) > 0) {
            return self::result(self::FAM_OPERATIONAL, 'fam', 'FAM department or operational FAM role.');
        }
        if (self::isMappedDepartmentHead($pdo, $employeeId)
            && in_array($departmentCode, self::EXTERNAL_HEAD_DEPARTMENT_CODES, true)
            && (in_array('DEPARTMENT_HEAD_EMPLOYEE', $roleCodes, true) || count(array_intersect($permissions, ['facility_requests.create', 'reservations.create', 'budget.approve', 'procurement.approve'])) > 0)) {
            return self::result(self::DEPARTMENT_HEAD_EMPLOYEE, 'employee', 'Authorized external FAM-facing department head.');
        }

        return ['code' => self::UNAUTHORIZED_OR_UNRESOLVED, 'portal' => 'none', 'is_employee_portal_allowed' => false, 'is_fam_portal_allowed' => false, 'reason' => 'No authorized FAM persona classification.'];
    }

    private static function isMappedDepartmentHead(PDO $pdo, int $employeeId): bool
    {
        if ($employeeId < 1) {
            return false;
        }
        $statement = $pdo->prepare("SELECT COUNT(*) FROM department_reference WHERE department_head_employee_reference_id = :employee_id AND status = 'ACTIVE'");
        $statement->execute(['employee_id' => $employeeId]);
        return (int) $statement->fetchColumn() > 0;
    }

    private static function result(string $code, string $portal, string $reason): array
    {
        return ['code' => $code, 'portal' => $portal, 'is_employee_portal_allowed' => $portal === 'employee', 'is_fam_portal_allowed' => $portal === 'fam', 'reason' => $reason];
    }
}
