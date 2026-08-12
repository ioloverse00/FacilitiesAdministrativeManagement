<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_bootstrap.php';

requireMethod('GET');

$user = currentEmployeeUser();
$pdo = Database::connection();

$statement = $pdo->prepare(<<<'SQL'
SELECT
    e.employee_reference_id,
    e.employee_number,
    e.full_name,
    e.position_title,
    e.email_address,
    e.contact_number,
    e.employment_status,
    d.department_reference_id,
    d.department_code,
    d.department_name
FROM employee_reference e
LEFT JOIN department_reference d ON d.department_reference_id = e.department_reference_id
WHERE e.employee_reference_id = :employee_reference_id
  AND e.deleted_at IS NULL
LIMIT 1
SQL);
$statement->execute(['employee_reference_id' => (int) $user['employee_id']]);
$employee = $statement->fetch();

if (!is_array($employee)) {
    jsonResponse(false, 'No employee record is linked to this account.', [
        'access_denied_reason' => 'missing_employee_linkage',
    ], 403);
}

jsonResponse(true, 'Employee context loaded.', [
    'context' => [
        'user_id' => (int) $user['id'],
        'username' => (string) $user['username'],
        'employee_reference_id' => (int) $employee['employee_reference_id'],
        'employee_number' => (string) $employee['employee_number'],
        'full_name' => (string) $employee['full_name'],
        'department' => [
            'id' => $employee['department_reference_id'] === null ? null : (int) $employee['department_reference_id'],
            'code' => (string) ($employee['department_code'] ?? ''),
            'name' => (string) ($employee['department_name'] ?? ''),
        ],
        'position' => (string) ($employee['position_title'] ?? ''),
        'email' => (string) ($employee['email_address'] ?? ''),
        'contact_number' => (string) ($employee['contact_number'] ?? ''),
        'employment_status' => (string) ($employee['employment_status'] ?? ''),
        'permissions' => employeePermissions($user),
        'roles' => $user['roles'] ?? [],
    ],
    'csrf_token' => ensureCsrfToken(),
]);
