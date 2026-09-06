<?php

declare(strict_types=1);

final class ContractGoogleDocumentService
{
    private const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    private const UNESTABLISHED_START_DATE = '1000-01-01';
    private const UNESTABLISHED_END_DATE = '9999-12-31';

    public function __construct(
        private readonly PDO $pdo,
        private readonly GoogleOAuthService $oauth,
        private readonly GoogleDriveService $drive,
        private readonly ?DocumentService $documents = null
    ) {
    }

    public function status(int $contractId, array $user): array
    {
        ContractPolicy::requirePermission($user, 'contract.view');
        $context = $this->context($contractId);
        if ($context === null) {
            return ['available' => false, 'reason' => 'Contract was not found.'];
        }
        $config = $this->oauth->configuration();
        $connection = $this->oauth->connectionForUser((int) $user['id']);
        $link = $this->googleLink($contractId);
        $policy = $this->confidentialityAllowed((string) ($context['confidentiality_level'] ?? ''));
        $schemaReady = $this->tableExists('google_account_connection') && $this->tableExists('contract_google_document');
        $eligibility = $this->eligibility($context, $user);

        return [
            'available' => $config['enabled'] && $config['configured'] && $schemaReady && $policy['allowed'],
            'enabled' => $config['enabled'],
            'configured' => $config['configured'],
            'schemaReady' => $schemaReady,
            'connected' => $connection !== null,
            'googleEmail' => $connection['google_email'] ?? null,
            'eligible' => $eligibility['eligible'],
            'eligibilityReason' => $eligibility['reason'],
            'canCreate' => $eligibility['eligible'] && $link === null,
            'canSync' => $eligibility['eligible'] && $this->linkCanSync($link),
            'reason' => $this->statusReason($config, $schemaReady, $policy),
            'template' => $this->templateSummary($context),
            'document' => $link ? $this->linkSummary($link) : null,
        ];
    }

    public function hasWorkingDocument(int $contractId): bool
    {
        $link = $this->googleLink($contractId);
        return $this->linkCanSync($link);
    }

    public function createWorkingDocument(int $contractId, array $user): array
    {
        ContractPolicy::requirePermission($user, 'contract.edit');
        $context = $this->context($contractId);
        if ($context === null) {
            throw new DomainException('Contract was not found.');
        }
        $existing = $this->googleLink($contractId);
        if ($existing !== null) {
            return $this->linkSummary($existing);
        }
        $this->assertWorkAllowed($context, $user);
        $connection = $this->oauth->connectionForUser((int) $user['id']);
        if ($connection === null) {
            throw new GoogleIntegrationException('google_connection', 'Connect your Google account before using Google Docs authoring.');
        }
        $file = $this->templateFile($context);
        $content = (string) file_get_contents((string) $file['absolute_path']);
        $content = $this->prefillDocx($content, $context);
        $name = trim((string) $context['contract_number'] . ' - ' . (string) $context['contract_title']);
        $google = $this->drive->uploadDocxAsGoogleDoc((int) $user['id'], $name, $content);
        $fileId = (string) ($google['id'] ?? '');
        if ($fileId === '') {
            throw new GoogleIntegrationException('google_api', 'Google did not return a working document id.');
        }
        $this->pdo->prepare("INSERT INTO contract_google_document (contract_id, template_id, template_version_id, google_account_connection_id, google_file_id, google_document_id, google_web_view_url, working_document_status, created_by_user_id, created_at, updated_at) VALUES (:contract_id, :template_id, :template_version_id, :connection_id, :file_id, :document_id, :url, 'WORKING', :user_id, NOW(), NOW())")->execute([
            'contract_id' => (int) $context['contract_id'],
            'template_id' => (int) $context['template_id'],
            'template_version_id' => (int) $context['template_version_id'],
            'connection_id' => (int) $connection['google_account_connection_id'],
            'file_id' => $fileId,
            'document_id' => $fileId,
            'url' => (string) ($google['webViewLink'] ?? ''),
            'user_id' => (int) $user['id'],
        ]);
        $this->history((int) $context['contract_id'], 'CONTRACT_GOOGLE_DOCUMENT_CREATED', 'Google working document created.', $user, ['template_version_id' => (int) $context['template_version_id']]);
        return $this->linkSummary($this->googleLink($contractId) ?? []);
    }

