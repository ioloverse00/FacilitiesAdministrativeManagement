<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('POST');
$user = currentApiUser();
DocumentTemplatePolicy::requirePermission($user, 'document_templates.edit');
requireCsrfToken();
try {
    $data = $_POST;
    $data['_file'] = is_array($_FILES['file'] ?? null) ? $_FILES['file'] : [];
    $item = documentTemplateService()->createVersion(idParam(), $data, $user);
    if ($item === null) jsonResponse(false, 'Template not found.', [], 404);
    jsonResponse(true, 'Template version created.', ['item' => $item], 201);
} catch (Throwable $e) {
    validationResponse($e);
}
