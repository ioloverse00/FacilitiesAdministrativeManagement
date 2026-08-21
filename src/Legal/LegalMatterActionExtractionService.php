<?php

declare(strict_types=1);

final class LegalMatterActionExtractionService
{
    private const READABLE_MIME = ['application/pdf', 'image/jpeg', 'image/png'];
    private const MAX_SOURCE_BYTES = 12_000_000;
    private const MAX_RECOMMENDATIONS = 5;

    public function __construct(
        private readonly PDO $pdo,
        private readonly ?DocumentService $documentService = null,
        private readonly ?LegalMatterActionService $actionService = null
    ) {
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
            $this->history($matterId, 'LEGAL_AI_ACTIONS_ANALYZED', 'AI action analysis skipped because no readable supporting documents were available.', ['suggestion_count' => 0], $user);
            return $this->payload($matterId);
        }
        $created = 0;
        try {
            $result = $this->requestExtraction($matter, $sources);
            $service = $this->actionService ?? new LegalMatterActionService($this->pdo);
            foreach (array_slice(($result['actions'] ?? []), 0, self::MAX_RECOMMENDATIONS) as $candidate) {
                $clean = $this->validateCandidate($candidate, $matter);
                if ($clean === null) {
                    continue;
                }
                if ($service->storeSuggestion($matterId, $clean)) {
                    $created++;
                }
            }
            $this->history($matterId, 'LEGAL_AI_ACTIONS_ANALYZED', "AI action analysis created $created new suggestion" . ($created === 1 ? '.' : 's.'), ['suggestion_count' => $created, 'needs_review' => (bool) ($result['needs_review'] ?? true)], $user);
        } catch (Throwable $exception) {
            $this->history($matterId, 'LEGAL_AI_ACTIONS_ANALYZED', 'AI action analysis could not be completed.', ['reason' => $this->safeFailureStage($exception->getMessage())], $user);
        }
        return $this->payload($matterId);
    }

    private function validateCandidate(array $candidate, array $matter): ?array
    {
        $title = $this->nullableText($candidate['title'] ?? null, 255);
        $type = strtoupper($this->text($candidate['action_type'] ?? 'OTHER', 50));
        if ($title === null) {
            return null;
        }
        if (!in_array($type, LegalMatterActionService::ACTION_TYPES, true)) {
            $type = 'OTHER';
        }
        $basis = strtoupper($this->text($candidate['deadline_basis'] ?? $candidate['date_basis'] ?? 'NO_DEADLINE', 30));
        if (!in_array($basis, LegalMatterActionService::DEADLINE_BASES, true)) {
            $basis = 'NO_DEADLINE';
        }
        $recommendedDays = $this->recommendedBusinessDays($candidate['recommended_business_days'] ?? null, (string) ($matter['priority'] ?? 'MEDIUM'));
        if ($basis === 'AI_RECOMMENDED' && $recommendedDays === null) {
            $priority = strtoupper((string) ($matter['priority'] ?? 'MEDIUM'));
            $window = LegalMatterActionService::PRIORITY_TARGET_WINDOWS[$priority] ?? LegalMatterActionService::PRIORITY_TARGET_WINDOWS['MEDIUM'];
            $recommendedDays = (int) $window['min'];
        }
        $dueAt = $basis === 'SOURCE_DERIVED'
            ? $this->dueDate($candidate['due_date'] ?? ($candidate['due_at'] ?? ($candidate['suggested_due_at'] ?? null)))
            : ($basis === 'AI_RECOMMENDED' && $recommendedDays !== null ? $this->businessDayTarget($recommendedDays) : null);
        if ($basis === 'SOURCE_DERIVED' && $dueAt === null) {
            $basis = $recommendedDays === null ? 'NO_DEADLINE' : 'AI_RECOMMENDED';
            $dueAt = $recommendedDays === null ? null : $this->businessDayTarget($recommendedDays);
        }
        return [
            'title' => $title,
            'action_type' => $type,
            'description' => $this->nullableText($candidate['description'] ?? null, 3000),
            'due_at' => $dueAt,
            'date_basis' => $this->nullableText($candidate['date_basis'] ?? null, 255),
            'deadline_basis' => $basis,
            'recommendation_reason' => $this->nullableText($candidate['recommendation_reason'] ?? $candidate['reason'] ?? null, 500),
            'recommended_business_days' => $recommendedDays,
            'source_context' => $this->nullableText($candidate['source_context'] ?? null, 1000),
            'source_document_id' => isset($candidate['source_document_id']) && ctype_digit((string) $candidate['source_document_id']) ? (int) $candidate['source_document_id'] : null,
            'source_document_version_id' => isset($candidate['source_document_version_id']) && ctype_digit((string) $candidate['source_document_version_id']) ? (int) $candidate['source_document_version_id'] : null,
            'confidence' => in_array(strtoupper((string) ($candidate['confidence'] ?? '')), ['LOW', 'MEDIUM', 'HIGH'], true) ? strtoupper((string) $candidate['confidence']) : null,
        ];
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
            if ($bytes === false || $bytes === '') {
                continue;
            }
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
        if (!is_array($decoded)) {
            throw new RuntimeException('GEMINI_RESPONSE_INVALID');
        }
        return $decoded;
    }

    private function geminiPayload(array $matter, array $sources): array
    {
        $parts = [['text' => $this->instructions($matter)]];
        foreach ($sources as $index => $source) {
            $parts[] = ['text' => 'Source ' . ($index + 1) . ': document_id=' . (int) $source['id'] . ', reference=' . (string) $source['documentNo'] . ', title=' . (string) $source['title'] . ', document_date=' . (string) ($source['documentDate'] ?? '')];
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
                        'actions' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                            'title' => ['type' => 'string'],
                            'action_type' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'due_date' => ['type' => 'string', 'nullable' => true],
                            'recommended_business_days' => ['type' => 'integer', 'nullable' => true],
                            'deadline_basis' => ['type' => 'string'],
                            'recommendation_reason' => ['type' => 'string', 'nullable' => true],
                            'date_basis' => ['type' => 'string', 'nullable' => true],
                            'source_context' => ['type' => 'string', 'nullable' => true],
                            'source_document_id' => ['type' => 'integer', 'nullable' => true],
                            'source_document_version_id' => ['type' => 'integer', 'nullable' => true],
                            'confidence' => ['type' => 'string'],
                        ]]],
                        'needs_review' => ['type' => 'boolean'],
                    ],
                    'required' => ['actions', 'needs_review'],
                ],
            ],
        ];
    }

    private function instructions(array $matter): string
    {
        $priority = strtoupper((string) ($matter['priority'] ?? 'MEDIUM'));
        $policy = $this->priorityPolicyText();
        return "Analyze the supporting evidence and matter metadata to identify useful administrative/legal workflow actions. Matter title: " . (string) $matter['title'] . ". Matter type: " . (string) ($matter['matter_type'] ?? '') . ". Matter priority: $priority.\n\n"
            . "First extract explicit obligations/deadlines from the source. If no explicit deadline exists, you may recommend an INTERNAL TARGET deadline using the supplied FAM priority policy. AI recommendations are advisory only and require Admin/Head approval before becoming official actions.\n\n"
            . "FAM priority target policy: $policy. For AI_RECOMMENDED targets, return recommended_business_days inside the policy window for the matter priority. Do not calculate the date yourself unless it is SOURCE_DERIVED from an explicit source date; backend will compute internal target dates deterministically using weekdays only.\n\n"
            . "Canonical action types: " . implode(', ', LegalMatterActionService::ACTION_TYPES) . ". Prefer OTHER when unclear.\n\n"
            . "Clearly distinguish deadline_basis: SOURCE_DERIVED when the evidence states an action/deadline/response date/submission requirement; AI_RECOMMENDED when you recommend a conservative internal follow-up target; NO_DEADLINE when no deadline or useful target is appropriate.\n\n"
            . "Do NOT create legal obligations, determine guilt/fault/liability, prescribe sanctions, invent statutory deadlines, invent court dates, invent hearings, invent penalties, or claim an AI-recommended target is legally required. Do not suggest liability demands, disciplinary sanctions, criminal/legal complaints, or penalties unless explicitly supported by source evidence.\n\n"
            . "For incident-only evidence, recommend a small number of conservative operational actions when useful, such as inspection, documentation, follow-up, review, or compliance checks. Limit actions to " . self::MAX_RECOMMENDATIONS . " maximum and avoid generic filler.\n\n"
            . "Existing official/pending action identities to avoid duplicates: " . $this->existingActionSummary((int) $matter['legal_matter_id']) . "\n\n"
            . "For every action include a concise title, canonical action_type, short factual description, due_date in YYYY-MM-DD only for SOURCE_DERIVED or null otherwise, recommended_business_days for AI_RECOMMENDED, deadline_basis, concise recommendation_reason, date_basis, source_context, source_document_id, source_document_version_id if known, and confidence LOW/MEDIUM/HIGH.";
    }

    private function recommendedBusinessDays(mixed $value, string $priority): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $days = (int) $value;
        $priority = strtoupper($priority);
        $window = LegalMatterActionService::PRIORITY_TARGET_WINDOWS[$priority] ?? LegalMatterActionService::PRIORITY_TARGET_WINDOWS['MEDIUM'];
        if ($days < (int) $window['min']) {
            return (int) $window['min'];
        }
        if ($days > (int) $window['max']) {
            return (int) $window['max'];
        }
        return $days > 0 ? $days : null;
    }

    private function businessDayTarget(int $businessDays): string
    {
        $date = new DateTimeImmutable('today');
        $remaining = max(1, $businessDays);
        while ($remaining > 0) {
            $date = $date->modify('+1 day');
            if ((int) $date->format('N') <= 5) {
                $remaining--;
            }
        }
        return $date->format('Y-m-d 23:59:59');
    }

    private function priorityPolicyText(): string
    {
        return implode('; ', array_map(
            static fn (string $priority, array $window): string => $window['min'] === $window['max']
                ? "$priority={$window['min']} business day" . ($window['min'] === 1 ? '' : 's')
                : "$priority={$window['min']}-{$window['max']} business days",
            array_keys(LegalMatterActionService::PRIORITY_TARGET_WINDOWS),
            LegalMatterActionService::PRIORITY_TARGET_WINDOWS
        ));
    }

    private function existingActionSummary(int $matterId): string
    {
        $rows = $this->rows(
            "SELECT title, action_type, due_at, status FROM legal_matter_action WHERE legal_matter_id = :action_id AND deleted_at IS NULL
             UNION ALL
             SELECT suggested_title title, suggested_action_type action_type, suggested_due_at due_at, status FROM legal_matter_action_suggestion WHERE legal_matter_id = :suggestion_id AND status IN ('PENDING','ACCEPTED','DISMISSED')
             ORDER BY title ASC LIMIT 20",
            ['action_id' => $matterId, 'suggestion_id' => $matterId]
        );
        if (!$rows) {
            return 'none';
        }
        return implode('; ', array_map(static fn (array $row): string => trim((string) $row['title']) . ' [' . trim((string) $row['action_type']) . ', ' . trim((string) $row['status']) . ']', $rows));
    }

    private function postJson(string $url, array $payload): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) throw new RuntimeException('GEMINI_REQUEST_INVALID');
        $ch = curl_init($url);
        if ($ch === false) throw new RuntimeException('GEMINI_TRANSPORT_ERROR');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => max(5, (int) env('GEMINI_LEGAL_ACTION_TIMEOUT_SECONDS', 35))]);
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

    private function payload(int $matterId): array
    {
        $service = $this->actionService ?? new LegalMatterActionService($this->pdo);
        return ['actions' => $service->actionsForMatter($matterId), 'actionSuggestions' => $service->suggestionsForMatter($matterId)];
    }

    private function matter(int $matterId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM legal_matter WHERE legal_matter_id = :id AND deleted_at IS NULL LIMIT 1');
        $stmt->execute(['id' => $matterId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    private function assertMatterNotClosed(array $matter): void
    {
        if (($matter['status'] ?? '') === 'CLOSED') {
            throw new InvalidArgumentException(json_encode(['status' => 'This legal matter is closed and is read-only.'], JSON_THROW_ON_ERROR));
        }
    }

    private function history(int $matterId, string $event, string $description, array $metadata, array $user): void
    {
        $userId = ((int) ($user['id'] ?? 0)) > 0 ? (int) $user['id'] : null;
        $this->pdo->prepare('INSERT INTO legal_matter_history (legal_matter_id, event_type, from_status, to_status, description, metadata_json, actor_user_id, created_at) VALUES (:id, :event, NULL, NULL, :description, :metadata, :user_id, NOW())')->execute([
            'id' => $matterId,
            'event' => $event,
            'description' => $description,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'user_id' => $userId,
        ]);
    }

    private function geminiEndpoint(): string
    {
        $model = trim((string) env('GEMINI_LEGAL_ACTION_MODEL', ''));
        if ($model === '') $model = trim((string) env('GEMINI_LEGAL_SUMMARY_MODEL', ''));
        if ($model === '') $model = trim((string) env('GEMINI_VISITOR_ID_MODEL', 'gemini-3.6-flash'));
        $modelPath = str_starts_with($model, 'models/') ? $model : 'models/' . rawurlencode($model);
        return 'https://generativelanguage.googleapis.com/v1beta/' . $modelPath . ':generateContent?key=' . rawurlencode(trim((string) env('GEMINI_API_KEY', '')));
    }

    private function dueDate(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));
        if ($text === '') return null;
        return (new DateTimeImmutable($text))->format('Y-m-d 23:59:59');
    }

    private function rows(string $sql, array $params = []): array { $stmt = $this->pdo->prepare($sql); $stmt->execute($params); return $stmt->fetchAll(); }
    private function text(mixed $value, int $max): string { return mb_substr(trim((string) $value), 0, $max); }
    private function nullableText(mixed $value, int $max): ?string { $text = $this->text($value ?? '', $max); return $text === '' ? null : $text; }
    private function safeFailureStage(string $message): string { $stage = strtok($message, ' '); return is_string($stage) && preg_match('/^[A-Z0-9_]+$/', $stage) ? $stage : 'AI_REQUEST_FAILED'; }
}
