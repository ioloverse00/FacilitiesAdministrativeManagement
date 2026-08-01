<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';

date_default_timezone_set('Asia/Manila');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method !== 'GET') {
    respond(405, false, 'Method not allowed');
}

try {
    $pdo = Database::connection();

    $connection = $pdo->query('SELECT 1 AS connection_ok')->fetch();
    $database = $pdo->query('SELECT DATABASE() AS database_name')->fetch();
    $version = $pdo->query('SELECT VERSION() AS database_server')->fetch();

    $connectionOk = (int) ($connection['connection_ok'] ?? 0) === 1;

    if (!$connectionOk) {
        throw new RuntimeException('Database health query returned an unexpected result.');
    }

    respond(200, true, 'Health check passed.', [
        'application' => 'Facilities & Administrative Management',
        'environment' => (string) env('APP_ENV', 'local'),
        'php_version' => PHP_VERSION,
        'database' => (string) ($database['database_name'] ?? ''),
        'database_server' => (string) ($version['database_server'] ?? ''),
        'database_connection' => true,
        'server_time' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format(DateTimeInterface::ATOM),
    ]);
} catch (Throwable $exception) {
    $extra = [];

    if (env('APP_DEBUG', false) === true) {
        $extra['error'] = safeErrorMessage($exception);
    }

    error_log('Health check database failure: ' . $exception::class);

    respond(500, false, 'Database connection failed.', [], $extra);
}

/**
 * @param array<string, mixed> $data
 * @param array<string, mixed> $extra
 */
function respond(int $statusCode, bool $success, string $message, array $data = [], array $extra = []): never
{
    http_response_code($statusCode);

    $payload = [
        'success' => $success,
        'message' => $message,
        'data' => (object) $data,
    ];

    echo json_encode(
        array_merge($payload, $extra),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    exit;
}

function safeErrorMessage(Throwable $exception): string
{
    $message = $exception->getMessage();

    if ($message === '') {
        return 'Database connection failed. Check local database configuration.';
    }

    return preg_replace('/password\\s*=\\s*[^;\\s]+/i', 'password=[redacted]', $message)
        ?? 'Database connection failed. Check local database configuration.';
}