    public function syncWorkingDocument(int $contractId, array $user, string $format = 'docx'): array
    {
        ContractPolicy::requirePermission($user, 'contract.edit');
        $context = $this->context($contractId);
        if ($context === null) {
            throw new DomainException('Contract was not found.');
        }
        $this->assertWorkAllowed($context, $user);
        $link = $this->googleLink($contractId);
        if ($link === null) {
            throw new DomainException('Create a Google working document before synchronizing.');
        }
        if (!$this->linkCanSync($link)) {
            throw new DomainException('This Google working document has already been finalized.');
        }
        $content = strtolower($format) === 'pdf'
            ? $this->drive->exportPdf((int) $user['id'], (string) $link['google_file_id'])
            : $this->drive->exportDocx((int) $user['id'], (string) $link['google_file_id']);
        $mime = strtolower($format) === 'pdf' ? 'application/pdf' : self::DOCX_MIME;
        $extension = strtolower($format) === 'pdf' ? 'pdf' : 'docx';
        $fileName = $this->safeFileName((string) $context['contract_number'] . '-google-working-copy.' . $extension);
        $document = $this->storeArtifact($context, $link, $content, $fileName, $mime, $user);
        if (empty($document['id'])) {
            throw new DomainException('Unable to synchronize because the FAM system document could not be stored.');
        }
        $versionId = $this->currentDocumentVersionId((int) $document['id']);
        if ($versionId === null) {
            throw new DomainException('Unable to synchronize because the FAM system document version could not be resolved.');
        }
        $this->pdo->prepare("UPDATE contract_google_document SET synced_document_id = :document_id, synced_document_version_id = :version_id, working_document_status = 'WORKING', finalized_at = NULL, last_synced_at = NOW(), updated_at = NOW() WHERE contract_google_document_id = :id")->execute([
            'document_id' => (int) $document['id'],
            'version_id' => $versionId,
            'id' => (int) $link['contract_google_document_id'],
        ]);
        $this->history((int) $context['contract_id'], 'CONTRACT_GOOGLE_DOCUMENT_SYNCED', 'Google working document synchronized to FAM.', $user, ['document_id' => (int) $document['id']]);
        return $this->linkSummary($this->googleLink($contractId) ?? []);
    }

    public function finalizeForReview(int $contractId, array $user): void
    {
        $link = $this->googleLink($contractId);
        if (!$this->linkCanSync($link)) {
            return;
        }
        $this->syncWorkingDocument($contractId, $user);
        $this->pdo->prepare("UPDATE contract_google_document SET working_document_status = 'FINALIZED', finalized_at = NOW(), updated_at = NOW() WHERE contract_id = :id")->execute(['id' => $contractId]);
        $this->history($contractId, 'CONTRACT_GOOGLE_DOCUMENT_FINALIZED', 'Google working document frozen as a FAM review artifact.', $user, []);
    }

    private function storeArtifact(array $context, array $link, string $content, string $fileName, string $mime, array $user): array
    {
        $categoryId = $this->contractCategoryId();
        $data = [
            'title' => $this->businessDocumentTitle($context),
            'description' => 'Draft contract artifact for ' . (string) $context['contract_title'] . '. Authoring provider: Google Docs.',
            'document_category_id' => $categoryId,
            'confidentiality_level' => 'CONFIDENTIAL',
            'status' => 'ACTIVE',
            'document_date' => date('Y-m-d'),
            'related_module' => 'CONTRACT_MANAGEMENT',
            'related_reference' => (string) $context['contract_number'],
            'change_summary' => 'Synchronized from Google Docs working document.',
        ];
        $service = $this->documents ?? new DocumentService($this->pdo);
        if (!empty($link['synced_document_id'])) {
            $this->ensureContractArtifactConfidential((int) $link['synced_document_id']);
            $document = $service->uploadVersionFromContent((int) $link['synced_document_id'], $content, $fileName, $mime, 'Synchronized from Google Docs working document.', $user);
            return $document ?? [];
        }
        $document = $service->createFromContent($data, $content, $fileName, $mime, $user);
        if (!empty($document['id'])) {
            $this->pdo->prepare("UPDATE record SET source_entity_id = :contract_id WHERE source_module = 'contract_management' AND source_entity_type = :contract_number AND source_entity_id IS NULL")->execute([
                'contract_id' => (int) $context['contract_id'],
                'contract_number' => (string) $context['contract_number'],
            ]);
        }
        return $document;
    }

