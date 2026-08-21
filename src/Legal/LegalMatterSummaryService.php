<?php

declare(strict_types=1);

final class LegalMatterSummaryService
{
    private const PROVIDER = 'GEMINI';
    private const READABLE_MIME = ['application/pdf', 'image/jpeg', 'image/png'];
    private const MAX_SOURCE_BYTES = 12_000_000;
    private const STATUSES = ['NOT_REQUESTED', 'PENDING', 'READY', 'FAILED', 'NO_READABLE_SOURCE', 'STALE'];

    public function __construct(private readonly PDO $pdo, private readonly ?DocumentService $documentService = null)
    {
    }

    public function generate(int $matterId, array $user, bool $regeneration = false): ?array
    {
        $matter = $this->matter($matterId);
        if ($matter === null) {
            return null;
        }
        $this->assertMatterNotClosed($matter);

        $sources = $this->readableSources($matter);
        if (!$sources) {
            $this->storeNoReadableSource($matterId, $regeneration, $user);
            return $this->matter($matterId);
        }

        $fingerprint = $this->fingerprint($sources);
        $this->updateAiFields($matterId, [
            'ai_summary_status' => 'PENDING',
            'ai_summary_provider' => self::PROVIDER,
            'ai_summary_model' => $this->model(),
            'ai_summary_source_fingerprint' => $fingerprint,
        ]);

        try {
            $result = $this->requestSummary($matter, $sources);
            if (!($result['summary_available'] ?? false) || trim((string) ($result['summary'] ?? '')) === '') {
                $this->storeNoReadableSource($matterId, $regeneration, $user);
                return $this->matter($matterId);
            }
            $summary = $this->cleanSummary((string) $result['summary']);
            $this->updateAiFields($matterId, [
                'ai_summary' => $summary,
                'ai_summary_status' => 'READY',
                'ai_summary_generated_at' => date('Y-m-d H:i:s'),
                'ai_summary_provider' => self::PROVIDER,
                'ai_summary_model' => $this->model(),
                'ai_summary_source_fingerprint' => $fingerprint,
            ]);
            $event = $regeneration ? 'LEGAL_AI_SUMMARY_REGENERATED' : 'LEGAL_AI_SUMMARY_GENERATED';
            $this->history($matterId, $event, 'AI matter summary generated from ' . count($sources) . ' supporting document' . (count($sources) === 1 ? '.' : 's.'), [
                'source_count' => count($sources),
                'needs_review' => (bool) ($result['needs_review'] ?? true),
            ], $user);
            $this->activity($event, $regeneration ? 'AI Matter Summary Regenerated' : 'AI Matter Summary Generated', 'AI matter summary generated.', $matterId, (string) $matter['matter_number'], $user);
        } catch (Throwable $exception) {
            $this->updateAiFields($matterId, [
                'ai_summary_status' => 'FAILED',
                'ai_summary_provider' => self::PROVIDER,
                'ai_summary_model' => $this->model(),
                'ai_summary_source_fingerprint' => $fingerprint,
            ]);
            $this->history($matterId, 'LEGAL_AI_SUMMARY_FAILED', 'AI matter summary could not be generated.', ['reason' => $this->safeFailureStage($exception->getMessage())], $user);
            $this->activity('LEGAL_AI_SUMMARY_FAILED', 'AI Matter Summary Failed', 'AI matter summary could not be generated.', $matterId, (string) $matter['matter_number'], $user);
        }

        return $this->matter($matterId);
    }

    public function markPending(int $matterId, array $user): ?array
    {
        $matter = $this->matter($matterId);
        if ($matter === null) {
            return null;
        }
        $this->assertMatterNotClosed($matter);

        $sources = $this->readableSources($matter);
        if (!$sources) {
            $this->storeNoReadableSource($matterId, false, $user);
            return $this->matter($matterId);
        }

        $this->updateAiFields($matterId, [
            'ai_summary_status' => 'PENDING',
            'ai_summary_provider' => self::PROVIDER,
            'ai_summary_model' => $this->model(),
            'ai_summary_source_fingerprint' => $this->fingerprint($sources),
        ]);

        return $this->matter($matterId);
    }

