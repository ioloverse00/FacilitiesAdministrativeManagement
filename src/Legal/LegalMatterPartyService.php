<?php

declare(strict_types=1);

final class LegalMatterPartyService
{
    public const PARTY_ROLES = [
        'REPORTING_PARTY',
        'COMPLAINANT',
        'RESPONDENT',
        'WITNESS',
        'PERSON_INVOLVED',
        'REPRESENTATIVE',
        'INSPECTOR',
        'OTHER',
    ];

    public const PARTY_TYPES = [
        'EMPLOYEE',
        'VISITOR',
        'SUPPLIER_VENDOR',
        'CONTRACTOR',
        'EXTERNAL_PERSON',
        'ORGANIZATION',
        'GOVERNMENT_AGENCY',
        'OTHER',
    ];

    public function __construct(private readonly PDO $pdo, private readonly ?DocumentService $documentService = null)
    {
    }

    public function partiesForMatter(int $matterId): array
    {
        return array_map(fn (array $row): array => $this->shapeParty($row), $this->rows(
            "SELECT p.*, e.full_name employee_name, e.employee_number, e.position_title, d.department_name,
                    CONCAT_WS(' ', v.first_name, v.middle_name, v.last_name) visitor_name, v.organization_name visitor_organization,
                    doc.document_number source_document_number
             FROM legal_matter_party p
             LEFT JOIN employee_reference e ON e.employee_reference_id = p.employee_reference_id
             LEFT JOIN department_reference d ON d.department_reference_id = e.department_reference_id
             LEFT JOIN visitor v ON v.visitor_id = p.visitor_id
             LEFT JOIN document doc ON doc.document_id = p.source_document_id
             WHERE p.legal_matter_id = :id AND p.deleted_at IS NULL
             ORDER BY FIELD(p.party_role,'REPORTING_PARTY','COMPLAINANT','RESPONDENT','INSPECTOR','WITNESS','PERSON_INVOLVED','REPRESENTATIVE','OTHER'), COALESCE(e.full_name, v.first_name, p.external_name, p.organization_name)",
            ['id' => $matterId]
        ));
    }

    public function suggestionsForMatter(int $matterId): array
    {
        return array_map(fn (array $row): array => $this->shapeSuggestion($row), $this->uniqueSuggestionRows($this->rows(
            "SELECT s.*, doc.document_number source_document_number
             FROM legal_matter_party_suggestion s
             LEFT JOIN document doc ON doc.document_id = s.source_document_id
             WHERE s.legal_matter_id = :id AND s.status = 'PENDING'
             ORDER BY FIELD(s.suggested_party_role,'REPORTING_PARTY','COMPLAINANT','RESPONDENT','INSPECTOR','WITNESS','PERSON_INVOLVED','REPRESENTATIVE','OTHER'), s.suggested_name",
            ['id' => $matterId]
        )));
    }

