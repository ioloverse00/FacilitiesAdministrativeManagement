<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('GET');
$user = currentApiUser();
LegalPolicy::requirePermission($user, 'legal.view');
jsonResponse(true, 'Legal matter options retrieved.', legalMatterService()->options());
