<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'LiveData' . DIRECTORY_SEPARATOR . 'LiveDataService.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Reservations' . DIRECTORY_SEPARATOR . 'ReservationService.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Documents' . DIRECTORY_SEPARATOR . 'DocumentService.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Documents' . DIRECTORY_SEPARATOR . 'DocumentStepUpService.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Documents' . DIRECTORY_SEPARATOR . 'DocumentTemplateService.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Documents' . DIRECTORY_SEPARATOR . 'ContractMetadataExtractionService.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Mail' . DIRECTORY_SEPARATOR . 'MailService.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'RecordsRetention' . DIRECTORY_SEPARATOR . 'RetentionService.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'RecordsRetention' . DIRECTORY_SEPARATOR . 'DispositionRecommendationService.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Organization' . DIRECTORY_SEPARATOR . 'FamEmployeeEligibilityService.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Integrations' . DIRECTORY_SEPARATOR . 'Google' . DIRECTORY_SEPARATOR . 'GoogleIntegrationException.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Integrations' . DIRECTORY_SEPARATOR . 'Google' . DIRECTORY_SEPARATOR . 'GoogleTokenCrypto.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Integrations' . DIRECTORY_SEPARATOR . 'Google' . DIRECTORY_SEPARATOR . 'GoogleOAuthService.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Integrations' . DIRECTORY_SEPARATOR . 'Google' . DIRECTORY_SEPARATOR . 'GoogleDriveService.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Contracts' . DIRECTORY_SEPARATOR . 'ContractService.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Legal' . DIRECTORY_SEPARATOR . 'LegalMatterService.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Legal' . DIRECTORY_SEPARATOR . 'LegalMatterSummaryService.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Legal' . DIRECTORY_SEPARATOR . 'LegalMatterPartyService.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Legal' . DIRECTORY_SEPARATOR . 'LegalMatterActionService.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Legal' . DIRECTORY_SEPARATOR . 'LegalMatterActionExtractionService.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Legal' . DIRECTORY_SEPARATOR . 'LegalMatterAiAnalysisService.php';

function currentApiUser(): array
{
    $auth = new AuthService(Database::connection());
    $auth->enforceSessionLifetime();
    $user = $auth->currentUser();
    if ($user === null) {
        jsonResponse(false, 'Authentication required.', [], 401);
    }
    $auth->touchSession();
    $data = $user->toArray();
    requireFamPortalUser($data);
    return $data;
}

function requirePermission(array $user, string $permission): void
{
    if (!in_array($permission, $user['permissions'] ?? [], true)) {
        jsonResponse(false, 'You do not have permission to perform this action.', [], 403);
    }
}

function liveDataService(): LiveDataService
{
    return new LiveDataService(Database::connection());
}

function reservationService(): ReservationService
{
    return new ReservationService(Database::connection());
}

function documentService(): DocumentService
{
    return new DocumentService(Database::connection());
}

function documentStepUpService(): DocumentStepUpService
{
    return new DocumentStepUpService(Database::connection(), new MailService());
}

function documentTemplateService(): DocumentTemplateService
{
    $connection = Database::connection();
    return new DocumentTemplateService($connection, new DocumentService($connection));
}

function contractMetadataExtractionService(): ContractMetadataExtractionService
{
    return new ContractMetadataExtractionService(Database::connection());
}

function retentionService(): RetentionService
{
    return new RetentionService(Database::connection());
}

function dispositionRecommendationService(): DispositionRecommendationService
{
    return new DispositionRecommendationService(Database::connection());
}

function famEmployeeEligibilityService(): FamEmployeeEligibilityService
{
    return new FamEmployeeEligibilityService(Database::connection());
}

function contractService(): ContractService
{
    $connection = Database::connection();
    return new ContractService($connection, new FamEmployeeEligibilityService($connection));
}

function legalMatterService(): LegalMatterService
{
    $connection = Database::connection();
    return new LegalMatterService($connection, null, new FamEmployeeEligibilityService($connection));
}

function legalMatterSummaryService(): LegalMatterSummaryService
{
    return new LegalMatterSummaryService(Database::connection());
}

function legalMatterPartyService(): LegalMatterPartyService
{
    return new LegalMatterPartyService(Database::connection());
}

function legalMatterActionService(): LegalMatterActionService
{
    $connection = Database::connection();
    return new LegalMatterActionService($connection, new FamEmployeeEligibilityService($connection));
}

