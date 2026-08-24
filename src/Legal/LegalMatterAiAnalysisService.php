<?php

declare(strict_types=1);

final class LegalMatterAiAnalysisService
{
    private const PROVIDER = 'GEMINI';
    private const READABLE_MIME = ['application/pdf', 'image/jpeg', 'image/png'];
    private const MAX_SOURCE_BYTES = 12_000_000;
    private const MAX_ACTIONS = 5;

    public function __construct(
        private readonly PDO $pdo,
        private readonly ?DocumentService $documentService = null,
        private readonly ?LegalMatterActionService $actionService = null
    ) {
    }

    public function markPending(int $matterId, array $user): ?array
    {
        $matter = $this->matter($matterId);
        if ($matter === null) return null;
        $this->assertMatterNotClosed($matter);
        $this->pdo->beginTransaction();
        try {
            $this->updateSummary($matterId, [
                'ai_summary_status' => 'PENDING',
                'ai_summary_provider' => self::PROVIDER,
                'ai_summary_model' => $this->model(),
            ]);
            $this->history($matterId, 'LEGAL_AI_ANALYSIS_STARTED', 'Unified Legal AI analysis queued.', [
                'status' => 'PENDING',
                'model' => $this->model(),
            ], $user);
            $this->history($matterId, 'LEGAL_AI_PARTIES_PENDING', 'AI party extraction queued.', ['status' => 'PENDING'], $user);
            $this->history($matterId, 'LEGAL_AI_ACTIONS_PENDING', 'AI action recommendation extraction queued.', ['status' => 'PENDING'], $user);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
        return $this->hydrate($matterId);
    }

    public function analyze(int $matterId, array $user, bool $regeneration = false): ?array
    {
        $matter = $this->matter($matterId);
        if ($matter === null) return null;
        $this->assertMatterNotClosed($matter);
        $startedAt = microtime(true);
        $sources = $this->readableSources($matter);
        if (!$sources) {
            $this->markNoReadableSource($matterId, $user);
            return $this->result($matterId, ['summary' => 'NO_READABLE_SOURCE', 'parties' => 'EMPTY', 'actions' => 'EMPTY'], 0, 0);
        }

        $fingerprint = $this->fingerprint($sources);
        $this->markAnalysisStarted($matterId, $sources, $user);
        $attempts = 0;
        try {
            $analysis = $this->requestAnalysis($matter, $sources, $attempts);
        } catch (Throwable $exception) {
            $reason = $this->safeFailureStage($exception->getMessage());
            $this->markProviderFailure($matterId, $reason, count($sources), $attempts, $startedAt, $user);
            return $this->result($matterId, ['summary' => $this->uiFailureStatus($reason), 'parties' => $this->uiFailureStatus($reason), 'actions' => $this->uiFailureStatus($reason)], $attempts, $this->elapsed($startedAt));
        }

        $sectionStatuses = ['summary' => 'FAILED', 'parties' => 'FAILED', 'actions' => 'FAILED'];
        $sectionCounts = ['parties' => 0, 'actions' => 0];
        $this->pdo->beginTransaction();
        try {
            $sectionStatuses['summary'] = $this->persistSummary($matterId, $analysis['summary'] ?? null, $fingerprint, $regeneration, count($sources), $user);
            [$sectionStatuses['parties'], $sectionCounts['parties']] = $this->persistParties($matterId, $analysis['parties'] ?? null, $user);
            [$sectionStatuses['actions'], $sectionCounts['actions']] = $this->persistActions($matterId, $analysis['actions'] ?? null);
            $this->history($matterId, 'LEGAL_AI_ANALYSIS_COMPLETED', 'Unified Legal AI analysis completed.', [
                'model' => $this->model(),
                'source_count' => count($sources),
                'elapsed_ms' => $this->elapsed($startedAt),
                'attempt_count' => $attempts,
                'section_statuses' => $sectionStatuses,
                'party_count' => $sectionCounts['parties'],
                'suggestion_count' => $sectionCounts['actions'],
            ], $user);
            $this->activity('LEGAL_AI_ANALYSIS_COMPLETED', 'Legal AI Analysis Completed', 'Unified Legal AI analysis completed.', $matterId, (string)$matter['matter_number'], $user);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }

        return $this->result($matterId, $sectionStatuses, $attempts, $this->elapsed($startedAt));
    }

    private function markAnalysisStarted(int $matterId, array $sources, array $user): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->updateSummary($matterId, [
                'ai_summary_status' => 'PENDING',
                'ai_summary_provider' => self::PROVIDER,
                'ai_summary_model' => $this->model(),
                'ai_summary_source_fingerprint' => $this->fingerprint($sources),
            ]);
            $this->history($matterId, 'LEGAL_AI_ANALYSIS_STARTED', 'Unified Legal AI analysis started.', [
                'status' => 'PENDING',
                'model' => $this->model(),
                'source_count' => count($sources),
            ], $user);
            $this->history($matterId, 'LEGAL_AI_PARTIES_PENDING', 'AI party extraction queued.', ['status' => 'PENDING'], $user);
            $this->history($matterId, 'LEGAL_AI_ACTIONS_PENDING', 'AI action recommendation extraction queued.', ['status' => 'PENDING'], $user);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    private function persistSummary(int $matterId, mixed $summary, string $fingerprint, bool $regeneration, int $sourceCount, array $user): string
    {
        if (!is_array($summary)) {
            $this->history($matterId, 'LEGAL_AI_SUMMARY_FAILED', 'AI matter summary section failed validation.', ['reason' => 'SECTION_VALIDATION_FAILED'], $user);
            return 'FAILED';
        }
        $available = (bool)($summary['available'] ?? $summary['summary_available'] ?? false);
        $text = $this->cleanText((string)($summary['text'] ?? $summary['summary'] ?? ''));
        if (!$available || $text === '') {
            $this->updateSummary($matterId, [
                'ai_summary_status' => 'NO_READABLE_SOURCE',
                'ai_summary_provider' => self::PROVIDER,
                'ai_summary_model' => $this->model(),
                'ai_summary_source_fingerprint' => $fingerprint,
            ]);
            $this->history($matterId, 'LEGAL_AI_SUMMARY_FAILED', 'No readable summary content was returned.', ['reason' => 'NO_SUMMARY_CONTENT'], $user);
            return 'EMPTY';
        }
        $this->updateSummary($matterId, [
            'ai_summary' => mb_substr($text, 0, 2500),
            'ai_summary_status' => 'READY',
            'ai_summary_generated_at' => date('Y-m-d H:i:s'),
            'ai_summary_provider' => self::PROVIDER,
            'ai_summary_model' => $this->model(),
            'ai_summary_source_fingerprint' => $fingerprint,
        ]);
        $event = $regeneration ? 'LEGAL_AI_SUMMARY_REGENERATED' : 'LEGAL_AI_SUMMARY_GENERATED';
        $this->history($matterId, $event, 'AI matter summary generated from unified analysis.', [
            'source_count' => $sourceCount,
            'needs_review' => (bool)($summary['needs_review'] ?? true),
        ], $user);
        return 'READY';
    }

    private function persistParties(int $matterId, mixed $parties, array $user): array
    {
        if (!is_array($parties)) {
            $this->history($matterId, 'LEGAL_AI_PARTIES_FAILED', 'AI parties section failed validation.', ['reason' => 'SECTION_VALIDATION_FAILED'], $user);
            return ['FAILED', 0];
        }
        $created = 0;
        foreach ($parties as $candidate) {
            if (!is_array($candidate)) continue;
            $clean = $this->partyCandidate($candidate);
            if ($clean === null || $this->duplicatePartyExists($matterId, $clean)) continue;
            $this->pdo->prepare("INSERT INTO legal_matter_party (legal_matter_id, party_role, party_type, external_name, organization_name, notes, source_document_id, ai_suggested, party_source, review_status, created_at, updated_at) VALUES (:matter_id, :role, :type, :name, :organization, :notes, :source_document_id, 1, 'AI', 'PENDING_REVIEW', NOW(), NOW())")->execute([
                'matter_id' => $matterId,
                'role' => $clean['role'],
                'type' => $clean['type'],
                'name' => $clean['name'],
                'organization' => $clean['organization'],
                'notes' => $clean['context'],
                'source_document_id' => $clean['source_document_id'],
            ]);
            $created++;
        }
        $this->history($matterId, 'LEGAL_AI_PARTIES_ANALYZED', $created === 0 ? 'AI party analysis returned no new parties.' : "AI party analysis populated $created new " . ($created === 1 ? 'party.' : 'parties.'), ['party_count' => $created], $user);
        return [$created > 0 ? 'READY' : 'EMPTY', $created];
    }

    private function persistActions(int $matterId, mixed $actions): array
    {
        if (!is_array($actions)) {
            $this->history($matterId, 'LEGAL_AI_ACTIONS_ANALYZED', 'AI action section failed validation.', ['reason' => 'SECTION_VALIDATION_FAILED'], ['id' => 0]);
            return ['FAILED', 0];
        }
        $created = 0;
        $service = $this->actionService ?? new LegalMatterActionService($this->pdo);
        $matter = $this->matter($matterId) ?? [];
        foreach (array_slice($actions, 0, self::MAX_ACTIONS) as $candidate) {
            if (!is_array($candidate)) continue;
            $clean = $this->actionCandidate($candidate, $matter);
            if ($clean !== null && $service->storeSuggestion($matterId, $clean)) $created++;
        }
        $this->history($matterId, 'LEGAL_AI_ACTIONS_ANALYZED', $created === 0 ? 'AI action analysis returned no new suggestions.' : "AI action analysis created $created new " . ($created === 1 ? 'suggestion.' : 'suggestions.'), ['suggestion_count' => $created], ['id' => 0]);
        return [$created > 0 ? 'READY' : 'EMPTY', $created];
    }

    private function requestAnalysis(array $matter, array $sources, int &$attempts): array
    {
        if (trim((string)env('GEMINI_API_KEY', '')) === '') throw new RuntimeException('GEMINI_KEY_MISSING');
        $payload = $this->payload($matter, $sources);
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) throw new RuntimeException('GEMINI_REQUEST_INVALID');
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        $timeout = max(5, (int)env('GEMINI_LEGAL_ANALYSIS_TIMEOUT_SECONDS', env('GEMINI_LEGAL_SUMMARY_TIMEOUT_SECONDS', 60)));
        $last = null;
        for ($attempts = 1; $attempts <= 2; $attempts++) {
            try {
                $response = $this->postJsonOnce($this->endpoint(), $body, $headers, $timeout, $payload);
                $text = $this->extractOutputText($response);
                $decoded = json_decode($text, true);
                if (!is_array($decoded)) throw new RuntimeException('GEMINI_INVALID_RESPONSE');
                return $decoded;
            } catch (RuntimeException $exception) {
                $last = $exception;
                if ($attempts >= 2 || !$this->isRetryableFailure($exception->getMessage())) throw $exception;
            }
        }
        throw $last ?? new RuntimeException('GEMINI_REQUEST_FAILED');
    }

