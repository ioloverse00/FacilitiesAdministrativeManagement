<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Mail' . DIRECTORY_SEPARATOR . 'MailService.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Visitors' . DIRECTORY_SEPARATOR . 'VisitorOtpService.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Visitors' . DIRECTORY_SEPARATOR . 'PublicVisitorRegistrationService.php';

header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; frame-ancestors 'self'; base-uri 'self'");

function publicVisitorService(): PublicVisitorRegistrationService
{
    $pdo = Database::connection();
    return new PublicVisitorRegistrationService($pdo, new VisitorOtpService($pdo, new MailService()));
}

function publicVisitorBody(): array
{
    return readJsonBody(32768);
}

function publicVisitorHandle(Throwable $e): void
{
    if ($e instanceof InvalidArgumentException) {
        $errors = json_decode($e->getMessage(), true);
        jsonResponse(false, 'Validation failed.', ['errors' => is_array($errors) ? $errors : ['request' => $e->getMessage()]], 422);
    }
    if ($e instanceof DomainException) {
        $code = $e->getCode();
        jsonResponse(false, $e->getMessage(), [], in_array($code, [400, 401, 404, 409, 422, 429], true) ? $code : 409);
    }
    throw $e;
}