    public function markStaleIfReady(int $matterId, array $user): void
    {
        $matter = $this->matter($matterId);
        if ($matter === null || !in_array((string) ($matter['ai_summary_status'] ?? ''), ['READY', 'FAILED', 'NO_READABLE_SOURCE'], true)) {
            return;
        }
        $this->assertMatterNotClosed($matter);

        $sources = $this->readableSources($matter);
        $fingerprint = $sources ? $this->fingerprint($sources) : null;
        if (($matter['ai_summary_status'] ?? '') === 'READY' && $fingerprint !== null && $fingerprint === (string) ($matter['ai_summary_source_fingerprint'] ?? '')) {
            return;
        }

        $this->updateAiFields($matterId, [
            'ai_summary_status' => 'STALE',
            'ai_summary_source_fingerprint' => $fingerprint,
        ]);
        $this->history($matterId, 'LEGAL_AI_SUMMARY_STALE', 'Supporting documents changed. AI matter summary marked stale.', ['source_count' => count($sources)], $user);
    }

    private function storeNoReadableSource(int $matterId, bool $regeneration, array $user): void
    {
        $this->updateAiFields($matterId, [
            'ai_summary_status' => 'NO_READABLE_SOURCE',
            'ai_summary_provider' => self::PROVIDER,
            'ai_summary_model' => $this->model(),
            'ai_summary_source_fingerprint' => null,
        ]);
        $this->history($matterId, 'LEGAL_AI_SUMMARY_FAILED', 'No readable supporting documents were available for AI summarization.', ['reason' => 'NO_READABLE_SOURCE', 'regeneration' => $regeneration], $user);
    }

