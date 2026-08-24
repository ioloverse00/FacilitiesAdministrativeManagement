<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('POST');
$user = currentApiUser();
LegalPolicy::requirePermission($user, 'legal.manage');
requireCsrfToken();
try {
    $matterId = idParam();
    $result = legalMatterActionExtractionService()->analyze($matterId, $user);
    if ($result === null) {
        jsonResponse(false, 'Legal matter not found.', [], 404);
    }
    $item = legalMatterService()->show($matterId);
    $status = (string) ($item['aiActionsStatus'] ?? '');
    $message = match ($status) {
        'TIMEOUT' => 'AI action analysis timed out. Try again.',
        'RATE_LIMITED' => 'AI action analysis is temporarily unavailable because the provider limit was reached.',
        'FAILED' => 'AI action analysis could not be completed.',
        default => 'AI action suggestions updated.',
    };
    jsonResponse(true, $message, ['item' => $item]);
} catch (Throwable $e) {
    validationResponse($e);
}
