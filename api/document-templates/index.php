<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('GET');
$user = currentApiUser();
DocumentTemplatePolicy::requirePermission($user, 'document_templates.view');
jsonResponse(true, 'Document templates retrieved.', documentTemplateService()->list($_GET));
