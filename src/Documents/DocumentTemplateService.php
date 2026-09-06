<?php
declare(strict_types=1);

final class DocumentTemplatePolicy
{
    public static function requirePermission(array $user, string $permission): void
    {
        if (!in_array($permission, $user['permissions'] ?? [], true)) {
            jsonResponse(false, 'You do not have permission to perform this action.', [], 403);
        }
    }
}

final class DocumentTemplateService
{
    private const CANONICAL_TEMPLATE_TYPES = ['CLIENT_CONTRACT', 'EMPLOYEE_CONTRACT', 'NDA', 'CONTRACT_AMENDMENT', 'OTHER'];
    private const CONFIDENTIALITY_LEVELS = ['PUBLIC', 'INTERNAL', 'CONFIDENTIAL'];
    private const STATUSES = ['ACTIVE', 'RETIRED'];
    private const NAMESPACES = ['contract', 'client', 'employee', 'agency'];

    public function __construct(private readonly PDO $pdo, private readonly ?DocumentService $documentService = null)
    {
    }

    public function options(): array
    {
        return [
            'template_types' => $this->templateTypes(),
            'statuses' => self::STATUSES,
            'source_modules' => ['DOCUMENT_MANAGEMENT', 'CONTRACT_MANAGEMENT', 'LEGAL_MANAGEMENT', 'HR', 'OTHER'],
            'confidentiality_levels' => self::CONFIDENTIALITY_LEVELS,
            'merge_fields' => $this->mergeFields(),
        ];
    }

    public function mergeFields(): array
    {
        return array_map(fn (array $row): array => [
            'id' => (int) $row['merge_field_id'],
            'fieldCode' => (string) $row['field_code'],
            'namespace' => (string) $row['namespace'],
            'fieldName' => (string) $row['field_name'],
            'displayName' => (string) $row['display_name'],
            'description' => (string) ($row['description'] ?? ''),
            'dataType' => (string) $row['data_type'],
            'sourceType' => (string) $row['source_type'],
            'sourceField' => (string) ($row['source_field'] ?? ''),
        ], $this->rows("SELECT * FROM document_template_merge_field WHERE status = 'ACTIVE' ORDER BY namespace, field_name"));
    }

