<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('GET');
$user = currentApiUser();
DocumentPolicy::requirePermission($user, 'records.view');
jsonResponse(true, 'Documents retrieved.', documentService()->list($_GET));
