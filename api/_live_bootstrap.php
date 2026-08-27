<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'LiveData' . DIRECTORY_SEPARATOR . 'LiveDataService.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Reservations' . DIRECTORY_SEPARATOR . 'ReservationService.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Documents' . DIRECTORY_SEPARATOR . 'DocumentService.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Documents' . DIRECTORY_SEPARATOR . 'ContractMetadataExtractionService.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'RecordsRetention' . DIRECTORY_SEPARATOR . 'RetentionService.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'RecordsRetention' . DIRECTORY_SEPARATOR . 'DispositionRecommendationService.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Organization' . DIRECTORY_SEPARATOR . 'FamEmployeeEligibilityService.php';
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
