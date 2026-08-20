<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

requireMethod('POST');
$user = currentApiUser();
if (!VisitorPolicy::can($user, 'visitors.create_walkin') && !VisitorPolicy::can($user, 'visitors.checkin')) {
    jsonResponse(false, 'You do not have permission to perform this action.', [], 403);
}
requireCsrfToken();

try {
    $file = $_FILES['id_image'] ?? null;
    if (!is_array($file)) {
        jsonResponse(false, 'Upload a captured ID image.', [], 422);
    }
    $result = visitorIdAnalysisService()->analyzeUpload($file);
    jsonResponse(true, 'AI-assisted ID details detected. Verify these details against the visitor\'s physical ID.', ['item' => $result]);
} catch (InvalidArgumentException $e) {
    jsonResponse(false, $e->getMessage(), [], 422);
} catch (RuntimeException $e) {
    jsonResponse(false, 'Could not analyze this ID automatically. Retake the ID or enter details manually.', [], 503);
}
