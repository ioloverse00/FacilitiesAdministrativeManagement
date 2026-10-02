<?php

declare(strict_types=1);

final class LegalRulePolicyService
{
    private const STATUSES = ['ACTIVE', 'INACTIVE', 'SUPERSEDED'];
    private const LINK_SOURCES = ['MANUAL', 'AI_REVIEWED'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function listPolicies(array $query): array
    {
        $where = ['p.deleted_at IS NULL'];
        $params = [];
        $search = trim((string)($query['search'] ?? ''));
        if ($search !== '') {
            $where[] = '(p.policy_code LIKE :search_code OR p.title LIKE :search_title OR p.category LIKE :search_category)';
            $params['search_code'] = '%' . $search . '%';
            $params['search_title'] = '%' . $search . '%';
            $params['search_category'] = '%' . $search . '%';
        }
        $status = strtoupper($this->text($query['status'] ?? '', 30));
        if ($status !== '' && $status !== 'ALL') {
            $where[] = 'p.status = :status';
            $params['status'] = $status;
        }
        $category = $this->text($query['category'] ?? '', 100);
        if ($category !== '' && strtolower($category) !== 'all') {
            $where[] = 'p.category = :category';
            $params['category'] = $category;
        }

        $sql = "SELECT p.*, doc.document_number source_document_number,
                       COUNT(pr.legal_rule_provision_id) provision_count,
                       MAX(CASE WHEN pr.deleted_at IS NULL THEN pr.version_number ELSE NULL END) latest_version
                FROM legal_rule_policy p
                LEFT JOIN document doc ON doc.document_id = p.source_document_id
                LEFT JOIN legal_rule_provision pr ON pr.legal_rule_policy_id = p.legal_rule_policy_id AND pr.deleted_at IS NULL
                WHERE " . implode(' AND ', $where) . "
                GROUP BY p.legal_rule_policy_id
                ORDER BY p.updated_at DESC, p.legal_rule_policy_id DESC";
        return [
            'items' => array_map(fn(array $row): array => $this->shapePolicy($row), $this->rows($sql, $params)),
            'categories' => $this->categories(),
            'statuses' => self::STATUSES,
        ];
    }

    public function activeProvisionOptions(array $query = []): array
    {
        $search = trim((string)($query['search'] ?? ''));
        $where = [
            "p.deleted_at IS NULL",
            "pr.deleted_at IS NULL",
            "p.status = 'ACTIVE'",
            "pr.status = 'ACTIVE'",
            '(pr.effective_from IS NULL OR pr.effective_from <= CURRENT_DATE)',
            '(pr.effective_until IS NULL OR pr.effective_until >= CURRENT_DATE)',
        ];
        $params = [];
        if ($search !== '') {
            $where[] = '(p.policy_code LIKE :search_code OR p.title LIKE :search_title OR pr.provision_code LIKE :search_provision OR pr.section_title LIKE :search_section)';
            $params = [
                'search_code' => '%' . $search . '%',
                'search_title' => '%' . $search . '%',
                'search_provision' => '%' . $search . '%',
                'search_section' => '%' . $search . '%',
            ];
        }
        return array_map(fn(array $row): array => [
            'id' => (int)$row['legal_rule_provision_id'],
            'policyId' => (int)$row['legal_rule_policy_id'],
            'policyCode' => (string)$row['policy_code'],
            'policyTitle' => (string)$row['title'],
            'category' => (string)($row['category'] ?? ''),
            'provisionCode' => (string)$row['provision_code'],
            'sectionTitle' => (string)($row['section_title'] ?? ''),
            'versionNumber' => (int)$row['version_number'],
            'effectiveFrom' => (string)($row['effective_from'] ?? ''),
            'effectiveUntil' => (string)($row['effective_until'] ?? ''),
            'label' => trim((string)$row['policy_code'] . ' / ' . (string)$row['provision_code'] . ' v' . (int)$row['version_number'] . ' - ' . (string)($row['section_title'] ?: $row['title'])),
        ], $this->rows(
            "SELECT p.legal_rule_policy_id, p.policy_code, p.title, p.category, pr.*
             FROM legal_rule_provision pr
             INNER JOIN legal_rule_policy p ON p.legal_rule_policy_id = pr.legal_rule_policy_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY p.policy_code, pr.provision_code, pr.version_number DESC
             LIMIT 100",
            $params
        ));
    }

    public function showPolicy(int $id): ?array
    {
        $row = $this->row("SELECT p.*, doc.document_number source_document_number FROM legal_rule_policy p LEFT JOIN document doc ON doc.document_id = p.source_document_id WHERE p.legal_rule_policy_id = :id AND p.deleted_at IS NULL LIMIT 1", ['id' => $id]);
        if ($row === null) return null;
        $item = $this->shapePolicy($row);
        $item['provisions'] = array_map(fn(array $provision): array => $this->shapeProvision($provision), $this->rows(
            "SELECT pr.*, doc.document_number source_document_number
             FROM legal_rule_provision pr
             LEFT JOIN document doc ON doc.document_id = pr.source_document_id
             WHERE pr.legal_rule_policy_id = :id AND pr.deleted_at IS NULL
             ORDER BY pr.provision_code, pr.version_number DESC",
            ['id' => $id]
        ));
        return $item;
    }

    public function createPolicy(array $data, array $user): array
    {
        $clean = $this->validatePolicy($data, true);
        $this->pdo->prepare("INSERT INTO legal_rule_policy (policy_code, title, category, status, source_document_id, source_document_version_id, created_by_user_id, updated_by_user_id, created_at, updated_at) VALUES (:code, :title, :category, 'ACTIVE', :document_id, :version_id, :user_id, :user_id, NOW(), NOW())")->execute([
            'code' => $clean['policy_code'],
            'title' => $clean['title'],
            'category' => $clean['category'],
            'document_id' => $clean['source_document_id'],
            'version_id' => $clean['source_document_version_id'],
            'user_id' => (int)$user['id'],
        ]);
        return $this->showPolicy((int)$this->pdo->lastInsertId()) ?? [];
    }

    public function updatePolicy(int $id, array $data, array $user): ?array
    {
        $existing = $this->showPolicy($id);
        if ($existing === null) return null;
        $clean = $this->validatePolicy($data + ['policy_code' => $existing['policyCode']], false, $id);
        $this->pdo->prepare('UPDATE legal_rule_policy SET title = :title, category = :category, source_document_id = :document_id, source_document_version_id = :version_id, updated_by_user_id = :user_id, updated_at = NOW() WHERE legal_rule_policy_id = :id AND deleted_at IS NULL')->execute([
            'title' => $clean['title'],
            'category' => $clean['category'],
            'document_id' => $clean['source_document_id'],
            'version_id' => $clean['source_document_version_id'],
            'user_id' => (int)$user['id'],
            'id' => $id,
        ]);
        return $this->showPolicy($id);
    }

    public function deactivatePolicy(int $id, array $user): ?array
    {
        if ($this->showPolicy($id) === null) return null;
        $this->pdo->prepare("UPDATE legal_rule_policy SET status = 'INACTIVE', updated_by_user_id = :user_id, updated_at = NOW() WHERE legal_rule_policy_id = :id AND deleted_at IS NULL")->execute(['user_id' => (int)$user['id'], 'id' => $id]);
        return $this->showPolicy($id);
    }

    public function addProvision(int $policyId, array $data, array $user): ?array
    {
        $policy = $this->policyRow($policyId);
        if ($policy === null) return null;
        $clean = $this->validateProvision($data, $policyId);
        $this->insertProvision($policyId, $clean, null, (int)$user['id']);
        return $this->showPolicy($policyId);
    }

    public function reviseProvision(int $provisionId, array $data, array $user): ?array
    {
        $this->pdo->beginTransaction();
        try {
            $current = $this->provisionRowForUpdate($provisionId);
            if ($current === null) {
                $this->pdo->rollBack();
                return null;
            }
            $policyId = (int)$current['legal_rule_policy_id'];
            $this->lockProvisionVersions($policyId, (string)$current['provision_code']);
            $nextVersion = (int)$this->scalar('SELECT COALESCE(MAX(version_number),0) + 1 FROM legal_rule_provision WHERE legal_rule_policy_id = :policy_id AND provision_code = :code AND deleted_at IS NULL', ['policy_id' => $policyId, 'code' => (string)$current['provision_code']]);
            $clean = $this->validateProvision(array_replace($data, [
                'provision_code' => $current['provision_code'],
                'section_title' => $current['section_title'],
                'version_number' => $nextVersion,
                'source_document_id' => $current['source_document_id'],
                'source_document_version_id' => $current['source_document_version_id'],
            ]), $policyId);
            $this->pdo->prepare("UPDATE legal_rule_provision SET status = 'SUPERSEDED', effective_until = COALESCE(effective_until, :until_date), updated_by_user_id = :user_id, updated_at = NOW() WHERE legal_rule_provision_id = :id")->execute([
                'until_date' => $clean['effective_from'],
                'user_id' => (int)$user['id'],
                'id' => $provisionId,
            ]);
            $this->insertProvision($policyId, $clean, $provisionId, (int)$user['id']);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
        return $this->showPolicy($policyId);
    }

    public function deactivateProvision(int $provisionId, array $user): ?array
    {
        $current = $this->provisionRow($provisionId);
        if ($current === null) return null;
        $this->pdo->prepare("UPDATE legal_rule_provision SET status = 'INACTIVE', updated_by_user_id = :user_id, updated_at = NOW() WHERE legal_rule_provision_id = :id AND deleted_at IS NULL")->execute(['user_id' => (int)$user['id'], 'id' => $provisionId]);
        return $this->showPolicy((int)$current['legal_rule_policy_id']);
    }

    public function matterRuleBases(int $matterId): array
    {
        return array_map(fn(array $row): array => $this->shapeMatterRule($row), $this->rows(
            "SELECT b.*, actor.full_name linked_by_name
             FROM legal_matter_rule_basis b
             LEFT JOIN user_account u ON u.user_account_id = b.linked_by_user_id
             LEFT JOIN employee_reference actor ON actor.employee_reference_id = u.employee_reference_id
             WHERE b.legal_matter_id = :matter_id AND b.deleted_at IS NULL
             ORDER BY b.linked_at DESC, b.legal_matter_rule_basis_id DESC",
            ['matter_id' => $matterId]
        ));
    }

    public function linkProvision(int $matterId, int $provisionId, array $data, array $user): ?array
    {
        $matter = $this->matterRow($matterId);
        if ($matter === null) return null;
        $this->assertMatterCanChangeRules($matter);
        $row = $this->row(
            "SELECT pr.*, p.policy_code, p.title policy_title, p.category
             FROM legal_rule_provision pr
             INNER JOIN legal_rule_policy p ON p.legal_rule_policy_id = pr.legal_rule_policy_id
             WHERE pr.legal_rule_provision_id = :id
               AND pr.deleted_at IS NULL
               AND p.deleted_at IS NULL
               AND pr.status = 'ACTIVE'
               AND p.status = 'ACTIVE'
               AND (pr.effective_from IS NULL OR pr.effective_from <= CURRENT_DATE)
               AND (pr.effective_until IS NULL OR pr.effective_until >= CURRENT_DATE)
             LIMIT 1",
            ['id' => $provisionId]
        );
        if ($row === null) {
            throw new InvalidArgumentException(json_encode(['provision_id' => 'Choose an active rule provision.'], JSON_THROW_ON_ERROR));
        }
        if ((int)$this->scalar('SELECT COUNT(*) FROM legal_matter_rule_basis WHERE legal_matter_id = :matter_id AND legal_rule_provision_id = :provision_id AND version_number_snapshot = :version AND deleted_at IS NULL', ['matter_id' => $matterId, 'provision_id' => $provisionId, 'version' => (int)$row['version_number']]) > 0) {
            throw new InvalidArgumentException(json_encode(['provision_id' => 'This provision version is already linked to the matter.'], JSON_THROW_ON_ERROR));
        }
        $source = strtoupper($this->text($data['source'] ?? 'MANUAL', 30));
        if (!in_array($source, self::LINK_SOURCES, true)) $source = 'MANUAL';
        $rationale = $this->nullableText($data['rationale'] ?? null, 2000);
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("INSERT INTO legal_matter_rule_basis (legal_matter_id, legal_rule_policy_id, legal_rule_provision_id, policy_code_snapshot, policy_title_snapshot, category_snapshot, provision_code_snapshot, section_title_snapshot, provision_text_snapshot, version_number_snapshot, effective_from_snapshot, effective_until_snapshot, linked_by_user_id, linked_at, rationale, source, created_at, updated_at) VALUES (:matter_id, :policy_id, :provision_id, :policy_code, :policy_title, :category, :provision_code, :section_title, :provision_text, :version_number, :effective_from, :effective_until, :user_id, NOW(), :rationale, :source, NOW(), NOW())")->execute([
                'matter_id' => $matterId,
                'policy_id' => (int)$row['legal_rule_policy_id'],
                'provision_id' => $provisionId,
                'policy_code' => (string)$row['policy_code'],
                'policy_title' => (string)$row['policy_title'],
                'category' => $row['category'] ?? null,
                'provision_code' => (string)$row['provision_code'],
                'section_title' => $row['section_title'] ?? null,
                'provision_text' => (string)$row['provision_text'],
                'version_number' => (int)$row['version_number'],
                'effective_from' => $row['effective_from'] ?? null,
                'effective_until' => $row['effective_until'] ?? null,
                'user_id' => (int)$user['id'],
                'rationale' => $rationale,
                'source' => $source,
            ]);
            $basisId = (int)$this->pdo->lastInsertId();
            $this->history($matterId, 'LEGAL_RULE_LINKED', 'Rule provision linked to Legal Matter.', ['matter_rule_basis_id' => $basisId, 'policy_code' => (string)$row['policy_code'], 'provision_code' => (string)$row['provision_code'], 'version_number' => (int)$row['version_number']], $user);
            $this->markAiStale($matterId);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
        return $this->matterPayload($matterId);
    }

    public function unlinkBasis(int $matterId, int $basisId, array $user): ?array
    {
        $matter = $this->matterRow($matterId);
        if ($matter === null) return null;
        $this->assertMatterCanChangeRules($matter);
        $basis = $this->row('SELECT * FROM legal_matter_rule_basis WHERE legal_matter_rule_basis_id = :basis_id AND legal_matter_id = :matter_id AND deleted_at IS NULL LIMIT 1', ['basis_id' => $basisId, 'matter_id' => $matterId]);
        if ($basis === null) return null;
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('UPDATE legal_matter_rule_basis SET deleted_at = NOW(), updated_at = NOW() WHERE legal_matter_rule_basis_id = :id')->execute(['id' => $basisId]);
            $this->history($matterId, 'LEGAL_RULE_UNLINKED', 'Rule provision unlinked from Legal Matter.', ['matter_rule_basis_id' => $basisId, 'policy_code' => (string)$basis['policy_code_snapshot'], 'provision_code' => (string)$basis['provision_code_snapshot']], $user);
            $this->markAiStale($matterId);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
        return $this->matterPayload($matterId);
    }

    private function validatePolicy(array $data, bool $creating, ?int $ignoreId = null): array
    {
        $code = strtoupper($this->text($data['policy_code'] ?? '', 80));
        $title = $this->text($data['title'] ?? '', 255);
        $category = $this->nullableText($data['category'] ?? null, 100);
        $documentId = $this->optionalId($data['source_document_id'] ?? null);
        $versionId = $this->optionalId($data['source_document_version_id'] ?? null);
        $errors = [];
        if ($creating && $code === '') $errors['policy_code'] = 'Enter the policy code.';
        if ($code !== '' && !preg_match('/^[A-Z0-9][A-Z0-9._-]{1,79}$/', $code)) $errors['policy_code'] = 'Use letters, numbers, dots, dashes, or underscores.';
        if ($title === '') $errors['title'] = 'Enter the policy title.';
        if ($versionId !== null && $documentId === null) $errors['source_document_version_id'] = 'Choose a source document before choosing a source version.';
        if (!$errors && $documentId !== null) $this->assertDocumentReference($documentId, $versionId);
        $duplicateParams = ['code' => $code];
        $duplicateSql = 'SELECT COUNT(*) FROM legal_rule_policy WHERE policy_code = :code AND deleted_at IS NULL';
        if ($ignoreId !== null) {
            $duplicateSql .= ' AND legal_rule_policy_id <> :ignore_id';
            $duplicateParams['ignore_id'] = $ignoreId;
        }
        if ($code !== '' && (int)$this->scalar($duplicateSql, $duplicateParams) > 0) $errors['policy_code'] = 'Policy code already exists.';
        if ($errors) throw new InvalidArgumentException(json_encode($errors, JSON_THROW_ON_ERROR));
        return ['policy_code' => $code, 'title' => $title, 'category' => $category, 'source_document_id' => $documentId, 'source_document_version_id' => $versionId];
    }

    private function validateProvision(array $data, int $policyId): array
    {
        $code = strtoupper($this->text($data['provision_code'] ?? '', 100));
        $section = $this->nullableText($data['section_title'] ?? null, 255);
        $text = $this->text($data['provision_text'] ?? '', 12000);
        $version = max(1, (int)($data['version_number'] ?? 1));
        $effectiveFrom = $this->date($data['effective_from'] ?? null);
        $effectiveUntil = $this->date($data['effective_until'] ?? null);
        $documentId = $this->optionalId($data['source_document_id'] ?? null);
        $versionId = $this->optionalId($data['source_document_version_id'] ?? null);
        $errors = [];
        if ($code === '') $errors['provision_code'] = 'Enter the provision code.';
        if ($text === '') $errors['provision_text'] = 'Enter the provision text.';
        if ($effectiveFrom !== null && $effectiveUntil !== null && $effectiveUntil < $effectiveFrom) $errors['effective_until'] = 'Effective-until date cannot be before effective-from date.';
        if ($versionId !== null && $documentId === null) $errors['source_document_version_id'] = 'Choose a source document before choosing a source version.';
        if (!$errors && $documentId !== null) $this->assertDocumentReference($documentId, $versionId);
        if ((int)$this->scalar('SELECT COUNT(*) FROM legal_rule_provision WHERE legal_rule_policy_id = :policy_id AND provision_code = :code AND version_number = :version AND deleted_at IS NULL', ['policy_id' => $policyId, 'code' => $code, 'version' => $version]) > 0) {
            $errors['version_number'] = 'This provision version already exists.';
        }
        if ($errors) throw new InvalidArgumentException(json_encode($errors, JSON_THROW_ON_ERROR));
        return ['provision_code' => $code, 'section_title' => $section, 'provision_text' => $text, 'version_number' => $version, 'effective_from' => $effectiveFrom, 'effective_until' => $effectiveUntil, 'source_document_id' => $documentId, 'source_document_version_id' => $versionId];
    }

    private function insertProvision(int $policyId, array $clean, ?int $supersedesId, int $userId): void
    {
        $this->pdo->prepare("INSERT INTO legal_rule_provision (legal_rule_policy_id, provision_code, section_title, provision_text, version_number, effective_from, effective_until, status, supersedes_provision_id, source_document_id, source_document_version_id, created_by_user_id, updated_by_user_id, created_at, updated_at) VALUES (:policy_id, :code, :section, :text, :version, :effective_from, :effective_until, 'ACTIVE', :supersedes_id, :document_id, :version_id, :user_id, :user_id, NOW(), NOW())")->execute([
            'policy_id' => $policyId,
            'code' => $clean['provision_code'],
            'section' => $clean['section_title'],
            'text' => $clean['provision_text'],
            'version' => $clean['version_number'],
            'effective_from' => $clean['effective_from'],
            'effective_until' => $clean['effective_until'],
            'supersedes_id' => $supersedesId,
            'document_id' => $clean['source_document_id'],
            'version_id' => $clean['source_document_version_id'],
            'user_id' => $userId,
        ]);
    }

    private function assertDocumentReference(int $documentId, ?int $versionId): void
    {
        $sql = 'SELECT COUNT(*) FROM document d';
        $params = ['document_id' => $documentId];
        if ($versionId !== null) {
            $sql .= ' INNER JOIN document_version dv ON dv.document_id = d.document_id AND dv.document_version_id = :version_id AND dv.deleted_at IS NULL';
            $params['version_id'] = $versionId;
        }
        $sql .= ' WHERE d.document_id = :document_id AND d.deleted_at IS NULL';
        if ((int)$this->scalar($sql, $params) < 1) {
            throw new InvalidArgumentException(json_encode(['source_document_id' => 'Choose a valid source document/version.'], JSON_THROW_ON_ERROR));
        }
    }

    private function assertMatterCanChangeRules(array $matter): void
    {
        if (in_array((string)($matter['status'] ?? ''), ['CLOSED', 'CANCELLED'], true)) {
            throw new InvalidArgumentException(json_encode(['status' => 'Rules cannot be changed for closed or cancelled matters.'], JSON_THROW_ON_ERROR));
        }
    }

    private function shapePolicy(array $row): array
    {
        return [
            'id' => (int)$row['legal_rule_policy_id'],
            'policyCode' => (string)$row['policy_code'],
            'title' => (string)$row['title'],
            'category' => (string)($row['category'] ?? ''),
            'status' => (string)$row['status'],
            'sourceDocumentId' => $row['source_document_id'] === null ? null : (int)$row['source_document_id'],
            'sourceDocumentVersionId' => $row['source_document_version_id'] === null ? null : (int)$row['source_document_version_id'],
            'sourceDocument' => (string)($row['source_document_number'] ?? ''),
            'provisionCount' => (int)($row['provision_count'] ?? 0),
            'latestVersion' => (int)($row['latest_version'] ?? 0),
            'createdAt' => (string)($row['created_at'] ?? ''),
            'updatedAt' => (string)($row['updated_at'] ?? ''),
        ];
    }

    private function shapeProvision(array $row): array
    {
        return [
            'id' => (int)$row['legal_rule_provision_id'],
            'policyId' => (int)$row['legal_rule_policy_id'],
            'provisionCode' => (string)$row['provision_code'],
            'sectionTitle' => (string)($row['section_title'] ?? ''),
            'provisionText' => (string)$row['provision_text'],
            'versionNumber' => (int)$row['version_number'],
            'effectiveFrom' => (string)($row['effective_from'] ?? ''),
            'effectiveUntil' => (string)($row['effective_until'] ?? ''),
            'status' => (string)$row['status'],
            'supersedesProvisionId' => $row['supersedes_provision_id'] === null ? null : (int)$row['supersedes_provision_id'],
            'sourceDocumentId' => $row['source_document_id'] === null ? null : (int)$row['source_document_id'],
            'sourceDocumentVersionId' => $row['source_document_version_id'] === null ? null : (int)$row['source_document_version_id'],
            'sourceDocument' => (string)($row['source_document_number'] ?? ''),
        ];
    }

    private function shapeMatterRule(array $row): array
    {
        return [
            'id' => (int)$row['legal_matter_rule_basis_id'],
            'policyId' => $row['legal_rule_policy_id'] === null ? null : (int)$row['legal_rule_policy_id'],
            'provisionId' => $row['legal_rule_provision_id'] === null ? null : (int)$row['legal_rule_provision_id'],
            'policyCode' => (string)$row['policy_code_snapshot'],
            'policyTitle' => (string)$row['policy_title_snapshot'],
            'category' => (string)($row['category_snapshot'] ?? ''),
            'provisionCode' => (string)$row['provision_code_snapshot'],
            'sectionTitle' => (string)($row['section_title_snapshot'] ?? ''),
            'provisionText' => (string)$row['provision_text_snapshot'],
            'versionNumber' => (int)$row['version_number_snapshot'],
            'effectiveFrom' => (string)($row['effective_from_snapshot'] ?? ''),
            'effectiveUntil' => (string)($row['effective_until_snapshot'] ?? ''),
            'linkedBy' => (string)($row['linked_by_name'] ?? 'System'),
            'linkedAt' => (string)$row['linked_at'],
            'rationale' => (string)($row['rationale'] ?? ''),
            'source' => (string)$row['source'],
        ];
    }

    private function history(int $matterId, string $event, string $description, array $metadata, array $user): void
    {
        $this->pdo->prepare('INSERT INTO legal_matter_history (legal_matter_id, event_type, from_status, to_status, description, metadata_json, actor_user_id, created_at) VALUES (:id, :event, NULL, NULL, :description, :metadata, :user_id, NOW())')->execute([
            'id' => $matterId,
            'event' => $event,
            'description' => $description,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'user_id' => (int)$user['id'],
        ]);
    }

    private function markAiStale(int $matterId): void
    {
        $this->pdo->prepare("UPDATE legal_matter SET ai_summary_status = 'STALE', updated_at = NOW() WHERE legal_matter_id = :id AND ai_summary_status IN ('READY','FAILED','NO_READABLE_SOURCE')")->execute(['id' => $matterId]);
    }

    private function matterPayload(int $matterId): ?array
    {
        return (new LegalMatterService($this->pdo))->show($matterId);
    }

    private function categories(): array
    {
        return array_values(array_filter(array_map(static fn(array $row): string => (string)$row['category'], $this->rows('SELECT DISTINCT category FROM legal_rule_policy WHERE category IS NOT NULL AND category <> "" AND deleted_at IS NULL ORDER BY category'))));
    }

    private function policyRow(int $id): ?array { return $this->row('SELECT * FROM legal_rule_policy WHERE legal_rule_policy_id = :id AND deleted_at IS NULL LIMIT 1', ['id' => $id]); }
    private function provisionRow(int $id): ?array { return $this->row('SELECT * FROM legal_rule_provision WHERE legal_rule_provision_id = :id AND deleted_at IS NULL LIMIT 1', ['id' => $id]); }
    private function provisionRowForUpdate(int $id): ?array { return $this->row('SELECT * FROM legal_rule_provision WHERE legal_rule_provision_id = :id AND deleted_at IS NULL LIMIT 1 FOR UPDATE', ['id' => $id]); }
    private function lockProvisionVersions(int $policyId, string $code): void { $this->rows('SELECT legal_rule_provision_id FROM legal_rule_provision WHERE legal_rule_policy_id = :policy_id AND provision_code = :code AND deleted_at IS NULL FOR UPDATE', ['policy_id' => $policyId, 'code' => $code]); }
    private function matterRow(int $id): ?array { return $this->row('SELECT * FROM legal_matter WHERE legal_matter_id = :id AND deleted_at IS NULL LIMIT 1', ['id' => $id]); }
    private function row(string $sql, array $params = []): ?array { $stmt = $this->pdo->prepare($sql); $stmt->execute($params); $row = $stmt->fetch(); return is_array($row) ? $row : null; }
    private function rows(string $sql, array $params = []): array { $stmt = $this->pdo->prepare($sql); $stmt->execute($params); return $stmt->fetchAll(); }
    private function scalar(string $sql, array $params = []): mixed { $stmt = $this->pdo->prepare($sql); $stmt->execute($params); return $stmt->fetchColumn(); }
    private function text(mixed $value, int $max): string { return mb_substr(trim((string)$value), 0, $max); }
    private function nullableText(mixed $value, int $max): ?string { $text = $this->text($value ?? '', $max); return $text === '' ? null : $text; }
    private function optionalId(mixed $value): ?int { if ($value === null || $value === '' || $value === 'all') return null; if (!ctype_digit((string)$value)) throw new InvalidArgumentException(json_encode(['id' => 'Choose a valid reference.'], JSON_THROW_ON_ERROR)); return (int)$value; }
    private function date(mixed $value): ?string { if ($value === null || $value === '') return null; return (new DateTimeImmutable((string)$value))->format('Y-m-d'); }
}
