<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'StoragePath.php';

final class ReservationRequestSummaryService
{
    private const PROVIDER = 'OPENAI';
    private const READABLE_MIME = ['application/pdf', 'image/jpeg', 'image/png'];
    private const MAX_SOURCE_BYTES = 10_000_000;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function markPending(int $reservationId): void
    {
        $this->update($reservationId, [
            'ai_request_summary_status' => 'PENDING',
            'ai_request_summary_provider' => self::PROVIDER,
            'ai_request_summary_model' => $this->model(),
            'ai_request_summary_failure_reason' => null,
        ]);
    }

    public function generate(int $reservationId, array $user, bool $force = false): array
    {
        $reservation = $this->reservation($reservationId);
        $letter = $this->letter($reservationId);
        if ($reservation === null || $letter === null) {
            return ['status' => 'NOT_FOUND', 'attempt_count' => 0];
        }
        if (!$force && strtoupper((string)($reservation['ai_request_summary_status'] ?? '')) === 'READY') {
            return ['status' => 'READY', 'attempt_count' => 0];
        }

        $this->markPending($reservationId);

        $attempts = 0;
        try {
            $source = $this->source($letter);
            if ($source === null) {
                $this->update($reservationId, [
                    'ai_request_summary_status' => 'NO_READABLE_SOURCE',
                    'ai_request_summary_failure_reason' => 'NO_READABLE_SOURCE',
                ]);
                return ['status' => 'NO_READABLE_SOURCE', 'attempt_count' => 0];
            }
            $result = $this->requestSummary($reservation, $source, $attempts);
            $summary = $this->clean((string) ($result['summary'] ?? ''));
            if ($summary === '') {
                $this->update($reservationId, [
                    'ai_request_summary_status' => 'NO_READABLE_SOURCE',
                    'ai_request_summary_failure_reason' => 'NO_READABLE_SOURCE',
                ]);
                return ['status' => 'NO_READABLE_SOURCE', 'attempt_count' => $attempts];
            }
            $this->update($reservationId, [
                'ai_request_summary' => $summary,
                'ai_request_summary_status' => 'READY',
                'ai_request_summary_generated_at' => date('Y-m-d H:i:s'),
                'ai_request_summary_provider' => self::PROVIDER,
                'ai_request_summary_model' => $this->model(),
                'ai_request_summary_failure_reason' => null,
            ]);
            return ['status' => 'READY', 'attempt_count' => $attempts];
        } catch (Throwable $exception) {
            $reason = $this->safeReason($exception->getMessage());
            $this->update($reservationId, [
                'ai_request_summary_status' => $this->statusForReason($reason),
                'ai_request_summary_provider' => self::PROVIDER,
                'ai_request_summary_model' => $this->model(),
                'ai_request_summary_failure_reason' => $reason,
            ]);
            error_log('Reservation AI request summary failed: ' . $exception->getMessage());
            return ['status' => $this->statusForReason($reason), 'attempt_count' => $attempts, 'failure_reason' => $reason];
        }
    }

