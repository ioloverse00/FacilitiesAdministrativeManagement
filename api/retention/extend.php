<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('POST');
$user = currentApiUser();
RetentionPolicy::requirePermission($user, 'retention.extend');
requireCsrfToken();
try {
    $item = retentionService()->extend(idParam(), $_POST, $user);
    if ($item === null) jsonResponse(false, 'Retention record not found.', [], 404);
    jsonResponse(true, 'Retention extended.', ['item' => $item]);
} catch (Throwable $e) {
    validationResponse($e);
}