    private function payload(array $matter, array $sources): array
    {
        $parts = [['text' => $this->instructions($matter)]];
        foreach ($sources as $index => $source) {
            $parts[] = ['text' => 'Source ' . ($index + 1) . ': document_id=' . (int)$source['id'] . ', version_id=' . (int)($source['versionId'] ?? 0) . ', reference=' . (string)$source['documentNo'] . ', title=' . (string)$source['title']];
            $parts[] = ['inline_data' => ['mime_type' => (string)$source['mimeType'], 'data' => (string)$source['base64']]];
        }
        return [
            'contents' => [['role' => 'user', 'parts' => $parts]],
            'generationConfig' => [
                'temperature' => 0,
                'response_mime_type' => 'application/json',
                'response_schema' => $this->schema(),
            ],
        ];
    }

    private function instructions(array $matter): string
    {
        return 'Analyze the supplied legal matter evidence once and return structured JSON with a concise factual summary, identifiable parties, source-supported action suggestions, and key dates. Use only supplied sources; return empty arrays/nulls for unavailable information; do not infer fault, liability, guilt, sanctions, legal conclusions, or unsupported deadlines; mark items needing human review. Matter context: title=' . (string)$matter['title'] . '; type=' . (string)$matter['matter_type'] . '; priority=' . (string)$matter['priority'] . '. Party roles: ' . implode(', ', LegalMatterPartyService::PARTY_ROLES) . '. Party types: ' . implode(', ', LegalMatterPartyService::PARTY_TYPES) . '. Action types: ' . implode(', ', LegalMatterActionService::ACTION_TYPES) . '. For action deadline_basis use SOURCE_DERIVED only for explicit source dates, AI_RECOMMENDED only for conservative internal targets, or NO_DEADLINE.';
    }