    private function reservation(int $reservationId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT r.*, fs.space_name, e.full_name requester_name, d.department_name FROM facility_reservation r INNER JOIN facility_space fs ON fs.facility_space_id = r.facility_space_id LEFT JOIN employee_reference e ON e.employee_reference_id = r.requested_by_employee_reference_id LEFT JOIN department_reference d ON d.department_reference_id = r.department_reference_id WHERE r.deleted_at IS NULL AND r.facility_reservation_id = :id LIMIT 1');
        $stmt->execute(['id' => $reservationId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    private function letter(int $reservationId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM reservation_request_letter WHERE facility_reservation_id = :id AND deleted_at IS NULL LIMIT 1');
        $stmt->execute(['id' => $reservationId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    private function source(array $letter): ?array
    {
        $mime = (string) ($letter['mime_type'] ?? '');
        $size = (int) ($letter['file_size'] ?? 0);
        $path = StoragePath::resolveExistingWithin('reservations', (string) $letter['storage_path']);
        if (!in_array($mime, self::READABLE_MIME, true) || $size <= 0 || $size > self::MAX_SOURCE_BYTES || $path === null) {
            return null;
        }
        $bytes = file_get_contents($path);
        if ($bytes === false || $bytes === '') {
            return null;
        }
        return [
            'fileName' => (string) $letter['original_file_name'],
            'mimeType' => $mime,
            'base64' => base64_encode($bytes),
        ];
    }

    private function requestSummary(array $reservation, array $source, int &$attempts): array
    {
        if ($this->apiKey() === '') {
            throw new RuntimeException('AI_KEY_MISSING');
        }
        $payload = $this->payload($reservation, $source);
        $last = null;
        for ($attempts = 1; $attempts <= 2; $attempts++) {
            try {
                $response = $this->postJson($this->endpoint(), $payload);
                $text = $this->extractOutputText($response);
                $decoded = json_decode($text, true);
                if (!is_array($decoded)) {
                    throw new RuntimeException('AI_RESPONSE_INVALID');
                }
                return [
                    'summary' => isset($decoded['summary']) ? (string) $decoded['summary'] : '',
                ];
            } catch (RuntimeException $exception) {
                $last = $exception;
                if ($attempts >= 2 || !$this->isRetryableFailure($exception->getMessage())) {
                    throw $exception;
                }
            }
        }
        throw $last ?? new RuntimeException('AI_REQUEST_FAILED');
    }

    private function payload(array $reservation, array $source): array
    {
        return [
            'model' => $this->model(),
            'store' => false,
            'input' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'input_text', 'text' => $this->instructions($reservation) . "\n\nRequest letter file: " . (string) $source['fileName']],
                    ...$this->sourceParts($source),
                ],
            ]],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'reservation_request_summary',
                    'strict' => true,
                    'schema' => [
                    'type' => 'object',
                    'properties' => [
                            'summary' => ['type' => ['string', 'null']],
                    ],
                    'required' => ['summary'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
        ];
    }

    private function sourceParts(array $source): array
    {
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

    private function instructions(array $reservation): string
    {
        return "Summarize a room reservation request letter for FAM review.\n"
            . "Use only facts supported by the uploaded letter. Do not approve, reject, judge policy compliance, or invent missing details.\n"
            . "Return concise JSON with summary only. Keep the summary to a maximum of 2 short factual sentences for a FAM reviewer.\n"
            . "Prioritize only letter-supported requester, department, requested facility, requested date/time, attendee count, purpose, setup requirements, equipment requirements, and special instructions when explicitly present.\n"
            . "When explicitly stated in the letter, include the reservation purpose/context, meeting/training/event context, requested room setup, seating arrangement, projector/display, microphone/audio, whiteboard/equipment, or other facility arrangement requests.\n"
            . "Do not infer setup or equipment requirements when the letter does not state them. Do not repeat room, schedule, or attendee data unless the letter itself makes it review-relevant.\n"
            . "Reservation number, room, requester, and department are supplied only as review context; do not state that the uploaded letter contains those facts unless the letter itself supports them.\n"
            . "The original request letter remains authoritative. If the file is unreadable or lacks enough meaningful content, return a null or empty summary.\n\n"
            . "Reservation context only: " . (string) $reservation['reservation_number'] . '; room ' . (string) $reservation['space_name'] . '; requester ' . (string) ($reservation['requester_name'] ?? '') . '; department ' . (string) ($reservation['department_name'] ?? '') . '.';
    }

    private function postJson(string $url, array $payload): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) {
            throw new RuntimeException('AI_REQUEST_FAILED');
        }
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $this->apiKey(),
        ];
        $timeout = max(5, (int) env('OPENAI_RESERVATION_SUMMARY_TIMEOUT_SECONDS', 45));
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
                throw new RuntimeException($this->httpFailureReason((string) ($raw ?: ''), $status, $error, $errorCode));
            }
            return $this->decode((string) $raw);
        }
        $context = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $body, 'timeout' => $timeout, 'ignore_errors' => true]]);
        $raw = file_get_contents($url, false, $context);
        $status = $this->streamStatus($http_response_header ?? []);
        if ($raw === false || $status < 200 || $status >= 300) {
            throw new RuntimeException($this->httpFailureReason((string) $raw, $status, '', 0));
        }
        return $this->decode((string) $raw);
    }

    private function decode(string $raw): array
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
            throw new RuntimeException($this->responseFailureReason($response));
        }
        if ($status === 'incomplete') {
            throw new RuntimeException('AI_RESPONSE_INVALID');
        }
        if (is_array($response['error'] ?? null)) {
            throw new RuntimeException($this->responseFailureReason($response));
        }
        if (isset($response['output_text']) && is_string($response['output_text']) && trim($response['output_text']) !== '') {
            return trim($response['output_text']);
        }
        $parts = [];
        foreach (($response['output'] ?? []) as $item) {
            foreach (($item['content'] ?? []) as $content) {
                if (isset($content['refusal']) && is_string($content['refusal']) && trim($content['refusal']) !== '') {
                    throw new RuntimeException('AI_RESPONSE_INVALID');
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

    private function update(int $reservationId, array $fields): void
    {
        $allowed = array_intersect_key($fields, array_flip([
            'ai_request_summary',
            'ai_request_summary_status',
            'ai_request_summary_generated_at',
            'ai_request_summary_provider',
            'ai_request_summary_model',
            'ai_request_summary_failure_reason',
        ]));
        if (!$allowed) {
            return;
        }
        $sets = [];
        $params = ['id' => $reservationId];
        foreach ($allowed as $column => $value) {
            $sets[] = "$column = :$column";
            $params[$column] = $value;
        }
        $sets[] = 'updated_at = NOW()';
        $this->pdo->prepare('UPDATE facility_reservation SET ' . implode(', ', $sets) . ' WHERE facility_reservation_id = :id AND deleted_at IS NULL')->execute($params);
    }

    private function endpoint(): string
    {
        return 'https://api.openai.com/v1/responses';
    }

    private function model(): string
    {
        $model = trim((string) env('OPENAI_RESERVATION_SUMMARY_MODEL', 'gpt-6-luna'));
        return $model === '' ? 'gpt-6-luna' : $model;
    }

    private function apiKey(): string
    {
        return trim((string) env('OPENAI_API_KEY', ''));
    }

    private function inputFileName(array $source): string
    {
        $fileName = trim((string) ($source['fileName'] ?? 'reservation-request-letter.pdf'));
        $fileName = preg_replace('/[^A-Za-z0-9._-]+/', '-', $fileName) ?: 'reservation-request-letter.pdf';
        return str_ends_with(strtolower($fileName), '.pdf') ? $fileName : $fileName . '.pdf';
    }

    private function clean(string $summary): string
    {
        return mb_substr(trim(preg_replace('/\s+/', ' ', $summary) ?? $summary), 0, 1800);
    }

    private function safeReason(string $message): string
    {
        return mb_substr(preg_replace('/[^A-Z0-9_:-]/i', '_', $message) ?? 'FAILED', 0, 120);
    }

    private function httpFailureReason(string $raw, int $status, string $transportError, int $transportErrorCode): string
    {
        $timedOut = $transportErrorCode > 0 && defined('CURLE_OPERATION_TIMEDOUT') && $transportErrorCode === CURLE_OPERATION_TIMEDOUT;
        $decoded = json_decode($raw, true);
        $error = is_array($decoded) && is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
        $providerType = is_string($error['type'] ?? null) ? $error['type'] : null;
        $providerCode = is_string($error['code'] ?? null) ? $error['code'] : null;
        return match (true) {
            $timedOut => 'AI_TIMEOUT',
            $transportError !== '' => 'AI_SERVICE_UNAVAILABLE',
            in_array($providerCode, ['model_not_found', 'invalid_model'], true) => 'AI_MODEL_INVALID',
            $providerType === 'authentication_error' || $providerType === 'permission_error' => 'AI_AUTH_ERROR',
            $providerType === 'rate_limit_error' => 'AI_RATE_LIMITED',
            $status === 401 || $status === 403 => 'AI_AUTH_ERROR',
            $status === 404 => 'AI_MODEL_INVALID',
            $status === 408 || $status === 504 => 'AI_TIMEOUT',
            $status === 429 => 'AI_RATE_LIMITED',
            $status === 0 => 'AI_SERVICE_UNAVAILABLE',
            $status >= 500 => 'AI_SERVICE_UNAVAILABLE',
            default => 'AI_REQUEST_FAILED',
        };
    }

    private function responseFailureReason(array $response): string
    {
        $error = is_array($response['error'] ?? null) ? $response['error'] : [];
        $providerType = is_string($error['type'] ?? null) ? $error['type'] : null;
        $providerCode = is_string($error['code'] ?? null) ? $error['code'] : null;
        if (in_array($providerCode, ['model_not_found', 'invalid_model'], true)) {
            return 'AI_MODEL_INVALID';
        }
        if ($providerType === 'authentication_error' || $providerType === 'permission_error') {
            return 'AI_AUTH_ERROR';
        }
        if ($providerType === 'rate_limit_error') {
            return 'AI_RATE_LIMITED';
        }
        return 'AI_RESPONSE_INVALID';
    }

    private function isRetryableFailure(string $reason): bool
    {
        return in_array($this->safeReason($reason), ['AI_TIMEOUT', 'AI_SERVICE_UNAVAILABLE'], true);
    }

    private function statusForReason(string $reason): string
    {
        return match ($reason) {
            'AI_TIMEOUT' => 'TIMEOUT',
            'AI_RATE_LIMITED' => 'RATE_LIMITED',
            default => 'FAILED',
        };
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

}
