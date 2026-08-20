<?php

declare(strict_types=1);

final class VisitorIdAnalysisService
{
    private const MAX_BYTES = 5_242_880;
    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp'];
    private const FORBIDDEN_NAME_LABELS = [
        'LAST NAME',
        'FIRST NAME',
        'MIDDLE NAME',
        'MIDDLE NAMES',
        'LAST NAME FIRST NAME',
        'LAST NAME FIRST NAME MIDDLE NAME',
        'LAST NAME FIRST NAME MIDDLE NAMES',
        'SURNAME',
        'GIVEN NAME',
        'GIVEN NAMES',
        'FULL NAME',
        'NAME',
        'NAME OF HOLDER',
        'CARD HOLDER NAME',
        'HOLDER NAME',
        'APELYIDO',
        'PANGALAN',
        'GITNANG PANGALAN',
    ];
    private const LABEL_TOKENS = [
        'LAST' => true,
        'FIRST' => true,
        'MIDDLE' => true,
        'NAMES' => true,
        'NAME' => true,
        'SURNAME' => true,
        'GIVEN' => true,
        'FULL' => true,
        'HOLDER' => true,
        'CARD' => true,
        'OF' => true,
    ];

    public function __construct(private readonly array $allowedIdTypes) {}

    public function analyzeUpload(array $file): array
    {
        $this->assertConfigured();
        $image = $this->validatedUpload($file);
        try {
            $analysis = $this->analyzeImage($image);
            error_log(sprintf('Visitor ID analysis completed model=%s latency_ms=%d', $this->model(), (int) ($analysis['diagnostics']['latency_ms'] ?? 0)));
            return $this->diagnosticsEnabled()
                ? $analysis['result'] + ['diagnostics' => $analysis['diagnostics']]
                : $analysis['result'];
        } catch (Throwable $e) {
            error_log('Visitor ID analysis failed: ' . $e::class);
            throw $e;
        } finally {
            if (isset($file['tmp_name']) && is_string($file['tmp_name']) && is_uploaded_file($file['tmp_name'])) {
                @unlink($file['tmp_name']);
            }
        }
    }

    public function analyzeLocalImage(string $path): array
    {
        $this->assertConfigured();
        $image = $this->validatedImagePath($path, false);
        return $this->analyzeImage($image)['result'];
    }

    public function analyzeLocalImageWithDiagnostics(string $path): array
    {
        $this->assertConfigured();
        return $this->analyzeImage($this->validatedImagePath($path, false));
    }

    private function assertConfigured(): void
    {
        if (trim((string) env('GEMINI_API_KEY', '')) === '') {
            throw new RuntimeException('GEMINI_KEY_MISSING');
        }
    }

