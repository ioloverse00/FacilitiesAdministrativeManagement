<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_bootstrap.php';

requireMethod('POST');

$user = currentEmployeeUser();
requireCsrfToken();
$body = readJsonBody();

try {
    $item = employeeContractService()->employeeApprovalAction((int) ($body['task_id'] ?? 0), $body, $user);
    jsonResponse(true, 'Employee approval action completed.', ['item' => $item]);
} catch (InvalidArgumentException $e) {
    $errors = json_decode($e->getMessage(), true);
    jsonResponse(false, 'Validation failed.', [
        'errors' => is_array($errors) ? $errors : ['approval' => $e->getMessage()],
    ], 422);
} catch (DomainException $e) {
    jsonResponse(false, $e->getMessage(), [], 409);
}
