<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Visitors' . DIRECTORY_SEPARATOR . 'VisitorPolicy.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Visitors' . DIRECTORY_SEPARATOR . 'VisitorService.php';

function visitorService(): VisitorService { return new VisitorService(Database::connection()); }
function visitorId(): int { return idParam('id'); }
function visitorValidation(Throwable $e): void
{
    if ($e instanceof InvalidArgumentException) {
        $errors = json_decode($e->getMessage(), true);
        jsonResponse(false, 'Validation failed.', ['errors'=>is_array($errors) ? $errors : ['request'=>$e->getMessage()]], 422);
    }
    if ($e instanceof DomainException) jsonResponse(false, $e->getMessage(), [], 409);
    if ($e instanceof RuntimeException && $e->getMessage() === 'NOT_FOUND') jsonResponse(false, 'Visitor visit was not found.', [], 404);
    throw $e;
}
function visitorBody(): array { return readJsonBody(32768); }
