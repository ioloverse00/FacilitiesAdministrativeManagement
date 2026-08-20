<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('GET');
$user = currentApiUser();
DocumentPolicy::requirePermission($user, 'records.view');
$documentId = idParam();
requireLegalDocumentAccessIfNeeded($documentId, $user);
$item = documentService()->show($documentId);
if ($item === null) jsonResponse(false, 'Document not found.', [], 404);
jsonResponse(true, 'Document retrieved.', ['item' => $item]);
