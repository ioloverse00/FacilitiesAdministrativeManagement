<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('POST');
$user = currentApiUser();
DocumentTemplatePolicy::requirePermission($user, 'document_templates.edit');
requireCsrfToken();
try {
    $item = documentTemplateService()->submit(idParam('template_version_id'), $user);
    if ($item === null) jsonResponse(false, 'Template version not found.', [], 404);
    jsonResponse(true, 'Template submitted for review.', ['item' => $item]);
} catch (Throwable $e) {
    validationResponse($e);
}
