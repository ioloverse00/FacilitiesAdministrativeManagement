<?php

declare(strict_types=1);

final class ContractMetadataExtractionService
{
    private const PROVIDER = 'GEMINI';
    private const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    private const READABLE_MIME = ['application/pdf', 'image/jpeg', 'image/png', self::DOCX_MIME];
    private const MAX_SOURCE_BYTES = 10_000_000;
    private const STATUSES = ['ACTIVE', 'EXPIRED', 'TERMINATED', 'RENEWED', 'UNKNOWN'];
    private const CONFIDENCE = ['LOW', 'MEDIUM', 'HIGH'];

    public function __construct(private readonly PDO $pdo, private readonly ?DocumentService $documentService = null)
    {
    }

    public function analyze(int $documentId, array $user): ?array
    {
        $document = ($this->documentService ?? new DocumentService($this->pdo))->show($documentId);
        if ($document === null) {
            return null;
        }

        $source = $this->source($documentId);
        if ($source === null) {
            $this->storeUnavailable($documentId, 'NO_READABLE_SOURCE', (int) $user['id']);
            return ($this->documentService ?? new DocumentService($this->pdo))->show($documentId);
        }

        try {
            $candidate = $this->requestExtraction($document, $source);
            ($this->documentService ?? new DocumentService($this->pdo))->storeContractMetadataCandidate($documentId, $candidate, (int) $user['id']);
            $this->activity('LEGAL_CONTRACT_METADATA_ANALYZED', 'Contract Metadata Analyzed', $documentId, (int) $user['id']);
        } catch (Throwable $exception) {
            $this->storeUnavailable($documentId, $this->safeFailureStage($exception->getMessage()), (int) $user['id']);
        }

        return ($this->documentService ?? new DocumentService($this->pdo))->show($documentId);
    }

    private function source(int $documentId): ?array
    {
        $statement = $this->pdo->prepare('SELECT dv.document_version_id, dv.file_name, dv.mime_type, dv.file_size, dv.storage_path, dv.file_hash FROM document_version dv INNER JOIN document d ON d.document_id = dv.document_id WHERE d.deleted_at IS NULL AND dv.deleted_at IS NULL AND dv.is_current = TRUE AND dv.document_id = :id LIMIT 1');
        $statement->execute(['id' => $documentId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }
        $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $row['storage_path']);
        $mime = (string) ($row['mime_type'] ?? '');
        $size = (int) ($row['file_size'] ?? 0);
        if (!in_array($mime, self::READABLE_MIME, true) || $size <= 0 || $size > self::MAX_SOURCE_BYTES || !is_file($path)) {
            return null;
        }
        if ($mime === self::DOCX_MIME) {
            $text = $this->extractDocxText($path);
            if ($text === '') {
                return null;
            }
            return $row + [
                'textContent' => $text,
                'mimeType' => 'text/plain',
            ];
        }

        $bytes = file_get_contents($path);
        if ($bytes === false || $bytes === '') {
            return null;
        }
        return $row + [
            'base64' => base64_encode($bytes),
            'mimeType' => $mime,
        ];
    }

