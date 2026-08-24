<?php

declare(strict_types=1);

final class ReservationRequestSummaryService
{
    private const PROVIDER = 'GEMINI';
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
        $path = $this->storageRoot() . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $letter['storage_path']);
        if (!in_array($mime, self::READABLE_MIME, true) || $size <= 0 || $size > self::MAX_SOURCE_BYTES || !is_file($path)) {
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
        if (trim((string) env('GEMINI_API_KEY', '')) === '') {
            throw new RuntimeException('GEMINI_KEY_MISSING');
        }
        $payload = $this->payload($reservation, $source);
        $last = null;
        for ($attempts = 1; $attempts <= 2; $attempts++) {
            try {
                $response = $this->postJson($this->endpoint(), $payload);
                $text = $this->extractOutputText($response);
                $decoded = json_decode($text, true);
                if (!is_array($decoded)) {
                    throw new RuntimeException('GEMINI_RESPONSE_INVALID');
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
        throw $last ?? new RuntimeException('GEMINI_REQUEST_FAILED');
    }

    private function payload(array $reservation, array $source): array
    {
        return [
            'contents' => [[
                'role' => 'user',
                'parts' => [
                    ['text' => $this->instructions($reservation)],
                    ['text' => 'Request letter file: ' . $source['fileName']],
                    ['inline_data' => [
                        'mime_type' => $source['mimeType'],
                        'data' => $source['base64'],
                    ]],
                ],
            ]],
            'generationConfig' => [
                'temperature' => 0,
                'response_mime_type' => 'application/json',
                'response_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'summary' => ['type' => 'string', 'nullable' => true],
                    ],
                    'required' => ['summary'],
                ],
            ],
        ];
    }

    private function instructions(array $reservation): string
    {
        return "Summarize a room reservation request letter for FAM review.\n"
            . "Use only facts supported by the uploaded letter. Do not approve, reject, judge policy compliance, or invent missing details.\n"
            . "Return concise JSON with summary only. Keep the summary to 2 to 4 factual advisory sentences for a FAM reviewer.\n"
            . "When explicitly stated in the letter, include the reservation purpose/context, meeting/training/event context, requested room setup, seating arrangement, projector/display, microphone/audio, whiteboard/equipment, or other facility arrangement requests.\n"
            . "Do not infer setup or equipment requirements when the letter does not state them. Do not repeat room, schedule, or attendee data unless the letter itself makes it review-relevant.\n"
            . "The original request letter remains authoritative. If the file is unreadable or lacks enough content, return an empty summary.\n\n"
            . "Reservation context: " . (string) $reservation['reservation_number'] . '; room ' . (string) $reservation['space_name'] . '; requester ' . (string) ($reservation['requester_name'] ?? '') . '; department ' . (string) ($reservation['department_name'] ?? '') . '.';
    }

    private function postJson(string $url, array $payload): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) {
            throw new RuntimeException('GEMINI_PAYLOAD_INVALID');
        }
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        $timeout = max(5, (int) env('GEMINI_RESERVATION_SUMMARY_TIMEOUT_SECONDS', 45));
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
                throw new RuntimeException($this->httpFailureReason($status, $error));
            }
            return $this->decode((string) $raw);
        }
        $context = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $body, 'timeout' => $timeout, 'ignore_errors' => true]]);
        $raw = file_get_contents($url, false, $context);
        if ($raw === false) {
            throw new RuntimeException('GEMINI_TRANSPORT_ERROR');
        }
        return $this->decode((string) $raw);
    }

    private function decode(string $raw): array
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
        $model = $this->model();
        $modelPath = str_starts_with($model, 'models/') ? $model : 'models/' . rawurlencode($model);
        return 'https://generativelanguage.googleapis.com/v1beta/' . $modelPath . ':generateContent?key=' . rawurlencode(trim((string) env('GEMINI_API_KEY', '')));
    }

    private function model(): string
    {
        $model = trim((string) env('GEMINI_RESERVATION_SUMMARY_MODEL', ''));
        if ($model === '') {
            $model = trim((string) env('GEMINI_VISITOR_ID_MODEL', 'gemini-3.6-flash'));
        }
        return $model === '' ? 'gemini-3.6-flash' : $model;
    }

    private function clean(string $summary): string
    {
        return mb_substr(trim(preg_replace('/\s+/', ' ', $summary) ?? $summary), 0, 1800);
    }

    private function safeReason(string $message): string
    {
        return mb_substr(preg_replace('/[^A-Z0-9_:-]/i', '_', $message) ?? 'FAILED', 0, 120);
    }

    private function httpFailureReason(int $status, string $transportError): string
    {
        if ($transportError !== '') return str_contains(strtolower($transportError), 'timed') ? 'GEMINI_TIMEOUT' : 'GEMINI_TRANSPORT_ERROR';
        return match ($status) {
            401, 403 => 'GEMINI_AUTH_ERROR',
            404 => 'GEMINI_MODEL_INVALID',
            408 => 'GEMINI_TIMEOUT',
            429 => 'GEMINI_QUOTA_OR_RATE_LIMIT',
            500, 502, 503, 504 => 'GEMINI_SERVICE_UNAVAILABLE',
            default => 'GEMINI_REQUEST_FAILED',
        };
    }

    private function isRetryableFailure(string $reason): bool
    {
        return in_array($this->safeReason($reason), ['GEMINI_TIMEOUT', 'GEMINI_TRANSPORT_ERROR', 'GEMINI_SERVICE_UNAVAILABLE'], true);
    }

    private function statusForReason(string $reason): string
    {
        return match ($reason) {
            'GEMINI_TIMEOUT' => 'TIMEOUT',
            'GEMINI_QUOTA_OR_RATE_LIMIT' => 'RATE_LIMITED',
            default => 'FAILED',
        };
    }

    private function storageRoot(): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage';
    }
}