function legalMatterActionExtractionService(): LegalMatterActionExtractionService
{
    return new LegalMatterActionExtractionService(Database::connection());
}

function legalMatterAiAnalysisService(): LegalMatterAiAnalysisService
{
    $connection = Database::connection();
    return new LegalMatterAiAnalysisService($connection, documentService(), new LegalMatterActionService($connection, new FamEmployeeEligibilityService($connection)));
}

function requireLegalDocumentAccessIfNeeded(int $documentId, array $user): void
{
    $reference = documentService()->legalMatterReferenceForDocument($documentId);
    if ($reference !== null) {
        LegalPolicy::requirePermission($user, 'legal.manage');
    }
}

function googleOAuthService(): GoogleOAuthService
{
    return new GoogleOAuthService(Database::connection());
}

function contractGoogleDocumentService(): ContractGoogleDocumentService
{
    $connection = Database::connection();
    $oauth = new GoogleOAuthService($connection);
    return new ContractGoogleDocumentService($connection, $oauth, new GoogleDriveService($oauth), new DocumentService($connection));
}

function requireConfidentialDocumentStepUp(int $documentId, ?int $versionId, array $user, string $action): void
{
    $service = documentService();
    if (!$service->requiresStepUp($documentId)) {
        return;
    }

    if (canViewClientRequirementEvidenceDuringWorkflowWithoutStepUp($documentId, $user, $action)) {
        return;
    }

    if (canViewContractDocumentDuringWorkflowWithoutStepUp($documentId, $versionId, $user, $action)) {
        return;
    }

    if (!documentStepUpService()->hasValidStepUp($user, $documentId)) {
        jsonResponse(false, 'Additional verification is required.', [
            'step_up_required' => true,
            'document_id' => $documentId,
            'version_id' => $versionId,
            'action' => $action,
        ], 403);
    }
}