    private function validatedUpload(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Upload a captured ID image.');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        if ($tmp === '' || !is_uploaded_file($tmp) || $size <= 0 || $size > self::MAX_BYTES) {
            throw new InvalidArgumentException('The captured ID image is too large or invalid.');
        }
        return $this->validatedImagePath($tmp, true);
    }

    private function validatedImagePath(string $path, bool $requireUpload): array
    {
        if ($path === '' || !is_file($path)) {
            throw new InvalidArgumentException('The captured ID image is invalid.');
        }
        if ($requireUpload && !is_uploaded_file($path)) {
            throw new InvalidArgumentException('The captured ID image is invalid.');
        }
        $size = filesize($path);
        if ($size === false || $size <= 0 || $size > self::MAX_BYTES) {
            throw new InvalidArgumentException('The captured ID image is too large or invalid.');
        }
        $info = @getimagesize($path);
        if (!is_array($info) || empty($info['mime']) || !in_array((string) $info['mime'], self::ALLOWED_MIME, true)) {
            throw new InvalidArgumentException('The captured file must be a JPEG, PNG, or WEBP image.');
        }
        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);
        if ($width < 240 || $height < 120 || $width > 5000 || $height > 5000) {
            throw new InvalidArgumentException('The captured ID image dimensions are not usable.');
        }
        $bytes = file_get_contents($path);
        if ($bytes === false || $bytes === '') {
            throw new InvalidArgumentException('Unable to read captured ID image.');
        }
        return [
            'mime' => (string) $info['mime'],
            'base64' => base64_encode($bytes),
            'data_url' => 'data:' . (string) $info['mime'] . ';base64,' . base64_encode($bytes),
            'path' => $path,
            'width' => $width,
            'height' => $height,
            'bytes' => $size,
            'sha256' => hash('sha256', $bytes),
        ];
    }

    private function analyzeImage(array $image): array
    {
        $started = microtime(true);
        $diagnostics = [
            'timestamp' => gmdate('c'),
            'stage' => 'CAPTURE',
            'provider' => 'GEMINI',
            'model' => $this->model(),
            'image' => [
                'width' => $image['width'] ?? null,
                'height' => $image['height'] ?? null,
                'crop_width' => $image['width'] ?? null,
                'crop_height' => $image['height'] ?? null,
                'sha256' => $image['sha256'] ?? null,
            ],
            'quality' => $this->imageQualityMetrics((string) ($image['path'] ?? '')),
        ];
        try {
            $diagnostics['stage'] = 'AI_REQUEST';
            $raw = $this->requestVision($image);
            $diagnostics['stage'] = 'AI_RESPONSE';
            $sanitized = $this->sanitizeResultWithDiagnostics($raw);
            $diagnostics = array_replace_recursive($diagnostics, $sanitized['diagnostics']);
            $diagnostics['latency_ms'] = (int) round((microtime(true) - $started) * 1000);
            $diagnostics['stage'] = $diagnostics['failure_stage'] ?? 'SUCCESS';
            return ['result' => $sanitized['result'], 'diagnostics' => $diagnostics];
        } catch (Throwable $e) {
            $diagnostics['latency_ms'] = (int) round((microtime(true) - $started) * 1000);
            $diagnostics['failure_stage'] = $this->safeFailureStage($e->getMessage());
            $diagnostics['gemini_error'] = $this->safeGeminiErrorDetails($e->getMessage());
            throw $e;
        }
    }

    private function requestVision(array $image): array
    {
        $response = $this->postJson($this->geminiEndpoint(), $this->geminiPayload($image));
        $text = $this->extractOutputText($response);
        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('GEMINI_RESPONSE_INVALID');
        }
        return $decoded;
    }

    private function geminiEndpoint(): string
    {
        $model = trim((string) env('GEMINI_VISITOR_ID_MODEL', 'gemini-3.6-flash'));
        if ($model === '') {
            $model = 'gemini-3.6-flash';
        }
        $modelPath = str_starts_with($model, 'models/') ? $model : 'models/' . rawurlencode($model);
        return 'https://generativelanguage.googleapis.com/v1beta/' . $modelPath . ':generateContent?key=' . rawurlencode(trim((string) env('GEMINI_API_KEY', '')));
    }

    private function geminiPayload(array $image): array
    {
        return [
            'contents' => [[
                'role' => 'user',
                'parts' => [
                    ['text' => $this->instructions()],
                    ['inline_data' => [
                        'mime_type' => (string) ($image['mime'] ?? 'image/jpeg'),
                        'data' => (string) ($image['base64'] ?? ''),
                    ]],
                ],
            ]],
            'generationConfig' => [
                'temperature' => 0,
                'response_mime_type' => 'application/json',
                'response_schema' => $this->schema(),
            ],
        ];
    }

    private function postJson(string $url, array $payload): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) {
            throw new RuntimeException('Unable to encode AI request.');
        }
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        $timeout = max(5, (int) env('GEMINI_VISITOR_ID_TIMEOUT_SECONDS', 30));
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

    private function geminiError(string $raw, int $status, string $transportError, string $url, array $payload): string
    {
        $category = 'GEMINI_REQUEST_FAILED';
        if ($transportError !== '') {
            $category = 'GEMINI_TRANSPORT_ERROR';
        } elseif ($status === 400) {
            $category = 'GEMINI_HTTP_400';
        } elseif ($status === 401 || $status === 403) {
            $category = 'GEMINI_KEY_INVALID';
        } elseif ($status === 404) {
            $category = 'GEMINI_MODEL_INVALID';
        } elseif ($status === 408 || $status === 504) {
            $category = 'GEMINI_TIMEOUT';
        } elseif ($status === 429) {
            $category = 'GEMINI_QUOTA_OR_RATE_LIMIT';
        } elseif ($status >= 500) {
            $category = 'GEMINI_SERVICE_UNAVAILABLE';
        } elseif ($status > 0) {
            $category = 'GEMINI_HTTP_' . $status;
        }
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

    private function safeGeminiErrorDetails(string $message): ?array
    {
        $jsonStart = strpos($message, '{');
        if ($jsonStart === false) {
            return null;
        }
        $decoded = json_decode(substr($message, $jsonStart), true);
        return is_array($decoded) ? $decoded : null;
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

    private function sanitizeResult(array $result): array
    {
        return $this->sanitizeResultWithDiagnostics($result)['result'];
    }

    private function sanitizeResultWithDiagnostics(array $result): array
    {
        $fullName = $this->sanitizeName($result['full_name'] ?? null);
        $nameReason = $this->nameValidationReason($result['full_name'] ?? null);
        $idType = $this->sanitizeIdType($result['id_type'] ?? null);
        $last4 = $this->sanitizeLast4($result['id_last4'] ?? null);
        $documentDetected = (bool) ($result['document_detected'] ?? false);
        $needsReview = (bool) ($result['needs_review'] ?? true);
        if ($fullName === null || $idType === null) {
            $needsReview = true;
        }
        $sanitized = [
            'document_detected' => $documentDetected,
            'full_name' => $fullName,
            'id_type' => $idType,
            'id_last4' => $last4,
            'needs_review' => $needsReview,
        ];
        $failureStage = 'SUCCESS';
        if (!$documentDetected) {
            $failureStage = 'AI_NO_DOCUMENT';
        } elseif ($nameReason !== 'VALID') {
            $failureStage = $nameReason === 'MATCHED_FIELD_LABEL' ? 'AI_NAME_LABEL_ERROR' : 'POST_VALIDATION_REJECTED_NAME';
        } elseif ($idType === null) {
            $failureStage = 'AI_ID_TYPE_MISSING';
        } elseif ($last4 === null && isset($result['id_last4']) && trim((string) $result['id_last4']) !== '') {
            $failureStage = 'POST_VALIDATION_REJECTED_LAST4';
        } elseif ($needsReview) {
            $failureStage = 'POST_VALIDATE';
        }
        return [
            'result' => $sanitized,
            'diagnostics' => [
                'failure_stage' => $failureStage,
                'ai_response' => [
                    'document_detected' => $documentDetected,
                    'full_name_present' => $fullName !== null,
                    'full_name_rejected_as_label' => $nameReason === 'MATCHED_FIELD_LABEL',
                    'name_validation_reason' => $nameReason,
                    'id_type_present' => $idType !== null,
                    'id_type_valid' => $idType !== null,
                    'raw_id_type' => is_string($result['id_type'] ?? null) ? strtoupper(trim((string) $result['id_type'])) : null,
                    'mapped_id_type' => $idType,
                    'last4_present' => $last4 !== null,
                    'needs_review' => $needsReview,
                ],
            ],
        ];
    }

    private function sanitizeName(mixed $value): ?string
    {
        $name = trim((string) $value);
        if ($this->nameValidationReason($value) !== 'VALID') {
            return null;
        }
        return preg_replace('/\s+/', ' ', $name);
    }

    private function nameValidationReason(mixed $value): string
    {
        $name = trim((string) $value);
        if ($name === '') {
            return 'EMPTY';
        }
        if (strlen($name) > 160) {
            return 'TOO_LONG';
        }
        if (strlen($name) < 5) {
            return 'TOO_SHORT';
        }
        if ($this->isForbiddenNameLabel($name)) {
            return 'MATCHED_FIELD_LABEL';
        }
        $nonNameReason = $this->obviousNonNameReason($name);
        return $nonNameReason ?? 'VALID';
    }

    public function isForbiddenNameLabel(string $value): bool
    {
        $normalized = $this->normalizeLabelText($value);
        if ($normalized === '') {
            return false;
        }
        if (in_array($normalized, self::FORBIDDEN_NAME_LABELS, true)) {
            return true;
        }
        $tokens = preg_split('/\s+/', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($tokens) < 2) {
            return false;
        }
        $labelTokens = 0;
        foreach ($tokens as $token) {
            if (isset(self::LABEL_TOKENS[$token])) {
                $labelTokens++;
            }
        }
        return $labelTokens >= 2 && ($labelTokens / count($tokens)) >= 0.75;
    }

    private function normalizeLabelText(string $value): string
    {
        $normalized = strtoupper($value);
        $normalized = preg_replace('/[^A-Z]+/', ' ', $normalized) ?? '';
        return trim(preg_replace('/\s+/', ' ', $normalized) ?? '');
    }

    private function isObviousNonName(string $value): bool
    {
        return $this->obviousNonNameReason($value) !== null;
    }

    private function obviousNonNameReason(string $value): ?string
    {
        $normalized = strtoupper(trim(preg_replace('/\s+/', ' ', $value) ?? ''));
        if ($normalized === '') {
            return 'EMPTY';
        }
        if (preg_match('/\b(REPUBLIC OF THE PHILIPPINES|DEPARTMENT OF|LAND TRANSPORTATION OFFICE|PROFESSIONAL REGULATION COMMISSION|PHILIPPINE IDENTIFICATION|PASSPORT|DRIVER.?S LICENSE|IDENTIFICATION CARD|NATIONAL ID|COMPANY ID|SCHOOL ID)\b/i', $normalized)) {
            return preg_match('/\b(REPUBLIC OF THE PHILIPPINES|DEPARTMENT OF|LAND TRANSPORTATION OFFICE|PROFESSIONAL REGULATION COMMISSION)\b/i', $normalized) ? 'MATCHED_AGENCY_NAME' : 'MATCHED_DOCUMENT_TITLE';
        }
        if (preg_match('/\b(ADDRESS|DATE OF BIRTH|BIRTH DATE|SEX|NATIONALITY|SIGNATURE|VALID UNTIL|EXPIRY|LICENSE NO|ID NO|CARD NO|DOCUMENT NO)\b/i', $normalized)) {
            return 'MATCHED_DOCUMENT_TITLE';
        }
        if (preg_match('/\d{4}-\d{2}-\d{2}|\d{1,2}\/\d{1,2}\/\d{2,4}/', $normalized)) {
            return 'DATE_VALUE';
        }
        if (preg_match('/^[A-Z0-9\s-]+$/', $normalized) && preg_match('/\d/', $normalized) && !preg_match('/[AEIOU]/', $normalized)) {
            return 'NUMERIC_OR_ID_VALUE';
        }
        return null;
    }

    private function sanitizeIdType(mixed $value): ?string
    {
        $type = strtoupper(trim((string) $value));
        if ($type === '') {
            return null;
        }
        if ($type === 'NATIONAL_ID' || $type === 'PROFESSIONAL_ID') {
            $type = 'GOVERNMENT_ID';
        }
        return in_array($type, $this->allowedIdTypes(), true) ? $type : null;
    }

    private function sanitizeLast4(mixed $value): ?string
    {
        $last4 = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) $value));
        if ($last4 === '') {
            return null;
        }
        return preg_match('/^[A-Z0-9]{4}$/', $last4) ? $last4 : null;
    }

    private function allowedIdTypes(): array
    {
        return array_values(array_filter($this->allowedIdTypes, static fn (string $type): bool => $type !== 'NONE'));
    }

    private function model(): string
    {
        $model = trim((string) env('GEMINI_VISITOR_ID_MODEL', 'gemini-3.6-flash'));
        return $model === '' ? 'gemini-3.6-flash' : $model;
    }

    private function diagnosticsEnabled(): bool
    {
        return env('VISITOR_ID_DIAGNOSTICS', false) === true;
    }

    private function imageQualityMetrics(string $path): array
    {
        $base = [
            'available' => false,
            'average_brightness' => null,
            'contrast' => null,
            'sharpness' => null,
            'underexposed_ratio' => null,
            'overexposed_ratio' => null,
            'brightness_rating' => 'UNKNOWN',
            'contrast_rating' => 'UNKNOWN',
            'sharpness_rating' => 'UNKNOWN',
            'unavailable_reason' => 'PHP_IMAGE_EXTENSION_UNAVAILABLE',
        ];
        if ($path === '' || !is_file($path) || !function_exists('imagecreatetruecolor')) {
            return $base;
        }
        $info = @getimagesize($path);
        $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
        $source = match ($mime) {
            'image/jpeg' => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($path) : false,
            'image/png' => function_exists('imagecreatefrompng') ? @imagecreatefrompng($path) : false,
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };
        if (!$source) {
            return $base;
        }
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $width = min(160, max(1, $sourceWidth));
        $height = max(1, (int) round(($sourceHeight / max(1, $sourceWidth)) * $width));
        $sample = imagecreatetruecolor($width, $height);
        imagecopyresampled($sample, $source, 0, 0, 0, 0, $width, $height, $sourceWidth, $sourceHeight);
        imagedestroy($source);

        $luma = [];
        $sum = 0.0;
        $under = 0;
        $over = 0;
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgb = imagecolorat($sample, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $v = 0.299 * $r + 0.587 * $g + 0.114 * $b;
                $luma[$y][$x] = $v;
                $sum += $v;
                if ($v < 18) $under++;
                if ($v > 238) $over++;
            }
        }
        $pixels = max(1, $width * $height);
        $brightness = $sum / $pixels;
        $variance = 0.0;
        $edge = 0.0;
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $delta = $luma[$y][$x] - $brightness;
                $variance += $delta * $delta;
                if ($x > 0) $edge += abs($luma[$y][$x] - $luma[$y][$x - 1]);
                if ($y > 0) $edge += abs($luma[$y][$x] - $luma[$y - 1][$x]);
            }
        }
        imagedestroy($sample);
        $contrast = sqrt($variance / $pixels);
        $sharpness = $edge / $pixels;
        return [
            'available' => true,
            'unavailable_reason' => null,
            'average_brightness' => round($brightness, 2),
            'contrast' => round($contrast, 2),
            'sharpness' => round($sharpness, 2),
            'underexposed_ratio' => round($under / $pixels, 4),
            'overexposed_ratio' => round($over / $pixels, 4),
            'brightness_rating' => $brightness < 55 ? 'LOW' : ($brightness > 215 ? 'HIGH' : 'GOOD'),
            'contrast_rating' => $contrast < 28 ? 'LOW' : 'GOOD',
            'sharpness_rating' => $sharpness < 7 ? 'LOW' : 'GOOD',
        ];
    }

    private function schema(): array
    {
        return [
            'type' => 'OBJECT',
            'required' => ['document_detected', 'full_name', 'id_type', 'id_last4', 'needs_review'],
            'properties' => [
                'document_detected' => ['type' => 'BOOLEAN'],
                'full_name' => ['type' => 'STRING', 'nullable' => true],
                'id_type' => ['type' => 'STRING', 'nullable' => true, 'enum' => $this->allowedIdTypes()],
                'id_last4' => ['type' => 'STRING', 'nullable' => true],
                'needs_review' => ['type' => 'BOOLEAN'],
            ],
        ];
    }

    private function instructions(): string
    {
        return implode("\n", [
            'Analyze only the supplied visitor ID image. Use the visual layout of the ID, not OCR text order alone.',
            'Extract only the actual card holder full name, the canonical ID type, and the last four characters of the relevant visible ID number when reliable.',
            'Do not extract address, birth date, sex, nationality, signature, photo biometrics, full ID number, or unrelated fields.',
            'Null is better than wrong: if you cannot confidently distinguish the actual person name from labels, return full_name=null and needs_review=true.',
            'Field labels are never names. Never return these labels by themselves: ' . implode(', ', self::FORBIDDEN_NAME_LABELS) . '.',
            'Driver license layout rule: strings such as LAST NAME FIRST NAME MIDDLE NAMES are labels. The real name value is usually printed near, beneath, or beside that label. If the nearby value is unreadable, return full_name=null.',
            'Passport layout rule: use the holder name value near Surname/Given names/Name of holder labels, not the label text or country/agency heading.',
            'Government/National/Professional ID layout rule: use the card holder name value near name labels. Do not return Republic of the Philippines, agency names, or document titles as the name.',
            'Company/School ID layout rule: use the employee/student/person name value, not school/company names, department labels, or ID card titles.',
            'ID type mapping: use only these id_type values when confident: ' . implode(', ', $this->allowedIdTypes()) . '. If the ID type is a Philippine National ID, PRC, or other government/professional ID, use GOVERNMENT_ID. If uncertain, use OTHER or null.',
            'ID last four rule: return only the final four alphanumeric characters of the relevant ID/license/passport/student/employee number when reasonably confident. Do not output the full ID number.',
            'Few-shot guidance: observed text "LAST NAME FIRST NAME MIDDLE NAMES" means field label, not person name. Correct extraction is the printed value aligned near that label, for example "JUAN DELA CRUZ"; if that value is missing or unclear, full_name=null.',
            'Return only the strict schema result. Do not return prose or reasoning.',
        ]);
    }
}
