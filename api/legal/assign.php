<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('POST');
$user = currentApiUser();
LegalPolicy::requirePermission($user, 'legal.assign');
requireCsrfToken();
try {
    $item = legalMatterService()->assign(idParam(), readJsonBody(), $user);
    if ($item === null) {
        jsonResponse(false, 'Legal matter not found.', [], 404);
    }
    jsonResponse(true, 'Legal matter assigned.', ['item' => $item]);
} catch (Throwable $e) {
    validationResponse($e);
}