    private function ensureContractArtifactConfidential(int $documentId): void
    {
        $this->pdo->prepare("UPDATE document SET confidentiality_level = 'CONFIDENTIAL', updated_at = NOW() WHERE document_id = :id AND deleted_at IS NULL AND confidentiality_level <> 'CONFIDENTIAL'")->execute(['id' => $documentId]);
    }

    private function businessDocumentTitle(array $context): string
    {
        $number = trim((string) ($context['contract_number'] ?? ''));
        $title = trim((string) ($context['contract_title'] ?? 'Contract'));
        if ($number === '') {
            return $title;
        }
        if ($title === '' || strcasecmp($title, $number) === 0) {
            return $number . ' - Contract';
        }
        return $number . ' - ' . $title;
    }

    private function context(int $contractId): ?array
    {
        return $this->row("SELECT c.*, ct.type_code, s.supplier_name, dt.template_code, dt.template_name, dt.template_type, dt.status template_status, tv.version_number, tv.document_id, tv.document_version_id, tv.status template_version_status, d.confidentiality_level, dv.file_name, dv.file_extension, dv.mime_type FROM contract c INNER JOIN contract_type ct ON ct.contract_type_id = c.contract_type_id LEFT JOIN supplier_reference s ON s.supplier_reference_id = c.supplier_reference_id LEFT JOIN document_template dt ON dt.template_id = c.template_id LEFT JOIN document_template_version tv ON tv.template_version_id = c.template_version_id AND tv.template_id = c.template_id LEFT JOIN document d ON d.document_id = tv.document_id AND d.deleted_at IS NULL LEFT JOIN document_version dv ON dv.document_version_id = tv.document_version_id AND dv.deleted_at IS NULL WHERE c.contract_id = :id AND c.deleted_at IS NULL LIMIT 1", ['id' => $contractId]);
    }

    private function assertWorkAllowed(array $context, array $user): void
    {
        if (!$this->canWork($context, $user)) {
            throw new DomainException('Google authoring is only available for draft contracts you can edit.');
        }
        if (!$this->tableExists('contract_google_document') || !$this->tableExists('google_account_connection')) {
            throw new GoogleIntegrationException('google_schema', 'Google integration tables have not been installed.');
        }
        $policy = $this->confidentialityAllowed((string) ($context['confidentiality_level'] ?? ''));
        if (!$policy['allowed']) {
            throw new GoogleIntegrationException('confidentiality_level', $policy['reason']);
        }
        $config = $this->oauth->configuration();
        if (!$config['enabled'] || !$config['configured']) {
            throw new GoogleIntegrationException('google_configuration', $this->statusReason($config, true, ['allowed' => true, 'reason' => null]) ?? 'Google Docs authoring is not configured.');
        }
    }

    private function canWork(array $context, array $user): bool
    {
        return $this->eligibility($context, $user)['eligible'];
    }

    private function eligibility(array $context, array $user): array
    {
        if (!ContractPolicy::hasPermission($user, 'contract.edit')) {
            return ['eligible' => false, 'reason' => 'You can view this Google authoring status, but you cannot edit this contract.'];
        }
        if ((string) ($context['contract_status'] ?? '') !== 'DRAFT') {
            return ['eligible' => false, 'reason' => 'Google authoring is only available while the contract is a draft.'];
        }
        if (empty($context['template_id']) || empty($context['template_version_id'])) {
            return ['eligible' => false, 'reason' => 'Assign a contract template before using Google authoring.'];
        }
        if (empty($context['document_id']) || empty($context['document_version_id'])) {
            return ['eligible' => false, 'reason' => 'The assigned template version does not have a stored file.'];
        }
        $extension = strtolower((string) ($context['file_extension'] ?? pathinfo((string) ($context['file_name'] ?? ''), PATHINFO_EXTENSION)));
        if ($extension !== 'docx') {
            return ['eligible' => false, 'reason' => 'Google authoring currently requires a DOCX template.'];
        }
        return ['eligible' => true, 'reason' => null];
    }

    private function templateFile(array $context): array
    {
        $extension = strtolower((string) ($context['file_extension'] ?? pathinfo((string) ($context['file_name'] ?? ''), PATHINFO_EXTENSION)));
        if ($extension !== 'docx') {
            throw new GoogleIntegrationException('template', 'Google Docs authoring currently requires a DOCX template.');
        }
        $file = ($this->documents ?? new DocumentService($this->pdo))->downloadVersion((int) $context['document_id'], (int) $context['document_version_id']);
        if ($file === null) {
            throw new GoogleIntegrationException('template', 'The stored template file is unavailable.');
        }
        return $file;
    }

