<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('POST');
$user = currentApiUser();
LegalPolicy::requirePermission($user, 'legal.manage');
DocumentPolicy::requirePermission($user, 'records.view');
requireCsrfToken();
try {
    $result = legalMatterPartyService()->analyze(idParam(), $user);
    if ($result === null) {
        jsonResponse(false, 'Legal matter not found.', [], 404);
    }
    $item = legalMatterService()->show(idParam());
    $status = (string) ($item['aiPartiesStatus'] ?? '');
    $message = match ($status) {
        'TIMEOUT' => 'AI party analysis timed out. Try again.',
        'RATE_LIMITED' => 'AI party analysis is temporarily unavailable because the provider limit was reached.',
        'FAILED' => 'AI party analysis could not be completed.',
        default => 'AI-extracted parties updated.',
    };
    jsonResponse(true, $message, ['item' => $item], 200);
} catch (Throwable $e) {
    validationResponse($e);
}
