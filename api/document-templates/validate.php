<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('POST');
$user = currentApiUser();
DocumentTemplatePolicy::requirePermission($user, 'document_templates.view');
requireCsrfToken();
jsonResponse(true, 'Template content validated.', ['validation' => documentTemplateService()->validate(readJsonBody(), $user)]);
