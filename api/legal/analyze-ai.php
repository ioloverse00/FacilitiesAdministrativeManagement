<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';

requireMethod('POST');
$user = currentApiUser();
LegalPolicy::requirePermission($user, 'legal.manage');
DocumentPolicy::requirePermission($user, 'records.view');
requireCsrfToken();

try {
    $matterId = idParam();
    $result = legalMatterAiAnalysisService()->analyze($matterId, $user, (bool)($_GET['regenerate'] ?? false));
    if ($result === null || ($result['item'] ?? null) === null) {
        jsonResponse(false, 'Legal matter not found.', [], 404);
    }
    $statuses = $result['section_statuses'] ?? [];
    $failed = array_filter($statuses, static fn (string $status): bool => in_array($status, ['FAILED', 'TIMEOUT', 'RATE_LIMITED'], true));
    $message = $failed
        ? 'Legal AI analysis completed with one or more unavailable sections.'
        : 'Legal AI analysis completed.';
    jsonResponse(true, $message, [
        'item' => $result['item'],
        'section_statuses' => $statuses,
        'attempt_count' => $result['attempt_count'] ?? 0,
        'elapsed_ms' => $result['elapsed_ms'] ?? 0,
    ]);
} catch (Throwable $e) {
    validationResponse($e);
}
