<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_bootstrap.php';

requireMethod('GET');

$user = currentEmployeeUser();
$items = employeeApprovalList($user, true);
$pending = count(array_filter($items, static fn (array $item): bool => (bool) ($item['is_actionable'] ?? false)));

jsonResponse(true, 'Employee approval tasks loaded.', [
    'items' => $items,
    'summary' => [
        'pending' => $pending,
    ],
]);
