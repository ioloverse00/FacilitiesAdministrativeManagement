<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('POST');
$user = currentApiUser();
DocumentTemplatePolicy::requirePermission($user, 'document_templates.retire');
requireCsrfToken();
try {
    $item = documentTemplateService()->retire(idParam('template_version_id'), $user);
    if ($item === null) jsonResponse(false, 'Template version not found.', [], 404);
    jsonResponse(true, 'Template retired.', ['item' => $item]);
} catch (Throwable $e) {
    validationResponse($e);
}