    private function prefillDocx(string $content, array $context): string
    {
        if (!class_exists('ZipArchive')) {
            return $content;
        }
        $source = tempnam(sys_get_temp_dir(), 'fam_docx_');
        $target = tempnam(sys_get_temp_dir(), 'fam_docx_');
        if ($source === false || $target === false) {
            return $content;
        }
        file_put_contents($source, $content);
        copy($source, $target);
        $zip = new ZipArchive();
        if ($zip->open($target) !== true) {
            @unlink($source);
            @unlink($target);
            return $content;
        }
        $xml = (string) ($zip->getFromName('word/document.xml') ?: '');
        if ($xml !== '') {
            $values = $this->placeholderValues($context);
            $xml = preg_replace_callback('/\[\s*([A-Z0-9][A-Z0-9\s\/&.,_-]{1,120})\s*\]/i', function (array $match) use ($values): string {
                $key = strtoupper(trim(preg_replace('/\s+/', ' ', $match[1])));
                return array_key_exists($key, $values) && trim((string) $values[$key]) !== '' ? htmlspecialchars((string) $values[$key], ENT_XML1 | ENT_COMPAT, 'UTF-8') : $match[0];
            }, $xml) ?? $xml;
            $zip->addFromString('word/document.xml', $xml);
        }
        $zip->close();
        $merged = (string) file_get_contents($target);
        @unlink($source);
        @unlink($target);
        return $merged !== '' ? $merged : $content;
    }

    private function placeholderValues(array $context): array
    {
        $values = [
            'CONTRACT NUMBER' => (string) $context['contract_number'],
            'CONTRACT TITLE' => (string) $context['contract_title'],
            'CONTRACT TYPE' => (string) $context['type_code'],
            'CLIENT NAME' => $this->counterpartyDisplayName($context),
            'START DATE' => $this->placeholderDate($context['start_date'] ?? null),
            'END DATE' => $this->placeholderDate($context['end_date'] ?? null),
            'CONTRACT VALUE' => $this->placeholderMoney($context['current_amount'] ?? null, $context['currency_code'] ?? null),
            'CURRENCY' => (float) ($context['current_amount'] ?? 0) <= 0.0 ? '' : (string) ($context['currency_code'] ?? ''),
        ];
        $saved = $this->rows('SELECT field_code, value_text FROM contract_template_value WHERE contract_id = :contract_id AND template_version_id = :version_id', [
            'contract_id' => (int) $context['contract_id'],
            'version_id' => (int) $context['template_version_id'],
        ]);
        foreach ($saved as $row) {
            $values[strtoupper(str_replace('_', ' ', (string) $row['field_code']))] = (string) $row['value_text'];
        }
        return $values;
    }

    private function placeholderMoney(mixed $amount, mixed $currency): string
    {
        if ($amount === null || $amount === '' || $currency === null || $currency === '') {
            return '';
        }
        if ((float) $amount <= 0.0) {
            return '';
        }
        return trim((string) $currency . ' ' . number_format((float) $amount, 2));
    }

    private function counterpartyDisplayName(array $context): string
    {
        $snapshot = trim((string) ($context['counterparty_name'] ?? ''));
        if ($snapshot !== '') {
            return $snapshot;
        }
        return trim((string) ($context['supplier_name'] ?? ''));
    }

    private function placeholderDate(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (in_array((string) $value, [self::UNESTABLISHED_START_DATE, self::UNESTABLISHED_END_DATE], true)) {
            return '';
        }
        return (string) $value;
    }

    private function confidentialityAllowed(string $level): array
    {
        $allowed = array_filter(array_map('trim', explode(',', strtoupper((string) env('GOOGLE_DOCS_ALLOWED_CONFIDENTIALITY', 'PUBLIC,INTERNAL')))));
        $level = strtoupper($level ?: 'INTERNAL');
        if (!in_array($level, $allowed, true)) {
            return ['allowed' => false, 'reason' => 'Google Docs authoring is unavailable for this confidentiality level.'];
        }
        return ['allowed' => true, 'reason' => null];
    }

    private function statusReason(array $config, bool $schemaReady, array $policy): ?string
    {
        if (!$config['enabled']) {
            return 'Google Docs authoring is disabled.';
        }
        if (!$config['configured']) {
            return 'Google Docs authoring is not fully configured.';
        }
        if (!$schemaReady) {
            return 'Google integration tables have not been installed.';
        }
        return $policy['reason'];
    }

