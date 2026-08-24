<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('POST');
$user = currentApiUser();
LegalPolicy::requirePermission($user, 'legal.manage');
requireCsrfToken();

function contractMetadataLegalDocument(PDO $pdo, int $documentId): ?array
{
    $statement = $pdo->prepare("SELECT d.document_id, d.document_number, lm.legal_matter_id, lm.matter_number, lm.matter_type FROM document d INNER JOIN record_document rd ON rd.document_id = d.document_id INNER JOIN record rec ON rec.record_id = rd.record_id AND rec.deleted_at IS NULL INNER JOIN legal_matter lm ON lm.matter_number COLLATE utf8mb4_unicode_ci = rec.source_entity_type COLLATE utf8mb4_unicode_ci AND lm.deleted_at IS NULL WHERE d.deleted_at IS NULL AND rec.source_module = 'legal_management' AND d.document_id = :document_id LIMIT 1");
    $statement->execute(['document_id' => $documentId]);
    $row = $statement->fetch();
    return is_array($row) ? $row : null;
}

try {
    $documentId = idParam('document_id');
    $link = contractMetadataLegalDocument(Database::connection(), $documentId);
    if ($link === null) {
        jsonResponse(false, 'Supporting document not found.', [], 404);
    }
    if (($link['matter_type'] ?? '') !== 'CONTRACT_RELATED') {
        jsonResponse(false, 'Contract metadata analysis is only available for contract-related legal matters.', [], 422);
    }
    $item = contractMetadataExtractionService()->analyze($documentId, $user);
    jsonResponse(true, 'Contract metadata analysis updated.', ['item' => $item]);
} catch (Throwable $e) {
    validationResponse($e);
}
