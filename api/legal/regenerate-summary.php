<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('POST');
$user = currentApiUser();
LegalPolicy::requirePermission($user, 'legal.manage');
DocumentPolicy::requirePermission($user, 'records.view');
requireCsrfToken();
try {
    $item = legalMatterSummaryService()->generate(idParam(), $user, true);
    if ($item === null) {
        jsonResponse(false, 'Legal matter not found.', [], 404);
    }
    $details = legalMatterService()->show((int) $item['legal_matter_id']);
    jsonResponse(true, legalSummaryMessage($details, 'refreshed'), ['item' => $details, 'ai_summary_status' => $details['aiSummaryStatus'] ?? 'FAILED', 'ai_summary_failure_reason' => $details['aiSummaryFailureReason'] ?? ''], 200);
} catch (Throwable $e) {
    validationResponse($e);
}

function legalSummaryMessage(?array $item, string $successVerb): string
{
    $status = (string) ($item['aiSummaryStatus'] ?? 'FAILED');
    $reason = (string) ($item['aiSummaryFailureReason'] ?? '');
    if ($status === 'READY') {
        return "AI matter summary $successVerb.";
    }
    if ($status === 'PENDING') {
        return 'AI matter summary is still being analyzed.';
    }
    if ($status === 'NO_READABLE_SOURCE') {
        return 'No readable supporting documents are available for AI summarization.';
    }
    if ($reason === 'GEMINI_TIMEOUT') {
        return 'AI summary generation timed out. You can try again.';
    }
    if ($reason === 'GEMINI_QUOTA_OR_RATE_LIMIT') {
        return 'AI summary is temporarily unavailable due to provider limits.';
    }
    return 'AI summary could not be generated.';
}
