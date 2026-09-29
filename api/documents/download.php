<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('GET');
$user = currentApiUser();
$documentId = idParam();
$contractId = isset($_GET['contract_id']) && ctype_digit((string) $_GET['contract_id']) ? (int) $_GET['contract_id'] : null;
$legalMatterId = isset($_GET['legal_matter_id']) && ctype_digit((string) $_GET['legal_matter_id']) ? (int) $_GET['legal_matter_id'] : null;
$versionId = isset($_GET['version_id']) && ctype_digit((string) $_GET['version_id']) ? (int) $_GET['version_id'] : null;
$stepUpContext = authorizeDocumentFileAccessOrDeny($documentId, $versionId, $user, 'download', $contractId, $legalMatterId);
requireConfidentialDocumentStepUp($documentId, $versionId, $user, 'download', $stepUpContext);
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
