<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'env.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Visitors' . DIRECTORY_SEPARATOR . 'VisitorService.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Visitors' . DIRECTORY_SEPARATOR . 'VisitorIdAnalysisService.php';

$fixtureDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'visitor-ids';
$manifestPath = $fixtureDir . DIRECTORY_SEPARATOR . 'fixtures.json';
$options = cliOptions($argv);
$repeat = max(1, min(10, (int) ($options['repeat'] ?? 5)));
$fixtureFilter = isset($options['fixture']) ? (string) $options['fixture'] : '';

if (!is_file($manifestPath)) {
    fwrite(STDERR, "Missing fixture manifest: {$manifestPath}" . PHP_EOL);
    exit(1);
}

$manifest = json_decode((string) file_get_contents($manifestPath), true);
if (!is_array($manifest)) {
    fwrite(STDERR, 'Fixture manifest is invalid JSON.' . PHP_EOL);
    exit(1);
}

$service = new VisitorIdAnalysisService(VisitorService::ID_TYPES);
$totals = [
    'fixtures' => 0,
    'trials' => 0,
    'id_type_pass' => 0,
    'full_name_pass' => 0,
    'last4_pass' => 0,
    'overall_pass' => 0,
    'label_name_failures' => 0,
    'request_failures' => 0,
    'skipped_missing_image' => 0,
];

echo 'Visitor ID Extraction Fixture Evaluation' . PHP_EOL;
echo str_repeat('=', 48) . PHP_EOL;