    private function schema(): array
    {
        return ['type' => 'object', 'properties' => [
            'summary' => ['type' => 'object', 'properties' => [
                'available' => ['type' => 'boolean'],
                'text' => ['type' => 'string', 'nullable' => true],
                'needs_review' => ['type' => 'boolean'],
            ], 'required' => ['available', 'text', 'needs_review']],
            'parties' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                'name' => ['type' => 'string', 'nullable' => true],
                'role' => ['type' => 'string'],
                'type' => ['type' => 'string'],
                'organization' => ['type' => 'string', 'nullable' => true],
                'source_document_id' => ['type' => 'integer', 'nullable' => true],
                'source_reference' => ['type' => 'string', 'nullable' => true],
                'context' => ['type' => 'string', 'nullable' => true],
                'needs_review' => ['type' => 'boolean'],
            ]]],
            'actions' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                'title' => ['type' => 'string'],
                'action_type' => ['type' => 'string'],
                'description' => ['type' => 'string', 'nullable' => true],
                'due_date' => ['type' => 'string', 'nullable' => true],
                'deadline_basis' => ['type' => 'string'],
                'recommended_business_days' => ['type' => 'integer', 'nullable' => true],
                'recommendation_reason' => ['type' => 'string', 'nullable' => true],
                'date_basis' => ['type' => 'string', 'nullable' => true],
                'source_context' => ['type' => 'string', 'nullable' => true],
                'source_document_id' => ['type' => 'integer', 'nullable' => true],
                'source_document_version_id' => ['type' => 'integer', 'nullable' => true],
                'confidence' => ['type' => 'string', 'nullable' => true],
                'needs_review' => ['type' => 'boolean'],
            ]]],
            'key_dates' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                'date' => ['type' => 'string'],
                'label' => ['type' => 'string'],
                'source_reference' => ['type' => 'string', 'nullable' => true],
                'needs_review' => ['type' => 'boolean'],
            ]]],
        ], 'required' => ['summary', 'parties', 'actions', 'key_dates']];
    }

    private function partyCandidate(array $candidate): ?array
    {
        $name = $this->nullableText($candidate['name'] ?? null, 255);
        $organization = $this->nullableText($candidate['organization'] ?? null, 255);
        if ($name === null && $organization === null) return null;
        $role = strtoupper($this->text($candidate['role'] ?? 'PERSON_INVOLVED', 40));
        $type = strtoupper($this->text($candidate['type'] ?? 'EXTERNAL_PERSON', 40));
        if (!in_array($role, LegalMatterPartyService::PARTY_ROLES, true)) $role = 'PERSON_INVOLVED';
        if (!in_array($type, LegalMatterPartyService::PARTY_TYPES, true)) $type = $organization !== null && $name === null ? 'ORGANIZATION' : 'EXTERNAL_PERSON';
        return ['name' => $name ?? $organization, 'organization' => $organization, 'role' => $role, 'type' => $type, 'context' => $this->nullableText($candidate['context'] ?? $candidate['source_reference'] ?? null, 1500), 'source_document_id' => $this->optionalId($candidate['source_document_id'] ?? null)];
    }

    private function actionCandidate(array $candidate, array $matter): ?array
    {
        $title = $this->nullableText($candidate['title'] ?? null, 255);
        if ($title === null) return null;
        $type = strtoupper($this->text($candidate['action_type'] ?? 'OTHER', 50));
        if (!in_array($type, LegalMatterActionService::ACTION_TYPES, true)) $type = 'OTHER';
        $basis = strtoupper($this->text($candidate['deadline_basis'] ?? $candidate['date_basis'] ?? 'NO_DEADLINE', 30));
        if (!in_array($basis, LegalMatterActionService::DEADLINE_BASES, true)) $basis = 'NO_DEADLINE';
        $recommendedDays = $this->recommendedBusinessDays($candidate['recommended_business_days'] ?? null, (string)($matter['priority'] ?? 'MEDIUM'));
        $dueAt = $basis === 'SOURCE_DERIVED' ? $this->dueDate($candidate['due_date'] ?? null) : ($basis === 'AI_RECOMMENDED' && $recommendedDays !== null ? $this->businessDayTarget($recommendedDays) : null);
        if ($basis === 'SOURCE_DERIVED' && $dueAt === null) $basis = 'NO_DEADLINE';
        return [
            'title' => $title,
            'action_type' => $type,
            'description' => $this->nullableText($candidate['description'] ?? null, 3000),
            'due_at' => $dueAt,
            'date_basis' => $this->nullableText($candidate['date_basis'] ?? null, 255),
            'deadline_basis' => $basis,
            'recommendation_reason' => $this->nullableText($candidate['recommendation_reason'] ?? null, 500),
            'recommended_business_days' => $recommendedDays,
            'source_context' => $this->nullableText($candidate['source_context'] ?? null, 1000),
            'source_document_id' => $this->optionalId($candidate['source_document_id'] ?? null),
            'source_document_version_id' => $this->optionalId($candidate['source_document_version_id'] ?? null),
            'confidence' => in_array(strtoupper((string)($candidate['confidence'] ?? '')), ['LOW', 'MEDIUM', 'HIGH'], true) ? strtoupper((string)$candidate['confidence']) : null,
        ];
    }

    private function readableSources(array $matter): array
    {
        $documents = ($this->documentService ?? new DocumentService($this->pdo))->currentRelatedFiles('LEGAL_MANAGEMENT', (string)$matter['matter_number']);
        $selected = [];
        $total = 0;
        foreach ($documents as $document) {
            $path = (string)($document['absolutePath'] ?? '');
            $mime = (string)($document['mimeType'] ?? '');
            $size = (int)($document['fileSize'] ?? 0);
            if (!in_array($mime, self::READABLE_MIME, true) || $path === '' || !is_file($path) || $size <= 0 || $total + $size > self::MAX_SOURCE_BYTES) continue;
            $bytes = file_get_contents($path);
            if ($bytes === false || $bytes === '') continue;
            $document['base64'] = base64_encode($bytes);
            $selected[] = $document;
            $total += $size;
        }
        return $selected;
    }

    private function markNoReadableSource(int $matterId, array $user): void
    {
        $this->updateSummary($matterId, ['ai_summary_status' => 'NO_READABLE_SOURCE', 'ai_summary_provider' => self::PROVIDER, 'ai_summary_model' => $this->model(), 'ai_summary_source_fingerprint' => null]);
        $this->history($matterId, 'LEGAL_AI_ANALYSIS_COMPLETED', 'Unified Legal AI analysis skipped because no readable supporting documents were available.', ['section_statuses' => ['summary' => 'NO_READABLE_SOURCE', 'parties' => 'EMPTY', 'actions' => 'EMPTY']], $user);
        $this->history($matterId, 'LEGAL_AI_SUMMARY_FAILED', 'No readable supporting documents were available for AI summarization.', ['reason' => 'NO_READABLE_SOURCE'], $user);
        $this->history($matterId, 'LEGAL_AI_PARTIES_ANALYZED', 'AI party analysis skipped because no readable supporting documents were available.', ['party_count' => 0], $user);
        $this->history($matterId, 'LEGAL_AI_ACTIONS_ANALYZED', 'AI action analysis skipped because no readable supporting documents were available.', ['suggestion_count' => 0], $user);
    }

    private function markProviderFailure(int $matterId, string $reason, int $sourceCount, int $attempts, float $startedAt, array $user): void
    {
        $this->updateSummary($matterId, ['ai_summary_status' => 'FAILED', 'ai_summary_provider' => self::PROVIDER, 'ai_summary_model' => $this->model()]);
        $metadata = ['reason' => $reason, 'source_count' => $sourceCount, 'attempt_count' => $attempts, 'elapsed_ms' => $this->elapsed($startedAt)];
        $this->history($matterId, 'LEGAL_AI_ANALYSIS_FAILED', 'Unified Legal AI analysis could not be completed.', $metadata, $user);
        $this->history($matterId, 'LEGAL_AI_SUMMARY_FAILED', 'AI matter summary could not be generated.', ['reason' => $reason], $user);
        $this->history($matterId, 'LEGAL_AI_PARTIES_FAILED', 'AI party analysis could not be completed.', ['reason' => $reason], $user);
        $this->history($matterId, 'LEGAL_AI_ACTIONS_ANALYZED', 'AI action analysis could not be completed.', ['reason' => $reason], $user);
    }

    private function updateSummary(int $matterId, array $fields): void
    {
        $allowed = array_intersect_key($fields, array_flip(['ai_summary', 'ai_summary_status', 'ai_summary_generated_at', 'ai_summary_provider', 'ai_summary_model', 'ai_summary_source_fingerprint']));
        if (!$allowed) return;
        $sets = [];
        $params = ['id' => $matterId];
        foreach ($allowed as $column => $value) {
            $sets[] = "$column = :$column";
            $params[$column] = $value;
        }
        $sets[] = 'updated_at = NOW()';
        $this->pdo->prepare('UPDATE legal_matter SET ' . implode(', ', $sets) . ' WHERE legal_matter_id = :id AND deleted_at IS NULL')->execute($params);
    }

    private function result(int $matterId, array $sectionStatuses, int $attempts, int $elapsedMs): array
    {
        return ['item' => $this->hydrate($matterId), 'section_statuses' => $sectionStatuses, 'attempt_count' => $attempts, 'elapsed_ms' => $elapsedMs];
    }

    private function hydrate(int $matterId): ?array
    {
        return (new LegalMatterService($this->pdo, $this->documentService))->show($matterId);
    }

    private function postJsonOnce(string $url, string $body, array $headers, int $timeout, array $payload): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) throw new RuntimeException('GEMINI_TRANSPORT_ERROR');
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout]);
            $raw = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);
            $errorCode = (int)curl_errno($ch);
            curl_close($ch);
            if ($raw === false || $status < 200 || $status >= 300) throw new RuntimeException($this->geminiError((string)($raw ?: ''), $status, $error, $errorCode, $payload));
            $decoded = json_decode((string)$raw, true);
            if (!is_array($decoded)) throw new RuntimeException('GEMINI_INVALID_RESPONSE');
            return $decoded;
        }
        throw new RuntimeException('GEMINI_TRANSPORT_ERROR');
    }

    private function extractOutputText(array $response): string
    {
        $parts = [];
        foreach (($response['candidates'] ?? []) as $candidate) foreach (($candidate['content']['parts'] ?? []) as $part) if (isset($part['text']) && is_string($part['text'])) $parts[] = $part['text'];
        $text = trim(implode("\n", $parts));
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
            $text = preg_replace('/\s*```$/', '', $text) ?? $text;
        }
        return trim($text);
    }

    private function matter(int $matterId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM legal_matter WHERE legal_matter_id = :id AND deleted_at IS NULL LIMIT 1');
        $stmt->execute(['id' => $matterId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    private function duplicatePartyExists(int $matterId, array $clean): bool
    {
        $name = $this->normalize((string)($clean['name'] ?? $clean['organization'] ?? ''));
        if ($name === '') return false;
        return (int)$this->scalar("SELECT COUNT(*) FROM legal_matter_party WHERE legal_matter_id = :matter_id AND deleted_at IS NULL AND party_role = :role AND party_type = :type AND LOWER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(external_name, organization_name, ''), ' ', ''), '.', ''), ',', ''), '-', '')) = :name", ['matter_id' => $matterId, 'role' => $clean['role'], 'type' => $clean['type'], 'name' => $name]) > 0;
    }

    private function endpoint(): string
    {
        $model = $this->model();
        $modelPath = str_starts_with($model, 'models/') ? $model : 'models/' . rawurlencode($model);
        return 'https://generativelanguage.googleapis.com/v1beta/' . $modelPath . ':generateContent?key=' . rawurlencode(trim((string)env('GEMINI_API_KEY', '')));
    }

    private function model(): string
    {
        $model = trim((string)env('GEMINI_LEGAL_ANALYSIS_MODEL', ''));
        if ($model === '') $model = trim((string)env('GEMINI_LEGAL_SUMMARY_MODEL', ''));
        if ($model === '') $model = trim((string)env('GEMINI_VISITOR_ID_MODEL', 'gemini-3.6-flash'));
        return $model === '' ? 'gemini-3.6-flash' : $model;
    }

    private function fingerprint(array $sources): string
    {
        $parts = array_map(static fn(array $source): string => implode('|', [$source['documentNo'] ?? '', $source['versionNumber'] ?? '', $source['fileHash'] ?? '', $source['fileSize'] ?? '']), $sources);
        sort($parts);
        return hash('sha256', implode(';;', $parts));
    }

    private function recommendedBusinessDays(mixed $value, string $priority): ?int
    {
        if ($value === null || $value === '') return null;
        $days = (int)$value;
        $window = LegalMatterActionService::PRIORITY_TARGET_WINDOWS[strtoupper($priority)] ?? LegalMatterActionService::PRIORITY_TARGET_WINDOWS['MEDIUM'];
        return max((int)$window['min'], min((int)$window['max'], $days));
    }

    private function businessDayTarget(int $businessDays): string
    {
        $date = new DateTimeImmutable('today');
        $remaining = max(1, $businessDays);
        while ($remaining > 0) {
            $date = $date->modify('+1 day');
            if ((int)$date->format('N') <= 5) $remaining--;
        }
        return $date->format('Y-m-d 23:59:59');
    }

    private function dueDate(mixed $value): ?string
    {
        $text = trim((string)($value ?? ''));
        if ($text === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $text)) return null;
        return (new DateTimeImmutable($text))->format('Y-m-d 23:59:59');
    }

    private function geminiError(string $raw, int $status, string $transportError, int $transportErrorCode, array $payload): string
    {
        $category = match (true) {
            defined('CURLE_OPERATION_TIMEDOUT') && $transportErrorCode === CURLE_OPERATION_TIMEDOUT => 'GEMINI_TIMEOUT',
            $transportError !== '' => 'GEMINI_TRANSPORT_ERROR',
            $status === 401 || $status === 403 => 'GEMINI_AUTH_ERROR',
            $status === 404 => 'GEMINI_MODEL_INVALID',
            $status === 408 || $status === 504 => 'GEMINI_TIMEOUT',
            $status === 429 => 'GEMINI_QUOTA_OR_RATE_LIMIT',
            $status >= 500 => 'GEMINI_SERVICE_UNAVAILABLE',
            $status > 0 => 'GEMINI_HTTP_' . $status,
            default => 'GEMINI_REQUEST_FAILED',
        };
        $decoded = json_decode($raw, true);
        $error = is_array($decoded) && is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
        return $category . ' ' . json_encode(['http_status' => $status, 'gemini_status' => is_string($error['status'] ?? null) ? $error['status'] : null, 'message' => $this->sanitizeGeminiMessage(is_string($error['message'] ?? null) ? $error['message'] : $transportError), 'structured_schema_included' => isset($payload['generationConfig']['response_schema'])], JSON_UNESCAPED_SLASHES);
    }

    private function uiFailureStatus(string $reason): string
    {
        if (str_contains($reason, 'TIMEOUT')) return 'TIMEOUT';
        if (str_contains($reason, 'RATE_LIMIT') || str_contains($reason, 'QUOTA')) return 'RATE_LIMITED';
        return 'FAILED';
    }

    private function assertMatterNotClosed(array $matter): void
    {
        if (($matter['status'] ?? '') === 'CLOSED') throw new InvalidArgumentException(json_encode(['status' => 'This legal matter is closed and is read-only.'], JSON_THROW_ON_ERROR));
    }

    private function history(int $matterId, string $event, string $description, array $metadata, array $user): void
    {
        $userId = ((int)($user['id'] ?? 0)) > 0 ? (int)$user['id'] : null;
        $this->pdo->prepare('INSERT INTO legal_matter_history (legal_matter_id, event_type, from_status, to_status, description, metadata_json, actor_user_id, created_at) VALUES (:id, :event, NULL, NULL, :description, :metadata, :user_id, NOW())')->execute(['id' => $matterId, 'event' => $event, 'description' => $description, 'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'user_id' => $userId]);
    }

    private function activity(string $event, string $title, string $description, int $matterId, string $reference, array $user): void
    {
        try {
            $this->pdo->prepare("INSERT INTO activity_event (event_uuid, module_code, entity_type, entity_id, entity_reference, event_type, event_title, event_description, actor_user_id, actor_employee_reference_id, visibility_scope, metadata_json, occurred_at, created_at) VALUES (UUID(), 'LEGAL_MANAGEMENT', 'legal_matter', :id, :reference, :event_type, :title, :description, :user_id, :employee_id, 'INTERNAL', '{}', NOW(), NOW())")->execute(['id' => $matterId, 'reference' => $reference, 'event_type' => $event, 'title' => $title, 'description' => $description, 'user_id' => (int)$user['id'], 'employee_id' => $user['employee_id'] ?? null]);
        } catch (Throwable) {
        }
    }

    private function scalar(string $sql, array $params = []): mixed { $stmt = $this->pdo->prepare($sql); $stmt->execute($params); return $stmt->fetchColumn(); }
    private function text(mixed $value, int $max): string { return mb_substr(trim((string)$value), 0, $max); }
    private function nullableText(mixed $value, int $max): ?string { $text = $this->text($value ?? '', $max); return $text === '' ? null : $text; }
    private function cleanText(string $value): string { return trim(preg_replace('/\s+/', ' ', $value) ?? $value); }
    private function normalize(string $value): string { return strtolower(preg_replace('/[^a-z0-9]+/i', '', $value) ?? ''); }
    private function optionalId(mixed $value): ?int { return ($value === null || $value === '' || !ctype_digit((string)$value)) ? null : (int)$value; }
    private function elapsed(float $startedAt): int { return (int)round((microtime(true) - $startedAt) * 1000); }
    private function safeFailureStage(string $message): string { $stage = strtok($message, ' '); return is_string($stage) && preg_match('/^[A-Z0-9_]+$/', $stage) ? $stage : 'GEMINI_REQUEST_FAILED'; }
    private function isRetryableFailure(string $message): bool { $stage = strtok($message, ' '); return in_array($stage, ['GEMINI_TIMEOUT', 'GEMINI_TRANSPORT_ERROR', 'GEMINI_SERVICE_UNAVAILABLE'], true); }
    private function sanitizeGeminiMessage(string $message): string
    {
        $message = preg_replace('/key=[^&\s]+/i', 'key=[redacted]', $message) ?? $message;
        $message = preg_replace('/AIza[0-9A-Za-z_\-]+/', '[redacted-api-key]', $message) ?? $message;
        $message = preg_replace('/[A-Za-z0-9+\/]{120,}={0,2}/', '[redacted-long-token]', $message) ?? $message;
        return trim($message);
    }
}
