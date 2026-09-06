<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('GET');
$user = currentApiUser();
DocumentTemplatePolicy::requirePermission($user, 'document_templates.view');
$items = documentTemplateService()->versions(idParam());
if ($items === null) jsonResponse(false, 'Template not found.', [], 404);
jsonResponse(true, 'Template versions retrieved.', $items);