foreach ($manifest as $fixture) {
    if (!is_array($fixture)) {
        continue;
    }
    $name = (string) ($fixture['fixture'] ?? 'unnamed_fixture');
    if ($fixtureFilter !== '' && $fixtureFilter !== $name) {
        continue;
    }
    $image = (string) ($fixture['image'] ?? '');
    $expected = is_array($fixture['expected'] ?? null) ? $fixture['expected'] : [];
    $imagePath = $fixtureDir . DIRECTORY_SEPARATOR . $image;

    if ($image === '' || !is_file($imagePath)) {
        $totals['skipped_missing_image']++;
        echo "[SKIP] {$name} - missing image {$image}" . PHP_EOL;
        continue;
    }

    $totals['fixtures']++;
    $outputs = [];
    echo "Fixture: {$name}" . PHP_EOL;
    echo "Trials: {$repeat}" . PHP_EOL;
    for ($trial = 1; $trial <= $repeat; $trial++) {
        $totals['trials']++;
        try {
            $analysis = $service->analyzeLocalImageWithDiagnostics($imagePath);
            $actual = $analysis['result'] ?? [];
            $diagnostics = $analysis['diagnostics'] ?? [];
        } catch (Throwable $e) {
            $actual = [
                'document_detected' => false,
                'full_name' => null,
                'id_type' => null,
                'id_last4' => null,
                'needs_review' => true,
            ];
            $diagnostics = localImageDiagnostics($imagePath) + [
                'failure_stage' => safeFailureStage($e->getMessage()),
                'latency_ms' => null,
                'error' => $e::class,
                'gemini_error' => safeGeminiErrorDetails($e->getMessage()),
            ];
        }

        $idTypePass = normalizeScalar($actual['id_type'] ?? null) === normalizeScalar($expected['id_type'] ?? null);
        $namePass = normalizeName($actual['full_name'] ?? null) === normalizeName($expected['full_name'] ?? null);
        $last4Pass = normalizeScalar($actual['id_last4'] ?? null) === normalizeScalar($expected['id_last4'] ?? null);
        $overallPass = $idTypePass && $namePass && $last4Pass;
        $hash = (string) ($diagnostics['image']['sha256'] ?? '');
        $outputKey = json_encode([
            'id_type' => $actual['id_type'] ?? null,
            'full_name' => normalizeName($actual['full_name'] ?? null),
            'id_last4' => $actual['id_last4'] ?? null,
            'needs_review' => $actual['needs_review'] ?? null,
        ]);
        if (is_string($outputKey)) $outputs[$outputKey] = ($outputs[$outputKey] ?? 0) + 1;

        if ($idTypePass) $totals['id_type_pass']++;
        if ($namePass) $totals['full_name_pass']++;
        if ($last4Pass) $totals['last4_pass']++;
        if ($overallPass) $totals['overall_pass']++;
        if (isset($actual['full_name']) && is_string($actual['full_name']) && $service->isForbiddenNameLabel($actual['full_name'])) {
            $totals['label_name_failures']++;
        }
        $failureStage = (string) ($diagnostics['failure_stage'] ?? '');
        if (str_starts_with($failureStage, 'GEMINI_') || $failureStage === 'AI_REQUEST_FAILED') {
            $totals['request_failures']++;
        }

        echo sprintf('  Trial %d [%s] hash=%s latency=%sms stage=%s needs_review=%s', $trial, $overallPass ? 'PASS' : 'FAIL', shortHash($hash), printable($diagnostics['latency_ms'] ?? null), printable($failureStage !== '' ? $failureStage : 'UNKNOWN'), printableBool($actual['needs_review'] ?? null)) . PHP_EOL;
        echo sprintf('    ID Type: expected=%s actual=%s %s', printable($expected['id_type'] ?? null), printable($actual['id_type'] ?? null), $idTypePass ? 'PASS' : 'FAIL') . PHP_EOL;
        echo sprintf('    Name:    expected=%s actual=%s %s', printable($expected['full_name'] ?? null), printable($actual['full_name'] ?? null), $namePass ? 'PASS' : 'FAIL') . PHP_EOL;
        echo sprintf('    Last 4:  expected=%s actual=%s %s', printable($expected['id_last4'] ?? null), printable($actual['id_last4'] ?? null), $last4Pass ? 'PASS' : 'FAIL') . PHP_EOL;
        echo sprintf('    Image:   dimensions=%sx%s sha256=%s', printable($diagnostics['image']['width'] ?? null), printable($diagnostics['image']['height'] ?? null), shortHash((string) ($diagnostics['image']['sha256'] ?? ''))) . PHP_EOL;
        echo sprintf('    Quality: brightness=%s contrast=%s sharpness=%s available=%s reason=%s', printable($diagnostics['quality']['brightness_rating'] ?? null), printable($diagnostics['quality']['contrast_rating'] ?? null), printable($diagnostics['quality']['sharpness_rating'] ?? null), printableBool($diagnostics['quality']['available'] ?? null), printable($diagnostics['quality']['unavailable_reason'] ?? null)) . PHP_EOL;
        if (is_array($diagnostics['gemini_error'] ?? null)) {
            $gemini = $diagnostics['gemini_error'];
            echo sprintf('    Gemini: http=%s status=%s code=%s model=%s endpoint=%s schema=%s message=%s', printable($gemini['http_status'] ?? null), printable($gemini['gemini_status'] ?? null), printable($gemini['gemini_code'] ?? null), printable($gemini['model'] ?? null), printable($gemini['endpoint_path'] ?? null), printableBool($gemini['structured_schema_included'] ?? null), printable($gemini['message'] ?? null)) . PHP_EOL;
        }
    }
    $consistent = $outputs ? max($outputs) : 0;
    echo sprintf('  Same-image consistency: %d / %d', $consistent, $repeat) . PHP_EOL;
}

echo str_repeat('-', 48) . PHP_EOL;
echo sprintf('Fixtures evaluated: %d', $totals['fixtures']) . PHP_EOL;
echo sprintf('Trials evaluated: %d', $totals['trials']) . PHP_EOL;
echo sprintf('Skipped missing images: %d', $totals['skipped_missing_image']) . PHP_EOL;
echo sprintf('ID Type accuracy: %d / %d', $totals['id_type_pass'], $totals['trials']) . PHP_EOL;
echo sprintf('Full Name accuracy: %d / %d', $totals['full_name_pass'], $totals['trials']) . PHP_EOL;
echo sprintf('ID Last 4 accuracy: %d / %d', $totals['last4_pass'], $totals['trials']) . PHP_EOL;
echo sprintf('Overall fixture pass rate: %d / %d', $totals['overall_pass'], $totals['trials']) . PHP_EOL;
echo sprintf('Field-label-as-name failures: %d / %d', $totals['label_name_failures'], $totals['trials']) . PHP_EOL;
echo 'Likely root cause: ' . likelyRootCause($totals) . PHP_EOL;

if ($totals['fixtures'] === 0) {
    echo 'No fixture images were evaluated. Add safe synthetic/redacted images before measuring accuracy.' . PHP_EOL;
    exit(0);
}

