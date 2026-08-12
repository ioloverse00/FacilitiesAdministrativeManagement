<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('POST');
$user = currentApiUser();
DocumentPolicy::requirePermission($user, 'records.create');
requireCsrfToken();
try {
    $file = $_FILES['file'] ?? [];
    $item = documentService()->create($_POST, is_array($file) ? $file : [], $user);
    jsonResponse(true, 'Document uploaded.', ['item' => $item], 201);
} catch (Throwable $e) {
    validationResponse($e);
}
