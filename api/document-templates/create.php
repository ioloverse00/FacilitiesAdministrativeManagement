<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('POST');
$user = currentApiUser();
DocumentTemplatePolicy::requirePermission($user, 'document_templates.create');
requireCsrfToken();
try {
    $file = $_FILES['file'] ?? [];
    $item = documentTemplateService()->create($_POST, is_array($file) ? $file : [], $user);
    jsonResponse(true, 'Document template created.', ['item' => $item], 201);
} catch (Throwable $e) {
    validationResponse($e);
}