exit($totals['overall_pass'] === $totals['trials'] ? 0 : 1);

function normalizeName(mixed $value): string
{
    if ($value === null) {
        return '';
    }
    $normalized = strtoupper((string) $value);
    $normalized = preg_replace('/[^A-Z ]+/', ' ', $normalized) ?? '';
    return trim(preg_replace('/\s+/', ' ', $normalized) ?? '');
}

function normalizeScalar(mixed $value): string
{
    if ($value === null) {
        return '';
    }
    return strtoupper(trim((string) $value));
}

function printable(mixed $value): string
{
    if ($value === null || $value === '') {
        return 'null';
    }
    return (string) $value;
}

function printableBool(mixed $value): string
{
    if ($value === null) {
        return 'null';
    }
    return $value ? 'yes' : 'no';
}

function shortHash(string $value): string
{
    return $value === '' ? 'null' : substr($value, 0, 12);
}

function cliOptions(array $argv): array
{
    $options = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }
        $parts = explode('=', substr($arg, 2), 2);
        $options[$parts[0]] = $parts[1] ?? true;
    }
    return $options;
}

function safeFailureStage(string $message): string
{
    $category = strtok(trim($message), ' ');
    if (is_string($category) && $category !== '' && preg_match('/^[A-Z0-9_]+$/', $category)) {
        return $category;
    }
    return 'AI_REQUEST_FAILED';
}

function safeGeminiErrorDetails(string $message): ?array
{
    $jsonStart = strpos($message, '{');
    if ($jsonStart === false) {
        return null;
    }
    $decoded = json_decode(substr($message, $jsonStart), true);
    return is_array($decoded) ? $decoded : null;
}

function localImageDiagnostics(string $path): array
{
    $image = [
        'width' => null,
        'height' => null,
        'crop_width' => null,
        'crop_height' => null,
        'sha256' => null,
    ];
    if (is_file($path)) {
        $info = @getimagesize($path);
        if (is_array($info)) {
            $image['width'] = (int) ($info[0] ?? 0);
            $image['height'] = (int) ($info[1] ?? 0);
            $image['crop_width'] = $image['width'];
            $image['crop_height'] = $image['height'];
        }
        $bytes = file_get_contents($path);
        if (is_string($bytes) && $bytes !== '') {
            $image['sha256'] = hash('sha256', $bytes);
        }
    }
    return ['image' => $image, 'quality' => localImageQualityMetrics($path)];
}

function localImageQualityMetrics(string $path): array
{
    $base = [
        'available' => false,
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
    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            $rgb = imagecolorat($sample, $x, $y);
            $v = 0.299 * (($rgb >> 16) & 0xFF) + 0.587 * (($rgb >> 8) & 0xFF) + 0.114 * ($rgb & 0xFF);
            $luma[$y][$x] = $v;
            $sum += $v;
        }
    }
    $pixels = max(1, $width * $height);
    $brightness = $sum / $pixels;
    $variance = 0.0;
    $edge = 0.0;
    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            $variance += ($luma[$y][$x] - $brightness) ** 2;
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
        'brightness_rating' => $brightness < 55 ? 'LOW' : ($brightness > 215 ? 'HIGH' : 'GOOD'),
        'contrast_rating' => $contrast < 28 ? 'LOW' : 'GOOD',
        'sharpness_rating' => $sharpness < 7 ? 'LOW' : 'GOOD',
    ];
}

function likelyRootCause(array $totals): string
{
    if (($totals['trials'] ?? 0) === 0) {
        return 'INSUFFICIENT_DATA';
    }
    if (($totals['label_name_failures'] ?? 0) > 0) {
        return 'LIKELY_PROMPT_OR_VISUAL_EXTRACTION_RULE';
    }
    if (($totals['request_failures'] ?? 0) === ($totals['trials'] ?? 0)) {
        return 'AI_PROVIDER_CONFIGURATION_OR_REQUEST_FAILURE';
    }
    if (($totals['overall_pass'] ?? 0) < ($totals['trials'] ?? 0)) {
        return 'LIKELY_AI_VARIABILITY_OR_POST_VALIDATION_NEEDS_REVIEW';
    }
    return 'NO_FAILURE_OBSERVED';
}
