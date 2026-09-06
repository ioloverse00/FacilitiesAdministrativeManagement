<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Organization' . DIRECTORY_SEPARATOR . 'FamEmployeeEligibilityService.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Documents' . DIRECTORY_SEPARATOR . 'DocumentService.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Documents' . DIRECTORY_SEPARATOR . 'ContractMetadataExtractionService.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'RecordsRetention' . DIRECTORY_SEPARATOR . 'RetentionService.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Integrations' . DIRECTORY_SEPARATOR . 'Google' . DIRECTORY_SEPARATOR . 'GoogleIntegrationException.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Integrations' . DIRECTORY_SEPARATOR . 'Google' . DIRECTORY_SEPARATOR . 'GoogleTokenCrypto.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Integrations' . DIRECTORY_SEPARATOR . 'Google' . DIRECTORY_SEPARATOR . 'GoogleOAuthService.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Integrations' . DIRECTORY_SEPARATOR . 'Google' . DIRECTORY_SEPARATOR . 'GoogleDriveService.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ContractGoogleDocumentService.php';

final class ContractPolicy
{
    public static function hasPermission(array $user, string $permission): bool
    {
        return in_array($permission, $user['permissions'] ?? [], true)
            || in_array('contract.manage', $user['permissions'] ?? [], true);
    }

    public static function requirePermission(array $user, string $permission): void
    {
        if (!self::hasPermission($user, $permission)) {
            jsonResponse(false, 'You do not have permission to perform this action.', [], 403);
        }
    }
}

final class ContractWorkflowException extends DomainException
{
    public function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly array $details = []
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function details(): array
    {
        return $this->details;
    }
}

final class ContractService
{
    public const STATUSES = ['DRAFT','FOR_REVIEW','FOR_APPROVAL','APPROVED','ACTIVE','EXPIRED','TERMINATED','ARCHIVED','REJECTED','CANCELLED'];
    private const EDITABLE_STATUSES = ['DRAFT'];
    private const RENEWAL_TYPES = ['NONE','MANUAL','AUTO'];
    private const RISK_LEVELS = ['LOW','MEDIUM','HIGH','CRITICAL'];
    private const CURRENCIES = ['PHP','USD','EUR'];
    private const UNESTABLISHED_START_DATE = '1000-01-01';
    private const UNESTABLISHED_END_DATE = '9999-12-31';
    private const CANONICAL_CONTRACT_TYPES = ['CLIENT_CONTRACT','EMPLOYEE_CONTRACT','NDA','CONTRACT_AMENDMENT','OTHER'];
    private const LEGACY_CONTRACT_TYPES = ['SERVICE','SUPPLY','LEASE','MAINTENANCE'];
    private const DEFAULT_CLIENT_REQUIREMENTS = [
        ['BUSINESS_REGISTRATION', 'Business Registration', 'DTI / SEC / CDA registration depending on organization type.', 'REQUIRED'],
        ['BIR_CERTIFICATE', 'BIR Certificate of Registration', 'Current registration or tax identification evidence.', 'REQUIRED'],
        ['BUSINESS_PERMIT', "Mayor's / Business Permit", 'Current local business permit where applicable.', 'REQUIRED'],
        ['AUTHORIZED_REP_ID', 'Authorized Representative Identification', 'Valid government or company identification for the authorized signatory.', 'REQUIRED'],
        ['AUTHORITY_TO_SIGN', 'Authority to Sign', "Secretary's Certificate, Board Resolution, SPA, authorization letter, or equivalent where applicable.", 'CONDITIONAL'],
        ['DOLE_COMPLIANCE', 'DOLE Registration / Compliance Document', 'Required where applicable to the engagement or service arrangement.', 'CONDITIONAL'],
        ['OTHER_REGULATORY_PERMIT', 'Other Regulatory / Industry Permit', 'Conditional depending on the contract, service, counterparty, or industry.', 'CONDITIONAL'],
    ];
    private const PLACEHOLDER_ALIASES = [
        'CLIENT LEGAL NAME' => 'CLIENT NAME',
        'COUNTERPARTY NAME' => 'CLIENT NAME',
        'CONTRACT NAME' => 'CONTRACT TITLE',
        'TOTAL CONTRACT VALUE' => 'CONTRACT VALUE',
        'SIGNING PLACE' => 'PLACE',
    ];
    private const TRANSITIONS = [
        'DRAFT' => ['FOR_REVIEW', 'CANCELLED'],
        'FOR_REVIEW' => ['DRAFT', 'FOR_APPROVAL'],
        'FOR_APPROVAL' => [],
        'APPROVED' => ['ACTIVE', 'DRAFT'],
        'ACTIVE' => ['TERMINATED', 'EXPIRED'],
        'EXPIRED' => ['ARCHIVED'],
        'TERMINATED' => ['ARCHIVED'],
        'REJECTED' => ['DRAFT'],
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly ?FamEmployeeEligibilityService $employeeEligibility = null
    ) {
    }

