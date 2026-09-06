<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('GET');
$user = currentApiUser();
DocumentPolicy::requirePermission($user, 'records.view');
$documentId = idParam();
requireLegalDocumentAccessIfNeeded($documentId, $user);
$versionId = isset($_GET['version_id']) && ctype_digit((string) $_GET['version_id']) ? (int) $_GET['version_id'] : null;
requireConfidentialDocumentStepUp($documentId, $versionId, $user, 'download');
$file = documentService()->downloadVersion($documentId, $versionId);
if ($file === null) jsonResponse(false, 'Document file not found.', [], 404);
if (strtoupper((string) ($file['confidentiality_level'] ?? '')) === 'CONFIDENTIAL') {
    documentStepUpService()->auditDocumentAccess('CONFIDENTIAL_DOCUMENT_DOWNLOADED', $user, $documentId, (int) $file['document_version_id']);
}
header_remove('Content-Type');
header('Content-Type: ' . $file['mime_type']);
header('Content-Length: ' . (string) $file['file_size']);
header('Content-Disposition: attachment; filename="' . addcslashes($file['download_name'], '"\\') . '"');
header('X-Content-Type-Options: nosniff');
readfile($file['absolute_path']);
exit;
