<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Visitors' . DIRECTORY_SEPARATOR . 'VisitorService.php';

function settingsVisitorService(): VisitorService
{
    return new VisitorService(Database::connection());
}

function requireVisitorBlacklistAdmin(array $user): void
{
    requirePermission($user, 'administration.view');
    foreach (($user['roles'] ?? []) as $role) {
        if (($role['code'] ?? '') === 'FAM_SUPER_ADMIN') {
            return;
        }
    }
    jsonResponse(false, 'Only FAM Super Admin can manage the visitor blacklist.', [], 403);
}

function settingsBody(): array
{
    return readJsonBody(32768);
}

function settingsValidation(Throwable $e): void
{
    if ($e instanceof InvalidArgumentException) {
        $errors = json_decode($e->getMessage(), true);
        jsonResponse(false, 'Validation failed.', ['errors' => is_array($errors) ? $errors : ['request' => $e->getMessage()]], 422);
    }
    if ($e instanceof DomainException) {
        jsonResponse(false, $e->getMessage(), [], 409);
    }
    throw $e;
}