    private function googleLink(int $contractId): ?array
    {
        if (!$this->tableExists('contract_google_document')) {
            return null;
        }
        return $this->row('SELECT * FROM contract_google_document WHERE contract_id = :id LIMIT 1', ['id' => $contractId]);
    }

    private function linkSummary(array $row): array
    {
        $documentId = empty($row['synced_document_id']) ? null : (int) $row['synced_document_id'];
        $versionId = empty($row['synced_document_version_id']) ? null : (int) $row['synced_document_version_id'];
        $document = $documentId === null ? null : $this->syncedDocumentSummary($documentId, $versionId);

        return [
            'id' => (int) ($row['contract_google_document_id'] ?? 0),
            'status' => (string) ($row['working_document_status'] ?? ''),
            'webViewUrl' => (string) ($row['google_web_view_url'] ?? ''),
            'lastSyncedAt' => $row['last_synced_at'] ?? null,
            'finalizedAt' => $row['finalized_at'] ?? null,
            'syncedDocumentId' => $documentId,
            'syncedDocumentVersionId' => $versionId,
            'documentNo' => $document['document_number'] ?? '',
            'title' => $document['document_title'] ?? '',
            'version' => empty($document['version_number']) ? '' : 'v' . (int) $document['version_number'],
            'fileName' => $document['file_name'] ?? '',
        ];
    }

    private function syncedDocumentSummary(int $documentId, ?int $versionId): ?array
    {
        $versionJoin = $versionId !== null
            ? 'dv.document_version_id = :version_id'
            : 'dv.document_id = d.document_id AND dv.is_current = TRUE';
        $params = ['document_id' => $documentId];
        if ($versionId !== null) {
            $params['version_id'] = $versionId;
        }

        return $this->row("SELECT d.document_number, d.document_title, dv.version_number, dv.file_name FROM document d LEFT JOIN document_version dv ON $versionJoin AND dv.deleted_at IS NULL WHERE d.document_id = :document_id AND d.deleted_at IS NULL LIMIT 1", $params);
    }

    private function linkCanSync(?array $link): bool
    {
        if ($link === null || trim((string) ($link['google_file_id'] ?? '')) === '') {
            return false;
        }
        return in_array((string) ($link['working_document_status'] ?? ''), ['WORKING', 'FINALIZED'], true);
    }

    private function templateSummary(array $context): array
    {
        return [
            'templateCode' => (string) ($context['template_code'] ?? ''),
            'templateName' => (string) ($context['template_name'] ?? ''),
            'version' => empty($context['version_number']) ? '' : 'v' . (int) $context['version_number'],
            'confidentiality' => (string) ($context['confidentiality_level'] ?? ''),
        ];
    }

    private function contractCategoryId(): int
    {
        $id = (int) $this->scalar("SELECT document_category_id FROM document_category WHERE category_code = 'DOC-CON' AND status = 'ACTIVE' LIMIT 1");
        if ($id < 1) {
            throw new GoogleIntegrationException('document_category_id', 'Contract document category is not configured.');
        }
        return $id;
    }

    private function history(int $contractId, string $event, string $description, array $user, array $metadata): void
    {
        if (!$this->tableExists('contract_history')) {
            return;
        }
        $this->pdo->prepare('INSERT INTO contract_history (contract_id, event_type, event_description, actor_user_id, event_at, metadata_json, created_at) VALUES (:contract_id, :event, :description, :user_id, NOW(), :metadata, NOW())')->execute([
            'contract_id' => $contractId,
            'event' => $event,
            'description' => $description,
            'user_id' => (int) $user['id'],
            'metadata' => $metadata ? json_encode($metadata, JSON_THROW_ON_ERROR) : null,
        ]);
    }

    private function currentDocumentVersionId(int $documentId): ?int
    {
        $id = (int) $this->scalar('SELECT document_version_id FROM document_version WHERE document_id = :id AND is_current = TRUE AND deleted_at IS NULL LIMIT 1', ['id' => $documentId]);
        return $id > 0 ? $id : null;
    }

    private function safeFileName(string $value): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $value) ?: 'contract-google-working-copy.docx';
        return trim($name, '.-') ?: 'contract-google-working-copy.docx';
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table');
        $statement->execute(['table' => $table]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function row(string $sql, array $params = []): ?array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function rows(string $sql, array $params = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function scalar(string $sql, array $params = []): mixed
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement->fetchColumn();
    }
}