    public function list(array $query): array
    {
        $where = ['dt.deleted_at IS NULL'];
        $params = [];
        if (($query['status'] ?? '') !== '' && ($query['status'] ?? '') !== 'all') {
            $where[] = 'dt.status = :status';
            $params['status'] = strtoupper((string) $query['status']);
        }
        if (($query['template_type'] ?? '') !== '' && ($query['template_type'] ?? '') !== 'all') {
            $where[] = 'dt.template_type = :type';
            $params['type'] = strtoupper((string) $query['template_type']);
        }
        if (trim((string) ($query['search'] ?? '')) !== '') {
            $where[] = '(dt.template_code LIKE :search OR dt.template_name LIKE :search OR dt.description LIKE :search)';
            $params['search'] = '%' . trim((string) $query['search']) . '%';
        }

        return [
            'items' => array_map(fn (array $row): array => $this->shape($row, false), $this->rows($this->baseSelect() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY dt.updated_at DESC, dt.template_id DESC', $params)),
            'options' => $this->options(),
        ];
    }

    public function show(int $id): ?array
    {
        $row = $this->row($this->baseSelect() . ' WHERE dt.deleted_at IS NULL AND dt.template_id = :id LIMIT 1', ['id' => $id]);
        return $row === null ? null : $this->shape($row, true);
    }

    public function versions(int $id): ?array
    {
        return $this->existsTemplate($id) ? ['items' => $this->versionRows($id)] : null;
    }

    public function create(array $data, array $file, array $user): array
    {
        $clean = $this->validateTemplate($data);
        $hasFile = isset($file['tmp_name']) && (string) $file['tmp_name'] !== '';
        if (!$hasFile) {
            throw new InvalidArgumentException(json_encode(['file' => 'Please select a template file.'], JSON_THROW_ON_ERROR));
        }

        $clean['template_code'] = $this->nextTemplateCode();
        $document = $this->createSourceDocument($clean, $data, $file, $user);
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("INSERT INTO document_template (template_code, template_name, description, template_type, source_module, status, created_by_user_id, created_at, updated_at) VALUES (:code, :name, :description, :type, :source, 'ACTIVE', :user_id, NOW(), NOW())")->execute([
                'code' => $clean['template_code'],
                'name' => $clean['template_name'],
                'description' => $clean['description'],
                'type' => $clean['template_type'],
                'source' => $clean['source_module'],
                'user_id' => (int) $user['id'],
            ]);
            $templateId = (int) $this->pdo->lastInsertId();
            $templateVersionId = $this->insertTemplateVersion($templateId, 1, (int) $document['id'], (int) ($document['currentVersion']['id'] ?? 0), 'ACTIVE', $data, $user);
            $this->storeVersionFields($templateVersionId, $this->validateContent((string) ($data['template_content'] ?? '')));
            $this->pdo->prepare('UPDATE document_template SET current_approved_version_id = :version_id, updated_at = NOW() WHERE template_id = :template_id')->execute(['version_id' => $templateVersionId, 'template_id' => $templateId]);
            $this->activity('DOCUMENT_TEMPLATE_CREATED', 'Document Template Created', $templateId, $clean['template_code'], $user);
            $this->pdo->commit();
            return $this->show($templateId) ?? [];
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function updateDraft(int $templateVersionId, array $data, array $user): ?array
    {
        throw new InvalidArgumentException(json_encode(['status' => 'Draft template updates are no longer supported. Create a new template version instead.'], JSON_THROW_ON_ERROR));
    }

    public function createVersion(int $templateId, array $data, array $user): ?array
    {
        $template = $this->row('SELECT * FROM document_template WHERE template_id = :id AND deleted_at IS NULL LIMIT 1', ['id' => $templateId]);
        $source = $this->row('SELECT * FROM document_template_version WHERE template_id = :id ORDER BY version_number DESC LIMIT 1', ['id' => $templateId]);
        if ($template === null || $source === null) return null;
        if ($template['status'] === 'RETIRED') {
            throw new InvalidArgumentException(json_encode(['status' => 'Retired templates cannot receive new versions.'], JSON_THROW_ON_ERROR));
        }
        $file = $data['_file'] ?? [];
        if (!is_array($file) || !isset($file['tmp_name']) || (string) $file['tmp_name'] === '') {
            throw new InvalidArgumentException(json_encode(['file' => 'Upload a new file to create the next version.'], JSON_THROW_ON_ERROR));
        }
        if (trim((string) ($data['change_summary'] ?? '')) === '') {
            throw new InvalidArgumentException(json_encode(['change_summary' => 'Enter a short change summary for this new version.'], JSON_THROW_ON_ERROR));
        }
        $document = $this->documents()->uploadVersion((int) $source['document_id'], ['change_summary' => (string) $data['change_summary']], $file, $user);
        if ($document === null) return null;
        $this->pdo->beginTransaction();
        try {
            $next = (int) $this->scalar('SELECT COALESCE(MAX(version_number),0) + 1 FROM document_template_version WHERE template_id = :id', ['id' => $templateId]);
            $this->pdo->prepare("UPDATE document_template_version SET status = 'RETIRED', retired_by_user_id = COALESCE(retired_by_user_id, :user_id), retired_at = COALESCE(retired_at, NOW()) WHERE template_id = :template_id AND status = 'ACTIVE'")->execute(['user_id' => (int) $user['id'], 'template_id' => $templateId]);
            $templateVersionId = $this->insertTemplateVersion($templateId, $next, (int) $source['document_id'], (int) ($document['currentVersion']['id'] ?? 0), 'ACTIVE', $data, $user);
            $this->storeVersionFields($templateVersionId, $this->validateContent((string) ($data['template_content'] ?? '')));
            $this->pdo->prepare("UPDATE document_template SET status = 'ACTIVE', current_approved_version_id = :version_id, updated_at = NOW() WHERE template_id = :id")->execute(['version_id' => $templateVersionId, 'id' => $templateId]);
            $this->activity('DOCUMENT_TEMPLATE_VERSION_CREATED', 'Document Template Version Created', $templateId, (string) $template['template_code'], $user);
            $this->pdo->commit();
            return $this->show($templateId);
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function submit(int $templateVersionId, array $user): ?array
    {
        throw new InvalidArgumentException(json_encode(['status' => 'Template review submission is no longer supported. Templates become active when saved.'], JSON_THROW_ON_ERROR));
    }

    public function approve(int $templateVersionId, array $user): ?array
    {
        throw new InvalidArgumentException(json_encode(['status' => 'Template approval is no longer supported. Templates become active when saved.'], JSON_THROW_ON_ERROR));
    }

    public function retire(int $templateVersionId, array $user): ?array
    {
        return $this->transition($templateVersionId, 'ACTIVE', 'RETIRED', 'retired_by_user_id = :user_id, retired_at = NOW()', 'DOCUMENT_TEMPLATE_RETIRED', 'Document Template Retired', $user, true);
    }

    public function validate(array $data, array $user): array
    {
        $result = $this->validateContent((string) ($data['template_content'] ?? ''));
        $this->activity('DOCUMENT_TEMPLATE_VALIDATED', 'Document Template Validated', 0, 'VALIDATION', $user);
        return $result;
    }

    public function resolveCurrentApprovedTemplate(int $templateId): ?array
    {
        return $this->row('SELECT dt.*, tv.* FROM document_template dt INNER JOIN document_template_version tv ON tv.template_version_id = dt.current_approved_version_id WHERE dt.template_id = :id AND dt.status = "ACTIVE" AND tv.status = "ACTIVE" AND dt.deleted_at IS NULL LIMIT 1', ['id' => $templateId]);
    }

    private function validateContent(string $content): array
    {
        preg_match_all('/{{\s*([^{}]+?)\s*}}/', $content, $matches);
        $registry = array_column($this->mergeFields(), null, 'fieldCode');
        $valid = [];
        $unknown = [];
        $malformed = [];
        $unsupported = [];
        foreach ($matches[1] as $raw) {
            $code = trim((string) $raw);
            if (!preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $code)) {
                $malformed[] = '{{' . $code . '}}';
                continue;
            }
            [$namespace] = explode('.', $code, 2);
            if (!in_array($namespace, self::NAMESPACES, true)) {
                $unsupported[] = $namespace;
                continue;
            }
            if (!isset($registry[$code])) {
                $unknown[] = $code;
                continue;
            }
            $valid[] = $code;
        }
        $counts = array_count_values($valid);
        return [
            'ok' => $unknown === [] && $malformed === [] && $unsupported === [],
            'valid' => array_values(array_unique($valid)),
            'duplicates' => array_values(array_keys(array_filter($counts, fn (int $count): bool => $count > 1))),
            'unknown' => array_values(array_unique($unknown)),
            'malformed' => array_values(array_unique($malformed)),
            'unsupportedNamespaces' => array_values(array_unique($unsupported)),
        ];
    }

    private function transition(int $templateVersionId, string $from, string $to, string $sets, string $event, string $title, array $user, bool $retire = false): ?array
    {
        $version = $this->version($templateVersionId);
        if ($version === null) return null;
        if ($version['status'] !== $from) {
            throw new InvalidArgumentException(json_encode(['status' => "Only $from template versions may move to $to."], JSON_THROW_ON_ERROR));
        }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("UPDATE document_template_version SET status = :to_status, $sets WHERE template_version_id = :id AND status = :from_status")->execute(['to_status' => $to, 'user_id' => (int) $user['id'], 'id' => $templateVersionId, 'from_status' => $from]);
            $currentSql = $retire ? ', current_approved_version_id = IF(current_approved_version_id = :version_id, NULL, current_approved_version_id)' : '';
            $this->pdo->prepare("UPDATE document_template SET status = :status, updated_at = NOW() $currentSql WHERE template_id = :template_id")->execute(['status' => $to, 'version_id' => $templateVersionId, 'template_id' => (int) $version['template_id']]);
            $this->activity($event, $title, (int) $version['template_id'], (string) $version['template_code'], $user);
            $this->pdo->commit();
            return $this->show((int) $version['template_id']);
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function createSourceDocument(array $clean, array $data, array $file, array $user): array
    {
        $categoryId = (int) $this->scalar("SELECT document_category_id FROM document_category WHERE category_code = 'DOC-CON' AND status = 'ACTIVE' LIMIT 1");
        if ($categoryId < 1) {
            $categoryId = (int) $this->scalar("SELECT document_category_id FROM document_category WHERE status = 'ACTIVE' ORDER BY document_category_id LIMIT 1");
        }
        $metadata = [
            'title' => $clean['template_name'],
            'description' => $clean['description'],
            'document_category_id' => $categoryId,
            'confidentiality_level' => $clean['confidentiality_level'],
            'status' => 'ACTIVE',
            'document_date' => date('Y-m-d'),
            'change_summary' => $this->text($data['change_summary'] ?? 'Initial template version', 1000),
            'related_module' => 'GENERAL_ADMINISTRATIVE',
            'related_reference' => $clean['template_code'],
        ];
        return $this->documents()->create($metadata, $file, $user);
    }

    private function insertTemplateVersion(int $templateId, int $version, int $documentId, int $documentVersionId, string $status, array $data, array $user): int
    {
        $this->pdo->prepare('INSERT INTO document_template_version (template_id, version_number, document_id, document_version_id, status, change_summary, effective_from, effective_until, created_by_user_id, created_at) VALUES (:template_id, :version, :document_id, :document_version_id, :status, :summary, :effective_from, :effective_until, :user_id, NOW())')->execute([
            'template_id' => $templateId,
            'version' => $version,
            'document_id' => $documentId,
            'document_version_id' => $documentVersionId,
            'status' => $status,
            'summary' => $this->text($data['change_summary'] ?? 'Template version created', 1000),
            'effective_from' => $this->date($data['effective_from'] ?? null),
            'effective_until' => $this->date($data['effective_until'] ?? null),
            'user_id' => (int) $user['id'],
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private function storeVersionFields(int $templateVersionId, array $validation): void
    {
        $this->pdo->prepare('DELETE FROM document_template_version_field WHERE template_version_id = :id')->execute(['id' => $templateVersionId]);
        foreach ($validation['valid'] as $code) {
            $id = $this->scalar('SELECT merge_field_id FROM document_template_merge_field WHERE field_code = :code AND status = "ACTIVE" LIMIT 1', ['code' => $code]);
            if ($id) {
                $this->pdo->prepare('INSERT INTO document_template_version_field (template_version_id, merge_field_id, required_flag, created_at) VALUES (:version_id, :field_id, TRUE, NOW())')->execute(['version_id' => $templateVersionId, 'field_id' => (int) $id]);
            }
        }
    }

    private function validateTemplate(array $data): array
    {
        $name = $this->text($data['template_name'] ?? '', 255);
        $type = strtoupper($this->text($data['template_type'] ?? 'OTHER', 50));
        $confidentiality = strtoupper($this->text($data['confidentiality_level'] ?? 'INTERNAL', 30));
        $errors = [];
        if ($name === '') $errors['template_name'] = 'Template name is required.';
        if (!in_array($type, $this->templateTypes(), true)) $errors['template_type'] = 'Choose a valid template type.';
        if (!in_array($confidentiality, self::CONFIDENTIALITY_LEVELS, true)) $errors['confidentiality_level'] = 'Choose a valid confidentiality level.';
        if ($errors !== []) throw new InvalidArgumentException(json_encode($errors, JSON_THROW_ON_ERROR));
        return ['template_name' => $name, 'description' => $this->nullableText($data['description'] ?? '', 4000), 'template_type' => $type, 'source_module' => $this->sourceModuleForType($type), 'confidentiality_level' => $confidentiality];
    }

    private function shape(array $row, bool $details): array
    {
        $item = [
            'id' => (int) $row['template_id'],
            'templateCode' => (string) $row['template_code'],
            'templateName' => (string) $row['template_name'],
            'description' => (string) ($row['description'] ?? ''),
            'templateType' => (string) $row['template_type'],
            'sourceModule' => (string) $row['source_module'],
            'status' => (string) $row['status'],
            'confidentiality' => (string) ($row['confidentiality_level'] ?? ''),
            'currentApprovedVersionId' => $row['current_approved_version_id'] === null ? null : (int) $row['current_approved_version_id'],
            'currentVersion' => $row['current_version_number'] === null ? '' : 'v' . (int) $row['current_version_number'],
            'effectiveFrom' => $row['effective_from'] ?? null,
            'createdBy' => (string) ($row['created_by_name'] ?? ''),
            'createdAt' => (string) $row['created_at'],
            'updatedAt' => (string) $row['updated_at'],
        ];
        if ($details) {
            $item['versions'] = $this->versionRows((int) $row['template_id']);
            $item['mergeFields'] = $this->fieldsForTemplate((int) $row['template_id']);
        }
        return $item;
    }

    private function versionRows(int $templateId): array
    {
        return array_map(fn (array $row): array => [
            'id' => (int) $row['template_version_id'],
            'versionNumber' => (int) $row['version_number'],
            'version' => 'v' . (int) $row['version_number'],
            'documentId' => (int) $row['document_id'],
            'documentVersionId' => (int) $row['document_version_id'],
            'fileName' => (string) ($row['file_name'] ?? ''),
            'fileSize' => (int) ($row['file_size'] ?? 0),
            'confidentiality' => (string) ($row['confidentiality_level'] ?? ''),
            'status' => (string) $row['status'],
            'changeSummary' => (string) ($row['change_summary'] ?? ''),
            'effectiveFrom' => $row['effective_from'] ?? null,
            'effectiveUntil' => $row['effective_until'] ?? null,
            'createdBy' => (string) ($row['created_by_name'] ?? ''),
            'createdAt' => (string) $row['created_at'],
            'submittedBy' => (string) ($row['submitted_by_name'] ?? ''),
            'submittedAt' => $row['submitted_at'] ?? null,
            'approvedBy' => (string) ($row['approved_by_name'] ?? ''),
            'approvedAt' => $row['approved_at'] ?? null,
            'retiredBy' => (string) ($row['retired_by_name'] ?? ''),
            'retiredAt' => $row['retired_at'] ?? null,
        ], $this->rows("SELECT tv.*, dv.file_name, dv.file_size, d.confidentiality_level, creator_emp.full_name created_by_name, submitter_emp.full_name submitted_by_name, approver_emp.full_name approved_by_name, retire_emp.full_name retired_by_name FROM document_template_version tv INNER JOIN document d ON d.document_id = tv.document_id LEFT JOIN document_version dv ON dv.document_version_id = tv.document_version_id LEFT JOIN user_account creator ON creator.user_account_id = tv.created_by_user_id LEFT JOIN employee_reference creator_emp ON creator_emp.employee_reference_id = creator.employee_reference_id LEFT JOIN user_account submitter ON submitter.user_account_id = tv.submitted_by_user_id LEFT JOIN employee_reference submitter_emp ON submitter_emp.employee_reference_id = submitter.employee_reference_id LEFT JOIN user_account approver ON approver.user_account_id = tv.approved_by_user_id LEFT JOIN employee_reference approver_emp ON approver_emp.employee_reference_id = approver.employee_reference_id LEFT JOIN user_account retire_user ON retire_user.user_account_id = tv.retired_by_user_id LEFT JOIN employee_reference retire_emp ON retire_emp.employee_reference_id = retire_user.employee_reference_id WHERE tv.template_id = :id ORDER BY tv.version_number DESC", ['id' => $templateId]));
    }

    private function fieldsForTemplate(int $templateId): array
    {
        return array_map(fn (array $row): array => ['fieldCode' => (string) $row['field_code'], 'displayName' => (string) $row['display_name'], 'dataType' => (string) $row['data_type']], $this->rows('SELECT DISTINCT mf.* FROM document_template_version tv INNER JOIN document_template_version_field tvf ON tvf.template_version_id = tv.template_version_id INNER JOIN document_template_merge_field mf ON mf.merge_field_id = tvf.merge_field_id WHERE tv.template_id = :id ORDER BY mf.namespace, mf.field_name', ['id' => $templateId]));
    }

    private function version(int $id): ?array
    {
        return $this->row('SELECT tv.*, dt.template_code, dt.template_id FROM document_template_version tv INNER JOIN document_template dt ON dt.template_id = tv.template_id WHERE tv.template_version_id = :id AND dt.deleted_at IS NULL LIMIT 1', ['id' => $id]);
    }

    private function baseSelect(): string
    {
        return "SELECT dt.*, current_tv.version_number current_version_number, current_tv.effective_from, current_doc.confidentiality_level, creator_emp.full_name created_by_name FROM document_template dt LEFT JOIN document_template_version current_tv ON current_tv.template_version_id = dt.current_approved_version_id LEFT JOIN document current_doc ON current_doc.document_id = current_tv.document_id LEFT JOIN user_account creator ON creator.user_account_id = dt.created_by_user_id LEFT JOIN employee_reference creator_emp ON creator_emp.employee_reference_id = creator.employee_reference_id";
    }

    private function templateTypes(): array
    {
        $quoted = implode(',', array_map(fn (string $type): string => $this->pdo->quote($type), self::CANONICAL_TEMPLATE_TYPES));
        $fieldOrder = implode(',', array_map(fn (string $type): string => $this->pdo->quote($type), self::CANONICAL_TEMPLATE_TYPES));
        $rows = $this->rows("SELECT type_code FROM contract_type WHERE status = 'ACTIVE' AND type_code IN ($quoted) ORDER BY FIELD(type_code, $fieldOrder)");
        $types = array_values(array_map(fn (array $row): string => (string) $row['type_code'], $rows));
        return $types === [] ? self::CANONICAL_TEMPLATE_TYPES : $types;
    }

    private function existsTemplate(int $id): bool { return (bool) $this->scalar('SELECT COUNT(*) FROM document_template WHERE template_id = :id AND deleted_at IS NULL', ['id' => $id]); }
    private function touchTemplate(int $id): void { $this->pdo->prepare('UPDATE document_template SET updated_at = NOW() WHERE template_id = :id')->execute(['id' => $id]); }
    private function documents(): DocumentService { return $this->documentService ?? new DocumentService($this->pdo); }
    private function fileName(string $code): string { return strtolower(preg_replace('/[^A-Za-z0-9-]+/', '-', $code) ?: 'template') . '.html'; }
    private function sourceModuleForType(string $type): string { return $type === 'OTHER' ? 'DOCUMENT_MANAGEMENT' : 'CONTRACT_MANAGEMENT'; }
    private function nextTemplateCode(): string
    {
        for ($i = 0; $i < 8; $i++) {
            $code = 'TPL-' . str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);
            if (!$this->scalar('SELECT template_id FROM document_template WHERE template_code = :code LIMIT 1', ['code' => $code])) {
                return $code;
            }
        }
        return 'TPL-' . strtoupper(bin2hex(random_bytes(4)));
    }
    private function text(mixed $value, int $max): string { return mb_substr(trim((string) $value), 0, $max); }
    private function nullableText(mixed $value, int $max): ?string { $text = $this->text($value, $max); return $text === '' ? null : $text; }
    private function date(mixed $value): ?string { $text = trim((string) $value); return preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) ? $text : null; }
    private function scalar(string $sql, array $params = []): mixed { $s = $this->pdo->prepare($sql); $s->execute($params); return $s->fetchColumn(); }
    private function row(string $sql, array $params = []): ?array { $s = $this->pdo->prepare($sql); $s->execute($params); $r = $s->fetch(); return is_array($r) ? $r : null; }
    private function rows(string $sql, array $params = []): array { $s = $this->pdo->prepare($sql); $s->execute($params); return $s->fetchAll(); }
    private function activity(string $type, string $title, int $id, string $reference, array $user): void
    {
        try {
            $this->pdo->prepare("INSERT INTO activity_event (event_uuid, module_code, entity_type, entity_id, entity_reference, event_type, event_title, event_description, actor_user_id, actor_employee_reference_id, visibility_scope, metadata_json, occurred_at, created_at) VALUES (UUID(), 'documents', 'document_template', :id, :reference, :type, :title, :description, :user_id, :employee_id, 'INTERNAL', '{}', NOW(), NOW())")->execute(['id' => $id, 'reference' => $reference, 'type' => $type, 'title' => $title, 'description' => $title . ' ' . $reference, 'user_id' => (int) ($user['id'] ?? 0), 'employee_id' => $user['employee_id'] ?? null]);
        } catch (Throwable) {
        }
    }
}
