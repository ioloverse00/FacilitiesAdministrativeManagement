<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('GET');
$user = currentApiUser();
RetentionPolicy::requirePermission($user, 'retention.view');
$item = retentionService()->show(idParam());
if ($item === null) jsonResponse(false, 'Retention record not found.', [], 404);
jsonResponse(true, 'Retention record retrieved.', ['item' => $item]);
