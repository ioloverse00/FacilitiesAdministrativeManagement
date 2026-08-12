<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('GET');
$user = currentApiUser();
DocumentPolicy::requirePermission($user, 'records.view');
$item = documentService()->show(idParam());
if ($item === null) jsonResponse(false, 'Document not found.', [], 404);
jsonResponse(true, 'Document retrieved.', ['item' => $item]);
