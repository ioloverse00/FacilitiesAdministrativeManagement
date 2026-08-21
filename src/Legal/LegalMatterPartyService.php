<?php

declare(strict_types=1);

final class LegalMatterPartyService
{
    private const READABLE_MIME = ['application/pdf', 'image/jpeg', 'image/png'];
    private const MAX_SOURCE_BYTES = 12_000_000;

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

    public function analyze(int $matterId, array $user): ?array
    {
        $matter = $this->matter($matterId);
        if ($matter === null) {
            return null;
        }
        $this->assertMatterNotClosed($matter);
        $sources = $this->readableSources($matter);
        if (!$sources) {
            $this->history($matterId, 'LEGAL_AI_PARTIES_ANALYZED', 'AI party analysis skipped because no readable supporting documents were available.', ['suggestion_count' => 0], $user);
            return $this->matterWithParties($matterId);
        }

        $created = 0;
        try {
            $result = $this->requestExtraction($matter, $sources);
            foreach (($result['parties'] ?? []) as $candidate) {
                $clean = $this->validateSuggestionCandidate($candidate);
                if ($clean === null || $this->duplicateSuggestionOrPartyExists($matterId, $clean)) {
                    continue;
                }
                $this->insertAiParty($matterId, $clean);
                $created++;
            }
            $this->history($matterId, 'LEGAL_AI_PARTIES_ANALYZED', "AI party analysis populated $created new part" . ($created === 1 ? 'y.' : 'ies.'), ['party_count' => $created, 'needs_review' => (bool) ($result['needs_review'] ?? true)], $user);
            $this->activity('LEGAL_AI_PARTIES_ANALYZED', 'AI Parties Populated', 'AI party extraction updated parties involved.', $matterId, (string) $matter['matter_number'], $user);
        } catch (Throwable $exception) {
            $this->history($matterId, 'LEGAL_AI_PARTIES_FAILED', 'AI party analysis could not be completed.', ['reason' => $this->safeFailureStage($exception->getMessage())], $user);
        }
        return $this->matterWithParties($matterId);
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

    private function validateSuggestionCandidate(array $candidate): ?array
    {
        $name = $this->nullableText($candidate['name'] ?? null, 255);
        $organization = $this->nullableText($candidate['organization'] ?? null, 255);
        if ($name === null && $organization === null) return null;
        $role = strtoupper($this->text($candidate['suggested_role'] ?? 'PERSON_INVOLVED', 40));
        $type = strtoupper($this->text($candidate['suggested_type'] ?? 'EXTERNAL_PERSON', 40));
        if (!in_array($role, self::PARTY_ROLES, true)) $role = 'PERSON_INVOLVED';
        if (!in_array($type, self::PARTY_TYPES, true)) $type = 'EXTERNAL_PERSON';
        if ($role === 'RESPONDENT' && empty($candidate['explicit_role_supported'])) {
            $role = 'PERSON_INVOLVED';
        }
        return [
            'name' => $name ?? $organization,
            'organization' => $organization,
            'suggested_role' => $role,
            'suggested_type' => $type,
            'context' => $this->nullableText($candidate['context'] ?? null, 1500),
            'confidence' => in_array(strtoupper((string) ($candidate['confidence'] ?? '')), ['LOW', 'MEDIUM', 'HIGH'], true) ? strtoupper((string) $candidate['confidence']) : null,
            'source_document_id' => isset($candidate['source_document_id']) && ctype_digit((string) $candidate['source_document_id']) ? (int) $candidate['source_document_id'] : null,
            'source_document_reference' => $this->nullableText($candidate['source_document_reference'] ?? null, 80),
        ];
    }

    private function insertSuggestion(int $matterId, array $clean): void
    {
        $this->pdo->prepare("INSERT INTO legal_matter_party_suggestion (legal_matter_id, suggestion_hash, suggested_name, suggested_organization, suggested_party_role, suggested_party_type, context, confidence, source_document_id, source_document_reference, status, created_at, updated_at) VALUES (:matter_id, :hash, :name, :organization, :role, :type, :context, :confidence, :source_document_id, :source_document_reference, 'PENDING', NOW(), NOW())")->execute([
            'matter_id' => $matterId,
            'hash' => $this->suggestionHash($matterId, $clean),
            'name' => $clean['name'],
            'organization' => $clean['organization'],
            'role' => $clean['suggested_role'],
            'type' => $clean['suggested_type'],
            'context' => $clean['context'],
            'confidence' => $clean['confidence'],
            'source_document_id' => $clean['source_document_id'],
            'source_document_reference' => $clean['source_document_reference'],
        ]);
    }

    private function insertAiParty(int $matterId, array $clean): void
    {
        $this->pdo->prepare("INSERT INTO legal_matter_party (legal_matter_id, party_role, party_type, external_name, organization_name, notes, source_document_id, ai_suggested, party_source, review_status, created_at, updated_at) VALUES (:matter_id, :role, :type, :name, :organization, :notes, :source_document_id, 1, 'AI', 'PENDING_REVIEW', NOW(), NOW())")->execute([
            'matter_id' => $matterId,
            'role' => $clean['suggested_role'],
            'type' => $clean['suggested_type'],
            'name' => $clean['name'],
            'organization' => $clean['organization'],
            'notes' => $clean['context'],
            'source_document_id' => $clean['source_document_id'],
        ]);
    }

    private function readableSources(array $matter): array
    {
        $service = $this->documentService ?? new DocumentService($this->pdo);
        $documents = $service->currentRelatedFiles('LEGAL_MANAGEMENT', (string) $matter['matter_number']);
        $selected = [];
        $total = 0;
        foreach ($documents as $document) {
            $path = (string) ($document['absolutePath'] ?? '');
            $mime = (string) ($document['mimeType'] ?? '');
            $size = (int) ($document['fileSize'] ?? 0);
            if (!in_array($mime, self::READABLE_MIME, true) || $path === '' || !is_file($path) || $size <= 0 || $total + $size > self::MAX_SOURCE_BYTES) {
                continue;
            }
            $bytes = file_get_contents($path);
            if ($bytes === false || $bytes === '') continue;
            $document['base64'] = base64_encode($bytes);
            $selected[] = $document;
            $total += $size;
        }
        return $selected;
    }

    private function requestExtraction(array $matter, array $sources): array
    {
        if (trim((string) env('GEMINI_API_KEY', '')) === '') {
            throw new RuntimeException('GEMINI_KEY_MISSING');
        }
        $response = $this->postJson($this->geminiEndpoint(), $this->geminiPayload($matter, $sources));
        $text = trim(implode("\n", array_map(static fn (array $candidate): string => implode("\n", array_column($candidate['content']['parts'] ?? [], 'text')), $response['candidates'] ?? [])));
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text) ?? $text;
        $decoded = json_decode(trim($text), true);
        if (!is_array($decoded)) throw new RuntimeException('GEMINI_RESPONSE_INVALID');
        return $decoded;
    }

