<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('POST');
$user = currentApiUser();
LegalPolicy::requirePermission($user, 'legal.manage');
DocumentPolicy::requirePermission($user, 'records.create');
requireCsrfToken();
try {
    $file = $_FILES['file'] ?? [];
    $item = legalMatterService()->attachSupportingDocument(idParam(), $_POST, is_array($file) ? $file : [], $user);
    if ($item === null) {
        jsonResponse(false, 'Legal matter not found.', [], 404);
    }
    jsonResponse(true, 'Supporting document attached.', ['item' => $item], 201);
} catch (Throwable $e) {
    validationResponse($e);
}