    private function matter(int $matterId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM legal_matter WHERE deleted_at IS NULL AND legal_matter_id = :id LIMIT 1');
        $statement->execute(['id' => $matterId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    private function assertMatterNotClosed(array $matter): void
    {
        if (($matter['status'] ?? '') === 'CLOSED') {
            throw new InvalidArgumentException(json_encode(['status' => 'This legal matter is closed and is read-only.'], JSON_THROW_ON_ERROR));
        }
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
            if (!in_array($mime, self::READABLE_MIME, true) || $path === '' || !is_file($path) || $size <= 0) {
                continue;
            }
            if ($total + $size > self::MAX_SOURCE_BYTES) {
                break;
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

    private function requestSummary(array $matter, array $sources): array
    {
        if (trim((string) env('GEMINI_API_KEY', '')) === '') {
            throw new RuntimeException('GEMINI_KEY_MISSING');
        }
        $response = $this->postJson($this->geminiEndpoint(), $this->geminiPayload($matter, $sources));
        $text = $this->extractOutputText($response);
        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('GEMINI_RESPONSE_INVALID');
        }
        return $this->sanitizeResult($decoded);
    }

    private function geminiPayload(array $matter, array $sources): array
    {
        $parts = [
            ['text' => $this->instructions($matter, $sources)],
        ];
        foreach ($sources as $index => $source) {
            $parts[] = ['text' => 'Source ' . ($index + 1) . ': ' . (string) $source['documentNo'] . ' - ' . (string) $source['title'] . ' (' . (string) $source['fileName'] . ')'];
            $parts[] = ['inline_data' => [
                'mime_type' => (string) $source['mimeType'],
                'data' => (string) $source['base64'],
            ]];
        }

        return [
            'contents' => [[
                'role' => 'user',
                'parts' => $parts,
            ]],
            'generationConfig' => [
                'temperature' => 0,
                'response_mime_type' => 'application/json',
                'response_schema' => $this->schema(),
            ],
        ];
    }

    private function instructions(array $matter, array $sources): string
    {
        return "You are generating an administrative summary of supporting documents linked to a Legal Matter.\n\n"
            . "Summarize ONLY information supported by the supplied documents. Matter title is context only, not evidence: " . (string) $matter['title'] . "\n"
            . "Identify where clearly available: central incident or issue, relevant date/time, location, involved parties, important factual sequence, reported damage/claim/complaint, and key supporting facts.\n\n"
            . "Do NOT determine guilt, legal liability, fault, legal advice, punishment, legal conclusions, or invent missing facts. Do NOT resolve conflicting evidence yourself; state if conflict exists. For allegations, use attribution such as 'The complaint states...' or 'The incident report indicates...'.\n\n"
            . "Produce a concise executive overview, not a full evidence digest. Use exactly 3 to 4 sentences and prefer approximately 60-100 words total. Prioritize: what incident, complaint, claim, or legal matter was reported; the most important factual details supported by the evidence; materially important location/date/parties; and the unresolved context or reason for administrative/legal review. Do not summarize every detail from every supporting document, do not repeat background information, and avoid names unless materially relevant.\n\n"
            . "Because the summary is intentionally short, return one compact paragraph, or two short paragraphs only when there is a genuine change in thought. Do not artificially create three paragraphs for a 3-4 sentence summary. Supporting Documents remain the authoritative source for complete details. If the sources do not contain enough readable evidence, return summary_available=false.\n\n"
            . "Readable source count supplied: " . count($sources) . '.';
    }

    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'summary_available' => ['type' => 'boolean'],
                'summary' => ['type' => 'string', 'nullable' => true],
                'source_count' => ['type' => 'integer'],
                'needs_review' => ['type' => 'boolean'],
            ],
            'required' => ['summary_available', 'summary', 'source_count', 'needs_review'],
        ];
    }

    private function sanitizeResult(array $result): array
    {
        $summary = isset($result['summary']) ? $this->cleanSummary((string) $result['summary']) : '';
        return [
            'summary_available' => (bool) ($result['summary_available'] ?? false),
            'summary' => $summary === '' ? null : $summary,
            'source_count' => max(0, (int) ($result['source_count'] ?? 0)),
            'needs_review' => (bool) ($result['needs_review'] ?? true),
        ];
    }

    private function cleanSummary(string $summary): string
    {
        $summary = str_replace(["\r\n", "\r"], "\n", $summary);
        $paragraphs = preg_split('/\n{2,}/', trim($summary)) ?: [];
        $paragraphs = array_values(array_filter(array_map(static function (string $paragraph): string {
            return trim(preg_replace('/[ \t\n]+/', ' ', $paragraph) ?? $paragraph);
        }, $paragraphs), static fn (string $paragraph): bool => $paragraph !== ''));
        $summary = $paragraphs ? implode("\n\n", $paragraphs) : trim(preg_replace('/\s+/', ' ', $summary) ?? $summary);
        return mb_substr($summary, 0, 2500);
    }

    private function fingerprint(array $sources): string
    {
        $parts = array_map(static fn (array $source): string => implode('|', [
            $source['documentNo'] ?? '',
            $source['versionNumber'] ?? '',
            $source['fileHash'] ?? '',
            $source['fileSize'] ?? '',
        ]), $sources);
        sort($parts);
        return hash('sha256', implode(';;', $parts));
    }

    private function updateAiFields(int $matterId, array $fields): void
    {
        $allowed = array_intersect_key($fields, array_flip([
            'ai_summary',
            'ai_summary_status',
            'ai_summary_generated_at',
            'ai_summary_provider',
            'ai_summary_model',
            'ai_summary_source_fingerprint',
        ]));
        if (isset($allowed['ai_summary_status']) && !in_array((string) $allowed['ai_summary_status'], self::STATUSES, true)) {
            throw new InvalidArgumentException('Invalid AI summary status.');
        }
        $sets = [];
        $params = ['id' => $matterId];
        foreach ($allowed as $column => $value) {
            $sets[] = "$column = :$column";
            $params[$column] = $value;
        }
        if (!$sets) {
            return;
        }
        $sets[] = 'updated_at = NOW()';
        $this->pdo->prepare('UPDATE legal_matter SET ' . implode(', ', $sets) . ' WHERE legal_matter_id = :id AND deleted_at IS NULL')->execute($params);
    }

    private function postJson(string $url, array $payload): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) {
            throw new RuntimeException('Unable to encode AI request.');
        }
        $timeout = max(5, (int) env('GEMINI_LEGAL_SUMMARY_TIMEOUT_SECONDS', 35));
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
            ]);
            $raw = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            if ($raw === false || $status < 200 || $status >= 300) {
                throw new RuntimeException($this->geminiError($raw ?: '', $status, $error, $url, $payload));
            }
            return $this->decodeResponse((string) $raw);
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
        ]);
        $raw = file_get_contents($url, false, $context);
        $status = $this->streamStatus($http_response_header ?? []);
        if ($raw === false || $status < 200 || $status >= 300) {
            throw new RuntimeException($this->geminiError((string) $raw, $status, '', $url, $payload));
        }
        return $this->decodeResponse((string) $raw);
    }

    private function decodeResponse(string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('GEMINI_RESPONSE_INVALID');
        }
        return $decoded;
    }

    private function extractOutputText(array $response): string
    {
        $parts = [];
        foreach (($response['candidates'] ?? []) as $candidate) {
            foreach (($candidate['content']['parts'] ?? []) as $part) {
                if (isset($part['text']) && is_string($part['text'])) {
                    $parts[] = $part['text'];
                }
            }
        }
        $text = trim(implode("\n", $parts));
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
            $text = preg_replace('/\s*```$/', '', $text) ?? $text;
        }
        return trim($text);
    }

    private function geminiEndpoint(): string
    {
        $model = $this->model();
        $modelPath = str_starts_with($model, 'models/') ? $model : 'models/' . rawurlencode($model);
        return 'https://generativelanguage.googleapis.com/v1beta/' . $modelPath . ':generateContent?key=' . rawurlencode(trim((string) env('GEMINI_API_KEY', '')));
    }

    private function model(): string
    {
        $model = trim((string) env('GEMINI_LEGAL_SUMMARY_MODEL', ''));
        if ($model === '') {
            $model = trim((string) env('GEMINI_VISITOR_ID_MODEL', 'gemini-3.6-flash'));
        }
        return $model === '' ? 'gemini-3.6-flash' : $model;
    }

    private function geminiError(string $raw, int $status, string $transportError, string $url, array $payload): string
    {
        $category = match (true) {
            $transportError !== '' => 'GEMINI_TRANSPORT_ERROR',
            $status === 400 => 'GEMINI_HTTP_400',
            $status === 401 || $status === 403 => 'GEMINI_KEY_INVALID',
            $status === 404 => 'GEMINI_MODEL_INVALID',
            $status === 408 || $status === 504 => 'GEMINI_TIMEOUT',
            $status === 429 => 'GEMINI_QUOTA_OR_RATE_LIMIT',
            $status >= 500 => 'GEMINI_SERVICE_UNAVAILABLE',
            $status > 0 => 'GEMINI_HTTP_' . $status,
            default => 'GEMINI_REQUEST_FAILED',
        };
        $decoded = json_decode($raw, true);
        $error = is_array($decoded) && is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
        $details = [
            'category' => $category,
            'http_status' => $status,
            'gemini_status' => is_string($error['status'] ?? null) ? $error['status'] : null,
            'gemini_code' => isset($error['code']) ? (int) $error['code'] : null,
            'message' => $this->sanitizeGeminiMessage(is_string($error['message'] ?? null) ? $error['message'] : $transportError),
            'model' => $this->model(),
            'endpoint_path' => (string) parse_url($url, PHP_URL_PATH),
            'structured_schema_included' => isset($payload['generationConfig']['response_schema']),
        ];
        return $category . ' ' . json_encode($details, JSON_UNESCAPED_SLASHES);
    }

    private function safeFailureStage(string $message): string
    {
        $category = strtok($message, ' ');
        return is_string($category) && preg_match('/^[A-Z0-9_]+$/', $category) ? $category : 'AI_REQUEST_FAILED';
    }

    private function sanitizeGeminiMessage(string $message): string
    {
        $message = preg_replace('/key=[^&\s]+/i', 'key=[redacted]', $message) ?? $message;
        $message = preg_replace('/AIza[0-9A-Za-z_\-]+/', '[redacted-api-key]', $message) ?? $message;
        $message = preg_replace('/[A-Za-z0-9+\/]{120,}={0,2}/', '[redacted-long-token]', $message) ?? $message;
        return trim($message);
    }

    private function streamStatus(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', (string) $header, $match)) {
                return (int) $match[1];
            }
        }
        return 0;
    }

    private function history(int $matterId, string $event, string $description, array $metadata, array $user): void
    {
        $this->pdo->prepare('INSERT INTO legal_matter_history (legal_matter_id, event_type, from_status, to_status, description, metadata_json, actor_user_id, created_at) VALUES (:id, :event, NULL, NULL, :description, :metadata, :user_id, NOW())')->execute([
            'id' => $matterId,
            'event' => $event,
            'description' => $description,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'user_id' => (int) $user['id'],
        ]);
    }

    private function activity(string $event, string $title, string $description, int $matterId, string $reference, array $user): void
    {
        try {
            $this->pdo->prepare("INSERT INTO activity_event (event_uuid, module_code, entity_type, entity_id, entity_reference, event_type, event_title, event_description, actor_user_id, actor_employee_reference_id, visibility_scope, metadata_json, occurred_at, created_at) VALUES (:uuid, 'LEGAL_MANAGEMENT', 'legal_matter', :id, :reference, :event_type, :title, :description, :user_id, :employee_id, 'INTERNAL', '{}', NOW(), NOW())")->execute([
                'uuid' => self::uuidV4(),
                'id' => $matterId,
                'reference' => $reference,
                'event_type' => $event,
                'title' => $title,
                'description' => $description,
                'user_id' => (int) $user['id'],
                'employee_id' => $user['employee_id'] ?? null,
            ]);
        } catch (Throwable) {
        }
    }

    private static function uuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
