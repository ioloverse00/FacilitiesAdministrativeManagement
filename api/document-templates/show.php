<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('GET');
$user = currentApiUser();
DocumentTemplatePolicy::requirePermission($user, 'document_templates.view');
$item = documentTemplateService()->show(idParam());
if ($item === null) jsonResponse(false, 'Template not found.', [], 404);
jsonResponse(true, 'Document template retrieved.', ['item' => $item]);
