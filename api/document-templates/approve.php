<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('POST');
$user = currentApiUser();
DocumentTemplatePolicy::requirePermission($user, 'document_templates.approve');
requireCsrfToken();
try {
    $item = documentTemplateService()->approve(idParam('template_version_id'), $user);
    if ($item === null) jsonResponse(false, 'Template version not found.', [], 404);
    jsonResponse(true, 'Template approved.', ['item' => $item]);
} catch (Throwable $e) {
    validationResponse($e);
}
