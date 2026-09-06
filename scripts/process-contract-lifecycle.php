<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Contracts' . DIRECTORY_SEPARATOR . 'ContractService.php';

$args = array_slice($argv, 1);
if (in_array('--help', $args, true)) {
    usage();
    exit(0);
}

$dryRun = in_array('--dry-run', $args, true);
$date = date('Y-m-d');
foreach ($args as $index => $arg) {
    if ($arg === '--date' && isset($args[$index + 1])) {
        $date = (string) $args[$index + 1];
    } elseif (str_starts_with($arg, '--date=')) {
        $date = substr($arg, 7);
    }
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    fwrite(STDERR, "Invalid --date value. Use YYYY-MM-DD.\n");
    exit(1);
}

try {
    $service = new ContractService(Database::connection());
    $result = $service->processAutomaticLifecycle([
        'dry_run' => $dryRun,
        'today' => $date,
    ]);

    printf(
        "Contract lifecycle processing%s for %s complete.\nActivated: %d\nExpired: %d\nSkipped: %d\nFailed: %d\n",
        $dryRun ? ' dry-run' : '',
        $date,
        $result['activated'],
        $result['expired'],
        $result['skipped'],
        $result['failed']
    );
    foreach ($result['items'] as $item) {
        printf(
            "- [%s] %s #%d %s\n",
            strtoupper((string) $item['status']),
            (string) ($item['contract_number'] ?: 'contract'),
            (int) $item['contract_id'],
            (string) $item['message']
        );
    }
    exit((int) $result['failed'] > 0 ? 1 : 0);
} catch (Throwable $exception) {
    fwrite(STDERR, "Contract lifecycle processing failed.\n");
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}

function usage(): void
{
    echo "Process automatic Contract Management lifecycle transitions.\n\n";
    echo "Usage:\n";
    echo "  php scripts/process-contract-lifecycle.php\n";
    echo "  php scripts/process-contract-lifecycle.php --dry-run\n";
    echo "  php scripts/process-contract-lifecycle.php --dry-run --date 2026-09-07\n";
}
