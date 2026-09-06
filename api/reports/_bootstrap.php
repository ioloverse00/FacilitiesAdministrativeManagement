<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Reports' . DIRECTORY_SEPARATOR . 'ReportsService.php';

function reportsService(): ReportsService
{
    return new ReportsService(Database::connection());
}

function reportsUser(): array
{
    return currentApiUser();
}

function reportsValidation(Throwable $e): void
{
    if ($e instanceof InvalidArgumentException) {
        jsonResponse(false, $e->getMessage(), [], 422);
    }
    throw $e;
}
