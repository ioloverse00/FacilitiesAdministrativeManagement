<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';

try {
    $pdo = Database::connection();

    $connectionOk = $pdo->query('SELECT 1 AS connection_ok')->fetch();
    $database = $pdo->query('SELECT DATABASE() AS database_name')->fetch();
    $version = $pdo->query('SELECT VERSION() AS database_version')->fetch();

    if (($connectionOk['connection_ok'] ?? null) !== 1) {
        throw new RuntimeException('Database verification query returned an unexpected result.');
    }

    echo 'Database connection successful.' . PHP_EOL;
    echo 'Database: ' . ($database['database_name'] ?? 'unknown') . PHP_EOL;
    echo 'Server: ' . ($version['database_version'] ?? 'unknown') . PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Database connection verification failed.' . PHP_EOL);
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
