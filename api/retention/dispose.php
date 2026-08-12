<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('POST');
$user = currentApiUser();
RetentionPolicy::requirePermission($user, 'retention.dispose');
requireCsrfToken();
try {
    $item = retentionService()->dispose(idParam(), $_POST, $user);
    if ($item === null) jsonResponse(false, 'Retention record not found.', [], 404);
    jsonResponse(true, 'Record disposed.', ['item' => $item]);
} catch (Throwable $e) {
    validationResponse($e);
}
