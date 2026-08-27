<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

requireMethod('POST');
$user = currentApiUser();
if (!VisitorPolicy::can($user, 'visitors.create_walkin') || !VisitorPolicy::can($user, 'visitors.checkin')) {
    jsonResponse(false, 'You do not have permission to perform this action.', [], 403);
}
requireCsrfToken();

try {
    $body = visitorBody();
    $identity = [
        'full_name' => trim((string)($body['full_name'] ?? 'Visitor Check')),
        'visitor_type' => trim((string)($body['visitor_type'] ?? 'GUEST')) ?: 'GUEST',
        'email_address' => trim((string)($body['email_address'] ?? '')),
        'mobile_number' => trim((string)($body['mobile_number'] ?? '')),
        'identification_type' => trim((string)($body['identification_type'] ?? '')),
        'identification_last4' => trim((string)($body['identification_last4'] ?? '')),
    ];

    $hasStableIdentity = ($identity['identification_type'] !== '' && $identity['identification_last4'] !== '')
        || $identity['email_address'] !== ''
        || $identity['mobile_number'] !== '';

    if (!$hasStableIdentity) {
        jsonResponse(false, 'Provide an ID type with ID last 4, email, or mobile number to verify active visits.', [
            'errors' => ['identity' => 'Provide stable identity details to verify active visits.'],
        ], 422);
    }

    $visit = visitorService()->activeVisitForIdentity($identity);
    jsonResponse(true, 'Active visit lookup complete.', [
        'has_active_visit' => $visit !== null,
        'visit' => $visit,
    ]);
} catch (Throwable $e) {
    visitorValidation($e);
}
