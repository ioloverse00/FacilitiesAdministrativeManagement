<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('POST');
$user = currentApiUser();
DocumentPolicy::requirePermission($user, 'records.edit');
requireCsrfToken();
$item = documentService()->archive(idParam(), $user);
if ($item === null) jsonResponse(false, 'Document not found.', [], 404);
jsonResponse(true, 'Document archived.', ['item' => $item]);
