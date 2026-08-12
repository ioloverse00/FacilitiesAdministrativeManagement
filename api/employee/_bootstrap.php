<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bootstrap.php';

function currentEmployeeUser(): array
{
    $auth = new AuthService(Database::connection());

    if (!$auth->enforceSessionLifetime()) {
        jsonResponse(false, 'Authentication required.', [], 401);
    }

    try {
        $user = $auth->currentUser();
    } catch (RuntimeException) {
        clearAuthSession();
        jsonResponse(false, 'Authentication required.', [], 401);
    }

    if (!$user instanceof AuthenticatedUser) {
        jsonResponse(false, 'Authentication required.', [], 401);
    }

    $auth->touchSession();

    $data = $user->toArray();
    if (empty($data['employee_id'])) {
        jsonResponse(false, 'No employee record is linked to this account.', [
            'access_denied_reason' => 'missing_employee_linkage',
        ], 403);
    }

    return $data;
}

function employeePermissions(array $user): array
{
    $existing = array_values(array_filter($user['permissions'] ?? [], static fn (string $permission): bool => str_starts_with($permission, 'employee_portal.')
        || in_array($permission, [
            'facility_requests.create',
            'facility_requests.view_own',
            'reservations.create',
            'reservations.view_own',
            'notifications.view_own',
            'profile.view_own',
        ], true)));

    return array_values(array_unique($existing));
}

