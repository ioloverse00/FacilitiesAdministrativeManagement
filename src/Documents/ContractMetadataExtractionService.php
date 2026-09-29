<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'StoragePath.php';

final class ContractMetadataExtractionService
{
    private const PROVIDER = 'OPENAI';
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
        return $this->analyzeVersion($documentId, null, $user);
    }

    public function analyzeVersion(int $documentId, ?int $documentVersionId, array $user): ?array
    {
        $document = ($this->documentService ?? new DocumentService($this->pdo))->show($documentId);
        if ($document === null) {
            return null;
        }

        $source = $this->source($documentId, $documentVersionId);
        if ($source === null) {
            $this->storeUnavailable($documentId, 'NO_READABLE_SOURCE', (int) $user['id'], $documentVersionId);
            return ($this->documentService ?? new DocumentService($this->pdo))->show($documentId);
        }

        try {
            $candidate = $this->requestExtraction($document, $source);
            ($this->documentService ?? new DocumentService($this->pdo))->storeContractMetadataCandidate($documentId, $candidate, (int) $user['id'], (int) $source['document_version_id']);
            $this->activity('LEGAL_CONTRACT_METADATA_ANALYZED', 'Contract Metadata Analyzed', $documentId, (int) $user['id']);
        } catch (Throwable $exception) {
            $stage = $this->safeFailureStage($exception->getMessage());
            $diagnostics = $this->safeFailureDiagnostics($exception->getMessage());
            $this->storeUnavailable($documentId, $stage, (int) $user['id'], (int) $source['document_version_id'], $diagnostics);
            $this->logExtractionFailure($documentId, (int) $source['document_version_id'], $stage, $diagnostics);
        }

        return ($this->documentService ?? new DocumentService($this->pdo))->show($documentId);
    }

    private function source(int $documentId, ?int $documentVersionId = null): ?array
    {
        $versionFilter = $documentVersionId !== null ? 'AND dv.document_version_id = :version_id' : 'AND dv.is_current = TRUE';
        $statement = $this->pdo->prepare("SELECT dv.document_version_id, dv.file_name, dv.mime_type, dv.file_size, dv.storage_path, dv.file_hash FROM document_version dv INNER JOIN document d ON d.document_id = dv.document_id WHERE d.deleted_at IS NULL AND dv.deleted_at IS NULL $versionFilter AND dv.document_id = :id LIMIT 1");
        $params = ['id' => $documentId];
        if ($documentVersionId !== null) {
            $params['version_id'] = $documentVersionId;
        }
        $statement->execute($params);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }
        $path = StoragePath::resolveExistingWithin('documents', (string) $row['storage_path']);
        $mime = (string) ($row['mime_type'] ?? '');
        $size = (int) ($row['file_size'] ?? 0);
        if (!in_array($mime, self::READABLE_MIME, true) || $size <= 0 || $size > self::MAX_SOURCE_BYTES || $path === null) {
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
        if ($this->apiKey() === '') {
            throw new RuntimeException('AI_KEY_MISSING');
        }
        $response = $this->postJson($this->openAiEndpoint(), $this->openAiPayload($document, $source));
        $text = $this->extractOutputText($response);
        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('AI_RESPONSE_INVALID');
        }
        return $this->sanitizeCandidate($decoded);
    }

    private function openAiPayload(array $document, array $source): array
    {
        return [
            'model' => $this->model(),
            'store' => false,
            'input' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'input_text', 'text' => $this->instructions($document, $source)],
                    ...$this->sourceParts($source),
                ],
            ]],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'contract_metadata_candidate',
                    'strict' => true,
                    'schema' => $this->schema(),
                ],
            ],
        ];
    }

    private function sourceParts(array $source): array
    {
        if (isset($source['textContent'])) {
            return [[
                'type' => 'input_text',
                'text' => "Extract from this DOCX text export:\n\n" . (string) $source['textContent'],
            ]];
        }

        if ((string) ($source['mimeType'] ?? '') === 'application/pdf') {
            return [[
                'type' => 'input_file',
                'filename' => $this->inputFileName($source),
                'file_data' => 'data:application/pdf;base64,' . (string) $source['base64'],
            ]];
        }

        return [[
            'type' => 'input_image',
            'image_url' => 'data:' . (string) $source['mimeType'] . ';base64,' . (string) $source['base64'],
        ]];
    }

    private function instructions(array $document, array $source): string
    {
        return "Extract structured contract/agreement metadata from this supporting document for human review.\n\n"
            . "Document title: " . (string) ($document['title'] ?? '') . "\n"
            . "File name: " . (string) ($source['file_name'] ?? '') . "\n\n"
            . "Return only values clearly supported by the document. Distinguish execution, signing, agreement, renewal, and amendment dates from the contract term dates. Agreement Date is not automatically Effective Date. Execution Date is not automatically Effective Date. Signing Date is not automatically Effective Date. Amendment Date is not automatically Effective Date. Renewal Date is not automatically Effective Date. Prefer an explicit Effective Date, Commencement Date, Start Date, or the explicit beginning of the contract/service term. effective_date means the date the contractual service or term actually begins, or an explicitly stated effective/start date. expiration_date means the date the contractual term ends or expires and must represent the end/expiry of the relevant contract term. Example: Agreement/Execution Date: October 20, 2026; Effective/Start Date: November 3, 2026; Expiration/End Date: October 31, 2027. Correct extraction: effective_date = 2026-11-03 and expiration_date = 2027-10-31. Incorrect: effective_date = 2026-10-20. Normalize clearly stated natural-language dates to ISO YYYY-MM-DD, such as November 1, 2026 to 2026-11-01. Do not infer ambiguous numeric dates. Do not infer dates from upload dates, matter dates, or summary text. If a value is not explicitly available, return null. Agreement status must be one of ACTIVE, EXPIRED, TERMINATED, RENEWED, or UNKNOWN. These are non-authoritative candidates; an admin will confirm or edit them before Records Retention can use them.";
    }

    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'agreement_reference' => ['type' => ['string', 'null']],
                'effective_date' => ['type' => ['string', 'null']],
                'expiration_date' => ['type' => ['string', 'null']],
                'agreement_status' => ['type' => ['string', 'null'], 'enum' => ['ACTIVE', 'EXPIRED', 'TERMINATED', 'RENEWED', 'UNKNOWN', null]],
                'confidence' => [
                    'type' => 'object',
                    'properties' => [
                        'agreement_reference' => ['type' => 'string', 'enum' => self::CONFIDENCE],
                        'effective_date' => ['type' => 'string', 'enum' => self::CONFIDENCE],
                        'expiration_date' => ['type' => 'string', 'enum' => self::CONFIDENCE],
                        'agreement_status' => ['type' => 'string', 'enum' => self::CONFIDENCE],
                    ],
                    'required' => ['agreement_reference', 'effective_date', 'expiration_date', 'agreement_status'],
                    'additionalProperties' => false,
                ],
            ],
            'required' => ['agreement_reference', 'effective_date', 'expiration_date', 'agreement_status', 'confidence'],
            'additionalProperties' => false,
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
            throw new RuntimeException('AI_REQUEST_FAILED');
        }
        $timeout = max(5, (int) env('OPENAI_CONTRACT_METADATA_TIMEOUT_SECONDS', 35));
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $this->apiKey(),
        ];
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
            $errorCode = (int) curl_errno($ch);
            curl_close($ch);
            if ($raw === false || $status < 200 || $status >= 300) {
                throw new RuntimeException($this->openAiError((string) ($raw ?: ''), $status, $error, $errorCode));
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
            throw new RuntimeException($this->openAiError((string) $raw, $status, '', 0));
        }
        return $this->decodeResponse((string) $raw);
    }

    private function decodeResponse(string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('AI_RESPONSE_INVALID');
        }
        return $decoded;
    }

    private function extractOutputText(array $response): string
    {
        $status = (string) ($response['status'] ?? '');
        if ($status !== '' && !in_array($status, ['completed', 'incomplete'], true)) {
            throw new RuntimeException($this->openAiResponseError($response));
        }
        if ($status === 'incomplete') {
            throw new RuntimeException('AI_RESPONSE_INVALID ' . json_encode([
                'http_status' => 200,
                'provider_status' => $status,
                'message' => is_string($response['incomplete_details']['reason'] ?? null) ? $response['incomplete_details']['reason'] : 'Response was incomplete.',
                'model' => $this->model(),
            ], JSON_UNESCAPED_SLASHES));
        }
        if (is_array($response['error'] ?? null)) {
            throw new RuntimeException($this->openAiResponseError($response));
        }
        if (isset($response['output_text']) && is_string($response['output_text']) && trim($response['output_text']) !== '') {
            return trim($response['output_text']);
        }
        $parts = [];
        foreach (($response['output'] ?? []) as $item) {
            foreach (($item['content'] ?? []) as $content) {
                if (isset($content['refusal']) && is_string($content['refusal']) && trim($content['refusal']) !== '') {
                    throw new RuntimeException('AI_RESPONSE_INVALID ' . json_encode([
                        'http_status' => 200,
                        'provider_status' => 'refused',
                        'message' => mb_substr(trim($content['refusal']), 0, 300),
                        'model' => $this->model(),
                    ], JSON_UNESCAPED_SLASHES));
                }
                if (isset($content['text']) && is_string($content['text'])) {
                    $parts[] = $content['text'];
                }
            }
        }
        $text = trim(implode("\n", $parts));
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
            $text = preg_replace('/\s*```$/', '', $text) ?? $text;
        }
        $text = trim($text);
        if ($text === '') {
            throw new RuntimeException('AI_RESPONSE_INVALID');
        }
        return $text;
    }

    private function openAiEndpoint(): string
    {
        return 'https://api.openai.com/v1/responses';
    }

    private function model(): string
    {
        $model = trim((string) env('OPENAI_CONTRACT_METADATA_MODEL', 'gpt-6-sol'));
        return $model === '' ? 'gpt-6-sol' : $model;
    }

    private function apiKey(): string
    {
        return trim((string) env('OPENAI_API_KEY', ''));
    }

    private function openAiError(string $raw, int $status, string $transportError, int $transportErrorCode): string
    {
        $timedOut = $transportErrorCode > 0 && defined('CURLE_OPERATION_TIMEDOUT') && $transportErrorCode === CURLE_OPERATION_TIMEDOUT;
        $decoded = json_decode($raw, true);
        $error = is_array($decoded) && is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
        $providerType = is_string($error['type'] ?? null) ? $error['type'] : null;
        $providerCode = is_string($error['code'] ?? null) ? $error['code'] : null;
        $category = match (true) {
            $timedOut => 'AI_TIMEOUT',
            $transportError !== '' => 'AI_REQUEST_FAILED',
            in_array($providerCode, ['model_not_found', 'invalid_model'], true) => 'AI_MODEL_INVALID',
            $providerType === 'authentication_error' || $providerType === 'permission_error' => 'AI_AUTH_ERROR',
            $providerType === 'rate_limit_error' => 'AI_RATE_LIMITED',
            $status === 400 => 'AI_REQUEST_FAILED',
            $status === 401 || $status === 403 => 'AI_AUTH_ERROR',
            $status === 404 => 'AI_MODEL_INVALID',
            $status === 408 || $status === 504 => 'AI_TIMEOUT',
            $status === 429 => 'AI_RATE_LIMITED',
            $status >= 500 => 'AI_SERVICE_UNAVAILABLE',
            $status > 0 => 'AI_REQUEST_FAILED',
            default => 'AI_REQUEST_FAILED',
        };
        $message = is_string($error['message'] ?? null) ? $error['message'] : $transportError;
        $message = $this->redactProviderMessage($message);
        return $category . ' ' . json_encode([
            'http_status' => $status,
            'provider' => self::PROVIDER,
            'provider_status' => $providerType,
            'provider_code' => $providerCode,
            'message' => trim($message),
            'model' => $this->model(),
        ], JSON_UNESCAPED_SLASHES);
    }

    private function openAiResponseError(array $response): string
    {
        $error = is_array($response['error'] ?? null) ? $response['error'] : [];
        $message = is_string($error['message'] ?? null) ? $error['message'] : 'OpenAI response was not completed.';
        return 'AI_RESPONSE_INVALID ' . json_encode([
            'http_status' => 200,
            'provider' => self::PROVIDER,
            'provider_status' => is_string($response['status'] ?? null) ? $response['status'] : null,
            'provider_code' => is_string($error['code'] ?? null) ? $error['code'] : null,
            'message' => $this->redactProviderMessage($message),
            'model' => $this->model(),
        ], JSON_UNESCAPED_SLASHES);
    }

    private function redactProviderMessage(string $message): string
    {
        $message = preg_replace('/Bearer\s+[A-Za-z0-9._~+\-\/]+=*/i', 'Bearer [redacted]', $message) ?? $message;
        $message = preg_replace('/api[_ -]?key["\']?\s*[:=]\s*["\']?[^"\',\s]+/i', 'api_key=[redacted]', $message) ?? $message;
        return mb_substr($message, 0, 300);
    }

    private function inputFileName(array $source): string
    {
        $fileName = trim((string) ($source['file_name'] ?? 'contract-document.pdf'));
        $fileName = preg_replace('/[^A-Za-z0-9._-]+/', '-', $fileName) ?: 'contract-document.pdf';
        return str_ends_with(strtolower($fileName), '.pdf') ? $fileName : $fileName . '.pdf';
    }

    private function storeUnavailable(int $documentId, string $stage, int $userId, ?int $documentVersionId = null, array $diagnostics = []): void
    {
        ($this->documentService ?? new DocumentService($this->pdo))->storeContractMetadataUnavailable($documentId, $stage, $userId, $documentVersionId, $diagnostics);
        $this->activity('LEGAL_CONTRACT_METADATA_UNAVAILABLE', 'Contract Metadata Unavailable', $documentId, $userId, $documentVersionId, $stage, $diagnostics);
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

    private function safeFailureDiagnostics(string $message): array
    {
        $space = strpos($message, ' ');
        if ($space === false) {
            return ['provider' => self::PROVIDER, 'model' => $this->model()];
        }
        $decoded = json_decode(trim(substr($message, $space + 1)), true);
        if (!is_array($decoded)) {
            return ['provider' => self::PROVIDER, 'model' => $this->model()];
        }
        return [
            'http_status' => isset($decoded['http_status']) && is_numeric($decoded['http_status']) ? (int) $decoded['http_status'] : null,
            'provider' => is_string($decoded['provider'] ?? null) ? mb_substr($decoded['provider'], 0, 40) : self::PROVIDER,
            'provider_status' => is_string($decoded['provider_status'] ?? null) ? mb_substr($decoded['provider_status'], 0, 80) : null,
            'provider_code' => is_string($decoded['provider_code'] ?? null) ? mb_substr($decoded['provider_code'], 0, 80) : null,
            'model' => is_string($decoded['model'] ?? null) ? mb_substr($decoded['model'], 0, 120) : $this->model(),
            'provider_message' => is_string($decoded['message'] ?? null) ? mb_substr($decoded['message'], 0, 300) : '',
        ];
    }

    private function logExtractionFailure(int $documentId, int $documentVersionId, string $stage, array $diagnostics): void
    {
        error_log('Contract metadata extraction failed: ' . json_encode([
            'document_id' => $documentId,
            'document_version_id' => $documentVersionId,
            'failure_stage' => $stage,
            'http_status' => $diagnostics['http_status'] ?? null,
            'provider' => $diagnostics['provider'] ?? self::PROVIDER,
            'provider_status' => $diagnostics['provider_status'] ?? null,
            'provider_code' => $diagnostics['provider_code'] ?? null,
            'model' => $diagnostics['model'] ?? $this->model(),
        ], JSON_UNESCAPED_SLASHES));
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

    private function activity(string $event, string $title, int $documentId, int $userId, ?int $documentVersionId = null, ?string $stage = null, array $diagnostics = []): void
    {
        try {
            $metadata = ['document_id' => $documentId];
            if ($documentVersionId !== null && $documentVersionId > 0) {
                $metadata['document_version_id'] = $documentVersionId;
            }
            if ($stage !== null && $stage !== '') {
                $metadata['failure_stage'] = $stage;
            }
            foreach ($diagnostics as $key => $value) {
                if (is_scalar($value) || $value === null) {
                    $metadata[$key] = is_string($value) ? mb_substr($value, 0, 300) : $value;
                }
            }
            $this->pdo->prepare("INSERT INTO activity_event (event_uuid, module_code, entity_type, entity_id, event_type, event_title, event_description, actor_user_id, visibility_scope, metadata_json, occurred_at, created_at) VALUES (UUID(), 'documents', 'document', :id, :event_type, :title, :description, :user_id, 'INTERNAL', :metadata, NOW(), NOW())")->execute([
                'id' => $documentId,
                'event_type' => $event,
                'title' => $title,
                'description' => $title,
                'user_id' => $userId,
                'metadata' => json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]);
        } catch (Throwable) {
        }
    }
}