function canViewClientRequirementEvidenceDuringWorkflowWithoutStepUp(int $documentId, array $user, string $action): bool
{
    if (strtolower($action) !== 'view') {
        return false;
    }

    try {
        $statement = Database::connection()->prepare("
            SELECT c.contract_id, c.contract_status, ccr.requirement_status, ccr.verification_status, ccr.applicability_status
            FROM contract_client_requirement ccr
            INNER JOIN contract c ON c.contract_id = ccr.contract_id AND c.deleted_at IS NULL
            WHERE ccr.uploaded_document_id = :document_id
            LIMIT 1
        ");
        $statement->execute(['document_id' => $documentId]);
        $row = $statement->fetch();
    } catch (Throwable) {
        return false;
    }
    if (!is_array($row)) {
        return false;
    }

    $contractStatus = strtoupper((string) $row['contract_status']);
    $requirementStatus = strtoupper((string) $row['requirement_status']);
    $verificationStatus = strtoupper((string) $row['verification_status']);
    $applicabilityStatus = strtoupper((string) $row['applicability_status']);
    if ($applicabilityStatus === 'NOT_APPLICABLE') {
        return false;
    }

    if ($contractStatus === 'DRAFT') {
        return in_array($requirementStatus, ['SUBMITTED','VERIFIED','REJECTED'], true)
            && ContractPolicy::hasPermission($user, 'contract.edit');
    }

    if ($contractStatus === 'FOR_REVIEW') {
        return in_array($requirementStatus, ['SUBMITTED','VERIFIED'], true)
            && in_array($verificationStatus, ['PENDING','VERIFIED'], true)
            && ContractPolicy::hasPermission($user, 'contract.review');
    }

    if ($contractStatus === 'FOR_APPROVAL') {
        return $requirementStatus === 'VERIFIED'
            && $verificationStatus === 'VERIFIED'
            && userCanViewCurrentContractApprovalEvidenceWithoutStepUp((int) $row['contract_id'], $user);
    }

    return false;
}

function canViewContractDocumentDuringWorkflowWithoutStepUp(int $documentId, ?int $versionId, array $user, string $action): bool
{
    if (strtolower($action) !== 'view') {
        return false;
    }

    try {
        $connection = Database::connection();
        $signedSchemaReady = (int) $connection->query("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND (
                (TABLE_NAME = 'contract' AND COLUMN_NAME = 'signed_document_version_id')
                OR (TABLE_NAME = 'approval_request' AND COLUMN_NAME = 'signed_document_version_id')
              )
        ")->fetchColumn() === 2;
        if (!$signedSchemaReady) {
            $statement = $connection->prepare("
                SELECT c.contract_id, c.contract_status, cgd.synced_document_version_id
                FROM contract_google_document cgd
                INNER JOIN contract c ON c.contract_id = cgd.contract_id AND c.deleted_at IS NULL
                WHERE cgd.synced_document_id = :document_id
                  AND cgd.working_document_status = 'FINALIZED'
                  AND cgd.synced_document_version_id IS NOT NULL
                LIMIT 1
            ");
            $statement->execute(['document_id' => $documentId]);
            $row = $statement->fetch();
            if (!is_array($row)) {
                return false;
            }
            $syncedVersionId = (int) ($row['synced_document_version_id'] ?? 0);
            if ($versionId !== null && $syncedVersionId !== $versionId) {
                return false;
            }
            $contractStatus = strtoupper((string) $row['contract_status']);
            if ($contractStatus === 'FOR_REVIEW') {
                return ContractPolicy::hasPermission($user, 'contract.review');
            }
            if ($contractStatus === 'FOR_APPROVAL') {
                return userCanViewCurrentContractApprovalEvidenceWithoutStepUp((int) $row['contract_id'], $user);
            }
            return false;
        }
        $statement = $connection->prepare("
            SELECT c.contract_id, c.contract_status, c.signed_document_version_id,
                   cgd.synced_document_version_id,
                   ar.signed_document_version_id approval_signed_document_version_id
            FROM contract_google_document cgd
            INNER JOIN contract c ON c.contract_id = cgd.contract_id AND c.deleted_at IS NULL
            LEFT JOIN approval_request ar ON ar.module_code = 'contract_management'
              AND ar.entity_type = 'contract'
              AND ar.entity_id = c.contract_id
              AND ar.approval_status = 'PENDING'
            WHERE cgd.synced_document_id = :document_id
              AND cgd.working_document_status = 'FINALIZED'
            LIMIT 1
        ");
        $statement->execute(['document_id' => $documentId]);
        $row = $statement->fetch();
    } catch (Throwable) {
        return false;
    }
    if (!is_array($row)) {
        return false;
    }

    $contractStatus = strtoupper((string) $row['contract_status']);
    $workflowVersionId = $contractStatus === 'FOR_APPROVAL'
        ? (int) ($row['approval_signed_document_version_id'] ?? 0)
        : (int) ($row['signed_document_version_id'] ?? 0);
    if ($workflowVersionId < 1) {
        $workflowVersionId = (int) ($row['synced_document_version_id'] ?? 0);
    }
    if ($versionId !== null && $workflowVersionId !== $versionId) {
        return false;
    }

    if ($contractStatus === 'FOR_REVIEW') {
        return ContractPolicy::hasPermission($user, 'contract.review');
    }

    if ($contractStatus === 'FOR_APPROVAL') {
        return userCanViewCurrentContractApprovalEvidenceWithoutStepUp((int) $row['contract_id'], $user);
    }

    return false;
}

function userCanViewCurrentContractApprovalEvidenceWithoutStepUp(int $contractId, array $user): bool
{
    try {
        return contractService()->canViewCurrentApprovalEvidence($contractId, $user);
    } catch (Throwable) {
        return false;
    }
}

function idParam(string $name = 'id'): int
{
    $value = $_GET[$name] ?? null;
    if ($value === null || !ctype_digit((string) $value)) {
        jsonResponse(false, 'A valid id is required.', [], 422);
    }
    return (int) $value;
}

function reservationIdentifier(): int|string
{
    $value = $_GET['id'] ?? $_GET['reservation_number'] ?? null;
    if ($value === null || $value === '') {
        jsonResponse(false, 'Reservation id or reservation_number is required.', [], 422);
    }
    return ctype_digit((string) $value) ? (int) $value : (string) $value;
}

function validationResponse(Throwable $e): void
{
    if ($e instanceof InvalidArgumentException) {
        $errors = json_decode($e->getMessage(), true);
        jsonResponse(false, 'Validation failed.', ['errors' => is_array($errors) ? $errors : ['request' => $e->getMessage()]], 422);
    }
    throw $e;
}