    private function requestExtraction(array $document, array $source): array
    {
        if (trim((string) env('GEMINI_API_KEY', '')) === '') {
            throw new RuntimeException('GEMINI_KEY_MISSING');
        }
        $response = $this->postJson($this->geminiEndpoint(), $this->geminiPayload($document, $source));
        $text = $this->extractOutputText($response);
        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('GEMINI_RESPONSE_INVALID');
        }
        return $this->sanitizeCandidate($decoded);
    }

    private function geminiPayload(array $document, array $source): array
    {
        return [
            'contents' => [[
                'role' => 'user',
                'parts' => [
                    ['text' => $this->instructions($document, $source)],
                    ...$this->sourceParts($source),
                ],
            ]],
            'generationConfig' => [
                'temperature' => 0,
                'response_mime_type' => 'application/json',
                'response_schema' => $this->schema(),
            ],
        ];
    }

    private function sourceParts(array $source): array
    {
        if (isset($source['textContent'])) {
            return [[
                'text' => "Extract from this DOCX text export:\n\n" . (string) $source['textContent'],
            ]];
        }

        return [[
            'inline_data' => [
                'mime_type' => (string) $source['mimeType'],
                'data' => (string) $source['base64'],
            ],
        ]];
    }

    private function instructions(array $document, array $source): string
    {
        return "Extract structured contract/agreement metadata from this supporting document for human review.\n\n"
            . "Document title: " . (string) ($document['title'] ?? '') . "\n"
            . "File name: " . (string) ($source['file_name'] ?? '') . "\n\n"
            . "Return only values clearly supported by the document. Do not infer dates from upload dates, matter dates, or summary text. Use ISO dates in YYYY-MM-DD format. If a value is not explicitly available, return null. Agreement status must be one of ACTIVE, EXPIRED, TERMINATED, RENEWED, or UNKNOWN. These are non-authoritative candidates; an admin will confirm or edit them before Records Retention can use them.";
    }

    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'agreement_reference' => ['type' => 'string', 'nullable' => true],
                'effective_date' => ['type' => 'string', 'nullable' => true],
                'expiration_date' => ['type' => 'string', 'nullable' => true],
                'agreement_status' => ['type' => 'string', 'nullable' => true],
                'confidence' => [
                    'type' => 'object',
                    'properties' => [
                        'agreement_reference' => ['type' => 'string'],
                        'effective_date' => ['type' => 'string'],
                        'expiration_date' => ['type' => 'string'],
                        'agreement_status' => ['type' => 'string'],
                    ],
                    'required' => ['agreement_reference', 'effective_date', 'expiration_date', 'agreement_status'],
                ],
            ],
            'required' => ['agreement_reference', 'effective_date', 'expiration_date', 'agreement_status', 'confidence'],
        ];
    }

    private function sanitizeCandidate(array $candidate): array
    {
        $confidence = is_array($candidate['confidence'] ?? null) ? $candidate['confidence'] : [];
        return [
            'agreement_reference' => $this->nullableText($candidate['agreement_reference'] ?? null, 100),
            'effective_date' => $this->nullableDate($candidate['effective_date'] ?? null),
            'expiration_date' => $this->nullableDate($candidate['expiration_date'] ?? null),
            'agreement_status' => $this->status($candidate['agreement_status'] ?? null),
            'confidence' => [
                'agreement_reference' => $this->confidence($confidence['agreement_reference'] ?? null),
                'effective_date' => $this->confidence($confidence['effective_date'] ?? null),
                'expiration_date' => $this->confidence($confidence['expiration_date'] ?? null),
                'agreement_status' => $this->confidence($confidence['agreement_status'] ?? null),
            ],
        ];
    }

    private function postJson(string $url, array $payload): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) {
            throw new RuntimeException('GEMINI_REQUEST_INVALID');
        }
        $timeout = max(5, (int) env('GEMINI_CONTRACT_METADATA_TIMEOUT_SECONDS', 35));
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
                throw new RuntimeException($this->geminiError((string) ($raw ?: ''), $status, $error));
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
            throw new RuntimeException($this->geminiError((string) $raw, $status, ''));
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
        $model = trim((string) env('GEMINI_CONTRACT_METADATA_MODEL', ''));
        if ($model === '') {
            $model = trim((string) env('GEMINI_LEGAL_SUMMARY_MODEL', ''));
        }
        if ($model === '') {
            $model = trim((string) env('GEMINI_VISITOR_ID_MODEL', 'gemini-3.6-flash'));
        }
        return $model === '' ? 'gemini-3.6-flash' : $model;
    }

    private function geminiError(string $raw, int $status, string $transportError): string
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
        $message = is_string($error['message'] ?? null) ? $error['message'] : $transportError;
        $message = preg_replace('/key=[^&\s]+/i', 'key=[redacted]', $message) ?? $message;
        return $category . ' ' . json_encode([
            'http_status' => $status,
            'gemini_status' => is_string($error['status'] ?? null) ? $error['status'] : null,
            'message' => trim($message),
            'model' => $this->model(),
        ], JSON_UNESCAPED_SLASHES);
    }

    private function storeUnavailable(int $documentId, string $stage, int $userId): void
    {
        ($this->documentService ?? new DocumentService($this->pdo))->storeContractMetadataUnavailable($documentId, $stage, $userId);
        $this->activity('LEGAL_CONTRACT_METADATA_UNAVAILABLE', 'Contract Metadata Unavailable', $documentId, $userId);
    }

    private function nullableText(mixed $value, int $max): ?string
    {
        $text = mb_substr(trim((string) $value), 0, $max);
        return $text === '' ? null : $text;
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

    private function nullableDate(mixed $value): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $text)) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $text);
        return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $text ? $text : null;
    }

    private function status(mixed $value): ?string
    {
        $status = strtoupper(trim((string) $value));
        return in_array($status, self::STATUSES, true) ? $status : null;
    }

    private function confidence(mixed $value): string
    {
        $confidence = strtoupper(trim((string) $value));
        return in_array($confidence, self::CONFIDENCE, true) ? $confidence : 'LOW';
    }

    private function safeFailureStage(string $message): string
    {
        $category = strtok($message, ' ');
        return is_string($category) && preg_match('/^[A-Z0-9_]+$/', $category) ? $category : 'AI_REQUEST_FAILED';
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

    private function activity(string $event, string $title, int $documentId, int $userId): void
    {
        try {
            $this->pdo->prepare("INSERT INTO activity_event (event_uuid, module_code, entity_type, entity_id, event_type, event_title, event_description, actor_user_id, visibility_scope, metadata_json, occurred_at, created_at) VALUES (UUID(), 'documents', 'document', :id, :event_type, :title, :description, :user_id, 'INTERNAL', :metadata, NOW(), NOW())")->execute([
                'id' => $documentId,
                'event_type' => $event,
                'title' => $title,
                'description' => $title,
                'user_id' => $userId,
                'metadata' => json_encode(['document_id' => $documentId], JSON_THROW_ON_ERROR),
            ]);
        } catch (Throwable) {
        }
    }
}