    private function geminiPayload(array $matter, array $sources): array
    {
        $parts = [['text' => $this->instructions($matter)]];
        foreach ($sources as $index => $source) {
            $parts[] = ['text' => 'Source ' . ($index + 1) . ': document_id=' . (int) $source['id'] . ', reference=' . (string) $source['documentNo'] . ', title=' . (string) $source['title']];
            $parts[] = ['inline_data' => ['mime_type' => (string) $source['mimeType'], 'data' => (string) $source['base64']]];
        }
        return [
            'contents' => [['role' => 'user', 'parts' => $parts]],
            'generationConfig' => [
                'temperature' => 0,
                'response_mime_type' => 'application/json',
                'response_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'parties' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                            'name' => ['type' => 'string', 'nullable' => true],
                            'organization' => ['type' => 'string', 'nullable' => true],
                            'suggested_type' => ['type' => 'string'],
                            'suggested_role' => ['type' => 'string'],
                            'context' => ['type' => 'string'],
                            'confidence' => ['type' => 'string'],
                            'source_document_id' => ['type' => 'integer', 'nullable' => true],
                            'source_document_reference' => ['type' => 'string', 'nullable' => true],
                            'explicit_role_supported' => ['type' => 'boolean'],
                        ]]],
                        'needs_review' => ['type' => 'boolean'],
                    ],
                    'required' => ['parties', 'needs_review'],
                ],
            ],
        ];
    }

    private function instructions(array $matter): string
    {
        return "Identify people or organizations clearly mentioned in the supporting evidence for this Legal Matter. Matter title is context only: " . (string) $matter['title'] . "\n\n"
            . "Return only party candidates supported by the supplied linked documents. Describe only the explicitly supported relationship to the matter.\n\n"
            . "Canonical roles: " . implode(', ', self::PARTY_ROLES) . ". Canonical types: " . implode(', ', self::PARTY_TYPES) . ". Prefer PERSON_INVOLVED when the role is unclear. Do NOT assign RESPONDENT unless the source explicitly characterizes that party as respondent or equivalent.\n\n"
            . "Do NOT infer guilt, fault, liability, responsibility, criminal involvement, wrongdoing, complainant status, or respondent status. A person being present, associated with a reservation, or mentioned in an incident report does not make them responsible. Do not invent names, organizations, titles, or relationships.\n\n"
            . "For every candidate include a short neutral context sentence and confidence LOW, MEDIUM, or HIGH. Set explicit_role_supported=true only when the source explicitly supports the suggested role.";
    }

    private function postJson(string $url, array $payload): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) throw new RuntimeException('GEMINI_REQUEST_INVALID');
        $ch = curl_init($url);
        if ($ch === false) throw new RuntimeException('GEMINI_TRANSPORT_ERROR');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => max(5, (int) env('GEMINI_LEGAL_SUMMARY_TIMEOUT_SECONDS', 35))]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($raw === false || $status < 200 || $status >= 300) {
            throw new RuntimeException($error !== '' ? 'GEMINI_TRANSPORT_ERROR' : 'GEMINI_HTTP_' . $status);
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) throw new RuntimeException('GEMINI_RESPONSE_INVALID');
        return $decoded;
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
    private function safeFailureStage(string $message): string { $stage = strtok($message, ' '); return is_string($stage) && preg_match('/^[A-Z0-9_]+$/', $stage) ? $stage : 'AI_REQUEST_FAILED'; }

    private function geminiEndpoint(): string
    {
        $model = trim((string) env('GEMINI_LEGAL_SUMMARY_MODEL', ''));
        if ($model === '') $model = trim((string) env('GEMINI_VISITOR_ID_MODEL', 'gemini-3.6-flash'));
        $modelPath = str_starts_with($model, 'models/') ? $model : 'models/' . rawurlencode($model);
        return 'https://generativelanguage.googleapis.com/v1beta/' . $modelPath . ':generateContent?key=' . rawurlencode(trim((string) env('GEMINI_API_KEY', '')));
    }

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
