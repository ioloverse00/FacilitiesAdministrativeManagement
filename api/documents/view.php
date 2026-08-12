<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('GET');
$user = currentApiUser();
DocumentPolicy::requirePermission($user, 'records.view');
$versionId = isset($_GET['version_id']) && ctype_digit((string) $_GET['version_id']) ? (int) $_GET['version_id'] : null;
$file = documentService()->downloadVersion(idParam(), $versionId);
if ($file === null) jsonResponse(false, 'Document file not found.', [], 404);
header_remove('Content-Type');
header('Content-Type: ' . $file['mime_type']);
header('Content-Length: ' . (string) $file['file_size']);
header('Content-Disposition: inline; filename="' . addcslashes($file['download_name'], '"\\') . '"');
header('X-Content-Type-Options: nosniff');
readfile($file['absolute_path']);
exit;
