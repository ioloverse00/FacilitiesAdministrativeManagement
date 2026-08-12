<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('GET');
$user = currentApiUser();
DocumentPolicy::requireAnyPermission($user, ['records.view', 'records.create']);
jsonResponse(true, 'Options retrieved.', documentService()->options());
