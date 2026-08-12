<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('POST');
$user = currentApiUser();
DocumentPolicy::requireAnyPermission($user, ['records.edit', 'records.create']);
requireCsrfToken();
try {
    $file = $_FILES['file'] ?? [];
    $item = documentService()->uploadVersion(idParam(), $_POST, is_array($file) ? $file : [], $user);
    if ($item === null) jsonResponse(false, 'Document not found.', [], 404);
    jsonResponse(true, 'New document version uploaded.', ['item' => $item]);
} catch (Throwable $e) {
    validationResponse($e);
}
