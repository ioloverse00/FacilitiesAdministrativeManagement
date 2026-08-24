<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('POST');
$user = currentApiUser();
RetentionPolicy::requirePermission($user, 'retention.review');
try {
    $recommendation = dispositionRecommendationService()->review(idParam(), $_POST, $user);
    if ($recommendation === null) jsonResponse(false, 'Disposition recommendation not found.', [], 404);
    jsonResponse(true, 'Disposition recommendation reviewed.', ['recommendation' => $recommendation]);
} catch (Throwable $e) {
    validationResponse($e);
}
