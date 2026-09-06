<?php

declare(strict_types=1);

final class PersonaService
{
    public const FAM_SUPER_ADMIN = 'FAM_SUPER_ADMIN';
    public const FAM_ADMIN = 'FAM_ADMIN';
    public const FAM_STAFF = 'FAM_STAFF';
    public const DEPARTMENT_HEAD = 'DEPARTMENT_HEAD';
    public const EMPLOYEE = 'EMPLOYEE';
    public const UNAUTHORIZED_OR_UNRESOLVED = 'UNAUTHORIZED_OR_UNRESOLVED';

    private const FAM_DEPARTMENT_CODE = 'DEP-FAC';

    public static function classify(PDO $pdo, array $profile, array $roles, array $permissions): array
    {
        $roleCodes = array_map(static fn (array $role): string => strtoupper((string) $role['code']), $roles);
        $departmentCode = strtoupper((string) ($profile['department']['code'] ?? ''));
        $employeeId = (int) ($profile['employee_id'] ?? 0);

        if (in_array(self::FAM_SUPER_ADMIN, $roleCodes, true)) {
            return self::result(self::FAM_SUPER_ADMIN, 'fam', 'FAM super administrator role.');
        }
        if (in_array(self::FAM_ADMIN, $roleCodes, true)) {
            return self::result(self::FAM_ADMIN, 'fam', 'FAM department head role.');
        }
        if (in_array(self::FAM_STAFF, $roleCodes, true)) {
            return self::result(self::FAM_STAFF, 'fam', 'FAM staff role.');
        }
        if (in_array(self::DEPARTMENT_HEAD, $roleCodes, true)
            && $departmentCode !== self::FAM_DEPARTMENT_CODE
            && self::isMappedDepartmentHead($pdo, $employeeId)) {
            return self::result(self::DEPARTMENT_HEAD, 'employee', 'Authorized non-FAM department head.');
        }
        if (in_array(self::EMPLOYEE, $roleCodes, true)) {
            return self::result(self::EMPLOYEE, 'employee', 'Employee self-service role.');
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