    public function addParty(int $matterId, array $data, array $user, bool $fromAi = false, ?int $suggestionId = null): ?array
    {
        $matter = $this->matter($matterId);
        if ($matter === null) {
            return null;
        }
        $this->assertMatterNotClosed($matter);
        $clean = $this->validateParty($data);
        if ($this->duplicatePartyExists($matterId, $clean)) {
            throw new InvalidArgumentException(json_encode(['party' => 'This party already exists for this matter.'], JSON_THROW_ON_ERROR));
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("INSERT INTO legal_matter_party (legal_matter_id, party_role, party_type, employee_reference_id, visitor_id, external_name, organization_name, contact_information, notes, source_document_id, ai_suggested, party_source, review_status, confirmed_by_user_id, confirmed_at, created_at, updated_at) VALUES (:matter_id, :role, :type, :employee_id, :visitor_id, :external_name, :organization, :contact, :notes, :source_document_id, :ai_suggested, :party_source, :review_status, :user_id, NOW(), NOW(), NOW())")->execute([
                'matter_id' => $matterId,
                'role' => $clean['party_role'],
                'type' => $clean['party_type'],
                'employee_id' => $clean['employee_reference_id'],
                'visitor_id' => $clean['visitor_id'],
                'external_name' => $clean['external_name'],
                'organization' => $clean['organization_name'],
                'contact' => $clean['contact_information'],
                'notes' => $clean['notes'],
                'source_document_id' => $clean['source_document_id'],
                'ai_suggested' => $fromAi ? 1 : 0,
                'party_source' => $fromAi ? 'AI' : 'MANUAL',
                'review_status' => $fromAi && $suggestionId === null ? 'PENDING_REVIEW' : 'REVIEWED',
                'user_id' => (int) $user['id'],
            ]);
            $partyId = (int) $this->pdo->lastInsertId();
            if ($suggestionId !== null) {
                $this->markSuggestion($suggestionId, 'ACCEPTED', (int) $user['id'], $partyId);
            }
            $name = $this->partyDisplayName($clean);
            $event = $fromAi ? ($suggestionId === null ? 'LEGAL_PARTY_ADDED' : 'LEGAL_AI_PARTY_ACCEPTED') : 'LEGAL_PARTY_ADDED';
            $this->history($matterId, $event, "$name was confirmed as " . $this->human($clean['party_role']) . '.', ['party_id' => $partyId], $user);
            $this->activity($event, $fromAi ? 'AI Party Accepted' : 'Legal Party Added', 'Legal matter party confirmed.', $matterId, (string) $matter['matter_number'], $user);
            $this->pdo->commit();
            return $this->matterWithParties($matterId);
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function updateParty(int $matterId, int $partyId, array $data, array $user): ?array
    {
        $party = $this->party($matterId, $partyId);
        if ($party === null) {
            return null;
        }
        $this->assertMatterNotClosed($this->matter($matterId));
        $clean = $this->validateParty($data);
        if ($this->duplicatePartyExists($matterId, $clean, $partyId)) {
            throw new InvalidArgumentException(json_encode(['party' => 'This party already exists for this matter.'], JSON_THROW_ON_ERROR));
        }
        $this->pdo->prepare("UPDATE legal_matter_party SET party_role = :role, party_type = :type, employee_reference_id = :employee_id, visitor_id = :visitor_id, external_name = :external_name, organization_name = :organization, contact_information = :contact, notes = :notes, source_document_id = :source_document_id, review_status = 'REVIEWED', confirmed_by_user_id = :user_id, confirmed_at = COALESCE(confirmed_at, NOW()), updated_at = NOW() WHERE legal_matter_id = :matter_id AND legal_matter_party_id = :party_id AND deleted_at IS NULL")->execute([
            'matter_id' => $matterId,
            'party_id' => $partyId,
            'role' => $clean['party_role'],
            'type' => $clean['party_type'],
            'employee_id' => $clean['employee_reference_id'],
            'visitor_id' => $clean['visitor_id'],
            'external_name' => $clean['external_name'],
            'organization' => $clean['organization_name'],
            'contact' => $clean['contact_information'],
            'notes' => $clean['notes'],
            'source_document_id' => $clean['source_document_id'],
            'user_id' => (int) $user['id'],
        ]);
        $this->history($matterId, 'LEGAL_PARTY_REVIEWED', $this->partyDisplayName($clean) . ' was reviewed and updated.', ['party_id' => $partyId], $user);
        return $this->matterWithParties($matterId);
    }

    public function dismissParty(int $matterId, int $partyId, array $data, array $user): ?array
    {
        $party = $this->party($matterId, $partyId);
        if ($party === null) {
            return null;
        }
        $this->assertMatterNotClosed($this->matter($matterId));
        $reason = $this->nullableText($data['reason'] ?? null, 255);
        if (($party['party_source'] ?? '') === 'AI' && $reason === null) {
            throw new InvalidArgumentException(json_encode(['reason' => 'Enter a short dismissal reason.'], JSON_THROW_ON_ERROR));
        }
        $this->pdo->prepare('UPDATE legal_matter_party SET dismissal_reason = :reason, deleted_at = NOW(), updated_at = NOW() WHERE legal_matter_id = :matter_id AND legal_matter_party_id = :party_id AND deleted_at IS NULL')->execute([
            'reason' => $reason,
            'matter_id' => $matterId,
            'party_id' => $partyId,
        ]);
        $name = (string) ($party['employee_name'] ?: trim((string) ($party['visitor_name'] ?? '')) ?: $party['external_name'] ?: $party['organization_name'] ?: 'Party');
        $this->history($matterId, 'LEGAL_PARTY_DISMISSED', "$name was dismissed from parties involved.", ['party_id' => $partyId, 'reason' => $reason], $user);
        return $this->matterWithParties($matterId);
    }

    public function acceptSuggestion(int $suggestionId, array $data, array $user): ?array
    {
        $suggestion = $this->suggestion($suggestionId, true);
        if ($suggestion === null) {
            return null;
        }
        $this->assertMatterNotClosed($this->matter((int) $suggestion['legal_matter_id']));
        $payload = [
            'party_role' => $data['party_role'] ?? $suggestion['suggested_party_role'],
            'party_type' => $data['party_type'] ?? $suggestion['suggested_party_type'],
            'external_name' => $data['external_name'] ?? $suggestion['suggested_name'],
            'organization_name' => $data['organization_name'] ?? $suggestion['suggested_organization'],
            'contact_information' => $data['contact_information'] ?? '',
            'notes' => $data['notes'] ?? $suggestion['context'],
            'employee_reference_id' => $data['employee_reference_id'] ?? null,
            'visitor_id' => $data['visitor_id'] ?? null,
            'source_document_id' => $suggestion['source_document_id'] ?? null,
        ];
        $edited = array_key_exists('party_role', $data) || array_key_exists('party_type', $data) || array_key_exists('external_name', $data) || array_key_exists('organization_name', $data);
        $result = $this->addParty((int) $suggestion['legal_matter_id'], $payload, $user, true, $suggestionId);
        if ($edited && $result !== null) {
            $this->history((int) $suggestion['legal_matter_id'], 'LEGAL_AI_PARTY_EDITED_ACCEPTED', 'AI party suggestion was edited and accepted.', ['suggestion_id' => $suggestionId], $user);
        }
        return $result;
    }

    public function dismissSuggestion(int $suggestionId, array $user): ?array
    {
        $suggestion = $this->suggestion($suggestionId, true);
        if ($suggestion === null) {
            return null;
        }
        $this->assertMatterNotClosed($this->matter((int) $suggestion['legal_matter_id']));
        $this->markSuggestion($suggestionId, 'DISMISSED', (int) $user['id'], null);
        $this->history((int) $suggestion['legal_matter_id'], 'LEGAL_AI_PARTY_DISMISSED', 'AI party suggestion dismissed.', ['suggestion_id' => $suggestionId], $user);
        return $this->matterWithParties((int) $suggestion['legal_matter_id']);
    }

    public function markPending(int $matterId, array $user): void
    {
        $matter = $this->matter($matterId);
        if ($matter === null || ($matter['status'] ?? '') === 'CLOSED') {
            return;
        }
        $this->history($matterId, 'LEGAL_AI_PARTIES_PENDING', 'AI party extraction queued.', ['status' => 'PENDING'], $user);
    }

    public function lookups(): array
    {
        return [
            'party_roles' => self::PARTY_ROLES,
            'party_types' => self::PARTY_TYPES,
            'employees' => array_map(fn (array $row): array => [
                'id' => (int) $row['employee_reference_id'],
                'name' => (string) $row['full_name'],
                'employeeNo' => (string) $row['employee_number'],
                'department' => (string) ($row['department_name'] ?? ''),
                'position' => (string) ($row['position_title'] ?? ''),
            ], $this->rows("SELECT e.employee_reference_id, e.employee_number, e.full_name, e.position_title, d.department_name FROM employee_reference e LEFT JOIN department_reference d ON d.department_reference_id = e.department_reference_id WHERE e.employment_status = 'ACTIVE' ORDER BY e.full_name")),
        ];
    }

    private function validateParty(array $data): array
    {
        $role = strtoupper($this->text($data['party_role'] ?? 'PERSON_INVOLVED', 40));
        $type = strtoupper($this->text($data['party_type'] ?? 'EXTERNAL_PERSON', 40));
        $employeeId = $this->optionalId($data['employee_reference_id'] ?? null);
        $visitorId = $this->optionalId($data['visitor_id'] ?? null);
        $externalName = $this->nullableText($data['external_name'] ?? null, 255);
        $organization = $this->nullableText($data['organization_name'] ?? null, 255);
        $errors = [];
        if (!in_array($role, self::PARTY_ROLES, true)) $errors['party_role'] = 'Choose a valid party role.';
        if (!in_array($type, self::PARTY_TYPES, true)) $errors['party_type'] = 'Choose a valid party type.';
        if ($type === 'EMPLOYEE') {
            if ($employeeId === null) $errors['employee_reference_id'] = 'Choose an employee.';
            elseif (!$this->exists('employee_reference', 'employee_reference_id', $employeeId, "employment_status='ACTIVE'")) $errors['employee_reference_id'] = 'Choose a valid employee.';
        }
        if ($type === 'VISITOR') {
            if ($visitorId === null) $errors['visitor_id'] = 'Choose a visitor reference.';
            elseif (!$this->exists('visitor', 'visitor_id', $visitorId)) $errors['visitor_id'] = 'Choose a valid visitor.';
        }
        if (!in_array($type, ['EMPLOYEE', 'VISITOR'], true) && $externalName === null && $organization === null) {
            $errors['external_name'] = 'Enter a party name or organization.';
        }
        if ($errors) {
            throw new InvalidArgumentException(json_encode($errors, JSON_THROW_ON_ERROR));
        }
        return [
            'party_role' => $role,
            'party_type' => $type,
            'employee_reference_id' => $type === 'EMPLOYEE' ? $employeeId : null,
            'visitor_id' => $type === 'VISITOR' ? $visitorId : null,
            'external_name' => in_array($type, ['EMPLOYEE', 'VISITOR'], true) ? null : $externalName,
            'organization_name' => $organization,
            'contact_information' => $this->nullableText($data['contact_information'] ?? null, 255),
            'notes' => $this->nullableText($data['notes'] ?? null, 2000),
            'source_document_id' => $this->optionalId($data['source_document_id'] ?? null),
        ];
    }

    private function matterWithParties(int $matterId): array
    {
        return ['parties' => $this->partiesForMatter($matterId), 'partySuggestions' => $this->suggestionsForMatter($matterId)];
    }

    private function matter(int $matterId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM legal_matter WHERE legal_matter_id = :id AND deleted_at IS NULL LIMIT 1');
        $stmt->execute(['id' => $matterId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    private function party(int $matterId, int $partyId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT p.*, e.full_name employee_name, CONCAT_WS(' ', v.first_name, v.middle_name, v.last_name) visitor_name FROM legal_matter_party p LEFT JOIN employee_reference e ON e.employee_reference_id = p.employee_reference_id LEFT JOIN visitor v ON v.visitor_id = p.visitor_id WHERE p.legal_matter_id = :matter_id AND p.legal_matter_party_id = :party_id AND p.deleted_at IS NULL LIMIT 1");
        $stmt->execute(['matter_id' => $matterId, 'party_id' => $partyId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    private function suggestion(int $suggestionId, bool $pendingOnly = false): ?array
    {
        $sql = 'SELECT * FROM legal_matter_party_suggestion WHERE legal_matter_party_suggestion_id = :id' . ($pendingOnly ? " AND status = 'PENDING'" : '') . ' LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $suggestionId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    private function markSuggestion(int $suggestionId, string $status, int $userId, ?int $partyId): void
    {
        $this->pdo->prepare('UPDATE legal_matter_party_suggestion SET status = :status, reviewed_by_user_id = :user_id, reviewed_at = NOW(), accepted_party_id = :party_id, updated_at = NOW() WHERE legal_matter_party_suggestion_id = :id')->execute(['status' => $status, 'user_id' => $userId, 'party_id' => $partyId, 'id' => $suggestionId]);
    }

    private function duplicateSuggestionOrPartyExists(int $matterId, array $clean): bool
    {
        if ($this->duplicateDismissedSuggestionExists($matterId, $clean)) return true;
        if ($this->duplicateDismissedAiPartyExists($matterId, $clean)) return true;
        return $this->duplicatePartyExists($matterId, [
            'party_role' => $clean['suggested_role'],
            'party_type' => $clean['suggested_type'],
            'employee_reference_id' => null,
            'visitor_id' => null,
            'external_name' => $clean['name'],
            'organization_name' => $clean['organization'],
        ]);
    }

    private function duplicateDismissedSuggestionExists(int $matterId, array $clean): bool
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM legal_matter_party_suggestion WHERE legal_matter_id = :matter_id AND suggestion_hash = :hash AND status = 'DISMISSED'", ['matter_id' => $matterId, 'hash' => $this->suggestionHash($matterId, $clean)]) > 0;
    }

    private function duplicatePartyExists(int $matterId, array $clean, ?int $ignorePartyId = null): bool
    {
        $ignoreSql = $ignorePartyId !== null ? ' AND legal_matter_party_id <> :ignore_id' : '';
        $ignoreParams = $ignorePartyId !== null ? ['ignore_id' => $ignorePartyId] : [];
        if (($clean['employee_reference_id'] ?? null) !== null) {
            return (int) $this->scalar('SELECT COUNT(*) FROM legal_matter_party WHERE legal_matter_id = :matter_id AND deleted_at IS NULL AND employee_reference_id = :id' . $ignoreSql, ['matter_id' => $matterId, 'id' => $clean['employee_reference_id']] + $ignoreParams) > 0;
        }
        if (($clean['visitor_id'] ?? null) !== null) {
            return (int) $this->scalar('SELECT COUNT(*) FROM legal_matter_party WHERE legal_matter_id = :matter_id AND deleted_at IS NULL AND visitor_id = :id' . $ignoreSql, ['matter_id' => $matterId, 'id' => $clean['visitor_id']] + $ignoreParams) > 0;
        }
        $name = $this->normalizeName((string) (($clean['external_name'] ?? '') ?: ($clean['organization_name'] ?? '')));
        $role = strtoupper((string) ($clean['party_role'] ?? ''));
        $type = strtoupper((string) ($clean['party_type'] ?? ''));
        if ($name === '' || $role === '' || $type === '') {
            return false;
        }
        return (int) $this->scalar("SELECT COUNT(*) FROM legal_matter_party WHERE legal_matter_id = :matter_id AND deleted_at IS NULL AND party_role = :role AND party_type = :type AND LOWER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(external_name, organization_name, ''), ' ', ''), '.', ''), ',', ''), '-', '')) = :name" . $ignoreSql, ['matter_id' => $matterId, 'role' => $role, 'type' => $type, 'name' => $name] + $ignoreParams) > 0;
    }

    private function duplicateDismissedAiPartyExists(int $matterId, array $clean): bool
    {
        $name = $this->normalizeName((string) ($clean['name'] ?? ''));
        if ($name === '') {
            return false;
        }
        return (int) $this->scalar("SELECT COUNT(*) FROM legal_matter_party WHERE legal_matter_id = :matter_id AND deleted_at IS NOT NULL AND party_source = 'AI' AND party_role = :role AND party_type = :type AND LOWER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(external_name, organization_name, ''), ' ', ''), '.', ''), ',', ''), '-', '')) = :name", [
            'matter_id' => $matterId,
            'role' => $clean['suggested_role'],
            'type' => $clean['suggested_type'],
            'name' => $name,
        ]) > 0;
    }

    private function suggestionHash(int $matterId, array $clean): string
    {
        return hash('sha256', $matterId . '|' . $this->suggestionIdentityKey($clean));
    }

    private function uniqueSuggestionRows(array $rows): array
    {
        $seen = [];
        $unique = [];
        foreach ($rows as $row) {
            $key = $this->suggestionIdentityKey([
                'name' => $row['suggested_name'] ?? '',
                'organization' => $row['suggested_organization'] ?? '',
                'suggested_role' => $row['suggested_party_role'] ?? '',
                'suggested_type' => $row['suggested_party_type'] ?? '',
            ]);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $row;
        }
        return $unique;
    }

    private function suggestionIdentityKey(array $clean): string
    {
        $name = $this->normalizeName((string) ($clean['name'] ?? ''));
        $organization = $this->normalizeName((string) ($clean['organization'] ?? ''));
        $role = strtoupper((string) ($clean['suggested_role'] ?? ''));
        $type = strtoupper((string) ($clean['suggested_type'] ?? ''));
        $identity = $name !== '' ? $name : $organization;
        if ($identity === '') {
            return '';
        }
        return $identity . '|' . $type . '|' . $role;
    }

    private function shapeParty(array $row): array
    {
        $name = (string) ($row['employee_name'] ?: trim((string) ($row['visitor_name'] ?? '')) ?: $row['external_name'] ?: $row['organization_name'] ?: 'Unnamed party');
        return [
            'id' => (int) $row['legal_matter_party_id'],
            'role' => (string) $row['party_role'],
            'type' => (string) $row['party_type'],
            'name' => $name,
            'organization' => (string) ($row['organization_name'] ?: $row['department_name'] ?: $row['visitor_organization'] ?: ''),
            'employeeReferenceId' => (string) ($row['employee_reference_id'] ?? ''),
            'visitorId' => (string) ($row['visitor_id'] ?? ''),
            'subtitle' => trim(implode(' • ', array_filter([(string) ($row['position_title'] ?? ''), (string) ($row['department_name'] ?? '')]))),
            'notes' => (string) ($row['notes'] ?? ''),
            'aiSuggested' => (bool) $row['ai_suggested'],
            'source' => (string) ($row['party_source'] ?? ((int) $row['ai_suggested'] === 1 ? 'AI' : 'MANUAL')),
            'reviewStatus' => (string) ($row['review_status'] ?? ''),
            'sourceDocument' => (string) ($row['source_document_number'] ?? ''),
            'confirmedAt' => (string) ($row['confirmed_at'] ?? ''),
        ];
    }

    private function shapeSuggestion(array $row): array
    {
        return [
            'id' => (int) $row['legal_matter_party_suggestion_id'],
            'name' => (string) $row['suggested_name'],
            'organization' => (string) ($row['suggested_organization'] ?? ''),
            'role' => (string) $row['suggested_party_role'],
            'type' => (string) $row['suggested_party_type'],
            'context' => (string) ($row['context'] ?? ''),
            'shortContext' => $this->shortContext((string) ($row['context'] ?? '')),
            'confidence' => (string) ($row['confidence'] ?? ''),
            'sourceDocument' => (string) ($row['source_document_number'] ?: $row['source_document_reference'] ?: ''),
        ];
    }

    private function shortContext(string $value): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $value) ?? '');
        if ($text === '') {
            return '';
        }
        if (preg_match('/^(.+?[.!?])(?:\s|$)/', $text, $match)) {
            $text = trim($match[1]);
        }
        if (mb_strlen($text) > 150) {
            $text = rtrim(mb_substr($text, 0, 147), " \t\n\r\0\x0B.,;:") . '...';
        }
        return $text;
    }

    private function partyDisplayName(array $clean): string
    {
        if ($clean['employee_reference_id']) return (string) $this->scalar('SELECT full_name FROM employee_reference WHERE employee_reference_id = :id', ['id' => $clean['employee_reference_id']]);
        if ($clean['visitor_id']) return (string) $this->scalar("SELECT TRIM(CONCAT_WS(' ', first_name, middle_name, last_name)) FROM visitor WHERE visitor_id = :id", ['id' => $clean['visitor_id']]);
        return (string) ($clean['external_name'] ?: $clean['organization_name'] ?: 'Party');
    }

    private function exists(string $table, string $key, int $id, string $extra = '1=1'): bool
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE `$key` = :id AND $extra");
        $stmt->execute(['id' => $id]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function assertMatterNotClosed(?array $matter): void
    {
        if (($matter['status'] ?? '') === 'CLOSED') {
            throw new InvalidArgumentException(json_encode(['status' => 'This legal matter is closed and is read-only.'], JSON_THROW_ON_ERROR));
        }
    }

    private function rows(string $sql, array $params = []): array { $stmt = $this->pdo->prepare($sql); $stmt->execute($params); return $stmt->fetchAll(); }
    private function scalar(string $sql, array $params = []): mixed { $stmt = $this->pdo->prepare($sql); $stmt->execute($params); return $stmt->fetchColumn(); }
    private function optionalId(mixed $value): ?int { return ($value === null || $value === '' || $value === 'all') ? null : (ctype_digit((string) $value) ? (int) $value : null); }
    private function text(mixed $value, int $max): string { return mb_substr(trim((string) $value), 0, $max); }
    private function nullableText(mixed $value, int $max): ?string { $text = $this->text($value ?? '', $max); return $text === '' ? null : $text; }
    private function normalizeName(string $value): string { return strtolower(preg_replace('/[^a-z0-9]+/i', '', $value) ?? ''); }
    private function human(string $value): string { return ucwords(strtolower(str_replace('_', ' ', $value))); }

    private function history(int $matterId, string $event, string $description, array $metadata, array $user): void
    {
        $this->pdo->prepare('INSERT INTO legal_matter_history (legal_matter_id, event_type, from_status, to_status, description, metadata_json, actor_user_id, created_at) VALUES (:id, :event, NULL, NULL, :description, :metadata, :user_id, NOW())')->execute(['id' => $matterId, 'event' => $event, 'description' => $description, 'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'user_id' => (int) $user['id']]);
    }

    private function activity(string $event, string $title, string $description, int $matterId, string $reference, array $user): void
    {
        try {
            $this->pdo->prepare("INSERT INTO activity_event (event_uuid, module_code, entity_type, entity_id, entity_reference, event_type, event_title, event_description, actor_user_id, actor_employee_reference_id, visibility_scope, metadata_json, occurred_at, created_at) VALUES (UUID(), 'LEGAL_MANAGEMENT', 'legal_matter', :id, :reference, :event_type, :title, :description, :user_id, :employee_id, 'INTERNAL', '{}', NOW(), NOW())")->execute(['id' => $matterId, 'reference' => $reference, 'event_type' => $event, 'title' => $title, 'description' => $description, 'user_id' => (int) $user['id'], 'employee_id' => $user['employee_id'] ?? null]);
        } catch (Throwable) {
        }
    }
}
