<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_bootstrap.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'FacilityRequests' . DIRECTORY_SEPARATOR . 'FacilityRequestService.php';

function employeeFacilityRequestService(): FacilityRequestService
{
    return new FacilityRequestService(Database::connection());
}

function employeeFacilityRequestIdentifier(): int|string
{
    $value = $_GET['id'] ?? $_GET['request_number'] ?? null;
    if ($value === null || $value === '') {
        jsonResponse(false, 'Facility request id or request_number is required.', [], 422);
    }

    return ctype_digit((string) $value) ? (int) $value : (string) $value;
}

function employeeOwnsFacilityRequest(array $item, array $user): bool
{
    return (int) ($item['requested_by']['employee_id'] ?? 0) === (int) ($user['employee_id'] ?? 0);
}

function employeeFacilityRequestValidation(Throwable $e): void
{
    if ($e instanceof InvalidArgumentException) {
        $errors = json_decode($e->getMessage(), true);
        jsonResponse(false, 'Validation failed.', [
            'errors' => is_array($errors) ? $errors : ['request' => $e->getMessage()],
        ], 422);
    }

    throw $e;
}

function employeeFacilityRequestList(array $query, array $user): array
{
    $query['requested_by'] = (int) $user['employee_id'];
    $query['per_page'] = min(50, max(1, (int) ($query['per_page'] ?? 10)));

    return employeeFacilityRequestService()->list($query);
}

function employeeVisibleFacilityRequest(array $item): array
{
    unset($item['assigned_to'], $item['workflow_tasks'], $item['approval_summary'], $item['ai_recommendations']);

    if (isset($item['history']) && is_array($item['history'])) {
        $item['history'] = array_map(static fn (array $row): array => [
            'old_status' => $row['old_status'] ?? null,
            'new_status' => $row['new_status'] ?? null,
            'change_reason' => $row['change_reason'] ?? null,
            'changed_at' => $row['changed_at'] ?? null,
        ], $item['history']);
    }

    $status = (string) ($item['status'] ?? '');
    $item['allowed_actions'] = [
        'cancel' => in_array($status, ['SUBMITTED', 'PENDING_APPROVAL'], true),
    ];

    return $item;
}