    public function list(array $query, array $user): array
    {
        ContractPolicy::requirePermission($user, 'contract.view');
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($query['per_page'] ?? 10)));
        $sortMap = [
            'contract_number' => 'c.contract_number',
            'title' => 'c.contract_title',
            'status' => 'c.contract_status',
            'end_date' => 'c.end_date',
            'updated_at' => 'c.updated_at',
        ];
        if ($this->canViewContractFinancials($user)) {
            $sortMap['current_amount'] = 'c.current_amount';
        }
        $sort = $sortMap[(string) ($query['sort'] ?? '')] ?? 'c.updated_at';
        $direction = strtolower((string) ($query['direction'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
        [$where, $params] = $this->filters($query);

        $count = $this->pdo->prepare($this->baseSelect('COUNT(DISTINCT c.contract_id)') . $where);
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $sql = $this->baseSelect($this->selectColumns()) . $where . " GROUP BY c.contract_id ORDER BY $sort $direction, c.contract_id DESC LIMIT :limit OFFSET :offset";
        $statement = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->bindValue('limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue('offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $statement->execute();

        return [
            'items' => array_map(fn (array $row): array => $this->shape($row, $user, false), $statement->fetchAll()),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) max(1, ceil($total / max(1, $perPage))),
            ],
            'summary' => $this->summary(),
        ];
    }

    public function dashboardSummary(): array
    {
        return $this->summary();
    }

    public function show(int|string $idOrNumber, array $user): ?array
    {
        ContractPolicy::requirePermission($user, 'contract.view');
        $where = is_int($idOrNumber) ? 'c.contract_id = :value' : 'c.contract_number = :value';
        $statement = $this->pdo->prepare($this->baseSelect($this->selectColumns()) . " WHERE c.deleted_at IS NULL AND $where GROUP BY c.contract_id LIMIT 1");
        $statement->execute(['value' => $idOrNumber]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }
        $item = $this->shape($row, $user, true);
        $item['documents'] = $this->documentsForContract((int) $row['contract_id'], (string) $row['contract_status']);
        $item['contractArtifacts'] = $item['documents'];
        $item['clientRequirements'] = $this->clientRequirementsForContract((int) $row['contract_id']);
        $item['history'] = $this->history((int) $row['contract_id']);
        return $item;
    }

    public function documents(int $id, array $user): ?array
    {
        ContractPolicy::requirePermission($user, 'contract.view');
        $contract = $this->row('SELECT contract_id FROM contract WHERE contract_id = :id AND deleted_at IS NULL LIMIT 1', ['id' => $id]);
        if ($contract === null) {
            return null;
        }
        return ['items' => $this->documentsForContract($id)];
    }

    public function attachDocument(int $id, array $data, array $file, array $user): ?array
    {
        ContractPolicy::requirePermission($user, 'contract.edit');
        $contract = $this->row('SELECT contract_id, contract_number, contract_title FROM contract WHERE contract_id = :id AND deleted_at IS NULL LIMIT 1', ['id' => $id]);
        if ($contract === null) {
            return null;
        }

        $categoryId = (int) $this->scalar("SELECT document_category_id FROM document_category WHERE category_code = 'DOC-CON' AND status = 'ACTIVE' LIMIT 1");
        if ($categoryId < 1) {
            throw new InvalidArgumentException(json_encode(['document_category_id' => 'Contract document category is not configured.'], JSON_THROW_ON_ERROR));
        }
        $isPrimary = !empty($data['is_primary_document']);
        $metadata = [
            'title' => $this->text($data['title'] ?? $data['document_title'] ?? ($contract['contract_title'] . ' Document'), 255),
            'description' => $this->nullableText($data['description'] ?? $data['document_description'] ?? '', 4000),
            'document_category_id' => $categoryId,
            'confidentiality_level' => strtoupper($this->text($data['confidentiality_level'] ?? 'CONFIDENTIAL', 30)),
            'status' => 'ACTIVE',
            'document_date' => $data['document_date'] ?? date('Y-m-d'),
            'change_summary' => $data['change_summary'] ?? 'Initial contract document upload',
            'related_module' => 'CONTRACT_MANAGEMENT',
            'related_reference' => (string) $contract['contract_number'],
        ];

        $document = (new DocumentService($this->pdo))->create($metadata, $file, $user);
        $documentId = (int) ($document['id'] ?? 0);
        if ($documentId > 0) {
            $this->pdo->prepare("UPDATE record r INNER JOIN record_document rd ON rd.record_id = r.record_id SET r.source_entity_id = :contract_id, rd.is_primary_document = IF(:primary_flag = 1, TRUE, rd.is_primary_document) WHERE rd.document_id = :document_id AND r.source_module = 'contract_management' AND r.source_entity_type = :contract_number")->execute([
                'contract_id' => $id,
                'primary_flag' => $isPrimary ? 1 : 0,
                'document_id' => $documentId,
                'contract_number' => (string) $contract['contract_number'],
            ]);
            if ($isPrimary) {
                $this->pdo->prepare("UPDATE record_document rd INNER JOIN record r ON r.record_id = rd.record_id SET rd.is_primary_document = IF(rd.document_id = :document_id, TRUE, FALSE) WHERE r.source_module = 'contract_management' AND r.source_entity_id = :contract_id")->execute(['document_id' => $documentId, 'contract_id' => $id]);
            }
            $this->historyEvent($id, 'DOCUMENT_ATTACHED', 'Contract document attached.', null, null, (int) $user['id'], ['document_id' => $documentId, 'is_primary' => $isPrimary]);
        }

        return $this->show($id, $user);
    }

    public function uploadSignedContract(int $id, array $data, array $file, array $user): ?array
    {
        ContractPolicy::requirePermission($user, 'contract.review');
        $this->assertSignedContractSchema();
        $contract = $this->row('SELECT c.*, cgd.synced_document_id, cgd.synced_document_version_id FROM contract c LEFT JOIN contract_google_document cgd ON cgd.contract_id = c.contract_id AND cgd.working_document_status = \'FINALIZED\' WHERE c.contract_id = :id AND c.deleted_at IS NULL LIMIT 1', ['id' => $id]);
        if ($contract === null) {
            return null;
        }
        if ((string) $contract['contract_status'] !== 'FOR_REVIEW') {
            throw new DomainException('Signed contract copies can only be uploaded while the contract is for review.');
        }
        $documentId = (int) ($contract['synced_document_id'] ?? 0);
        if ($documentId < 1) {
            throw new DomainException('Finalize and synchronize the contract document before uploading the signed copy.');
        }

        $previousVersionId = empty($contract['signed_document_version_id']) ? null : (int) $contract['signed_document_version_id'];
        $summary = $this->text($data['change_summary'] ?? 'Signed/executed contract copy uploaded.', 1000);
        $this->ensureContractArtifactConfidential($documentId);
        $document = (new DocumentService($this->pdo))->uploadVersion($documentId, ['change_summary' => $summary], $file, $user);
        if (empty($document['id'])) {
            throw new RuntimeException('Signed contract document could not be stored.');
        }
        $versionId = $this->currentDocumentVersionId($documentId);
        if ($versionId === null) {
            throw new RuntimeException('Signed contract document version could not be resolved.');
        }

        $fields = ['signed_document_id = :document_id', 'signed_document_version_id = :version_id', 'updated_by_user_id = :user_id', 'updated_at = NOW()'];
        if ($this->hasColumn('contract', 'signed_document_verified_by_user_id') && $this->hasColumn('contract', 'signed_document_verified_at')) {
            $fields[] = 'signed_document_verified_by_user_id = NULL';
            $fields[] = 'signed_document_verified_at = NULL';
        }
        $this->pdo->prepare('UPDATE contract SET ' . implode(', ', $fields) . ' WHERE contract_id = :contract_id AND deleted_at IS NULL')->execute([
            'document_id' => $documentId,
            'version_id' => $versionId,
            'user_id' => (int) $user['id'],
            'contract_id' => $id,
        ]);
        $this->historyEvent($id, 'SIGNED_CONTRACT_UPLOADED', $previousVersionId ? 'Signed contract copy replaced.' : 'Signed contract copy uploaded.', null, null, (int) $user['id'], [
            'document_id' => $documentId,
            'document_version_id' => $versionId,
            'previous_document_version_id' => $previousVersionId,
            'file_name' => (string) ($document['currentVersion']['fileName'] ?? ''),
            'signed_copy_action' => $previousVersionId ? 'replacement' : 'upload',
        ]);

        return $this->show($id, $user);
    }

    public function verifySignedContract(int $id, array $user): ?array
    {
        ContractPolicy::requirePermission($user, 'contract.review');
        throw new DomainException('Separate signed-copy verification is no longer part of the active contract workflow.');
    }

    public function uploadClientRequirement(int $id, array $data, array $file, array $user): ?array
    {
        ContractPolicy::requirePermission($user, 'contract.edit');
        DocumentPolicy::requirePermission($user, 'records.create');
        $this->assertClientRequirementInfrastructure();
        $contract = $this->row('SELECT contract_id, contract_number, contract_title, contract_status FROM contract WHERE contract_id = :id AND deleted_at IS NULL LIMIT 1', ['id' => $id]);
        if ($contract === null) {
            return null;
        }
        if (!in_array((string) $contract['contract_status'], self::EDITABLE_STATUSES, true)) {
            throw new DomainException('Client requirement documents can only be uploaded while the contract is a draft.');
        }
        $requirementId = isset($data['requirement_id']) && ctype_digit((string) $data['requirement_id']) ? (int) $data['requirement_id'] : 0;
        $requirement = $requirementId > 0
            ? $this->row('SELECT * FROM contract_client_requirement WHERE contract_client_requirement_id = :requirement_id AND contract_id = :contract_id LIMIT 1', ['requirement_id' => $requirementId, 'contract_id' => $id])
            : null;
        if ($requirementId > 0 && $requirement === null) {
            throw new DomainException('Client requirement was not found for this contract.');
        }
        if ($requirement !== null && (string) $requirement['applicability_status'] === 'NOT_APPLICABLE') {
            throw new DomainException('This requirement is marked not applicable and cannot receive evidence.');
        }
        $name = $this->text($requirement['requirement_name'] ?? $data['requirement_name'] ?? '', 180);
        if ($name === '') {
            throw new InvalidArgumentException(json_encode(['requirement_name' => 'Enter the client requirement name.'], JSON_THROW_ON_ERROR));
        }
        $categoryId = (int) $this->scalar("SELECT document_category_id FROM document_category WHERE category_code = 'DOC-CON' AND status = 'ACTIVE' LIMIT 1");
        if ($categoryId < 1) {
            throw new InvalidArgumentException(json_encode(['document_category_id' => 'Contract document category is not configured.'], JSON_THROW_ON_ERROR));
        }
        $metadata = [
            'title' => $name,
            'description' => $this->nullableText($data['requirement_description'] ?? '', 4000) ?? ('Client requirement for ' . (string) $contract['contract_number']),
            'document_category_id' => $categoryId,
            'confidentiality_level' => 'CONFIDENTIAL',
            'status' => 'ACTIVE',
            'document_date' => $data['document_date'] ?? date('Y-m-d'),
            'change_summary' => $data['change_summary'] ?? 'Client requirement document upload',
            'related_module' => 'CONTRACT_MANAGEMENT',
            'related_reference' => (string) $contract['contract_number'],
        ];
        $classification = strtoupper($this->text($data['requirement_classification'] ?? 'REQUIRED', 30));
        if (!in_array($classification, ['REQUIRED', 'CONDITIONAL', 'OPTIONAL'], true)) {
            $classification = 'REQUIRED';
        }

        $createdDocumentStoragePaths = [];
        $this->pdo->beginTransaction();
        try {
            if ($requirementId > 0) {
                $requirement = $this->row('SELECT * FROM contract_client_requirement WHERE contract_client_requirement_id = :requirement_id AND contract_id = :contract_id FOR UPDATE', ['requirement_id' => $requirementId, 'contract_id' => $id]);
                if ($requirement === null) {
                    throw new DomainException('Client requirement was not found for this contract.');
                }
                if ((string) $requirement['applicability_status'] === 'NOT_APPLICABLE') {
                    throw new DomainException('This requirement is marked not applicable and cannot receive evidence.');
                }
            }

            $document = (new DocumentService($this->pdo))->create($metadata, $file, $user);
            $documentId = (int) ($document['id'] ?? 0);
            if ($documentId < 1) {
                throw new RuntimeException('Client requirement document could not be stored.');
            }
            $createdDocumentStoragePaths = array_map(
                fn (array $row): string => (string) $row['storage_path'],
                $this->rows('SELECT storage_path FROM document_version WHERE document_id = :document_id', ['document_id' => $documentId])
            );
            $this->pdo->prepare("UPDATE record r INNER JOIN record_document rd ON rd.record_id = r.record_id SET r.source_entity_id = :contract_id, rd.is_primary_document = FALSE WHERE rd.document_id = :document_id AND r.source_module = 'contract_management' AND r.source_entity_type = :contract_number")->execute([
                'contract_id' => $id,
                'document_id' => $documentId,
                'contract_number' => (string) $contract['contract_number'],
            ]);
            if ($requirement !== null) {
                $this->pdo->prepare("UPDATE contract_client_requirement SET requirement_status = 'SUBMITTED', verification_status = 'PENDING', applicability_status = IF(requirement_classification = 'CONDITIONAL' AND applicability_status = 'PENDING', 'APPLICABLE', applicability_status), uploaded_document_id = :document_id, uploaded_by_user_id = :uploaded_by, verified_by_user_id = NULL, verified_at = NULL, rejected_by_user_id = NULL, rejected_at = NULL, rejection_reason = NULL, uploaded_at = NOW(), updated_at = NOW() WHERE contract_client_requirement_id = :requirement_id AND contract_id = :contract_id")->execute([
                    'document_id' => $documentId,
                    'uploaded_by' => (int) $user['id'],
                    'requirement_id' => $requirementId,
                    'contract_id' => $id,
                ]);
            } else {
                $code = 'CUSTOM_' . strtoupper(bin2hex(random_bytes(4)));
                $this->pdo->prepare("INSERT INTO contract_client_requirement (contract_id, requirement_code, requirement_name, requirement_description, requirement_classification, applicability_status, requirement_status, verification_status, uploaded_document_id, created_by_user_id, uploaded_by_user_id, uploaded_at, created_at, updated_at) VALUES (:contract_id, :code, :name, :description, :classification, 'APPLICABLE', 'SUBMITTED', 'PENDING', :document_id, :created_by, :uploaded_by, NOW(), NOW(), NOW())")->execute([
                    'contract_id' => $id,
                    'code' => $code,
                    'name' => $name,
                    'description' => $this->nullableText($data['requirement_description'] ?? '', 4000),
                    'classification' => $classification,
                    'document_id' => $documentId,
                    'created_by' => (int) $user['id'],
                    'uploaded_by' => (int) $user['id'],
                ]);
            }
            $previousDocumentId = $requirement === null || empty($requirement['uploaded_document_id']) ? null : (int) $requirement['uploaded_document_id'];
            $this->historyEvent($id, 'CLIENT_REQUIREMENT_DOCUMENT_ATTACHED', 'Client requirement document ' . ($previousDocumentId ? 'replaced' : 'attached') . '.', null, null, (int) $user['id'], [
                'requirement_id' => $requirementId ?: null,
                'requirement_name' => $name,
                'document_id' => $documentId,
                'previous_document_id' => $previousDocumentId,
                'document_number' => (string) ($document['documentNo'] ?? ''),
                'file_name' => (string) ($document['currentVersion']['fileName'] ?? ''),
                'evidence_action' => $previousDocumentId ? 'replacement' : 'attachment',
                'classification' => $requirement['requirement_classification'] ?? $classification,
                'confidentiality_level' => 'CONFIDENTIAL',
            ]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->deleteRolledBackDocumentFiles($createdDocumentStoragePaths);
            throw $exception;
        }

        return $this->show($id, $user);
    }

    private function deleteRolledBackDocumentFiles(array $storagePaths): void
    {
        $base = realpath(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'documents');
        if ($base === false) {
            return;
        }
        $basePrefix = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        foreach ($storagePaths as $storagePath) {
            $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $storagePath);
            if ($normalized === '' || str_contains($normalized, '..')) {
                continue;
            }
            $absolute = realpath(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . $normalized);
            if ($absolute !== false && str_starts_with($absolute, $basePrefix) && is_file($absolute)) {
                @unlink($absolute);
            }
        }
    }

    public function clientRequirementAction(int $id, array $data, array $user): ?array
    {
        $this->assertClientRequirementInfrastructure();
        $action = strtoupper(trim((string) ($data['action'] ?? '')));
        if (!in_array($action, ['VERIFY','REJECT','MARK_APPLICABLE','MARK_NOT_APPLICABLE'], true)) {
            throw new InvalidArgumentException(json_encode(['action' => 'Choose a supported requirement action.'], JSON_THROW_ON_ERROR));
        }
        $requirementId = isset($data['requirement_id']) && ctype_digit((string) $data['requirement_id']) ? (int) $data['requirement_id'] : 0;
        if ($requirementId < 1) {
            throw new InvalidArgumentException(json_encode(['requirement_id' => 'A valid client requirement is required.'], JSON_THROW_ON_ERROR));
        }
        $comment = $this->nullableText($data['comment'] ?? $data['reason'] ?? '', 1000);
        if ($action === 'REJECT' && $comment === null) {
            throw new InvalidArgumentException(json_encode(['reason' => 'Reason is required.'], JSON_THROW_ON_ERROR));
        }

        $permission = in_array($action, ['VERIFY','REJECT'], true) ? 'contract.review' : 'contract.edit';
        ContractPolicy::requirePermission($user, $permission);

        $this->pdo->beginTransaction();
        try {
            $contract = $this->row('SELECT * FROM contract WHERE contract_id = :id AND deleted_at IS NULL FOR UPDATE', ['id' => $id]);
            if ($contract === null) {
                $this->pdo->rollBack();
                return null;
            }
            $contractStatus = (string) $contract['contract_status'];
            if (in_array($action, ['MARK_APPLICABLE','MARK_NOT_APPLICABLE'], true) && !in_array($contractStatus, self::EDITABLE_STATUSES, true)) {
                throw new DomainException('Conditional requirement applicability can only be changed while the contract is a draft.');
            }
            if (in_array($action, ['VERIFY','REJECT'], true) && $contractStatus !== 'FOR_REVIEW') {
                throw new DomainException('Client requirements can only be verified while the contract is for review.');
            }
            $requirement = $this->row('SELECT * FROM contract_client_requirement WHERE contract_client_requirement_id = :requirement_id AND contract_id = :contract_id FOR UPDATE', ['requirement_id' => $requirementId, 'contract_id' => $id]);
            if ($requirement === null) {
                throw new DomainException('Client requirement was not found for this contract.');
            }

            if ($action === 'MARK_NOT_APPLICABLE') {
                if ((string) $requirement['requirement_classification'] !== 'CONDITIONAL') {
                    throw new DomainException('Required client requirements cannot be marked not applicable.');
                }
                $this->pdo->prepare("UPDATE contract_client_requirement SET applicability_status = 'NOT_APPLICABLE', requirement_status = 'NOT_APPLICABLE', verification_status = 'NOT_REQUIRED', verified_by_user_id = NULL, verified_at = NULL, rejected_by_user_id = NULL, rejected_at = NULL, rejection_reason = NULL, updated_at = NOW() WHERE contract_client_requirement_id = :id")->execute(['id' => $requirementId]);
                $this->historyEvent($id, 'CLIENT_REQUIREMENT_MARKED_NOT_APPLICABLE', 'Conditional client requirement marked not applicable: ' . (string) $requirement['requirement_name'] . '.', null, null, (int) $user['id'], ['requirement_id' => $requirementId, 'reason' => $comment]);
            } elseif ($action === 'MARK_APPLICABLE') {
                if ((string) $requirement['requirement_classification'] !== 'CONDITIONAL') {
                    throw new DomainException('Only conditional client requirements use applicability decisions.');
                }
                $status = empty($requirement['uploaded_document_id']) ? 'MISSING' : 'SUBMITTED';
                $this->pdo->prepare("UPDATE contract_client_requirement SET applicability_status = 'APPLICABLE', requirement_status = :status, verification_status = IF(uploaded_document_id IS NULL, 'PENDING', verification_status), updated_at = NOW() WHERE contract_client_requirement_id = :id")->execute(['status' => $status, 'id' => $requirementId]);
                $this->historyEvent($id, 'CLIENT_REQUIREMENT_MARKED_APPLICABLE', 'Conditional client requirement marked applicable: ' . (string) $requirement['requirement_name'] . '.', null, null, (int) $user['id'], ['requirement_id' => $requirementId, 'reason' => $comment]);
            } elseif ($action === 'VERIFY') {
                if ((string) $requirement['applicability_status'] === 'NOT_APPLICABLE') {
                    throw new DomainException('Not applicable client requirements do not need verification.');
                }
                if (empty($requirement['uploaded_document_id']) || (string) $requirement['requirement_status'] !== 'SUBMITTED') {
                    throw new DomainException('Evidence must be submitted before this client requirement can be verified.');
                }
                $this->pdo->prepare("UPDATE contract_client_requirement SET requirement_status = 'VERIFIED', verification_status = 'VERIFIED', verified_by_user_id = :user_id, verified_at = NOW(), rejected_by_user_id = NULL, rejected_at = NULL, rejection_reason = NULL, updated_at = NOW() WHERE contract_client_requirement_id = :id")->execute(['user_id' => (int) $user['id'], 'id' => $requirementId]);
                $this->historyEvent($id, 'CLIENT_REQUIREMENT_VERIFIED', 'Client requirement verified: ' . (string) $requirement['requirement_name'] . '.', null, null, (int) $user['id'], ['requirement_id' => $requirementId, 'document_id' => (int) $requirement['uploaded_document_id']]);
            } else {
                if (empty($requirement['uploaded_document_id']) || (string) $requirement['requirement_status'] !== 'SUBMITTED') {
                    throw new DomainException('Evidence must be submitted before this client requirement can be rejected.');
                }
                $this->pdo->prepare("UPDATE contract_client_requirement SET requirement_status = 'REJECTED', verification_status = 'REJECTED', verified_by_user_id = NULL, verified_at = NULL, rejected_by_user_id = :user_id, rejected_at = NOW(), rejection_reason = :reason, updated_at = NOW() WHERE contract_client_requirement_id = :id")->execute(['user_id' => (int) $user['id'], 'reason' => $comment, 'id' => $requirementId]);
                $this->historyEvent($id, 'CLIENT_REQUIREMENT_REJECTED', 'Client requirement rejected: ' . (string) $requirement['requirement_name'] . '.', null, null, (int) $user['id'], ['requirement_id' => $requirementId, 'document_id' => (int) $requirement['uploaded_document_id'], 'reason' => $comment]);
            }

            $this->pdo->commit();
            return $this->show($id, $user);
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function create(array $data, array $user): array
    {
        ContractPolicy::requirePermission($user, 'contract.create');
        $this->rejectUnauthorizedFinancialInput($data, $user);
        $clean = $this->validateContract($data, true, null, $user);
        $this->pdo->beginTransaction();
        try {
            $number = $this->nextContractNumber();
            $statement = $this->pdo->prepare('INSERT INTO contract (contract_number, contract_type_id, template_id, template_version_id, contract_title, contract_description, counterparty_name, supplier_reference_id, budget_reference_id, procurement_request_id, purchase_order_reference_id, contract_owner_employee_reference_id, owning_department_reference_id, fam_handler_employee_reference_id, start_date, executed_date, effective_date, end_date, termination_date, termination_reason, original_amount, current_amount, currency_code, contract_status, notice_period_days, renewal_type, renewal_decision_date, renewed_from_contract_id, risk_level, created_by_user_id, updated_by_user_id, created_at, updated_at) VALUES (:number, :type_id, :template_id, :template_version_id, :title, :description, :counterparty_name, :supplier_id, :budget_id, :procurement_request_id, :purchase_order_id, :owner_id, :department_id, :handler_id, :start_date, NULL, :effective_date, :end_date, NULL, NULL, :original_amount, :current_amount, :currency, \'DRAFT\', :notice_days, :renewal_type, :renewal_decision_date, :renewed_from_id, :risk_level, :created_by, :updated_by, NOW(), NOW())');
            $statement->execute($clean + ['number' => $number, 'created_by' => (int) $user['id'], 'updated_by' => (int) $user['id']]);
            $id = (int) $this->pdo->lastInsertId();
            $metadata = ['contract_number' => $number];
            if ($clean['template_id'] !== null) {
                $metadata += ['template_id' => $clean['template_id'], 'template_version_id' => $clean['template_version_id']];
            }
            $this->historyEvent($id, 'CREATED', "Contract $number created.", null, 'DRAFT', (int) $user['id'], $metadata);
            if ($clean['template_id'] !== null) {
                $this->historyEvent($id, 'CONTRACT_TEMPLATE_ASSIGNED', 'Contract template assigned.', null, null, (int) $user['id'], ['template_id' => $clean['template_id'], 'template_version_id' => $clean['template_version_id']]);
            }
            $this->pdo->commit();
            return $this->show($id, $user) ?? ['id' => $id, 'contractNo' => $number];
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            if ($exception instanceof PDOException && $exception->getCode() === '23000') {
                throw new RuntimeException('Contract number conflict. Please retry.');
            }
            throw $exception;
        }
    }

    public function update(int $id, array $data, array $user): ?array
    {
        ContractPolicy::requirePermission($user, 'contract.edit');
        $before = $this->row('SELECT * FROM contract WHERE contract_id = :id AND deleted_at IS NULL LIMIT 1', ['id' => $id]);
        if ($before === null) {
            return null;
        }
        if (!in_array((string) $before['contract_status'], self::EDITABLE_STATUSES, true)) {
            throw new DomainException('This contract status is read-only for direct metadata edits.');
        }
        $this->rejectUnauthorizedFinancialInput($data, $user);
        $clean = $this->validateContract(array_merge($before, $data), false, $before, $user);
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare('UPDATE contract SET contract_type_id = :type_id, template_id = :template_id, template_version_id = :template_version_id, contract_title = :title, contract_description = :description, counterparty_name = :counterparty_name, supplier_reference_id = :supplier_id, budget_reference_id = :budget_id, procurement_request_id = :procurement_request_id, purchase_order_reference_id = :purchase_order_id, contract_owner_employee_reference_id = :owner_id, owning_department_reference_id = :department_id, fam_handler_employee_reference_id = :handler_id, start_date = :start_date, effective_date = :effective_date, end_date = :end_date, original_amount = :original_amount, current_amount = :current_amount, currency_code = :currency, notice_period_days = :notice_days, renewal_type = :renewal_type, renewal_decision_date = :renewal_decision_date, renewed_from_contract_id = :renewed_from_id, risk_level = :risk_level, updated_by_user_id = :user_id, updated_at = NOW() WHERE contract_id = :id AND deleted_at IS NULL');
            $statement->execute($clean + ['id' => $id, 'user_id' => (int) $user['id']]);
            $this->historyEvent($id, 'UPDATED', 'Contract draft metadata updated.', null, null, (int) $user['id']);
            if ((string) ($before['template_version_id'] ?? '') !== (string) ($clean['template_version_id'] ?? '')) {
                $this->historyEvent($id, 'CONTRACT_TEMPLATE_ASSIGNED', 'Contract template assignment updated.', null, null, (int) $user['id'], [
                    'from_template_id' => $before['template_id'] === null ? null : (int) $before['template_id'],
                    'from_template_version_id' => $before['template_version_id'] === null ? null : (int) $before['template_version_id'],
                    'template_id' => $clean['template_id'],
                    'template_version_id' => $clean['template_version_id'],
                ]);
            }
            $this->pdo->commit();
            return $this->show($id, $user);
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function transition(int $id, array $data, array $user): ?array
    {
        $target = strtoupper(trim((string) ($data['target_status'] ?? $data['status'] ?? '')));
        if (!in_array($target, self::STATUSES, true)) {
            throw new InvalidArgumentException(json_encode(['target_status' => 'Choose a supported contract lifecycle status.'], JSON_THROW_ON_ERROR));
        }
        $this->pdo->beginTransaction();
        try {
            $current = $this->row('SELECT * FROM contract WHERE contract_id = :id AND deleted_at IS NULL FOR UPDATE', ['id' => $id]);
            if ($current === null) {
                $this->pdo->rollBack();
                return null;
            }
            $from = (string) $current['contract_status'];
            if (isset($data['reason']) && is_scalar($data['reason'])) {
                $data['reason'] = trim((string) $data['reason']);
            }
            $this->assertTransition($current, $target, $data, $user);
            if ($target === 'FOR_REVIEW' && $this->googleDocuments()->hasWorkingDocument($id)) {
                $this->pdo->commit();
                try {
                    $this->googleDocuments()->finalizeForReview($id, $user);
                } catch (GoogleIntegrationException $exception) {
                    throw new DomainException($exception->getMessage(), 0, $exception);
                }
                $this->pdo->beginTransaction();
                $current = $this->row('SELECT * FROM contract WHERE contract_id = :id AND deleted_at IS NULL FOR UPDATE', ['id' => $id]);
                if ($current === null) {
                    $this->pdo->rollBack();
                    return null;
                }
            }
            $approvalRequestId = null;
            if ($target === 'FOR_APPROVAL') {
                $this->assertClientRequirementsReadyForApproval((int) $current['contract_id']);
                $this->assertContractDatesReadyForApproval($current);
                $this->assertSignedContractReadyForApproval($current);
                $approvalRequestId = $this->createApprovalWorkflow($current, $user);
            }
            $fields = ['contract_status = :status', 'updated_by_user_id = :user_id', 'updated_at = NOW()'];
            $params = ['status' => $target, 'user_id' => (int) $user['id'], 'id' => $id];
            if ($target === 'ACTIVE') {
                $fields[] = 'executed_date = :executed_date';
                $fields[] = 'effective_date = :effective_date';
                $params['executed_date'] = $this->requiredDate($data['executed_date'] ?? null, 'executed_date');
                $params['effective_date'] = $this->date($data['effective_date'] ?? $current['effective_date'] ?? $current['start_date']);
            }
            if ($target === 'TERMINATED') {
                $fields[] = 'termination_date = :termination_date';
                $fields[] = 'termination_reason = :termination_reason';
                $params['termination_date'] = $this->requiredDate($data['termination_date'] ?? null, 'termination_date');
                $params['termination_reason'] = $this->requiredText($data['termination_reason'] ?? $data['reason'] ?? '', 2000, 'termination_reason');
            }
            $this->pdo->prepare('UPDATE contract SET ' . implode(', ', $fields) . ' WHERE contract_id = :id AND deleted_at IS NULL')->execute($params);
            [$event, $description] = $this->transitionEvent($from, $target, $current, $data);
            $metadata = $this->transitionMetadata($target, $data);
            if ($approvalRequestId !== null) {
                $metadata['approval_request_id'] = $approvalRequestId;
                $metadata['signed_document_id'] = (int) $current['signed_document_id'];
                $metadata['signed_document_version_id'] = (int) $current['signed_document_version_id'];
            }
            $this->historyEvent($id, $event, $description, $from, $target, (int) $user['id'], $metadata);
            $this->pdo->commit();
            if ($target === 'FOR_REVIEW') {
                $this->tryAnalyzeContractDates($id, $user);
            }
            return $this->show($id, $user);
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function processAutomaticLifecycle(array $options = []): array
    {
        $dryRun = !empty($options['dry_run']);
        $today = $this->date($options['today'] ?? date('Y-m-d')) ?? date('Y-m-d');
        $result = ['activated' => 0, 'expired' => 0, 'skipped' => 0, 'failed' => 0, 'items' => []];

        foreach ($this->automaticActivationCandidates($today) as $candidate) {
            $outcome = $this->processAutomaticActivationCandidate((int) $candidate['contract_id'], $today, $dryRun);
            $result[$outcome['status']]++;
            $result['items'][] = $outcome;
        }

        foreach ($this->automaticExpirationCandidates($today) as $candidate) {
            $outcome = $this->processAutomaticExpirationCandidate((int) $candidate['contract_id'], $today, $dryRun);
            $result[$outcome['status']]++;
            $result['items'][] = $outcome;
        }

        return $result;
    }

    public function approvalAction(int $id, array $data, array $user): ?array
    {
        $action = strtoupper(trim((string) ($data['action'] ?? '')));
        if (!in_array($action, ['APPROVE','REJECT','RETURN'], true)) {
            throw new InvalidArgumentException(json_encode(['action' => 'Choose approve, reject, or return.'], JSON_THROW_ON_ERROR));
        }
        ContractPolicy::requirePermission($user, 'contract.approve');
        $comment = $this->nullableText($data['comment'] ?? $data['reason'] ?? '', 2000);
        if (in_array($action, ['REJECT','RETURN'], true) && $comment === null) {
            throw new InvalidArgumentException(json_encode(['reason' => 'Reason is required.'], JSON_THROW_ON_ERROR));
        }

        $this->pdo->beginTransaction();
        try {
            $contract = $this->row('SELECT * FROM contract WHERE contract_id = :id AND deleted_at IS NULL FOR UPDATE', ['id' => $id]);
            if ($contract === null) {
                $this->pdo->rollBack();
                return null;
            }
            if ((string) $contract['contract_status'] !== 'FOR_APPROVAL') {
                throw new DomainException('Contract is not awaiting approval.');
            }
            $request = $this->currentApprovalRequest($id, true);
            if ($request === null) {
                throw new DomainException('No active approval workflow exists for this contract.');
            }
            $step = $this->currentApprovalStep((int) $request['approval_request_id'], true);
            if ($step === null || (string) $step['step_status'] !== 'PENDING' || (string) $step['decision'] !== 'PENDING') {
                throw new DomainException('This approval step is no longer actionable.');
            }
            if (!$this->userCanActOnStep($user, $step)) {
                throw new DomainException('You are not authorized for the current approval step.');
            }

            if ($action === 'APPROVE') {
                if ((int) $step['step_number'] >= (int) $request['total_steps']) {
                    $this->assertClientRequirementsReadyForApproval((int) $contract['contract_id']);
                    $this->assertApprovalRequestSignedDocumentVersion($request);
                }
                $this->approveCurrentStep($contract, $request, $step, $comment, $user);
            } elseif ($action === 'REJECT') {
                $this->terminalApprovalAction($contract, $request, $step, 'REJECTED', 'REJECTED', 'APPROVAL_STEP_REJECTED', 'REJECTED', $comment, $user);
            } else {
                $this->terminalApprovalAction($contract, $request, $step, 'RETURNED', 'CANCELLED', 'RETURNED_FOR_CHANGES', 'DRAFT', $comment, $user);
            }

            $this->pdo->commit();
            return $this->show($id, $user);
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function employeeApprovalTask(int $taskId, array $user): array
    {
        if ($taskId < 1) {
            throw new InvalidArgumentException(json_encode(['task_id' => 'A valid approval task is required.'], JSON_THROW_ON_ERROR));
        }

        $context = $this->employeeApprovalViewContext($taskId, $user);

        return $this->employeeApprovalPayload($context, $user);
    }

    public function employeeApprovalAction(int $taskId, array $data, array $user): array
    {
        if ($taskId < 1) {
            throw new InvalidArgumentException(json_encode(['task_id' => 'A valid approval task is required.'], JSON_THROW_ON_ERROR));
        }
        $action = strtoupper(trim((string) ($data['action'] ?? '')));
        if (!in_array($action, ['APPROVE','REJECT','RETURN'], true)) {
            throw new InvalidArgumentException(json_encode(['action' => 'Choose approve, reject, or return.'], JSON_THROW_ON_ERROR));
        }
        $comment = $this->nullableText($data['comment'] ?? $data['reason'] ?? '', 2000);
        if (in_array($action, ['REJECT','RETURN'], true) && $comment === null) {
            throw new InvalidArgumentException(json_encode(['reason' => 'Reason is required.'], JSON_THROW_ON_ERROR));
        }

        $this->pdo->beginTransaction();
        try {
            $context = $this->employeeApprovalContext($taskId, $user, true);
            if (!$this->userHasStepAuthority($user, $context['contract'], $context['step'])) {
                throw new DomainException('You are not authorized for the current approval step.');
            }

            if ($action === 'APPROVE') {
                if ((int) $context['step']['step_number'] >= (int) $context['request']['total_steps']) {
                    $this->assertClientRequirementsReadyForApproval((int) $context['contract']['contract_id']);
                    $this->assertApprovalRequestSignedDocumentVersion($context['request']);
                }
                $this->approveCurrentStep($context['contract'], $context['request'], $context['step'], $comment, $user);
            } elseif ($action === 'REJECT') {
                $this->terminalApprovalAction($context['contract'], $context['request'], $context['step'], 'REJECTED', 'REJECTED', 'APPROVAL_STEP_REJECTED', 'REJECTED', $comment, $user);
            } else {
                $this->terminalApprovalAction($context['contract'], $context['request'], $context['step'], 'RETURNED', 'CANCELLED', 'RETURNED_FOR_CHANGES', 'DRAFT', $comment, $user);
            }

            $result = $this->approvalActionResult((int) $context['contract']['contract_id'], (int) $context['request']['approval_request_id']);
            $this->pdo->commit();
            return $result;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function confirmContractDates(int $id, array $data, array $user): ?array
    {
        ContractPolicy::requirePermission($user, 'contract.review');
        $this->assertContractDateAuthoritySchema();
        $startDate = $this->realContractDate($data['start_date'] ?? $data['effective_date'] ?? null, 'start_date');
        $endDate = $this->realContractDate($data['end_date'] ?? $data['expiration_date'] ?? null, 'end_date');
        if ($endDate < $startDate) {
            throw new InvalidArgumentException(json_encode(['end_date' => 'End date cannot be before start date.'], JSON_THROW_ON_ERROR));
        }

        $this->pdo->beginTransaction();
        try {
            $contract = $this->row('SELECT * FROM contract WHERE contract_id = :id AND deleted_at IS NULL FOR UPDATE', ['id' => $id]);
            if ($contract === null) {
                $this->pdo->rollBack();
                return null;
            }
            if ((string) $contract['contract_status'] !== 'FOR_REVIEW') {
                throw new DomainException('Contract dates can only be confirmed while the contract is for review.');
            }
            $source = $this->currentFinalizedContractDateSource($id);
            if ($source === null) {
                throw new DomainException('Finalize the contract document before confirming contract dates.');
            }

            $this->pdo->prepare("UPDATE document SET effective_date = :effective_date, expiration_date = :expiration_date, contract_metadata_status = 'CONFIRMED', contract_metadata_source = 'AI_EXTRACTED_CONFIRMED', contract_metadata_confirmed_by_user_id = :user_id, contract_metadata_confirmed_at = NOW(), updated_at = NOW() WHERE document_id = :document_id AND deleted_at IS NULL")->execute([
                'effective_date' => $startDate,
                'expiration_date' => $endDate,
                'user_id' => (int) $user['id'],
                'document_id' => (int) $source['document_id'],
            ]);
            $this->pdo->prepare('UPDATE contract SET start_date = :start_date, effective_date = :effective_date, end_date = :end_date, contract_dates_source_document_id = :document_id, contract_dates_source_document_version_id = :version_id, contract_dates_confirmed_by_user_id = :confirmed_by_user_id, contract_dates_confirmed_at = NOW(), updated_by_user_id = :updated_by_user_id, updated_at = NOW() WHERE contract_id = :id AND deleted_at IS NULL')->execute([
                'start_date' => $startDate,
                'effective_date' => $startDate,
                'end_date' => $endDate,
                'document_id' => (int) $source['document_id'],
                'version_id' => (int) $source['document_version_id'],
                'confirmed_by_user_id' => (int) $user['id'],
                'updated_by_user_id' => (int) $user['id'],
                'id' => $id,
            ]);
            $this->recalculateContractRetentionRecords($id, (int) $user['id']);
            $this->historyEvent($id, 'CONTRACT_DATES_CONFIRMED', 'Contract dates confirmed.', null, null, (int) $user['id'], [
                'document_id' => (int) $source['document_id'],
                'document_version_id' => (int) $source['document_version_id'],
                'proposed_start_date' => $this->candidateDate($source['candidate'] ?? [], 'effective_date'),
                'proposed_end_date' => $this->candidateDate($source['candidate'] ?? [], 'expiration_date'),
                'confirmed_start_date' => $startDate,
                'confirmed_end_date' => $endDate,
            ]);
            $this->pdo->commit();
            return $this->show($id, $user);
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function canViewCurrentApprovalEvidence(int $contractId, array $user): bool
    {
        try {
            $contract = $this->row('SELECT * FROM contract WHERE contract_id = :id AND deleted_at IS NULL LIMIT 1', ['id' => $contractId]);
            if ($contract === null || (string) $contract['contract_status'] !== 'FOR_APPROVAL') {
                return false;
            }
            $request = $this->currentApprovalRequest($contractId);
            if ($request === null) {
                return false;
            }
            $step = $this->currentApprovalStep((int) $request['approval_request_id']);
            if ($step === null || (string) $step['step_status'] !== 'PENDING' || (string) $step['decision'] !== 'PENDING') {
                return false;
            }
            if (ContractPolicy::hasPermission($user, 'contract.manage')) {
                return true;
            }
            if ($this->userCanActOnStep($user, $step)) {
                return true;
            }
            $task = $this->row("SELECT * FROM workflow_task WHERE approval_request_id = :request_id AND approval_step_id = :step_id AND module_code = 'contract_management' AND entity_type = 'contract' AND entity_id = :contract_id AND task_type = 'CONTRACT_APPROVAL' AND task_status IN ('PENDING','IN_PROGRESS') LIMIT 1", [
                'request_id' => (int) $request['approval_request_id'],
                'step_id' => (int) $step['approval_step_id'],
                'contract_id' => $contractId,
            ]);
            return $task !== null
                && $this->taskBelongsToUser($task, $user)
                && $this->taskBelongsToStep($task, $step)
                && $this->userHasStepAuthority($user, $contract, $step);
        } catch (Throwable) {
            return false;
        }
    }

    public function options(array $user): array
    {
        ContractPolicy::requirePermission($user, 'contract.view');
        return [
            'contract_types' => $this->rows("SELECT type_code id, type_code code, type_name name FROM contract_type WHERE status = 'ACTIVE' AND type_code IN ('CLIENT_CONTRACT','EMPLOYEE_CONTRACT','NDA','CONTRACT_AMENDMENT','OTHER') ORDER BY FIELD(type_code,'CLIENT_CONTRACT','EMPLOYEE_CONTRACT','NDA','CONTRACT_AMENDMENT','OTHER')"),
            'statuses' => self::STATUSES,
            'renewal_types' => self::RENEWAL_TYPES,
            'risk_levels' => self::RISK_LEVELS,
            'currencies' => self::CURRENCIES,
            'departments' => $this->rows("SELECT department_reference_id id, department_code code, department_name name FROM department_reference WHERE status = 'ACTIVE' ORDER BY department_name"),
            'contract_owners' => $this->eligibility()->famInternalHandlers(),
            'contract_administrators' => $this->eligibility()->famInternalHandlers(),
            'fam_handlers' => $this->eligibility()->famInternalHandlers(),
            'budgets' => $this->rows("SELECT budget_reference_id id, budget_code code, budget_name name, fiscal_year, available_amount, currency_code FROM budget_reference WHERE status = 'ACTIVE' ORDER BY fiscal_year DESC, budget_name"),
            'procurement_requests' => $this->rows("SELECT procurement_request_id id, request_number number, status, approval_status, estimated_total, currency_code FROM procurement_request WHERE deleted_at IS NULL ORDER BY created_at DESC LIMIT 200"),
            'purchase_orders' => $this->rows("SELECT purchase_order_reference_id id, purchase_order_number number, procurement_request_id procurementRequestId, total_amount, currency_code, purchase_order_status status FROM purchase_order_reference ORDER BY order_date DESC, purchase_order_reference_id DESC LIMIT 200"),
        ];
    }

    public function eligibleTemplates(array $query, array $user): array
    {
        if (!ContractPolicy::hasPermission($user, 'contract.create') && !ContractPolicy::hasPermission($user, 'contract.edit')) {
            jsonResponse(false, 'You do not have permission to perform this action.', [], 403);
        }
        $type = $this->contractTypeFromInput($query['contract_type_id'] ?? $query['contract_type'] ?? null, true);
        if ($type === null) {
            return ['items' => []];
        }
        $templateType = (string) $type['type_code'];
        if (!in_array($templateType, self::CANONICAL_CONTRACT_TYPES, true)) {
            return ['items' => []];
        }
        $statement = $this->pdo->prepare("SELECT dt.template_id, dt.template_code, dt.template_name, dt.template_type, current_tv.version_number, d.confidentiality_level FROM document_template dt INNER JOIN document_template_version current_tv ON current_tv.template_version_id = dt.current_approved_version_id AND current_tv.template_id = dt.template_id INNER JOIN document d ON d.document_id = current_tv.document_id AND d.deleted_at IS NULL WHERE dt.deleted_at IS NULL AND dt.status = 'ACTIVE' AND current_tv.status = 'ACTIVE' AND dt.template_type = :template_type ORDER BY dt.template_name");
        $statement->execute(['template_type' => $templateType]);
        return ['items' => array_map(fn (array $row): array => [
            'id' => (int) $row['template_id'],
            'templateCode' => (string) $row['template_code'],
            'templateName' => (string) $row['template_name'],
            'templateType' => (string) $row['template_type'],
            'currentVersion' => 'v' . (int) $row['version_number'],
            'confidentiality' => (string) $row['confidentiality_level'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC) ?: [])];
    }

    public function templateAuthoringContext(int $id, array $user, string $permission = 'contract.view'): ?array
    {
        ContractPolicy::requirePermission($user, $permission);
        $row = $this->row("SELECT c.contract_id, c.contract_number, c.contract_title, c.contract_status, c.counterparty_name, c.start_date, c.end_date, c.current_amount, c.currency_code, c.template_id, c.template_version_id, ct.type_code, s.supplier_name, linked_template.template_code, linked_template.template_name, linked_template.template_type, linked_template.status template_status, linked_template_version.version_number, linked_template_version.document_id, linked_template_version.document_version_id, linked_template_version.status template_version_status, linked_doc.confidentiality_level, linked_dv.file_name, linked_dv.file_extension, linked_dv.mime_type, linked_dv.file_size FROM contract c INNER JOIN contract_type ct ON ct.contract_type_id = c.contract_type_id LEFT JOIN supplier_reference s ON s.supplier_reference_id = c.supplier_reference_id LEFT JOIN document_template linked_template ON linked_template.template_id = c.template_id LEFT JOIN document_template_version linked_template_version ON linked_template_version.template_version_id = c.template_version_id AND linked_template_version.template_id = linked_template.template_id LEFT JOIN document linked_doc ON linked_doc.document_id = linked_template_version.document_id AND linked_doc.deleted_at IS NULL LEFT JOIN document_version linked_dv ON linked_dv.document_version_id = linked_template_version.document_version_id AND linked_dv.deleted_at IS NULL WHERE c.contract_id = :id AND c.deleted_at IS NULL LIMIT 1", ['id' => $id]);
        if ($row === null) {
            return null;
        }
        if ($row['template_id'] === null || $row['template_version_id'] === null) {
            return $row + ['authoring_error' => 'NO_TEMPLATE'];
        }
        if ($row['document_id'] === null || $row['document_version_id'] === null) {
            return $row + ['authoring_error' => 'MISSING_FILE'];
        }
        return $row;
    }

    public function templateAuthoringData(int $id, array $user): ?array
    {
        $context = $this->templateAuthoringContext($id, $user, 'contract.view');
        if ($context === null) {
            return null;
        }
        return $this->buildTemplateAuthoringPayload($context, $user);
    }

    public function saveTemplateValues(int $id, array $data, array $user): ?array
    {
        $context = $this->templateAuthoringContext($id, $user, 'contract.edit');
        if ($context === null) {
            return null;
        }
        if (($context['authoring_error'] ?? '') !== '') {
            throw new DomainException('This contract does not have an editable template assignment.');
        }
        if (!in_array((string) ($context['contract_status'] ?? ''), self::EDITABLE_STATUSES, true)) {
            throw new DomainException('Template values can only be edited while the contract is a draft.');
        }
        $payload = $this->buildTemplateAuthoringPayload($context, $user);
        if (($payload['preview']['mode'] ?? '') !== 'DOCX_PLACEHOLDER') {
            throw new DomainException('Placeholder authoring is unavailable for this template file type.');
        }
        $manualByCode = [];
        foreach ($payload['fields'] as $field) {
            if (($field['source'] ?? '') === 'MANUAL' && empty($field['unsupported'])) {
                $manualByCode[$field['fieldCode']] = $field;
            }
        }
        $incoming = is_array($data['values'] ?? null) ? $data['values'] : [];
        $clean = [];
        $errors = [];
        foreach ($incoming as $code => $value) {
            $code = (string) $code;
            if (!isset($manualByCode[$code])) {
                $errors['values'] = 'Only recognized manual template fields can be saved.';
                continue;
            }
            $text = $this->nullableText($value, 2000);
            $clean[$code] = $text ?? '';
        }
        if ($errors) {
            throw new InvalidArgumentException(json_encode($errors, JSON_THROW_ON_ERROR));
        }
        $before = $this->savedTemplateValues((int) $context['contract_id'], (int) $context['template_version_id']);
        $changed = [];
        $this->pdo->beginTransaction();
        try {
            foreach ($clean as $code => $value) {
                $field = $manualByCode[$code];
                if (($before[$code] ?? '') === $value) {
                    continue;
                }
                $changed[] = $code;
                $this->pdo->prepare("INSERT INTO contract_template_value (contract_id, template_id, template_version_id, merge_field_id, field_code, value_text, source_type, created_by_user_id, created_at, updated_by_user_id, updated_at) VALUES (:contract_id, :template_id, :template_version_id, :merge_field_id, :field_code, :value_text, 'MANUAL', :user_id, NOW(), :user_id_update, NOW()) ON DUPLICATE KEY UPDATE value_text = VALUES(value_text), source_type = 'MANUAL', updated_by_user_id = VALUES(updated_by_user_id), updated_at = NOW()")->execute([
                    'contract_id' => (int) $context['contract_id'],
                    'template_id' => (int) $context['template_id'],
                    'template_version_id' => (int) $context['template_version_id'],
                    'merge_field_id' => (int) $field['mergeFieldId'],
                    'field_code' => $code,
                    'value_text' => $value,
                    'user_id' => (int) $user['id'],
                    'user_id_update' => (int) $user['id'],
                ]);
            }
            if ($changed) {
                $this->historyEvent((int) $context['contract_id'], 'CONTRACT_TEMPLATE_VALUES_UPDATED', 'Contract template values updated.', null, null, (int) $user['id'], [
                    'template_id' => (int) $context['template_id'],
                    'template_version_id' => (int) $context['template_version_id'],
                    'changed_fields' => array_values($changed),
                    'changed_count' => count($changed),
                ]);
            }
            $this->pdo->commit();
            return $this->templateAuthoringData($id, $user);
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function googleDocumentStatus(int $id, array $user): array
    {
        return $this->googleDocuments()->status($id, $user);
    }

    public function createGoogleDocument(int $id, array $user): array
    {
        return $this->googleDocuments()->createWorkingDocument($id, $user);
    }

    public function syncGoogleDocument(int $id, array $user, string $format = 'docx'): array
    {
        return $this->googleDocuments()->syncWorkingDocument($id, $user, $format);
    }

    private function assertTransition(array $current, string $target, array $data, array $user): void
    {
        $from = (string) $current['contract_status'];
        if (!in_array($target, self::TRANSITIONS[$from] ?? [], true)) {
            throw new DomainException("Contract cannot move from $from to $target.");
        }
        if ($from === 'FOR_APPROVAL' && $target === 'APPROVED') {
            throw new DomainException('Contracts awaiting approval must be approved through the approval workflow.');
        }
        ContractPolicy::requirePermission($user, $this->permissionForTransition($target));
        if (in_array($target, ['CANCELLED', 'REJECTED', 'DRAFT'], true) && $from !== 'APPROVED') {
            $this->requiredText($data['reason'] ?? '', 1000, 'reason');
        }
        if ($target === 'FOR_REVIEW') {
            $this->validateReadyForReview($current);
        }
        if ($target === 'FOR_APPROVAL') {
            $this->validateReadyForReview($current);
        }
        if ($target === 'ACTIVE') {
            $this->validateActivation($current, $data);
        }
        if ($target === 'TERMINATED') {
            $date = $this->requiredDate($data['termination_date'] ?? null, 'termination_date');
            $this->requiredText($data['termination_reason'] ?? $data['reason'] ?? '', 2000, 'termination_reason');
            $start = (string) ($current['effective_date'] ?: $current['start_date']);
            if ($date < $start) {
                throw new InvalidArgumentException(json_encode(['termination_date' => 'Termination date cannot be before the effective/start date.'], JSON_THROW_ON_ERROR));
            }
        }
        if ($target === 'EXPIRED') {
            if ((string) $current['end_date'] >= date('Y-m-d')) {
                throw new DomainException('Active contracts can only be expired after the contractual end date has passed.');
            }
        }
    }

    private function permissionForTransition(string $target): string
    {
        return match ($target) {
            'FOR_REVIEW', 'CANCELLED' => 'contract.edit',
            'FOR_APPROVAL', 'DRAFT' => 'contract.review',
            'REJECTED' => 'contract.approve',
            'ACTIVE' => 'contract.activate',
            'TERMINATED' => 'contract.terminate',
            'EXPIRED', 'ARCHIVED' => 'contract.archive',
            default => 'contract.manage',
        };
    }

    private function validateReadyForReview(array $contract): void
    {
        $errors = [];
        foreach (['contract_type_id','owning_department_reference_id','contract_owner_employee_reference_id'] as $field) {
            if (empty($contract[$field])) {
                $errors[$field] = 'This field is required before review.';
            }
        }
        if ($errors) {
            throw new InvalidArgumentException(json_encode($errors, JSON_THROW_ON_ERROR));
        }
    }

    private function createApprovalWorkflow(array $contract, array $user): int
    {
        if ($this->currentApprovalRequest((int) $contract['contract_id'], true) !== null) {
            throw new DomainException('An active approval workflow already exists for this contract.');
        }
        $route = $this->approvalRoute($contract);
        if ($route === []) {
            throw new DomainException('No valid approval route is configured for this contract.');
        }

        $statement = $this->pdo->prepare("INSERT INTO approval_request (module_code, entity_type, entity_id, request_reference, requested_by_user_id, requested_by_employee_reference_id, approval_status, current_step_number, total_steps, submitted_at, remarks, signed_document_id, signed_document_version_id, created_at, updated_at) VALUES ('contract_management', 'contract', :contract_id, :reference, :user_id, :employee_id, 'PENDING', 1, :total_steps, NOW(), :remarks, :signed_document_id, :signed_document_version_id, NOW(), NOW())");
        $statement->execute([
            'contract_id' => (int) $contract['contract_id'],
            'reference' => (string) $contract['contract_number'],
            'user_id' => (int) $user['id'],
            'employee_id' => (int) ($user['employee_id'] ?? 0) ?: null,
            'total_steps' => count($route),
            'remarks' => 'Contract approval workflow created.',
            'signed_document_id' => (int) $contract['signed_document_id'],
            'signed_document_version_id' => (int) $contract['signed_document_version_id'],
        ]);
        $requestId = (int) $this->pdo->lastInsertId();
        foreach ($route as $index => $step) {
            $stepNumber = $index + 1;
            $this->pdo->prepare("INSERT INTO approval_step (approval_request_id, step_number, step_name, approver_role_id, approver_user_id, approver_employee_reference_id, is_required, step_status, decision, assigned_at, created_at, updated_at) VALUES (:request_id, :step_number, :step_name, :role_id, :user_id, :employee_id, 1, 'PENDING', 'PENDING', :assigned_at, NOW(), NOW())")->execute([
                'request_id' => $requestId,
                'step_number' => $stepNumber,
                'step_name' => $step['name'],
                'role_id' => $step['role_id'],
                'user_id' => $step['user_id'],
                'employee_id' => $step['employee_id'],
                'assigned_at' => $stepNumber === 1 ? date('Y-m-d H:i:s') : null,
            ]);
            $stepId = (int) $this->pdo->lastInsertId();
            if ($stepNumber === 1) {
                $taskId = $this->createWorkflowTask($contract, $requestId, $stepId, $step);
                $this->notifyApprover($contract, $step, 'CONTRACT_APPROVAL_READY', 'Contract approval ready', 'A contract approval step is ready for your action.', $taskId);
            }
        }
        return $requestId;
    }

    private function approvalRoute(array $contract): array
    {
        return [
            $this->roleStep('FAM Contract Approval', 'contract.approve', 'FAM contract approval authority is not configured.'),
        ];
    }

    private function departmentHeadStep(array $contract): array
    {
        $row = $this->row("SELECT d.department_head_employee_reference_id employee_id, e.full_name approver_name, ua.user_account_id user_id FROM department_reference d INNER JOIN employee_reference e ON e.employee_reference_id = d.department_head_employee_reference_id AND e.employment_status = 'ACTIVE' AND e.deleted_at IS NULL INNER JOIN user_account ua ON ua.employee_reference_id = e.employee_reference_id AND ua.account_status = 'ACTIVE' AND ua.deleted_at IS NULL WHERE d.department_reference_id = :department_id AND d.status = 'ACTIVE' LIMIT 1", ['department_id' => (int) $contract['owning_department_reference_id']]);
        if ($row === null) {
            throw new DomainException('Owning department has no active approval authority configured.');
        }
        return [
            'name' => 'Owning Department Head Approval',
            'employee_id' => (int) $row['employee_id'],
            'user_id' => (int) $row['user_id'],
            'role_id' => null,
            'display' => (string) $row['approver_name'],
        ];
    }

    private function roleStep(string $name, string $permission, string $message): array
    {
        $row = $this->row("SELECT ua.user_account_id user_id, e.employee_reference_id employee_id, e.full_name approver_name, r.role_id role_id FROM user_account ua INNER JOIN employee_reference e ON e.employee_reference_id = ua.employee_reference_id AND e.employment_status = 'ACTIVE' AND e.deleted_at IS NULL INNER JOIN user_role ur ON ur.user_account_id = ua.user_account_id AND (ur.expires_at IS NULL OR ur.expires_at > NOW()) INNER JOIN role r ON r.role_id = ur.role_id AND r.status = 'ACTIVE' INNER JOIN role_permission rp ON rp.role_id = r.role_id INNER JOIN permission p ON p.permission_id = rp.permission_id AND p.permission_code = :permission WHERE ua.account_status = 'ACTIVE' AND ua.deleted_at IS NULL ORDER BY r.role_code = 'FAM_SUPER_ADMIN' DESC, e.full_name LIMIT 1", ['permission' => $permission]);
        if ($row === null) {
            throw new DomainException($message);
        }
        return [
            'name' => $name,
            'employee_id' => (int) $row['employee_id'],
            'user_id' => (int) $row['user_id'],
            'role_id' => (int) $row['role_id'],
            'display' => (string) $row['approver_name'],
        ];
    }

    private function googleDocuments(): ContractGoogleDocumentService
    {
        $oauth = new GoogleOAuthService($this->pdo);
        return new ContractGoogleDocumentService($this->pdo, $oauth, new GoogleDriveService($oauth), new DocumentService($this->pdo));
    }

    private function financeDepartmentHeadStep(): array
    {
        $row = $this->row(<<<'SQL'
SELECT
    d.department_head_employee_reference_id employee_id,
    e.full_name approver_name,
    ua.user_account_id user_id
FROM department_reference d
INNER JOIN employee_reference e
    ON e.employee_reference_id = d.department_head_employee_reference_id
    AND e.employment_status = 'ACTIVE'
    AND e.deleted_at IS NULL
INNER JOIN user_account ua
    ON ua.employee_reference_id = e.employee_reference_id
    AND ua.account_status = 'ACTIVE'
    AND ua.deleted_at IS NULL
INNER JOIN user_role ur
    ON ur.user_account_id = ua.user_account_id
    AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
INNER JOIN role r
    ON r.role_id = ur.role_id
    AND r.status = 'ACTIVE'
    AND r.role_code = 'DEPARTMENT_HEAD'
WHERE d.status = 'ACTIVE'
  AND UPPER(d.department_code) = 'DEP-FIN'
LIMIT 1
SQL);
        if ($row === null) {
            throw new DomainException('Finance department has no active budget approval authority configured.');
        }
        return [
            'name' => 'Finance/Budget Approval',
            'employee_id' => (int) $row['employee_id'],
            'user_id' => (int) $row['user_id'],
            'role_id' => null,
            'display' => (string) $row['approver_name'],
        ];
    }

    private function approveCurrentStep(array $contract, array $request, array $step, ?string $comment, array $user): void
    {
        $requestId = (int) $request['approval_request_id'];
        $stepNumber = (int) $step['step_number'];
        $this->completeStep((int) $step['approval_step_id'], 'APPROVED', $comment);
        $this->closeWorkflowTask((int) $step['approval_step_id'], (int) $user['id'], $comment);
        $this->historyEvent((int) $contract['contract_id'], 'APPROVAL_STEP_APPROVED', (string) $step['step_name'] . ' approved.', 'FOR_APPROVAL', 'FOR_APPROVAL', (int) $user['id'], ['approval_request_id' => $requestId, 'approval_step_id' => (int) $step['approval_step_id'], 'comment' => $comment]);

        if ($stepNumber >= (int) $request['total_steps']) {
            $this->pdo->prepare("UPDATE approval_request SET approval_status = 'APPROVED', completed_at = NOW(), decided_at = NOW(), updated_at = NOW() WHERE approval_request_id = :id")->execute(['id' => $requestId]);
            $this->pdo->prepare("UPDATE contract SET contract_status = 'APPROVED', updated_by_user_id = :user_id, updated_at = NOW() WHERE contract_id = :contract_id AND contract_status = 'FOR_APPROVAL'")->execute(['user_id' => (int) $user['id'], 'contract_id' => (int) $contract['contract_id']]);
            $this->historyEvent((int) $contract['contract_id'], 'APPROVED', 'Contract ' . (string) $contract['contract_number'] . ' approved by completed workflow.', 'FOR_APPROVAL', 'APPROVED', (int) $user['id'], ['approval_request_id' => $requestId]);
            $this->auditApproval($user, 'CONTRACT_APPROVED', $contract, $requestId, (int) $step['approval_step_id'], 'SUCCESS', $comment);
            return;
        }

        $nextNumber = $stepNumber + 1;
        $next = $this->row('SELECT * FROM approval_step WHERE approval_request_id = :request_id AND step_number = :step_number FOR UPDATE', ['request_id' => $requestId, 'step_number' => $nextNumber]);
        if ($next === null) {
            throw new DomainException('Next approval step is missing.');
        }
        $this->pdo->prepare('UPDATE approval_request SET current_step_number = :step_number, updated_at = NOW() WHERE approval_request_id = :id')->execute(['step_number' => $nextNumber, 'id' => $requestId]);
        $this->pdo->prepare('UPDATE approval_step SET assigned_at = COALESCE(assigned_at, NOW()), updated_at = NOW() WHERE approval_step_id = :id')->execute(['id' => (int) $next['approval_step_id']]);
        $nextRoute = ['name' => (string) $next['step_name'], 'employee_id' => $next['approver_employee_reference_id'], 'user_id' => $next['approver_user_id'], 'role_id' => $next['approver_role_id']];
        $taskId = $this->createWorkflowTask($contract, $requestId, (int) $next['approval_step_id'], $nextRoute);
        $this->notifyApprover($contract, $nextRoute, 'CONTRACT_APPROVAL_READY', 'Contract approval ready', 'A contract approval step is ready for your action.', $taskId);
        $this->auditApproval($user, 'CONTRACT_APPROVAL_STEP_APPROVED', $contract, $requestId, (int) $step['approval_step_id'], 'SUCCESS', $comment);
    }

    private function terminalApprovalAction(array $contract, array $request, array $step, string $requestStatus, string $stepDecision, string $event, string $targetStatus, ?string $comment, array $user): void
    {
        $requestId = (int) $request['approval_request_id'];
        $this->completeStep((int) $step['approval_step_id'], $stepDecision, $comment);
        $this->closeWorkflowTask((int) $step['approval_step_id'], (int) $user['id'], $comment);
        $this->pdo->prepare('UPDATE approval_step SET step_status = :status, decision = :decision, updated_at = NOW() WHERE approval_request_id = :request_id AND step_status = \'PENDING\' AND approval_step_id <> :step_id')->execute(['status' => $requestStatus, 'decision' => $stepDecision, 'request_id' => $requestId, 'step_id' => (int) $step['approval_step_id']]);
        $this->pdo->prepare('UPDATE approval_request SET approval_status = :status, decided_at = NOW(), completed_at = NOW(), cancelled_at = IF(:cancel_status = \'RETURNED\', NOW(), cancelled_at), remarks = :remarks, updated_at = NOW() WHERE approval_request_id = :id')->execute(['status' => $requestStatus, 'cancel_status' => $requestStatus, 'remarks' => $comment, 'id' => $requestId]);
        $this->pdo->prepare("UPDATE workflow_task SET task_status = 'CANCELLED', completed_at = NOW(), completed_by_user_id = :user_id, completion_notes = :notes, updated_at = NOW() WHERE approval_request_id = :request_id AND task_status IN ('PENDING','IN_PROGRESS')")->execute(['user_id' => (int) $user['id'], 'notes' => $comment, 'request_id' => $requestId]);
        $this->pdo->prepare('UPDATE contract SET contract_status = :status, updated_by_user_id = :user_id, updated_at = NOW() WHERE contract_id = :contract_id AND contract_status = \'FOR_APPROVAL\'')->execute(['status' => $targetStatus, 'user_id' => (int) $user['id'], 'contract_id' => (int) $contract['contract_id']]);
        $this->historyEvent((int) $contract['contract_id'], $event, (string) $step['step_name'] . ' ' . strtolower(str_replace('_', ' ', $requestStatus)) . '.', 'FOR_APPROVAL', $targetStatus, (int) $user['id'], ['approval_request_id' => $requestId, 'approval_step_id' => (int) $step['approval_step_id'], 'reason' => $comment]);
        $this->auditApproval($user, 'CONTRACT_APPROVAL_' . $requestStatus, $contract, $requestId, (int) $step['approval_step_id'], 'SUCCESS', $comment);
    }

    private function completeStep(int $stepId, string $decision, ?string $comment): void
    {
        $this->pdo->prepare('UPDATE approval_step SET step_status = :status, decision = :decision, decision_comments = :comments, decided_at = NOW(), updated_at = NOW() WHERE approval_step_id = :id AND step_status = \'PENDING\' AND decision = \'PENDING\'')->execute([
            'status' => $decision,
            'decision' => $decision,
            'comments' => $comment,
            'id' => $stepId,
        ]);
    }

    private function currentApprovalRequest(int $contractId, bool $forUpdate = false): ?array
    {
        return $this->row("SELECT * FROM approval_request WHERE module_code = 'contract_management' AND entity_type = 'contract' AND entity_id = :id AND approval_status = 'PENDING' ORDER BY approval_request_id DESC LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : ''), ['id' => $contractId]);
    }

    private function currentApprovalStep(int $requestId, bool $forUpdate = false): ?array
    {
        return $this->row("SELECT s.* FROM approval_request r INNER JOIN approval_step s ON s.approval_request_id = r.approval_request_id AND s.step_number = r.current_step_number WHERE r.approval_request_id = :id LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : ''), ['id' => $requestId]);
    }

    private function userCanActOnStep(array $user, array $step): bool
    {
        if (!ContractPolicy::hasPermission($user, 'contract.approve')) {
            return false;
        }
        if (!empty($step['approver_user_id']) && (int) $step['approver_user_id'] === (int) $user['id']) {
            return true;
        }
        return !empty($step['approver_employee_reference_id']) && (int) $step['approver_employee_reference_id'] === (int) ($user['employee_id'] ?? 0);
    }

    private function approvalActionResult(int $contractId, int $requestId): array
    {
        $contract = $this->row('SELECT contract_id, contract_number, contract_status FROM contract WHERE contract_id = :id LIMIT 1', ['id' => $contractId]);
        $request = $this->row('SELECT approval_request_id, approval_status, current_step_number, total_steps FROM approval_request WHERE approval_request_id = :id LIMIT 1', ['id' => $requestId]);

        return [
            'taskCompleted' => true,
            'contract_id' => $contractId,
            'contract_number' => (string) ($contract['contract_number'] ?? ''),
            'contract_status' => (string) ($contract['contract_status'] ?? ''),
            'approval_status' => (string) ($request['approval_status'] ?? ''),
            'current_step_number' => isset($request['current_step_number']) ? (int) $request['current_step_number'] : null,
            'total_steps' => isset($request['total_steps']) ? (int) $request['total_steps'] : null,
        ];
    }

    private function employeeApprovalContext(int $taskId, array $user, bool $forUpdate): array
    {
        $lock = $forUpdate ? ' FOR UPDATE' : '';
        $task = $this->row("SELECT * FROM workflow_task WHERE workflow_task_id = :id AND module_code = 'contract_management' AND entity_type = 'contract' AND task_type = 'CONTRACT_APPROVAL' AND task_status IN ('PENDING','IN_PROGRESS')$lock", ['id' => $taskId]);
        if ($task === null || !$this->taskBelongsToUser($task, $user)) {
            throw new DomainException('Approval task not found.');
        }

        $request = $this->row("SELECT * FROM approval_request WHERE approval_request_id = :id AND module_code = 'contract_management' AND entity_type = 'contract' AND approval_status = 'PENDING'$lock", ['id' => (int) $task['approval_request_id']]);
        if ($request === null) {
            throw new DomainException('Approval task is no longer active.');
        }

        $step = $this->row("SELECT * FROM approval_step WHERE approval_step_id = :id AND approval_request_id = :request_id$lock", [
            'id' => (int) $task['approval_step_id'],
            'request_id' => (int) $request['approval_request_id'],
        ]);
        if ($step === null || (int) $step['step_number'] !== (int) $request['current_step_number'] || (string) $step['step_status'] !== 'PENDING' || (string) $step['decision'] !== 'PENDING') {
            throw new DomainException('This approval step is no longer actionable.');
        }
        if (!$this->taskBelongsToStep($task, $step)) {
            throw new DomainException('Approval task is not assigned to the current approval step.');
        }

        $contract = $this->row("SELECT * FROM contract WHERE contract_id = :id AND deleted_at IS NULL$lock", ['id' => (int) $request['entity_id']]);
        if ($contract === null || (int) $contract['contract_id'] !== (int) $task['entity_id']) {
            throw new DomainException('Contract approval task is invalid.');
        }
        if ((string) $contract['contract_status'] !== 'FOR_APPROVAL') {
            throw new DomainException('Contract is not awaiting approval.');
        }

        return ['task' => $task, 'request' => $request, 'step' => $step, 'contract' => $contract];
    }

    private function employeeApprovalViewContext(int $taskId, array $user): array
    {
        $task = $this->row("SELECT * FROM workflow_task WHERE workflow_task_id = :id AND module_code = 'contract_management' AND entity_type = 'contract' AND task_type = 'CONTRACT_APPROVAL'", ['id' => $taskId]);
        if ($task === null || !$this->taskBelongsToUser($task, $user)) {
            throw new DomainException('Approval task not found.');
        }

        $request = $this->row("SELECT * FROM approval_request WHERE approval_request_id = :id AND module_code = 'contract_management' AND entity_type = 'contract'", ['id' => (int) $task['approval_request_id']]);
        if ($request === null) {
            throw new DomainException('Approval task is no longer available.');
        }

        $step = $this->row('SELECT * FROM approval_step WHERE approval_step_id = :id AND approval_request_id = :request_id', [
            'id' => (int) $task['approval_step_id'],
            'request_id' => (int) $request['approval_request_id'],
        ]);
        if ($step === null || !$this->taskBelongsToStep($task, $step)) {
            throw new DomainException('Approval task assignment is invalid.');
        }

        $contract = $this->row('SELECT * FROM contract WHERE contract_id = :id AND deleted_at IS NULL', ['id' => (int) $request['entity_id']]);
        if ($contract === null || (int) $contract['contract_id'] !== (int) $task['entity_id']) {
            throw new DomainException('Contract approval task is invalid.');
        }

        return ['task' => $task, 'request' => $request, 'step' => $step, 'contract' => $contract];
    }

    private function taskBelongsToUser(array $task, array $user): bool
    {
        return (!empty($task['assigned_to_user_id']) && (int) $task['assigned_to_user_id'] === (int) $user['id'])
            || (!empty($task['assigned_to_employee_reference_id']) && (int) $task['assigned_to_employee_reference_id'] === (int) ($user['employee_id'] ?? 0));
    }

    private function taskBelongsToStep(array $task, array $step): bool
    {
        return (!empty($step['approver_user_id']) && (int) $step['approver_user_id'] === (int) $task['assigned_to_user_id'])
            || (!empty($step['approver_employee_reference_id']) && (int) $step['approver_employee_reference_id'] === (int) $task['assigned_to_employee_reference_id']);
    }

    private function userHasStepAuthority(array $user, array $contract, array $step): bool
    {
        if (!empty($step['approver_user_id']) && (int) $step['approver_user_id'] !== (int) $user['id']) {
            return false;
        }
        if (!empty($step['approver_employee_reference_id']) && (int) $step['approver_employee_reference_id'] !== (int) ($user['employee_id'] ?? 0)) {
            return false;
        }

        $stepName = (string) $step['step_name'];
        if ($stepName === 'Owning Department Head Approval') {
            $headId = (int) $this->scalar('SELECT COALESCE(department_head_employee_reference_id, 0) FROM department_reference WHERE department_reference_id = :id AND status = \'ACTIVE\'', ['id' => (int) $contract['owning_department_reference_id']]);
            return $headId > 0 && $headId === (int) ($user['employee_id'] ?? 0);
        }
        if ($stepName === 'Finance/Budget Approval') {
            return (int) $this->scalar("SELECT COUNT(*) FROM department_reference d INNER JOIN user_account ua ON ua.employee_reference_id = d.department_head_employee_reference_id AND ua.account_status = 'ACTIVE' AND ua.deleted_at IS NULL INNER JOIN user_role ur ON ur.user_account_id = ua.user_account_id AND (ur.expires_at IS NULL OR ur.expires_at > NOW()) INNER JOIN role r ON r.role_id = ur.role_id AND r.status = 'ACTIVE' AND r.role_code = 'DEPARTMENT_HEAD' WHERE d.status = 'ACTIVE' AND UPPER(d.department_code) = 'DEP-FIN' AND d.department_head_employee_reference_id = :employee_id AND ua.user_account_id = :user_id", ['employee_id' => (int) ($user['employee_id'] ?? 0), 'user_id' => (int) $user['id']]) > 0;
        }

        $permission = match ($stepName) {
            'Procurement Approval' => 'procurement.approve',
            'Legal Approval' => 'legal.manage',
            'FAM Contract Approval' => 'contract.approve',
            default => '',
        };

        if ($permission === 'contract.approve') {
            return ContractPolicy::hasPermission($user, $permission);
        }

        return $permission !== '' && $this->hasUserPermission($user, $permission);
    }

    private function hasUserPermission(array $user, string $permission): bool
    {
        if (in_array($permission, $user['permissions'] ?? [], true)) {
            return true;
        }
        [$module] = explode('.', $permission, 2);
        return in_array($module . '.manage', $user['permissions'] ?? [], true);
    }

    private function employeeApprovalPayload(array $context, array $user): array
    {
        $contractRow = $this->row($this->baseSelect($this->selectColumns()) . ' WHERE c.contract_id = :id AND c.deleted_at IS NULL GROUP BY c.contract_id LIMIT 1', ['id' => (int) $context['contract']['contract_id']]);
        if ($contractRow === null) {
            throw new DomainException('Contract approval task is invalid.');
        }

        $steps = $this->approvalSteps((int) $context['request']['approval_request_id']);
        $contract = $this->shape($contractRow, $user, false);

        $isActionable = in_array((string) $context['task']['task_status'], ['PENDING','IN_PROGRESS'], true)
            && (string) $context['request']['approval_status'] === 'PENDING'
            && (int) $context['request']['current_step_number'] === (int) $context['step']['step_number']
            && (string) $context['step']['step_status'] === 'PENDING'
            && (string) $context['step']['decision'] === 'PENDING'
            && (string) $context['contract']['contract_status'] === 'FOR_APPROVAL'
            && $this->userHasStepAuthority($user, $context['contract'], $context['step']);

        return [
            'task' => [
                'id' => (int) $context['task']['workflow_task_id'],
                'number' => (string) $context['task']['task_number'],
                'title' => (string) $context['task']['task_title'],
                'status' => (string) $context['task']['task_status'],
                'assigned_at' => $context['step']['assigned_at'] ?? $context['task']['created_at'],
                'completed_at' => $context['task']['completed_at'],
                'is_actionable' => $isActionable,
            ],
            'contract' => $contractPayload,
            'approval' => [
                'request_id' => (int) $context['request']['approval_request_id'],
                'status' => (string) $context['request']['approval_status'],
                'current_step_number' => (int) $context['request']['current_step_number'],
                'total_steps' => (int) $context['request']['total_steps'],
                'current_step' => [
                    'id' => (int) $context['step']['approval_step_id'],
                    'name' => (string) $context['step']['step_name'],
                    'status' => (string) $context['step']['step_status'],
                    'decision' => (string) $context['step']['decision'],
                    'assigned_at' => $context['step']['assigned_at'],
                    'decided_at' => $context['step']['decided_at'],
                    'approver' => (string) ($user['full_name'] ?? $user['username'] ?? 'Assigned approver'),
                ],
                'steps' => $steps,
                'available_actions' => $isActionable ? ['approve', 'reject', 'return'] : [],
            ],
        ];
    }

    private function approvalSummary(int $contractId, array $user, string $contractStatus): array
    {
        $request = $this->authoritativeApprovalRequest($contractId, $contractStatus);
        $previousRequests = $this->previousApprovalRequests($contractId, (int) ($request['approval_request_id'] ?? 0));
        if ($request === null) {
            return [
                'required' => false,
                'status' => 'NOT_SUBMITTED',
                'requestId' => null,
                'currentStep' => null,
                'totalSteps' => 0,
                'completedSteps' => 0,
                'canApproveCurrentStep' => false,
                'canRejectCurrentStep' => false,
                'canReturnCurrentStep' => false,
                'steps' => [],
                'previousRequests' => $previousRequests,
            ];
        }
        $steps = $this->approvalSteps((int) $request['approval_request_id']);
        $current = null;
        foreach ($steps as $step) {
            if ((int) $step['sequence'] === (int) $request['current_step_number']) {
                $current = $step;
                break;
            }
        }
        $canAct = $current !== null && (string) $request['approval_status'] === 'PENDING' && $this->userCanActOnStep($user, [
            'approver_user_id' => $current['approverUserId'],
            'approver_employee_reference_id' => $current['approverEmployeeId'],
        ]);
        return [
            'required' => true,
            'requestId' => (int) $request['approval_request_id'],
            'status' => (string) $request['approval_status'],
            'currentStep' => $current,
            'totalSteps' => (int) $request['total_steps'],
            'completedSteps' => count(array_filter($steps, static fn (array $step): bool => in_array((string) $step['status'], ['APPROVED','REJECTED','RETURNED','CANCELLED'], true))),
            'canApproveCurrentStep' => $canAct,
            'canRejectCurrentStep' => $canAct,
            'canReturnCurrentStep' => $canAct,
            'steps' => $steps,
            'submittedAt' => (string) $request['submitted_at'],
            'completedAt' => $request['completed_at'],
            'signedDocumentId' => empty($request['signed_document_id'] ?? null) ? null : (int) $request['signed_document_id'],
            'signedDocumentVersionId' => empty($request['signed_document_version_id'] ?? null) ? null : (int) $request['signed_document_version_id'],
            'previousRequests' => $previousRequests,
        ];
    }

    private function approvalSteps(int $requestId): array
    {
        return array_map(fn (array $row): array => [
            'id' => (int) $row['approval_step_id'],
            'sequence' => (int) $row['step_number'],
            'name' => (string) $row['step_name'],
            'approverDisplay' => (string) ($row['approver_name'] ?? $row['role_name'] ?? 'Configured approver'),
            'approverEmployeeId' => $row['approver_employee_reference_id'] === null ? null : (int) $row['approver_employee_reference_id'],
            'approverUserId' => $row['approver_user_id'] === null ? null : (int) $row['approver_user_id'],
            'status' => (string) $row['step_status'],
            'decision' => (string) ($row['decision'] ?? 'PENDING'),
            'actedBy' => null,
            'actedAt' => $row['decided_at'],
            'comment' => $row['decision_comments'],
            'assignedAt' => $row['assigned_at'],
        ], $this->rows("SELECT s.*, e.full_name approver_name, r.role_name FROM approval_step s LEFT JOIN employee_reference e ON e.employee_reference_id = s.approver_employee_reference_id LEFT JOIN role r ON r.role_id = s.approver_role_id WHERE s.approval_request_id = :id ORDER BY s.step_number", ['id' => $requestId]));
    }

    private function authoritativeApprovalRequest(int $contractId, string $contractStatus): ?array
    {
        $status = strtoupper($contractStatus);
        if ($status === 'FOR_APPROVAL') {
            return $this->currentApprovalRequest($contractId);
        }
        if ($status === 'APPROVED') {
            return $this->row("SELECT * FROM approval_request WHERE module_code = 'contract_management' AND entity_type = 'contract' AND entity_id = :id AND approval_status = 'APPROVED' ORDER BY completed_at DESC, approval_request_id DESC LIMIT 1", ['id' => $contractId]);
        }
        if ($status === 'REJECTED') {
            return $this->row("SELECT * FROM approval_request WHERE module_code = 'contract_management' AND entity_type = 'contract' AND entity_id = :id AND approval_status = 'REJECTED' ORDER BY completed_at DESC, approval_request_id DESC LIMIT 1", ['id' => $contractId]);
        }

        return $this->currentApprovalRequest($contractId);
    }

    private function previousApprovalRequests(int $contractId, int $currentRequestId): array
    {
        return array_map(static fn (array $row): array => [
            'requestId' => (int) $row['approval_request_id'],
            'status' => (string) $row['approval_status'],
            'submittedAt' => (string) $row['submitted_at'],
            'completedAt' => $row['completed_at'],
            'totalSteps' => (int) $row['total_steps'],
        ], $this->rows("SELECT approval_request_id, approval_status, submitted_at, completed_at, total_steps FROM approval_request WHERE module_code = 'contract_management' AND entity_type = 'contract' AND entity_id = :id AND (:current_id_filter = 0 OR approval_request_id <> :current_id_value) ORDER BY approval_request_id DESC LIMIT 10", ['id' => $contractId, 'current_id_filter' => $currentRequestId, 'current_id_value' => $currentRequestId]));
    }

    private function createWorkflowTask(array $contract, int $requestId, int $stepId, array $step): int
    {
        $existing = $this->row("SELECT workflow_task_id FROM workflow_task WHERE approval_step_id = :step_id AND task_status IN ('PENDING','IN_PROGRESS') ORDER BY workflow_task_id DESC LIMIT 1", ['step_id' => $stepId]);
        if ($existing !== null) {
            return (int) $existing['workflow_task_id'];
        }
        $this->pdo->prepare("INSERT INTO workflow_task (task_number, module_code, entity_type, entity_id, entity_reference, approval_request_id, approval_step_id, task_type, task_title, task_description, assigned_to_user_id, assigned_to_employee_reference_id, assigned_to_role_id, priority, task_status, created_at, updated_at) VALUES (:task_number, 'contract_management', 'contract', :contract_id, :reference, :request_id, :step_id, 'CONTRACT_APPROVAL', :title, :description, :user_id, :employee_id, :role_id, 'HIGH', 'PENDING', NOW(), NOW())")->execute([
            'task_number' => $this->nextTaskNumber(),
            'contract_id' => (int) $contract['contract_id'],
            'reference' => (string) $contract['contract_number'],
            'request_id' => $requestId,
            'step_id' => $stepId,
            'title' => (string) $step['name'],
            'description' => 'Approve contract ' . (string) $contract['contract_number'] . '.',
            'user_id' => $step['user_id'],
            'employee_id' => $step['employee_id'],
            'role_id' => $step['role_id'],
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private function closeWorkflowTask(int $stepId, int $userId, ?string $notes): void
    {
        $this->pdo->prepare("UPDATE workflow_task SET task_status = 'COMPLETED', completed_at = NOW(), completed_by_user_id = :user_id, completion_notes = :notes, updated_at = NOW() WHERE approval_step_id = :step_id AND task_status IN ('PENDING','IN_PROGRESS')")->execute(['user_id' => $userId, 'notes' => $notes, 'step_id' => $stepId]);
    }

    private function notifyApprover(array $contract, array $step, string $event, string $title, string $message, ?int $taskId = null): void
    {
        if (empty($step['user_id'])) {
            return;
        }
        try {
            $actionUrl = $this->approverHasContractApprovalPermission((int) $step['user_id'])
                ? 'pages/contract-management.html'
                : 'pages/employee/tasks.html' . ($taskId !== null ? '?task=' . $taskId : '');
            $this->pdo->prepare("INSERT INTO notification (recipient_user_id, event_code, module_code, notification_type, title, message, priority, related_entity_type, related_entity_id, related_reference, action_url, metadata_json, created_at) VALUES (:user_id, :event, 'contract_management', 'IN_APP', :title, :message, 'HIGH', 'contract', :contract_id, :reference, :url, :metadata, NOW())")->execute([
                'user_id' => (int) $step['user_id'],
                'event' => $event,
                'title' => $title,
                'message' => $message . ' ' . (string) $contract['contract_number'],
                'contract_id' => (int) $contract['contract_id'],
                'reference' => (string) $contract['contract_number'],
                'url' => $actionUrl,
                'metadata' => json_encode(['step' => $step['name'], 'workflow_task_id' => $taskId], JSON_THROW_ON_ERROR),
            ]);
        } catch (Throwable) {
            // Notifications are helpful, but approval evidence remains authoritative.
        }
    }

    private function approverHasContractApprovalPermission(int $userId): bool
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM user_account ua INNER JOIN user_role ur ON ur.user_account_id = ua.user_account_id AND (ur.expires_at IS NULL OR ur.expires_at > NOW()) INNER JOIN role r ON r.role_id = ur.role_id AND r.status = 'ACTIVE' INNER JOIN role_permission rp ON rp.role_id = r.role_id INNER JOIN permission p ON p.permission_id = rp.permission_id WHERE ua.user_account_id = :user_id AND ua.account_status = 'ACTIVE' AND ua.deleted_at IS NULL AND p.permission_code IN ('contract.approve','contract.manage')", ['user_id' => $userId]) > 0;
    }

    private function auditApproval(array $user, string $action, array $contract, int $requestId, int $stepId, string $status, ?string $comment): void
    {
        try {
            $this->pdo->prepare("INSERT INTO audit_log (audit_uuid, actor_user_id, actor_username, action_code, module_code, entity_type, entity_id, entity_reference, result_status, result_message, metadata_json, created_at) VALUES (UUID(), :user_id, :username, :action, 'contract_management', 'contract', :contract_id, :reference, :status, :message, :metadata, NOW())")->execute([
                'user_id' => (int) $user['id'],
                'username' => (string) ($user['username'] ?? ''),
                'action' => $action,
                'contract_id' => (int) $contract['contract_id'],
                'reference' => (string) $contract['contract_number'],
                'status' => $status,
                'message' => $comment,
                'metadata' => json_encode(['approval_request_id' => $requestId, 'approval_step_id' => $stepId], JSON_THROW_ON_ERROR),
            ]);
        } catch (Throwable) {
            // Audit logging should not split approval state if the generic audit table is unavailable.
        }
    }

    private function nextTaskNumber(): string
    {
        return 'TASK-' . date('Ymd-His') . '-' . random_int(1000, 9999);
    }

    private function validateActivation(array $contract, array $data): void
    {
        $executed = $this->requiredDate($data['executed_date'] ?? null, 'executed_date');
        $effective = $this->date($data['effective_date'] ?? $contract['effective_date'] ?? $contract['start_date']);
        if ($effective === null) {
            throw new InvalidArgumentException(json_encode(['effective_date' => 'Effective date is required to activate.'], JSON_THROW_ON_ERROR));
        }
        if ($effective > date('Y-m-d')) {
            throw new InvalidArgumentException(json_encode(['effective_date' => 'Future-effective contracts must remain approved until activation is valid.'], JSON_THROW_ON_ERROR));
        }
        if ($executed > date('Y-m-d')) {
            throw new InvalidArgumentException(json_encode(['executed_date' => 'Executed date cannot be in the future.'], JSON_THROW_ON_ERROR));
        }
        if (empty($contract['end_date']) || (string) $contract['end_date'] === self::UNESTABLISHED_END_DATE) {
            throw new InvalidArgumentException(json_encode(['end_date' => 'End date is required to activate.'], JSON_THROW_ON_ERROR));
        }
        if ((string) $contract['end_date'] < $effective) {
            throw new InvalidArgumentException(json_encode(['end_date' => 'End date cannot be before effective date.'], JSON_THROW_ON_ERROR));
        }
        $this->validateReadyForReview($contract);
    }

    private function validateContract(array $data, bool $creating, ?array $existing = null, ?array $user = null): array
    {
        $errors = [];
        if (!$creating && isset($data['contract_status']) && !in_array((string) $data['contract_status'], self::STATUSES, true)) {
            $errors['contract_status'] = 'Unsupported contract lifecycle status.';
        }
        $typeInput = $data['contract_type_id'] ?? $data['contract_type'] ?? null;
        $existingType = $existing === null ? null : $this->contractType((int) ($existing['contract_type_id'] ?? 0));
        $preservingLegacyType = $existingType !== null
            && in_array((string) $existingType['type_code'], self::LEGACY_CONTRACT_TYPES, true)
            && ($typeInput === null || $typeInput === '' || (string) $typeInput === (string) $existingType['contract_type_id'] || strtoupper((string) $typeInput) === (string) $existingType['type_code']);
        $type = $preservingLegacyType ? $existingType : $this->contractTypeFromInput($typeInput, true);
        $typeId = $type === null ? null : (int) $type['contract_type_id'];
        $typeCode = $type === null ? null : (string) $type['type_code'];
        if ($typeId === null) {
            $errors['contract_type_id'] = 'Choose an active canonical contract type.';
        }
        $title = $this->text($data['contract_title'] ?? $data['title'] ?? '', 255);
        if ($title === '') {
            $errors['contract_title'] = 'Enter a contract title.';
        }
        if ($creating) {
            $creatorAssignment = $this->resolveCreatorAssignment($user);
            $departmentId = $creatorAssignment['department_id'];
            $ownerId = $creatorAssignment['employee_id'];
            $errors += $creatorAssignment['errors'];
        } else {
            $departmentId = $this->id($data['owning_department_reference_id'] ?? null);
            if ($departmentId === null || !$this->exists('department_reference', 'department_reference_id', $departmentId, "status='ACTIVE'")) {
                $errors['owning_department_reference_id'] = 'Choose an active owning department.';
            }
            $ownerId = $this->id($data['contract_owner_employee_reference_id'] ?? $data['contract_administrator_employee_reference_id'] ?? null);
        }
        if (!$creating && $ownerId === null) {
            $errors['contract_owner_employee_reference_id'] = 'Choose an active Contract Administrator.';
        } elseif (!$creating && $ownerId !== null) {
            try {
                $this->eligibility()->assertFamInternalHandler($ownerId, 'contract_owner_employee_reference_id');
            } catch (InvalidArgumentException $exception) {
                $decoded = json_decode($exception->getMessage(), true);
                $ownerErrors = is_array($decoded) ? $decoded : ['contract_owner_employee_reference_id' => 'Choose an active FAM Contract Administrator.'];
                $errors += $ownerErrors;
            }
        }
        $handlerId = $creating ? $ownerId : ($this->id($data['fam_handler_employee_reference_id'] ?? null) ?? $ownerId);
        if (!$creating && $handlerId !== null) {
            try {
                $this->eligibility()->assertFamInternalHandler($handlerId, 'fam_handler_employee_reference_id');
            } catch (InvalidArgumentException $exception) {
                $decoded = json_decode($exception->getMessage(), true);
                $errors += is_array($decoded) ? $decoded : ['fam_handler_employee_reference_id' => 'Choose an eligible FAM handler.'];
            }
        }
        $startDate = $this->date($data['start_date'] ?? null);
        $effectiveDate = $this->date($data['effective_date'] ?? null);
        $endDate = $this->date($data['end_date'] ?? null);
        if ($creating) {
            $startDate ??= self::UNESTABLISHED_START_DATE;
            $endDate ??= self::UNESTABLISHED_END_DATE;
        }
        if ($startDate !== null && $endDate !== null && $endDate < ($effectiveDate ?? $startDate)) {
            $errors['end_date'] = 'End date cannot be before effective/start date.';
        }
        $originalAmount = $this->nullableAmount($data['original_amount'] ?? null, 'original_amount', $errors);
        $currentAmount = $this->nullableAmount($data['current_amount'] ?? $originalAmount, 'current_amount', $errors);
        $currencyInput = $data['currency_code'] ?? null;
        $currency = $currencyInput === null || $currencyInput === '' ? null : strtoupper($this->text($currencyInput, 3));
        if ($currency !== null && !preg_match('/^[A-Z]{3}$/', $currency)) {
            $errors['currency_code'] = 'Currency code must use three uppercase letters.';
        }
        if ($creating) {
            $originalAmount ??= '0.00';
            $currentAmount ??= $originalAmount;
            $currency ??= 'PHP';
        }
        if (isset($data['counterparty_name']) && !is_scalar($data['counterparty_name'])) {
            $errors['counterparty_name'] = 'Enter a valid counterparty name.';
        }
        $counterpartyName = $this->nullableText($data['counterparty_name'] ?? '', 255);
        $supplierId = $creating
            ? null
            : $this->id($data['supplier_reference_id'] ?? $existing['supplier_reference_id'] ?? null);
        if (!$creating && $supplierId !== null && !$this->exists('supplier_reference', 'supplier_reference_id', $supplierId, "supplier_status='ACTIVE'")) {
            $errors['supplier_reference_id'] = 'Choose an active supplier.';
        }
        $budgetId = $this->id($data['budget_reference_id'] ?? null);
        if ($budgetId !== null && !$this->exists('budget_reference', 'budget_reference_id', $budgetId, "status='ACTIVE'")) {
            $errors['budget_reference_id'] = 'Choose an active budget reference.';
        }
        $procurementId = $this->id($data['procurement_request_id'] ?? null);
        if ($procurementId !== null && !$this->exists('procurement_request', 'procurement_request_id', $procurementId, 'deleted_at IS NULL')) {
            $errors['procurement_request_id'] = 'Choose a valid procurement request.';
        }
        $poId = $this->id($data['purchase_order_reference_id'] ?? null);
        if ($poId !== null && !$this->exists('purchase_order_reference', 'purchase_order_reference_id', $poId)) {
            $errors['purchase_order_reference_id'] = 'Choose a valid purchase order.';
        }
        $noticeDays = $this->nullableInt($data['notice_period_days'] ?? null);
        if ($noticeDays !== null && $noticeDays < 0) {
            $errors['notice_period_days'] = 'Notice period must be zero or greater.';
        }
        $renewalType = strtoupper($this->text($data['renewal_type'] ?? 'NONE', 20));
        if (!in_array($renewalType, self::RENEWAL_TYPES, true)) {
            $errors['renewal_type'] = 'Choose a supported renewal type.';
        }
        $renewalDecisionDate = $this->date($data['renewal_decision_date'] ?? null);
        if ($renewalDecisionDate !== null && $endDate !== null && $renewalDecisionDate > $endDate) {
            $errors['renewal_decision_date'] = 'Renewal decision date cannot be after end date.';
        }
        $risk = $this->nullableUpper($data['risk_level'] ?? null, 20);
        if ($risk !== null && !in_array($risk, self::RISK_LEVELS, true)) {
            $errors['risk_level'] = 'Choose a supported risk level.';
        }
        $renewedFromId = $this->id($data['renewed_from_contract_id'] ?? null);
        if ($renewedFromId !== null && !$this->exists('contract', 'contract_id', $renewedFromId, 'deleted_at IS NULL')) {
            $errors['renewed_from_contract_id'] = 'Choose a valid prior contract.';
        }
        $templateId = $this->id($data['template_id'] ?? $data['contract_template_id'] ?? null);
        $templateVersionId = null;
        if ($creating && $templateId === null) {
            $errors['template_id'] = 'Choose an active contract template.';
        }
        if (
            $existing !== null
            && $templateId !== null
            && (int) ($existing['template_id'] ?? 0) === $templateId
            && $typeId !== null
            && (int) ($existing['contract_type_id'] ?? 0) === $typeId
        ) {
            $templateVersionId = $existing['template_version_id'] === null ? null : (int) $existing['template_version_id'];
        } elseif ($templateId !== null && $typeId !== null && $typeCode !== null) {
            $template = $this->resolveContractTemplate($templateId, $typeCode);
            if ($template === null) {
                $errors['template_id'] = 'Choose an active template compatible with this contract type.';
            } else {
                $templateVersionId = (int) $template['template_version_id'];
            }
        }
        if ($errors) {
            throw new InvalidArgumentException(json_encode($errors, JSON_THROW_ON_ERROR));
        }
        return [
            'type_id' => $typeId,
            'template_id' => $templateId,
            'template_version_id' => $templateVersionId,
            'title' => $title,
            'description' => $this->nullableText($data['contract_description'] ?? $data['description'] ?? '', 4000),
            'counterparty_name' => $counterpartyName,
            'supplier_id' => $supplierId,
            'budget_id' => $budgetId,
            'procurement_request_id' => $procurementId,
            'purchase_order_id' => $poId,
            'owner_id' => $ownerId,
            'department_id' => $departmentId,
            'handler_id' => $handlerId,
            'start_date' => $startDate,
            'effective_date' => $effectiveDate,
            'end_date' => $endDate,
            'original_amount' => $originalAmount,
            'current_amount' => $currentAmount,
            'currency' => $currency,
            'notice_days' => $noticeDays,
            'renewal_type' => $renewalType,
            'renewal_decision_date' => $renewalDecisionDate,
            'renewed_from_id' => $renewedFromId,
            'risk_level' => $risk,
        ];
    }

    private function resolveContractTemplate(int $templateId, string $contractTypeCode): ?array
    {
        $contractTypeCode = strtoupper($contractTypeCode);
        if (!in_array($contractTypeCode, self::CANONICAL_CONTRACT_TYPES, true)) {
            return null;
        }
        return $this->row("SELECT dt.template_id, dt.template_type, current_tv.template_version_id FROM document_template dt INNER JOIN document_template_version current_tv ON current_tv.template_version_id = dt.current_approved_version_id AND current_tv.template_id = dt.template_id INNER JOIN document d ON d.document_id = current_tv.document_id AND d.deleted_at IS NULL WHERE dt.template_id = :id AND dt.deleted_at IS NULL AND dt.status = 'ACTIVE' AND current_tv.status = 'ACTIVE' AND dt.template_type = :template_type LIMIT 1", ['id' => $templateId, 'template_type' => $contractTypeCode]);
    }

    private function contractType(int $typeId): ?array
    {
        return $this->row('SELECT contract_type_id, type_code, type_name FROM contract_type WHERE contract_type_id = :id LIMIT 1', ['id' => $typeId]);
    }

    private function contractTypeFromInput(mixed $value, bool $canonicalOnly): ?array
    {
        if ($value === null || $value === '' || $value === 'null') {
            return null;
        }
        $canonicalFilter = $canonicalOnly ? " AND status = 'ACTIVE' AND type_code IN ('CLIENT_CONTRACT','EMPLOYEE_CONTRACT','NDA','CONTRACT_AMENDMENT','OTHER')" : '';
        if (ctype_digit((string) $value)) {
            return $this->row("SELECT contract_type_id, type_code, type_name FROM contract_type WHERE contract_type_id = :value$canonicalFilter LIMIT 1", ['value' => (int) $value]);
        }
        $code = strtoupper($this->text($value, 80));
        return $this->row("SELECT contract_type_id, type_code, type_name FROM contract_type WHERE type_code = :value$canonicalFilter LIMIT 1", ['value' => $code]);
    }

    /**
     * @return array{employee_id:?int,department_id:?int,errors:array<string,string>}
     */
    private function resolveCreatorAssignment(?array $user): array
    {
        $userId = $this->id($user['id'] ?? null);
        $employeeId = $this->id($user['employee_id'] ?? null);
        if ($userId === null || $employeeId === null) {
            return [
                'employee_id' => null,
                'department_id' => null,
                'errors' => ['contract_owner_employee_reference_id' => 'Your account is not linked to an active employee profile. Contact a FAM administrator.'],
            ];
        }

        $row = $this->row("SELECT e.employee_reference_id, d.department_reference_id FROM user_account ua INNER JOIN employee_reference e ON e.employee_reference_id = ua.employee_reference_id AND e.employment_status = 'ACTIVE' AND e.deleted_at IS NULL LEFT JOIN department_reference d ON d.department_reference_id = e.department_reference_id AND d.status = 'ACTIVE' WHERE ua.user_account_id = :user_id AND ua.employee_reference_id = :employee_id AND ua.account_status = 'ACTIVE' AND ua.deleted_at IS NULL LIMIT 1", [
            'user_id' => $userId,
            'employee_id' => $employeeId,
        ]);
        if ($row === null) {
            return [
                'employee_id' => null,
                'department_id' => null,
                'errors' => ['contract_owner_employee_reference_id' => 'Your account is not linked to an active employee profile. Contact a FAM administrator.'],
            ];
        }

        $departmentId = $row['department_reference_id'] === null ? null : (int) $row['department_reference_id'];
        $errors = [];
        if ($departmentId === null) {
            $errors['owning_department_reference_id'] = 'Your account is not associated with an active owning department. Contact a FAM administrator.';
        }

        return [
            'employee_id' => (int) $row['employee_reference_id'],
            'department_id' => $departmentId,
            'errors' => $errors,
        ];
    }

    private function buildTemplateAuthoringPayload(array $context, ?array $user = null): array
    {
        $template = [
            'id' => $context['template_id'] === null ? null : (int) $context['template_id'],
            'versionId' => $context['template_version_id'] === null ? null : (int) $context['template_version_id'],
            'templateCode' => (string) ($context['template_code'] ?? ''),
            'templateName' => (string) ($context['template_name'] ?? ''),
            'templateType' => (string) ($context['template_type'] ?? ''),
            'version' => $context['version_number'] === null ? '' : 'v' . (int) $context['version_number'],
            'status' => (string) ($context['template_status'] ?? ''),
            'versionStatus' => (string) ($context['template_version_status'] ?? ''),
            'fileName' => (string) ($context['file_name'] ?? ''),
            'mimeType' => (string) ($context['mime_type'] ?? ''),
            'confidentiality' => (string) ($context['confidentiality_level'] ?? ''),
        ];
        if (($context['authoring_error'] ?? '') === 'NO_TEMPLATE') {
            return ['contract' => $this->contractAuthoringSummary($context), 'template' => $template, 'preview' => ['mode' => 'NO_TEMPLATE', 'message' => 'No template is associated with this contract.'], 'fields' => [], 'unsupportedPlaceholders' => [], 'canEdit' => false];
        }
        if (($context['authoring_error'] ?? '') === 'MISSING_FILE') {
            return ['contract' => $this->contractAuthoringSummary($context), 'template' => $template, 'preview' => ['mode' => 'MISSING_FILE', 'message' => 'The assigned template file is missing.'], 'fields' => [], 'unsupportedPlaceholders' => [], 'canEdit' => false];
        }
        if (!$this->contractTemplateAuthoringConfigured()) {
            return ['contract' => $this->contractAuthoringSummary($context), 'template' => $template, 'preview' => ['mode' => 'NOT_CONFIGURED', 'message' => 'Template authoring is not configured yet. Contact the system administrator.'], 'fields' => [], 'unsupportedPlaceholders' => [], 'canEdit' => false];
        }
        $extension = strtolower((string) ($context['file_extension'] ?? pathinfo((string) ($context['file_name'] ?? ''), PATHINFO_EXTENSION)));
        if ($extension !== 'docx') {
            return ['contract' => $this->contractAuthoringSummary($context), 'template' => $template, 'preview' => ['mode' => 'PREVIEW_ONLY', 'message' => 'Placeholder authoring is unavailable for this file type. Use the secure View or Download action for reference.'], 'fields' => [], 'unsupportedPlaceholders' => [], 'canEdit' => false];
        }
        $file = (new DocumentService($this->pdo))->downloadVersion((int) $context['document_id'], (int) $context['document_version_id']);
        if ($file === null) {
            return ['contract' => $this->contractAuthoringSummary($context), 'template' => $template, 'preview' => ['mode' => 'MISSING_FILE', 'message' => 'The assigned template file is unavailable.'], 'fields' => [], 'unsupportedPlaceholders' => [], 'canEdit' => false];
        }
        $previewText = $this->extractDocxText((string) $file['absolute_path']);
        if ($previewText === '') {
            return ['contract' => $this->contractAuthoringSummary($context), 'template' => $template, 'preview' => ['mode' => 'PREVIEW_UNAVAILABLE', 'message' => 'Unable to read the selected DOCX template.'], 'fields' => [], 'unsupportedPlaceholders' => [], 'canEdit' => false];
        }
        $placeholders = $this->discoverPlaceholders($previewText);
        $registry = $this->mergeFieldRegistry();
        $saved = $this->savedTemplateValues((int) $context['contract_id'], (int) $context['template_version_id']);
        $fields = [];
        $unsupported = [];
        foreach ($placeholders as $placeholder) {
            $key = $this->normalizePlaceholder($placeholder);
            $field = $registry[$key] ?? null;
            if ($field === null) {
                $unsupported[] = $placeholder;
                continue;
            }
            $auto = $this->autoTemplateValue((string) $field['field_code'], $context, $user);
            $source = $auto !== null ? 'SYSTEM' : 'MANUAL';
            $fields[] = [
                'placeholder' => $placeholder,
                'fieldCode' => (string) $field['field_code'],
                'mergeFieldId' => (int) $field['merge_field_id'],
                'label' => (string) $field['display_name'],
                'dataType' => (string) $field['data_type'],
                'source' => $source,
                'value' => $source === 'SYSTEM' ? $auto : ($saved[(string) $field['field_code']] ?? ''),
                'completed' => trim((string) ($source === 'SYSTEM' ? $auto : ($saved[(string) $field['field_code']] ?? ''))) !== '',
                'unsupported' => false,
            ];
        }
        return [
            'contract' => $this->contractAuthoringSummary($context),
            'template' => $template,
            'preview' => ['mode' => 'DOCX_PLACEHOLDER', 'text' => $previewText, 'message' => 'DOCX text preview; layout may differ from Microsoft Word.'],
            'fields' => $fields,
            'unsupportedPlaceholders' => array_values(array_unique($unsupported)),
            'canEdit' => in_array((string) ($context['contract_status'] ?? ''), self::EDITABLE_STATUSES, true),
        ];
    }

    private function extractDocxText(string $absolutePath): string
    {
        if (!class_exists('ZipArchive')) {
            return '';
        }
        $zip = new ZipArchive();
        if ($zip->open($absolutePath) !== true) {
            return '';
        }
        $xml = (string) ($zip->getFromName('word/document.xml') ?: '');
        $zip->close();
        if ($xml === '') {
            return '';
        }
        if (!class_exists('XMLReader')) {
            return $this->extractDocxTextFallback($xml);
        }
        $text = '';
        $previousRunText = false;
        $reader = new XMLReader();
        if (!$reader->XML($xml, null, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            return '';
        }
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 't') {
                $text .= $reader->readString();
                $previousRunText = true;
            } elseif ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'tab') {
                $text .= "\t";
                $previousRunText = false;
            } elseif ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'br') {
                $text .= "\n";
                $previousRunText = false;
            } elseif ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'p') {
                $text .= "\n";
                $previousRunText = false;
            } elseif ($reader->nodeType === XMLReader::ELEMENT && in_array($reader->localName, ['instrText', 'delText'], true) && $previousRunText) {
                $text .= ' ';
                $previousRunText = false;
            }
        }
        $reader->close();
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        return trim($text);
    }

    private function extractDocxTextFallback(string $xml): string
    {
        $xml = preg_replace('/<\/w:p>/', "\n", $xml) ?? $xml;
        $xml = preg_replace('/<\/w:tab>/', "\t", $xml) ?? $xml;
        $text = html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        return trim($text);
    }

    private function discoverPlaceholders(string $text): array
    {
        preg_match_all('/\[\s*([A-Z0-9][A-Z0-9\s\/&.,_-]{1,120})\s*\]/u', $text, $matches);
        return array_values(array_unique(array_map(fn (string $value): string => preg_replace('/\s+/', ' ', trim($value)) ?? trim($value), $matches[1] ?? [])));
    }

    private function mergeFieldRegistry(): array
    {
        $registry = [];
        foreach ($this->rows("SELECT * FROM document_template_merge_field WHERE status = 'ACTIVE'") as $row) {
            foreach ([$row['display_name'] ?? '', $row['field_name'] ?? '', $row['field_code'] ?? ''] as $alias) {
                $key = $this->normalizePlaceholder((string) $alias);
                if ($key !== '') {
                    $registry[$key] = $row;
                }
            }
        }
        foreach (self::PLACEHOLDER_ALIASES as $alias => $target) {
            $aliasKey = $this->normalizePlaceholder($alias);
            $targetKey = $this->normalizePlaceholder($target);
            if (isset($registry[$targetKey])) {
                $registry[$aliasKey] = $registry[$targetKey];
            }
        }
        return $registry;
    }

    private function savedTemplateValues(int $contractId, int $templateVersionId): array
    {
        if (!$this->hasTable('contract_template_value')) {
            return [];
        }
        $values = [];
        foreach ($this->rows('SELECT field_code, value_text FROM contract_template_value WHERE contract_id = :contract_id AND template_version_id = :template_version_id', ['contract_id' => $contractId, 'template_version_id' => $templateVersionId]) as $row) {
            $values[(string) $row['field_code']] = (string) ($row['value_text'] ?? '');
        }
        return $values;
    }

    private function contractTemplateAuthoringConfigured(): bool
    {
        if (!$this->hasTable('contract_template_value') || !$this->hasTable('document_template_merge_field')) {
            return false;
        }
        return (bool) $this->scalar("SELECT COUNT(*) FROM document_template_merge_field WHERE status = 'ACTIVE'");
    }

    private function hasTable(string $table): bool
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name');
        $statement->execute(['table_name' => $table]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function hasColumn(string $table, string $column): bool
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name');
        $statement->execute(['table_name' => $table, 'column_name' => $column]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function autoTemplateValue(string $fieldCode, array $context, ?array $user = null): ?string
    {
        if ($fieldCode === 'contract.contract_value' && $user !== null && !$this->canViewContractFinancials($user)) {
            return null;
        }

        return match ($fieldCode) {
            'contract.contract_number' => (string) $context['contract_number'],
            'contract.title' => (string) $context['contract_title'],
            'contract.start_date' => $this->displayDate($context['start_date'] ?? null),
            'contract.end_date' => $this->displayDate($context['end_date'] ?? null),
            'contract.contract_value' => $this->displayMoney($context['current_amount'] ?? null, $context['currency_code'] ?? null),
            'client.name' => $this->counterpartyDisplayName($context),
            default => null,
        };
    }

    private function counterpartyDisplayName(array $row): ?string
    {
        $snapshot = trim((string) ($row['counterparty_name'] ?? ''));
        if ($snapshot !== '') {
            return $snapshot;
        }
        $supplierName = trim((string) ($row['supplier_name'] ?? ''));
        return $supplierName === '' ? null : $supplierName;
    }

    private function contractAuthoringSummary(array $context): array
    {
        return ['id' => (int) $context['contract_id'], 'contractNo' => (string) $context['contract_number'], 'title' => (string) $context['contract_title'], 'status' => (string) ($context['contract_status'] ?? '')];
    }

    private function normalizePlaceholder(string $value): string
    {
        $value = strtoupper(trim($value));
        $value = preg_replace('/[{}\\[\\]]/', '', $value) ?? $value;
        $value = str_replace(['.', '_'], ' ', $value);
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        return trim($value);
    }

    private function displayDate(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (in_array((string) $value, [self::UNESTABLISHED_START_DATE, self::UNESTABLISHED_END_DATE], true)) {
            return '';
        }
        $time = strtotime((string) $value);
        return $time === false ? (string) $value : date('M j, Y', $time);
    }

    private function contractDateValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (in_array((string) $value, [self::UNESTABLISHED_START_DATE, self::UNESTABLISHED_END_DATE], true)) {
            return '';
        }
        return (string) $value;
    }

    private function nextContractNumber(): string
    {
        $year = date('Y');
        $prefix = "CTR-$year-";
        $statement = $this->pdo->prepare('SELECT contract_number FROM contract WHERE contract_number LIKE :prefix ORDER BY contract_number DESC LIMIT 1 FOR UPDATE');
        $statement->execute(['prefix' => $prefix . '%']);
        $last = (string) ($statement->fetchColumn() ?: '');
        $next = preg_match('/^CTR-\d{4}-(\d{4})$/', $last, $matches) ? ((int) $matches[1]) + 1 : 1;
        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    private function derived(array $row): array
    {
        $status = (string) $row['contract_status'];
        $today = new DateTimeImmutable('today');
        $endValue = (string) ($row['end_date'] ?? '');
        $end = $endValue === '' || $endValue === self::UNESTABLISHED_END_DATE ? null : new DateTimeImmutable($endValue);
        $days = $end === null ? null : (int) $today->diff($end)->format('%r%a');
        $notice = $row['notice_period_days'] === null ? 0 : (int) $row['notice_period_days'];
        $isActiveFuture = $status === 'ACTIVE' && !empty($row['effective_date']) && (string) $row['effective_date'] > $today->format('Y-m-d');
        $renewalDue = $status === 'ACTIVE' && !empty($row['renewal_decision_date']) && (string) $row['renewal_decision_date'] <= $today->format('Y-m-d');
        $overdue = (int) ($row['overdue_obligation_count'] ?? 0) > 0;
        $expiryState = match (true) {
            $status === 'TERMINATED' => 'TERMINATED',
            $status === 'EXPIRED' || ($status === 'ACTIVE' && $days !== null && $days < 0) => 'EXPIRED',
            $status === 'ACTIVE' && $days !== null && $days >= 0 && $days <= $notice => 'EXPIRING_SOON',
            $status === 'ACTIVE' => 'NORMAL',
            default => 'NOT_APPLICABLE',
        };
        return [
            'daysToExpiry' => $status === 'ACTIVE' ? $days : null,
            'isExpiringSoon' => $expiryState === 'EXPIRING_SOON',
            'expiryState' => $expiryState,
            'isPendingEffective' => $isActiveFuture,
            'isRenewalDue' => $renewalDue,
            'hasOverdueObligations' => $overdue,
        ];
    }

    private function summary(): array
    {
        $row = $this->row("SELECT COUNT(*) total, SUM(contract_status = 'ACTIVE') active, SUM(contract_status IN ('FOR_REVIEW','FOR_APPROVAL')) pending_review_approval, SUM(contract_status = 'ACTIVE' AND end_date >= CURRENT_DATE() AND DATEDIFF(end_date, CURRENT_DATE()) <= COALESCE(notice_period_days, 0)) expiring_soon FROM contract WHERE deleted_at IS NULL");
        return [
            'total' => (int) ($row['total'] ?? 0),
            'active' => (int) ($row['active'] ?? 0),
            'expiringSoon' => (int) ($row['expiring_soon'] ?? 0),
            'pendingReviewApproval' => (int) ($row['pending_review_approval'] ?? 0),
        ];
    }

    private function allowedActions(array $row, array $user): array
    {
        $status = (string) $row['contract_status'];
        $map = [
            'DRAFT' => ['edit' => 'contract.edit', 'submit_review' => 'contract.edit', 'cancel' => 'contract.edit'],
            'FOR_REVIEW' => ['return_draft' => 'contract.review', 'submit_approval' => 'contract.review'],
            'FOR_APPROVAL' => [],
            'APPROVED' => ['return_draft' => 'contract.review'],
            'ACTIVE' => ['terminate' => 'contract.terminate'],
            'EXPIRED' => ['archive' => 'contract.archive'],
            'TERMINATED' => ['archive' => 'contract.archive'],
            'REJECTED' => ['return_draft' => 'contract.review'],
        ];
        $actions = [];
        foreach ($map[$status] ?? [] as $action => $permission) {
            if (ContractPolicy::hasPermission($user, $permission)) {
                $actions[] = $action;
            }
        }
        return $actions;
    }

    private function shape(array $row, array $user, bool $details): array
    {
        $item = [
            'id' => (int) $row['contract_id'],
            'contractNo' => (string) $row['contract_number'],
            'title' => (string) $row['contract_title'],
            'description' => (string) ($row['contract_description'] ?? ''),
            'type' => ['id' => (int) $row['contract_type_id'], 'code' => (string) $row['type_code'], 'name' => (string) $row['type_name']],
            'template' => $row['template_id'] === null ? null : [
                'id' => (int) $row['template_id'],
                'versionId' => $row['template_version_id'] === null ? null : (int) $row['template_version_id'],
                'templateCode' => (string) ($row['linked_template_code'] ?? ''),
                'templateName' => (string) ($row['linked_template_name'] ?? ''),
                'templateType' => (string) ($row['linked_template_type'] ?? ''),
                'status' => (string) ($row['linked_template_status'] ?? ''),
                'currentVersion' => $row['linked_template_version_number'] === null ? '' : 'v' . (int) $row['linked_template_version_number'],
                'documentId' => $row['linked_template_document_id'] === null ? null : (int) $row['linked_template_document_id'],
                'documentVersionId' => $row['linked_template_document_version_id'] === null ? null : (int) $row['linked_template_document_version_id'],
                'fileName' => (string) ($row['linked_template_file_name'] ?? ''),
                'fileSize' => $row['linked_template_file_size'] === null ? null : (int) $row['linked_template_file_size'],
                'confidentiality' => (string) ($row['linked_template_confidentiality'] ?? ''),
            ],
            'counterparty' => ['name' => $this->counterpartyDisplayName($row)],
            'supplier' => $row['supplier_reference_id'] === null ? null : ['id' => (int) $row['supplier_reference_id'], 'code' => (string) $row['supplier_code'], 'name' => (string) $row['supplier_name']],
            'budget' => $row['budget_reference_id'] === null ? null : ['id' => (int) $row['budget_reference_id'], 'code' => (string) $row['budget_code'], 'name' => (string) $row['budget_name']],
            'procurementRequest' => $row['procurement_request_id'] === null ? null : ['id' => (int) $row['procurement_request_id'], 'number' => (string) $row['request_number']],
            'purchaseOrder' => $row['purchase_order_reference_id'] === null ? null : ['id' => (int) $row['purchase_order_reference_id'], 'number' => (string) ($row['purchase_order_number'] ?? '')],
            'owningDepartment' => $row['owning_department_reference_id'] === null ? null : ['id' => (int) $row['owning_department_reference_id'], 'code' => (string) $row['department_code'], 'name' => (string) $row['department_name']],
            'owner' => ['id' => (int) $row['contract_owner_employee_reference_id'], 'employeeNo' => (string) $row['owner_employee_number'], 'name' => (string) $row['owner_name']],
            'famHandler' => $row['fam_handler_employee_reference_id'] === null ? null : ['id' => (int) $row['fam_handler_employee_reference_id'], 'employeeNo' => (string) $row['handler_employee_number'], 'name' => (string) $row['handler_name']],
            'dates' => ['startDate' => $this->contractDateValue($row['start_date'] ?? null), 'executedDate' => $row['executed_date'], 'effectiveDate' => $row['effective_date'], 'endDate' => $this->contractDateValue($row['end_date'] ?? null), 'terminationDate' => $row['termination_date']],
            'status' => (string) $row['contract_status'],
            'signedContract' => $this->signedContractReviewState($row, $user),
            'noticePeriodDays' => $row['notice_period_days'] === null ? null : (int) $row['notice_period_days'],
            'renewalType' => (string) $row['renewal_type'],
            'renewalDecisionDate' => $row['renewal_decision_date'],
            'riskLevel' => $row['risk_level'],
            'derived' => $this->derived($row),
            'allowedActions' => $this->allowedActions($row, $user),
            'createdAt' => (string) $row['created_at'],
            'updatedAt' => (string) $row['updated_at'],
        ];
        if ($this->canViewContractFinancials($user)) {
            $hasFinancialValue = (float) $row['original_amount'] > 0.0 || (float) $row['current_amount'] > 0.0;
            $item['financial'] = [
                'originalAmount' => $hasFinancialValue ? (float) $row['original_amount'] : null,
                'currentAmount' => $hasFinancialValue ? (float) $row['current_amount'] : null,
                'currencyCode' => $hasFinancialValue ? (string) $row['currency_code'] : null,
            ];
        }
        if ($details) {
            $item['terminationReason'] = $row['termination_reason'];
            $item['summary'] = ['overdueObligationCount' => (int) ($row['overdue_obligation_count'] ?? 0)];
            $item['contractDates'] = $this->contractDatesReviewState($row, $user);
            $item['approval'] = $this->approvalSummary((int) $row['contract_id'], $user, (string) $row['contract_status']);
        }
        return $item;
    }

    private function contractDatesReviewState(array $row, array $user): array
    {
        $schemaReady = $this->hasColumn('contract', 'contract_dates_source_document_version_id');
        $source = $schemaReady ? $this->currentFinalizedContractDateSource((int) $row['contract_id']) : null;
        $confirmedVersionId = $schemaReady ? (int) ($row['contract_dates_source_document_version_id'] ?? 0) : 0;
        $currentVersionId = (int) ($source['document_version_id'] ?? 0);
        $candidate = is_array($source['candidate'] ?? null) ? $source['candidate'] : [];
        $startDate = $this->contractDateValue($row['start_date'] ?? null);
        $endDate = $this->contractDateValue($row['end_date'] ?? null);
        $stale = $confirmedVersionId > 0 && $currentVersionId > 0 && $confirmedVersionId !== $currentVersionId;
        $confirmed = $schemaReady
            && !$stale
            && $currentVersionId > 0
            && $confirmedVersionId === $currentVersionId
            && (string) ($row['contract_dates_confirmed_at'] ?? '') !== ''
            && $startDate !== ''
            && $endDate !== '';

        return [
            'schemaReady' => $schemaReady,
            'confirmed' => $confirmed,
            'stale' => $stale,
            'canConfirm' => $schemaReady && $source !== null && (string) $row['contract_status'] === 'FOR_REVIEW' && ContractPolicy::hasPermission($user, 'contract.review'),
            'startDate' => $startDate,
            'endDate' => $endDate,
            'suggestedStartDate' => $this->candidateDate($candidate, 'effective_date'),
            'suggestedEndDate' => $this->candidateDate($candidate, 'expiration_date'),
            'candidateStatus' => (string) ($source['contract_metadata_status'] ?? 'NOT_ANALYZED'),
            'sourceDocumentId' => $source['document_id'] ?? null,
            'sourceDocumentVersionId' => $currentVersionId ?: null,
            'confirmedSourceDocumentVersionId' => $confirmedVersionId ?: null,
            'confirmedAt' => $schemaReady ? (string) ($row['contract_dates_confirmed_at'] ?? '') : '',
        ];
    }

    private function signedContractReviewState(array $row, array $user): array
    {
        $schemaReady = $this->signedContractSchemaReady();
        if (!$schemaReady) {
            return ['schemaReady' => false, 'uploaded' => false, 'verified' => false, 'state' => 'UNAVAILABLE'];
        }
        $documentId = empty($row['signed_document_id']) ? null : (int) $row['signed_document_id'];
        $versionId = empty($row['signed_document_version_id']) ? null : (int) $row['signed_document_version_id'];
        $document = null;
        if ($documentId !== null && $versionId !== null) {
            $document = $this->row('SELECT d.document_number, d.document_title, dv.version_number, dv.file_name, dv.uploaded_at FROM document d INNER JOIN document_version dv ON dv.document_version_id = :version_id AND dv.document_id = d.document_id AND dv.deleted_at IS NULL WHERE d.document_id = :document_id AND d.deleted_at IS NULL LIMIT 1', [
                'document_id' => $documentId,
                'version_id' => $versionId,
            ]);
        }
        $status = (string) ($row['contract_status'] ?? '');
        $uploaded = $document !== null;
        $legacyVerified = $uploaded && (string) ($row['signed_document_verified_at'] ?? '') !== '';
        $approvalVersionId = null;
        if ($status === 'FOR_APPROVAL') {
            $request = $this->currentApprovalRequest((int) $row['contract_id']);
            $approvalVersionId = empty($request['signed_document_version_id'] ?? null) ? null : (int) $request['signed_document_version_id'];
        }

        return [
            'schemaReady' => true,
            'uploaded' => $uploaded,
            'verified' => $legacyVerified,
            'state' => $uploaded ? 'UPLOADED' : 'WAITING_FOR_SIGNED_CONTRACT',
            'documentId' => $documentId,
            'documentVersionId' => $versionId,
            'approvalDocumentVersionId' => $approvalVersionId,
            'documentNo' => (string) ($document['document_number'] ?? ''),
            'title' => (string) ($document['document_title'] ?? ''),
            'version' => isset($document['version_number']) ? 'v' . (int) $document['version_number'] : '',
            'fileName' => (string) ($document['file_name'] ?? ''),
            'uploadedAt' => $document['uploaded_at'] ?? null,
            'verifiedAt' => $row['signed_document_verified_at'] ?? null,
            'verifiedByUserId' => empty($row['signed_document_verified_by_user_id']) ? null : (int) $row['signed_document_verified_by_user_id'],
            'canUpload' => $status === 'FOR_REVIEW' && ContractPolicy::hasPermission($user, 'contract.review'),
            'canReplace' => $status === 'FOR_REVIEW' && ContractPolicy::hasPermission($user, 'contract.review'),
            'canVerify' => false,
        ];
    }

    private function canViewContractFinancials(array $user): bool
    {
        return in_array('budget.approve', $user['permissions'] ?? [], true)
            || in_array('contract.manage', $user['permissions'] ?? [], true);
    }

    private function rejectUnauthorizedFinancialInput(array $data, array $user): void
    {
        if ($this->canViewContractFinancials($user)) {
            return;
        }
        foreach (['original_amount', 'current_amount', 'currency_code'] as $field) {
            if (array_key_exists($field, $data) && trim((string) $data[$field]) !== '') {
                throw new DomainException('You are not authorized to modify confidential contract financial information.');
            }
        }
    }

    private function filters(array $query): array
    {
        $where = ['c.deleted_at IS NULL'];
        $params = [];
        if (($query['search'] ?? '') !== '') {
            $where[] = '(c.contract_number LIKE :search_number OR c.contract_title LIKE :search_title OR c.counterparty_name LIKE :search_counterparty OR s.supplier_name LIKE :search_supplier)';
            $needle = '%' . trim((string) $query['search']) . '%';
            $params['search_number'] = $needle;
            $params['search_title'] = $needle;
            $params['search_counterparty'] = $needle;
            $params['search_supplier'] = $needle;
        }
        if (($query['contract_type_id'] ?? '') !== '') {
            $type = $this->contractTypeFromInput($query['contract_type_id'], false);
            if ($type !== null) {
                $where[] = 'c.contract_type_id = :contract_type_id';
                $params['contract_type_id'] = (int) $type['contract_type_id'];
            }
        }
        foreach (['contract_status' => 'c.contract_status', 'owning_department_reference_id' => 'c.owning_department_reference_id', 'fam_handler_employee_reference_id' => 'c.fam_handler_employee_reference_id', 'risk_level' => 'c.risk_level'] as $key => $column) {
            if (($query[$key] ?? '') !== '') {
                $where[] = "$column = :$key";
                $params[$key] = ctype_digit((string) $query[$key]) ? (int) $query[$key] : strtoupper((string) $query[$key]);
            }
        }
        if (($query['expiry_state'] ?? '') === 'expiring_soon') {
            $where[] = "c.contract_status = 'ACTIVE' AND c.end_date >= CURRENT_DATE() AND DATEDIFF(c.end_date, CURRENT_DATE()) <= COALESCE(c.notice_period_days, 0)";
        }
        return [' WHERE ' . implode(' AND ', $where), $params];
    }

    private function baseSelect(string $columns): string
    {
        return "SELECT $columns FROM contract c INNER JOIN contract_type ct ON ct.contract_type_id = c.contract_type_id LEFT JOIN document_template linked_template ON linked_template.template_id = c.template_id LEFT JOIN document_template_version linked_template_version ON linked_template_version.template_version_id = c.template_version_id AND linked_template_version.template_id = linked_template.template_id LEFT JOIN document linked_template_document ON linked_template_document.document_id = linked_template_version.document_id LEFT JOIN document_version linked_template_document_version ON linked_template_document_version.document_version_id = linked_template_version.document_version_id LEFT JOIN supplier_reference s ON s.supplier_reference_id = c.supplier_reference_id LEFT JOIN budget_reference b ON b.budget_reference_id = c.budget_reference_id LEFT JOIN procurement_request pr ON pr.procurement_request_id = c.procurement_request_id LEFT JOIN purchase_order_reference po ON po.purchase_order_reference_id = c.purchase_order_reference_id LEFT JOIN department_reference d ON d.department_reference_id = c.owning_department_reference_id INNER JOIN employee_reference owner ON owner.employee_reference_id = c.contract_owner_employee_reference_id LEFT JOIN employee_reference handler ON handler.employee_reference_id = c.fam_handler_employee_reference_id LEFT JOIN (SELECT contract_id, COUNT(*) overdue_obligation_count FROM contract_obligation WHERE deleted_at IS NULL AND status = 'PENDING' AND due_date < CURRENT_DATE() GROUP BY contract_id) overdue ON overdue.contract_id = c.contract_id";
    }

    private function selectColumns(): string
    {
        return 'c.*, ct.type_code, ct.type_name, linked_template.template_code linked_template_code, linked_template.template_name linked_template_name, linked_template.template_type linked_template_type, linked_template.status linked_template_status, linked_template_version.version_number linked_template_version_number, linked_template_version.document_id linked_template_document_id, linked_template_version.document_version_id linked_template_document_version_id, linked_template_document.confidentiality_level linked_template_confidentiality, linked_template_document_version.file_name linked_template_file_name, linked_template_document_version.file_size linked_template_file_size, s.supplier_code, s.supplier_name, b.budget_code, b.budget_name, pr.request_number, po.purchase_order_number, d.department_code, d.department_name, owner.employee_number owner_employee_number, owner.full_name owner_name, handler.employee_number handler_employee_number, handler.full_name handler_name, COALESCE(overdue.overdue_obligation_count, 0) overdue_obligation_count';
    }

    private function history(int $contractId): array
    {
        return array_map(function (array $row): array {
            $metadata = $this->historyMetadata((string) ($row['metadata_json'] ?? ''));
            $document = $this->historyDocumentSummary($metadata);
            return [
                'type' => (string) $row['event_type'],
                'description' => (string) $row['event_description'],
                'fromStatus' => (string) ($row['from_status'] ?? ''),
                'toStatus' => (string) ($row['to_status'] ?? ''),
                'actor' => (string) ($row['actor_name'] ?? 'System'),
                'eventAt' => (string) $row['event_at'],
                'reason' => isset($metadata['reason']) && is_scalar($metadata['reason']) ? trim((string) $metadata['reason']) : '',
                'requirementName' => isset($metadata['requirement_name']) && is_scalar($metadata['requirement_name']) ? trim((string) $metadata['requirement_name']) : '',
                'document' => $document,
                'evidenceAction' => isset($metadata['evidence_action']) && is_scalar($metadata['evidence_action']) ? trim((string) $metadata['evidence_action']) : '',
                'contractDates' => $this->historyContractDateSummary($metadata),
            ];
        }, $this->rows("SELECT h.*, actor.full_name actor_name FROM contract_history h LEFT JOIN user_account u ON u.user_account_id = h.actor_user_id LEFT JOIN employee_reference actor ON actor.employee_reference_id = u.employee_reference_id WHERE h.contract_id = :id ORDER BY h.event_at DESC, h.contract_history_id DESC LIMIT 50", ['id' => $contractId]));
    }

    private function historyContractDateSummary(array $metadata): ?array
    {
        $confirmedStart = $this->date($metadata['confirmed_start_date'] ?? null);
        $confirmedEnd = $this->date($metadata['confirmed_end_date'] ?? null);
        if ($confirmedStart === null && $confirmedEnd === null) {
            return null;
        }

        return [
            'confirmedStartDate' => $confirmedStart,
            'confirmedEndDate' => $confirmedEnd,
            'proposedStartDate' => $this->date($metadata['proposed_start_date'] ?? null),
            'proposedEndDate' => $this->date($metadata['proposed_end_date'] ?? null),
            'sourceDocumentVersionId' => isset($metadata['document_version_id']) && is_numeric($metadata['document_version_id']) ? (int) $metadata['document_version_id'] : null,
        ];
    }

    private function historyDocumentSummary(array $metadata): ?array
    {
        $documentId = isset($metadata['document_id']) && is_numeric($metadata['document_id']) ? (int) $metadata['document_id'] : 0;
        if ($documentId < 1) {
            return null;
        }
        $documentNumber = isset($metadata['document_number']) && is_scalar($metadata['document_number']) ? trim((string) $metadata['document_number']) : '';
        $fileName = isset($metadata['file_name']) && is_scalar($metadata['file_name']) ? trim((string) $metadata['file_name']) : '';
        if ($documentNumber === '' || $fileName === '') {
            $row = $this->row('SELECT d.document_number, dv.file_name FROM document d LEFT JOIN document_version dv ON dv.document_id = d.document_id AND dv.is_current = TRUE AND dv.deleted_at IS NULL WHERE d.document_id = :id AND d.deleted_at IS NULL LIMIT 1', ['id' => $documentId]);
            if (is_array($row)) {
                $documentNumber = $documentNumber !== '' ? $documentNumber : (string) ($row['document_number'] ?? '');
                $fileName = $fileName !== '' ? $fileName : (string) ($row['file_name'] ?? '');
            }
        }
        return [
            'id' => $documentId,
            'documentNo' => $documentNumber,
            'fileName' => $fileName,
        ];
    }

    private function historyMetadata(string $json): array
    {
        if (trim($json) === '') {
            return [];
        }
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            return is_array($data) ? $data : [];
        } catch (Throwable) {
            return [];
        }
    }

    private function documentsForContract(int $contractId, ?string $contractStatus = null): array
    {
        $requirementExclusion = $this->hasTable('contract_client_requirement')
            ? ' AND NOT EXISTS (SELECT 1 FROM contract_client_requirement ccr WHERE ccr.uploaded_document_id = d.document_id)'
            : '';
        $googleWorkingExclusion = '';
        if ($contractStatus === 'DRAFT' && $this->hasTable('contract_google_document')) {
            $googleWorkingExclusion = " AND NOT EXISTS (
                SELECT 1
                FROM contract_google_document cgd
                WHERE cgd.contract_id = :google_contract_id
                  AND cgd.working_document_status = 'WORKING'
                  AND cgd.synced_document_id = d.document_id
            )";
        }
        $params = ['id' => $contractId, 'id_ref' => $contractId];
        if ($googleWorkingExclusion !== '') {
            $params['google_contract_id'] = $contractId;
        }
        return array_map(fn (array $row): array => [
            'id' => (int) $row['document_id'],
            'documentNo' => (string) $row['document_number'],
            'title' => (string) $row['document_title'],
            'category' => (string) $row['category_name'],
            'status' => (string) $row['document_status'],
            'version' => 'v' . (int) $row['current_version_number'],
            'uploadedAt' => (string) ($row['uploaded_at'] ?? $row['created_at']),
            'expirationDate' => $row['expiration_date'],
            'isPrimary' => (bool) $row['is_primary_document'],
            'fileName' => (string) ($row['file_name'] ?? ''),
        ], $this->rows("SELECT d.*, dc.category_name, rd.is_primary_document, dv.file_name, dv.uploaded_at FROM record r INNER JOIN record_document rd ON rd.record_id = r.record_id INNER JOIN document d ON d.document_id = rd.document_id AND d.deleted_at IS NULL INNER JOIN document_category dc ON dc.document_category_id = d.document_category_id LEFT JOIN document_version dv ON dv.document_id = d.document_id AND dv.is_current = TRUE AND dv.deleted_at IS NULL WHERE r.deleted_at IS NULL AND r.source_module = 'contract_management' AND (r.source_entity_id = :id OR r.source_entity_type = (SELECT contract_number FROM contract WHERE contract_id = :id_ref))$requirementExclusion$googleWorkingExclusion ORDER BY rd.is_primary_document DESC, d.updated_at DESC", $params));
    }

    private function clientRequirementsForContract(int $contractId): array
    {
        if (!$this->clientRequirementInfrastructureReady()) {
            return ['installed' => false, 'items' => [], 'complete' => 0, 'total' => 0, 'requiredMissing' => 0, 'readiness' => ['ready' => false, 'message' => 'Client requirement status is unavailable.']];
        }
        $this->initializeClientRequirements($contractId);
        $items = array_map(fn (array $row): array => [
            'id' => (int) $row['contract_client_requirement_id'],
            'code' => (string) $row['requirement_code'],
            'name' => (string) $row['requirement_name'],
            'description' => (string) ($row['requirement_description'] ?? ''),
            'classification' => (string) $row['requirement_classification'],
            'required' => (string) $row['requirement_classification'] === 'REQUIRED',
            'applicabilityStatus' => (string) $row['applicability_status'],
            'status' => (string) $row['requirement_status'],
            'verificationStatus' => (string) $row['verification_status'],
            'uploadedAt' => $row['uploaded_at'],
            'uploadedBy' => (string) ($row['uploaded_by_name'] ?? ''),
            'verifiedAt' => $row['verified_at'] ?? null,
            'verifiedBy' => (string) ($row['verified_by_name'] ?? ''),
            'rejectedAt' => $row['rejected_at'] ?? null,
            'rejectedBy' => (string) ($row['rejected_by_name'] ?? ''),
            'rejectionReason' => (string) ($row['rejection_reason'] ?? ''),
            'document' => $row['document_id'] === null ? null : [
                'id' => (int) $row['document_id'],
                'documentNo' => (string) $row['document_number'],
                'title' => (string) $row['document_title'],
                'version' => 'v' . (int) $row['current_version_number'],
                'fileName' => (string) ($row['file_name'] ?? ''),
            ],
        ], $this->rows("SELECT ccr.*, d.document_id, d.document_number, d.document_title, d.current_version_number, dv.file_name, uploaded_emp.full_name uploaded_by_name, verified_emp.full_name verified_by_name, rejected_emp.full_name rejected_by_name FROM contract_client_requirement ccr LEFT JOIN document d ON d.document_id = ccr.uploaded_document_id AND d.deleted_at IS NULL LEFT JOIN document_version dv ON dv.document_id = d.document_id AND dv.is_current = TRUE AND dv.deleted_at IS NULL LEFT JOIN user_account uploaded_user ON uploaded_user.user_account_id = ccr.uploaded_by_user_id LEFT JOIN employee_reference uploaded_emp ON uploaded_emp.employee_reference_id = uploaded_user.employee_reference_id LEFT JOIN user_account verified_user ON verified_user.user_account_id = ccr.verified_by_user_id LEFT JOIN employee_reference verified_emp ON verified_emp.employee_reference_id = verified_user.employee_reference_id LEFT JOIN user_account rejected_user ON rejected_user.user_account_id = ccr.rejected_by_user_id LEFT JOIN employee_reference rejected_emp ON rejected_emp.employee_reference_id = rejected_user.employee_reference_id WHERE ccr.contract_id = :id ORDER BY FIELD(ccr.requirement_classification, 'REQUIRED','CONDITIONAL','OPTIONAL'), ccr.created_at ASC, ccr.contract_client_requirement_id ASC", ['id' => $contractId]));
        $readiness = $this->clientRequirementReadinessFromItems($items);
        return [
            'installed' => true,
            'items' => $items,
            'complete' => $readiness['complete'],
            'total' => count($items),
            'requiredMissing' => count($readiness['requiredIncomplete']),
            'conditionalPending' => count($readiness['conditionalPending']),
            'conditionalIncomplete' => count($readiness['conditionalIncomplete']),
            'readiness' => $readiness,
        ];
    }

    private function initializeClientRequirements(int $contractId): void
    {
        $existing = (int) $this->scalar('SELECT COUNT(*) FROM contract_client_requirement WHERE contract_id = :id', ['id' => $contractId]);
        if ($existing > 0) {
            return;
        }
        $createdBy = (int) ($this->scalar('SELECT created_by_user_id FROM contract WHERE contract_id = :id LIMIT 1', ['id' => $contractId]) ?? 0);
        if ($createdBy < 1) {
            throw new RuntimeException('Contract creator metadata is required to initialize client requirements.');
        }
        foreach (self::DEFAULT_CLIENT_REQUIREMENTS as [$code, $name, $description, $classification]) {
            $this->pdo->prepare("INSERT INTO contract_client_requirement (contract_id, requirement_code, requirement_name, requirement_description, requirement_classification, applicability_status, requirement_status, verification_status, created_by_user_id, created_at, updated_at) VALUES (:contract_id, :code, :name, :description, :classification, :applicability, 'MISSING', 'PENDING', :created_by, NOW(), NOW())")->execute([
                'contract_id' => $contractId,
                'code' => $code,
                'name' => $name,
                'description' => $description,
                'classification' => $classification,
                'applicability' => $classification === 'CONDITIONAL' ? 'PENDING' : 'APPLICABLE',
                'created_by' => $createdBy,
            ]);
        }
    }

    private function assertClientRequirementsReadyForApproval(int $contractId): void
    {
        $requirements = $this->clientRequirementsForContract($contractId);
        if (empty($requirements['installed']) || !is_array($requirements['readiness'] ?? null)) {
            throw new DomainException('Client requirement status is unavailable. Contract cannot be submitted for approval until compliance requirements can be evaluated.');
        }
        $readiness = $requirements['readiness'];
        if (!empty($readiness['ready'])) {
            return;
        }
        $parts = ['Cannot submit contract for approval.'];
        if (!empty($readiness['requiredIncomplete'])) {
            $parts[] = count($readiness['requiredIncomplete']) . ' required client requirements are incomplete: ' . implode(', ', $readiness['requiredIncomplete']) . '.';
        }
        if (!empty($readiness['conditionalPending'])) {
            $parts[] = 'Conditional applicability is pending for: ' . implode(', ', $readiness['conditionalPending']) . '.';
        }
        if (!empty($readiness['conditionalIncomplete'])) {
            $parts[] = 'Applicable conditional requirements are incomplete: ' . implode(', ', $readiness['conditionalIncomplete']) . '.';
        }
        throw new ContractWorkflowException('CLIENT_REQUIREMENTS_INCOMPLETE', implode(' ', $parts), [
            'requiredIncomplete' => $readiness['requiredIncomplete'],
            'conditionalPending' => $readiness['conditionalPending'],
            'conditionalIncomplete' => $readiness['conditionalIncomplete'],
        ]);
    }

    private function assertContractDatesReadyForApproval(array $contract): void
    {
        $this->assertContractDateAuthoritySchema();
        $source = $this->currentFinalizedContractDateSource((int) $contract['contract_id']);
        $startDate = $this->contractDateValue($contract['start_date'] ?? null);
        $endDate = $this->contractDateValue($contract['end_date'] ?? null);
        $confirmedVersionId = (int) ($contract['contract_dates_source_document_version_id'] ?? 0);
        $currentVersionId = (int) ($source['document_version_id'] ?? 0);
        $confirmedAt = (string) ($contract['contract_dates_confirmed_at'] ?? '');
        $ready = $source !== null
            && $startDate !== ''
            && $endDate !== ''
            && $confirmedAt !== ''
            && $confirmedVersionId > 0
            && $confirmedVersionId === $currentVersionId
            && $endDate >= $startDate;
        if ($ready) {
            return;
        }

        throw new ContractWorkflowException('CONTRACT_DATES_UNCONFIRMED', 'Cannot submit contract for approval. Confirm the contract dates first.', [
            'startDateEstablished' => $startDate !== '',
            'endDateEstablished' => $endDate !== '',
            'currentDocumentVersionId' => $currentVersionId ?: null,
            'confirmedDocumentVersionId' => $confirmedVersionId ?: null,
            'stale' => $confirmedVersionId > 0 && $currentVersionId > 0 && $confirmedVersionId !== $currentVersionId,
        ]);
    }

    private function automaticActivationCandidates(string $today): array
    {
        return $this->rows("SELECT contract_id FROM contract WHERE deleted_at IS NULL AND contract_status = 'APPROVED' AND COALESCE(effective_date, start_date) IS NOT NULL AND COALESCE(effective_date, start_date) NOT IN (:start_sentinel, :end_sentinel) AND COALESCE(effective_date, start_date) <= :today ORDER BY COALESCE(effective_date, start_date), contract_id", [
            'start_sentinel' => self::UNESTABLISHED_START_DATE,
            'end_sentinel' => self::UNESTABLISHED_END_DATE,
            'today' => $today,
        ]);
    }

    private function automaticExpirationCandidates(string $today): array
    {
        return $this->rows("SELECT contract_id FROM contract WHERE deleted_at IS NULL AND contract_status = 'ACTIVE' AND end_date IS NOT NULL AND end_date NOT IN (:start_sentinel, :end_sentinel) AND end_date < :today ORDER BY end_date, contract_id", [
            'start_sentinel' => self::UNESTABLISHED_START_DATE,
            'end_sentinel' => self::UNESTABLISHED_END_DATE,
            'today' => $today,
        ]);
    }

    private function processAutomaticActivationCandidate(int $contractId, string $today, bool $dryRun): array
    {
        try {
            $this->pdo->beginTransaction();
            $contract = $this->row('SELECT * FROM contract WHERE contract_id = :id AND deleted_at IS NULL FOR UPDATE', ['id' => $contractId]);
            if ($contract === null || (string) $contract['contract_status'] !== 'APPROVED') {
                $this->pdo->rollBack();
                return $this->automaticLifecycleOutcome('skipped', $contractId, (string) ($contract['contract_number'] ?? ''), 'Status no longer eligible.');
            }
            $activationDate = $this->contractDateValue($contract['effective_date'] ?? null) ?: $this->contractDateValue($contract['start_date'] ?? null);
            if ($activationDate === '' || $activationDate > $today || !$this->contractDateAuthorityCurrent($contract)) {
                $this->pdo->rollBack();
                return $this->automaticLifecycleOutcome('skipped', $contractId, (string) $contract['contract_number'], 'Activation date is not due or date authority is not current.');
            }
            if ($dryRun) {
                $this->pdo->rollBack();
                return $this->automaticLifecycleOutcome('activated', $contractId, (string) $contract['contract_number'], "Would activate on $today.", ['activation_date' => $activationDate]);
            }
            $this->pdo->prepare("UPDATE contract SET contract_status = 'ACTIVE', updated_by_user_id = NULL, updated_at = NOW() WHERE contract_id = :id AND contract_status = 'APPROVED' AND deleted_at IS NULL")->execute(['id' => $contractId]);
            $this->historyEvent($contractId, 'AUTO_ACTIVATED', 'Contract automatically activated on effective date.', 'APPROVED', 'ACTIVE', null, [
                'activation_date' => $activationDate,
                'processed_date' => $today,
                'source' => 'contract_lifecycle_worker',
            ]);
            $this->pdo->commit();
            return $this->automaticLifecycleOutcome('activated', $contractId, (string) $contract['contract_number'], "Activated on $today.", ['activation_date' => $activationDate]);
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return $this->automaticLifecycleOutcome('failed', $contractId, '', $exception->getMessage());
        }
    }

    private function processAutomaticExpirationCandidate(int $contractId, string $today, bool $dryRun): array
    {
        try {
            $this->pdo->beginTransaction();
            $contract = $this->row('SELECT * FROM contract WHERE contract_id = :id AND deleted_at IS NULL FOR UPDATE', ['id' => $contractId]);
            if ($contract === null || (string) $contract['contract_status'] !== 'ACTIVE') {
                $this->pdo->rollBack();
                return $this->automaticLifecycleOutcome('skipped', $contractId, (string) ($contract['contract_number'] ?? ''), 'Status no longer eligible.');
            }
            $endDate = $this->contractDateValue($contract['end_date'] ?? null);
            if ($endDate === '' || $endDate >= $today) {
                $this->pdo->rollBack();
                return $this->automaticLifecycleOutcome('skipped', $contractId, (string) $contract['contract_number'], 'End date has not passed.');
            }
            if ($dryRun) {
                $this->pdo->rollBack();
                return $this->automaticLifecycleOutcome('expired', $contractId, (string) $contract['contract_number'], "Would expire on $today.", ['end_date' => $endDate]);
            }
            $this->pdo->prepare("UPDATE contract SET contract_status = 'EXPIRED', updated_by_user_id = NULL, updated_at = NOW() WHERE contract_id = :id AND contract_status = 'ACTIVE' AND deleted_at IS NULL")->execute(['id' => $contractId]);
            $this->historyEvent($contractId, 'AUTO_EXPIRED', 'Contract automatically expired after contract end date.', 'ACTIVE', 'EXPIRED', null, [
                'end_date' => $endDate,
                'processed_date' => $today,
                'source' => 'contract_lifecycle_worker',
            ]);
            $this->pdo->commit();
            return $this->automaticLifecycleOutcome('expired', $contractId, (string) $contract['contract_number'], "Expired on $today.", ['end_date' => $endDate]);
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return $this->automaticLifecycleOutcome('failed', $contractId, '', $exception->getMessage());
        }
    }

    private function contractDateAuthorityCurrent(array $contract): bool
    {
        if (!$this->hasColumn('contract', 'contract_dates_source_document_version_id')) {
            return false;
        }
        $source = $this->currentFinalizedContractDateSource((int) $contract['contract_id']);
        $currentVersionId = (int) ($source['document_version_id'] ?? 0);
        $confirmedVersionId = (int) ($contract['contract_dates_source_document_version_id'] ?? 0);
        return $source !== null
            && $currentVersionId > 0
            && $confirmedVersionId === $currentVersionId
            && (string) ($contract['contract_dates_confirmed_at'] ?? '') !== '';
    }

    private function automaticLifecycleOutcome(string $status, int $contractId, string $contractNumber, string $message, array $metadata = []): array
    {
        return [
            'status' => $status,
            'contract_id' => $contractId,
            'contract_number' => $contractNumber,
            'message' => $message,
            'metadata' => $metadata,
        ];
    }

    private function assertSignedContractReadyForApproval(array $contract): void
    {
        $this->assertSignedContractSchema();
        $documentId = (int) ($contract['signed_document_id'] ?? 0);
        $versionId = (int) ($contract['signed_document_version_id'] ?? 0);
        if ($documentId > 0 && $versionId > 0 && $this->documentVersionExists($documentId, $versionId)) {
            return;
        }

        throw new ContractWorkflowException('SIGNED_CONTRACT_REQUIRED', 'Cannot submit for approval. Upload the signed contract first.', [
            'signedDocumentUploaded' => $documentId > 0 && $versionId > 0,
        ]);
    }

    private function ensureContractArtifactConfidential(int $documentId): void
    {
        $this->pdo->prepare("UPDATE document SET confidentiality_level = 'CONFIDENTIAL', updated_at = NOW() WHERE document_id = :id AND deleted_at IS NULL AND confidentiality_level <> 'CONFIDENTIAL'")->execute(['id' => $documentId]);
    }

    private function assertApprovalRequestSignedDocumentVersion(array $request): void
    {
        $this->assertSignedContractSchema();
        $documentId = (int) ($request['signed_document_id'] ?? 0);
        $versionId = (int) ($request['signed_document_version_id'] ?? 0);
        if ($documentId > 0 && $versionId > 0 && $this->documentVersionExists($documentId, $versionId)) {
            return;
        }

        throw new ContractWorkflowException('SIGNED_CONTRACT_REQUIRED', 'Cannot approve contract. The signed contract version for this approval cycle is unavailable.');
    }

    private function assertSignedContractSchema(): void
    {
        foreach (['signed_document_id', 'signed_document_version_id'] as $column) {
            if (!$this->hasColumn('contract', $column)) {
                throw new DomainException('Signed contract workflow schema has not been installed.');
            }
        }
        foreach (['signed_document_id', 'signed_document_version_id'] as $column) {
            if (!$this->hasColumn('approval_request', $column)) {
                throw new DomainException('Signed contract approval binding schema has not been installed.');
            }
        }
    }

    private function signedContractSchemaReady(): bool
    {
        foreach (['signed_document_id', 'signed_document_version_id'] as $column) {
            if (!$this->hasColumn('contract', $column)) {
                return false;
            }
        }
        foreach (['signed_document_id', 'signed_document_version_id'] as $column) {
            if (!$this->hasColumn('approval_request', $column)) {
                return false;
            }
        }
        return true;
    }

    private function assertLegacySignedVerificationSchema(): void
    {
        $this->assertSignedContractSchema();
        foreach (['signed_document_verified_by_user_id', 'signed_document_verified_at'] as $column) {
            if (!$this->hasColumn('contract', $column)) {
                throw new DomainException('Signed contract verification is no longer part of the active workflow.');
            }
        }
    }

    private function documentVersionExists(int $documentId, int $versionId): bool
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM document_version dv INNER JOIN document d ON d.document_id = dv.document_id AND d.deleted_at IS NULL WHERE dv.document_id = :document_id AND dv.document_version_id = :version_id AND dv.deleted_at IS NULL', [
            'document_id' => $documentId,
            'version_id' => $versionId,
        ]) > 0;
    }

    private function currentDocumentVersionId(int $documentId): ?int
    {
        $id = (int) $this->scalar('SELECT document_version_id FROM document_version WHERE document_id = :id AND is_current = TRUE AND deleted_at IS NULL LIMIT 1', ['id' => $documentId]);
        return $id > 0 ? $id : null;
    }

    private function assertContractDateAuthoritySchema(): void
    {
        foreach (['contract_dates_source_document_id', 'contract_dates_source_document_version_id', 'contract_dates_confirmed_by_user_id', 'contract_dates_confirmed_at'] as $column) {
            if (!$this->hasColumn('contract', $column)) {
                throw new DomainException('Contract date confirmation schema has not been installed.');
            }
        }
    }

    private function currentFinalizedContractDateSource(int $contractId): ?array
    {
        if (!$this->hasTable('contract_google_document')) {
            return null;
        }
        $row = $this->row("SELECT cgd.synced_document_id document_id, cgd.synced_document_version_id document_version_id, d.contract_metadata_status, d.contract_metadata_candidate_json, d.effective_date, d.expiration_date FROM contract_google_document cgd INNER JOIN document d ON d.document_id = cgd.synced_document_id AND d.deleted_at IS NULL INNER JOIN document_version dv ON dv.document_version_id = cgd.synced_document_version_id AND dv.document_id = d.document_id AND dv.deleted_at IS NULL WHERE cgd.contract_id = :id AND cgd.working_document_status = 'FINALIZED' AND cgd.synced_document_id IS NOT NULL AND cgd.synced_document_version_id IS NOT NULL LIMIT 1", ['id' => $contractId]);
        if (!is_array($row)) {
            return null;
        }
        $candidate = [];
        if (trim((string) ($row['contract_metadata_candidate_json'] ?? '')) !== '') {
            try {
                $decoded = json_decode((string) $row['contract_metadata_candidate_json'], true, 512, JSON_THROW_ON_ERROR);
                $candidate = is_array($decoded) ? $decoded : [];
            } catch (Throwable) {
                $candidate = [];
            }
        }
        return $row + ['candidate' => $candidate];
    }

    private function tryAnalyzeContractDates(int $contractId, array $user): void
    {
        $source = $this->currentFinalizedContractDateSource($contractId);
        if ($source === null) {
            return;
        }
        try {
            (new ContractMetadataExtractionService($this->pdo, new DocumentService($this->pdo)))->analyze((int) $source['document_id'], $user);
        } catch (Throwable) {
            // Date extraction is advisory. A valid review transition must not depend on AI availability.
        }
    }

    private function realContractDate(mixed $value, string $field): string
    {
        $date = $this->date($value);
        if ($date === null || in_array($date, [self::UNESTABLISHED_START_DATE, self::UNESTABLISHED_END_DATE], true)) {
            throw new InvalidArgumentException(json_encode([$field => 'Enter a valid contract date.'], JSON_THROW_ON_ERROR));
        }
        return $date;
    }

    private function candidateDate(array $candidate, string $key): ?string
    {
        $date = $this->date($candidate[$key] ?? null);
        return $date === null || in_array($date, [self::UNESTABLISHED_START_DATE, self::UNESTABLISHED_END_DATE], true) ? null : $date;
    }

    private function recalculateContractRetentionRecords(int $contractId, int $userId): void
    {
        $retention = new RetentionService($this->pdo);
        $rows = $this->rows("SELECT r.record_id, rs.* FROM record r INNER JOIN retention_schedule rs ON rs.retention_schedule_id = r.retention_schedule_id WHERE r.deleted_at IS NULL AND r.source_module = 'contract_management' AND r.source_entity_id = :id", ['id' => $contractId]);
        foreach ($rows as $row) {
            $retention->resolveAndApplyRetentionTrigger((int) $row['record_id'], $row, null, $userId);
        }
    }

    private function clientRequirementInfrastructureReady(): bool
    {
        foreach (['requirement_code', 'requirement_classification', 'applicability_status', 'requirement_status', 'verification_status', 'uploaded_document_id', 'verified_by_user_id', 'verified_at', 'rejected_by_user_id', 'rejected_at', 'rejection_reason'] as $column) {
            if (!$this->hasTable('contract_client_requirement') || !$this->hasColumn('contract_client_requirement', $column)) {
                return false;
            }
        }
        return true;
    }

    private function assertClientRequirementInfrastructure(): void
    {
        if (!$this->clientRequirementInfrastructureReady()) {
            throw new DomainException('Client requirement status is unavailable. Contract cannot be submitted for approval until compliance requirements can be evaluated.');
        }
    }

    private function clientRequirementReadinessFromItems(array $items): array
    {
        $requiredIncomplete = [];
        $conditionalPending = [];
        $conditionalIncomplete = [];
        $complete = 0;

        foreach ($items as $item) {
            $classification = strtoupper((string) ($item['classification'] ?? ''));
            $applicability = strtoupper((string) ($item['applicabilityStatus'] ?? 'APPLICABLE'));
            $status = strtoupper((string) ($item['status'] ?? 'MISSING'));
            $verification = strtoupper((string) ($item['verificationStatus'] ?? 'PENDING'));
            $verified = $status === 'VERIFIED' && $verification === 'VERIFIED';
            if ($classification === 'REQUIRED') {
                if ($verified) {
                    $complete++;
                } else {
                    $requiredIncomplete[] = (string) $item['name'];
                }
                continue;
            }
            if ($classification === 'CONDITIONAL') {
                if ($applicability === 'NOT_APPLICABLE' || $status === 'NOT_APPLICABLE') {
                    $complete++;
                } elseif ($applicability === 'PENDING') {
                    $conditionalPending[] = (string) $item['name'];
                } elseif ($verified) {
                    $complete++;
                } else {
                    $conditionalIncomplete[] = (string) $item['name'];
                }
            }
        }

        return [
            'ready' => $requiredIncomplete === [] && $conditionalPending === [] && $conditionalIncomplete === [],
            'complete' => $complete,
            'requiredIncomplete' => $requiredIncomplete,
            'conditionalPending' => $conditionalPending,
            'conditionalIncomplete' => $conditionalIncomplete,
        ];
    }

    private function historyEvent(int $id, string $event, string $description, ?string $from, ?string $to, ?int $userId, array $metadata = []): void
    {
        $this->pdo->prepare('INSERT INTO contract_history (contract_id, event_type, event_description, from_status, to_status, actor_user_id, event_at, metadata_json, created_at) VALUES (:id, :event, :description, :from_status, :to_status, :user_id, NOW(), :metadata, NOW())')->execute([
            'id' => $id,
            'event' => $event,
            'description' => $description,
            'from_status' => $from,
            'to_status' => $to,
            'user_id' => $userId,
            'metadata' => $metadata ? json_encode($metadata, JSON_THROW_ON_ERROR) : null,
        ]);
    }

    private function transitionEvent(string $from, string $to, array $contract, array $data): array
    {
        $number = (string) $contract['contract_number'];
        return match ($to) {
            'FOR_REVIEW' => ['SUBMITTED_FOR_REVIEW', "Contract $number submitted for review."],
            'FOR_APPROVAL' => ['SUBMITTED_FOR_APPROVAL', "Contract $number submitted for lifecycle approval."],
            'APPROVED' => ['APPROVED', "Contract $number approved through Phase 2 lifecycle approval."],
            'REJECTED' => ['REJECTED', "Contract $number rejected."],
            'ACTIVE' => ['ACTIVATED', "Contract $number activated."],
            'TERMINATED' => ['TERMINATED', "Contract $number terminated."],
            'EXPIRED' => ['EXPIRED', "Contract $number marked expired."],
            'ARCHIVED' => ['ARCHIVED', "Contract $number archived."],
            'CANCELLED' => ['CANCELLED', "Contract $number cancelled."],
            'DRAFT' => ['RETURNED_FOR_CHANGES', "Contract $number returned to draft."],
            default => ['STATUS_CHANGED', "Contract $number moved from $from to $to."],
        };
    }

    private function transitionMetadata(string $target, array $data): array
    {
        $keys = ['reason', 'executed_date', 'effective_date', 'termination_date'];
        $metadata = [];
        foreach ($keys as $key) {
            if (isset($data[$key]) && $data[$key] !== '') {
                $metadata[$key] = $data[$key];
            }
        }
        return $metadata;
    }

    private function eligibility(): FamEmployeeEligibilityService
    {
        return $this->employeeEligibility ?? new FamEmployeeEligibilityService($this->pdo);
    }

    private function exists(string $table, string $column, int $id, string $extra = '1=1'): bool
    {
        $statement = $this->pdo->prepare("SELECT 1 FROM $table WHERE $column = :id AND $extra LIMIT 1");
        $statement->execute(['id' => $id]);
        return (bool) $statement->fetchColumn();
    }

    private function id(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 'null') return null;
        if (!ctype_digit((string) $value)) return null;
        $id = (int) $value;
        return $id > 0 ? $id : null;
    }

    private function requiredDate(mixed $value, string $field, ?array &$errors = null): ?string
    {
        if ($value === null || $value === '') {
            if (is_array($errors)) {
                $errors[$field] = 'Enter a valid date.';
                return null;
            }
            throw new InvalidArgumentException(json_encode([$field => 'Enter a valid date.'], JSON_THROW_ON_ERROR));
        }
        return $this->date($value);
    }

    private function date(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        try {
            return (new DateTimeImmutable((string) $value))->format('Y-m-d');
        } catch (Throwable) {
            throw new InvalidArgumentException(json_encode(['date' => 'Enter a valid date.'], JSON_THROW_ON_ERROR));
        }
    }

    private function amount(mixed $value, string $field, array &$errors): string
    {
        if (!is_numeric($value) || (float) $value < 0) {
            $errors[$field] = 'Amount must be zero or greater.';
            return '0.00';
        }
        return number_format((float) $value, 2, '.', '');
    }

    private function nullableAmount(mixed $value, string $field, array &$errors): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return $this->amount($value, $field, $errors);
    }

    private function displayMoney(mixed $amount, mixed $currency): ?string
    {
        if ($amount === null || $amount === '' || $currency === null || $currency === '') {
            return null;
        }
        if ((float) $amount <= 0.0) {
            return null;
        }
        return trim((string) $currency . ' ' . number_format((float) $amount, 2));
    }

    private function requiredText(mixed $value, int $max, string $field): string
    {
        $text = $this->text($value, $max);
        if ($text === '') {
            throw new InvalidArgumentException(json_encode([$field => 'This field is required.'], JSON_THROW_ON_ERROR));
        }
        return $text;
    }

    private function nullableText(mixed $value, int $max): ?string
    {
        $text = $this->text($value, $max);
        return $text === '' ? null : $text;
    }

    private function text(mixed $value, int $max): string
    {
        return mb_substr(trim((string) $value), 0, $max);
    }

    private function nullableUpper(mixed $value, int $max): ?string
    {
        $text = strtoupper($this->text($value ?? '', $max));
        return $text === '' ? null : $text;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') return null;
        return is_numeric($value) ? (int) $value : null;
    }

    private function rows(string $sql, array $params = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll();
    }

    private function row(string $sql, array $params = []): ?array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    private function scalar(string $sql, array $params = []): mixed
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement->fetchColumn();
    }
}
