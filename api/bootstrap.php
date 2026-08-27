<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'session.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'response.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Auth' . DIRECTORY_SEPARATOR . 'AuthenticatedUser.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Auth' . DIRECTORY_SEPARATOR . 'PersonaService.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Auth' . DIRECTORY_SEPARATOR . 'AuthService.php';

date_default_timezone_set('Asia/Manila');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

$allowedOrigin = (string) env('APP_ALLOWED_ORIGIN', '');
$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';

if ($allowedOrigin !== '' && $requestOrigin === $allowedOrigin) {
    header('Access-Control-Allow-Origin: ' . $allowedOrigin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Vary: Origin');
}

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }

    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(static function (Throwable $exception): void {
    $isLocalDebug = env('APP_ENV', 'production') === 'local' && env('APP_DEBUG', false) === true;

    if ($isLocalDebug) {
        error_log(sprintf(
            'API error: %s: %s in %s:%d',
            $exception::class,
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine()
        ));

        $previous = $exception->getPrevious();
        if ($previous instanceof Throwable) {
            error_log(sprintf(
                'API previous error: %s: %s in %s:%d',
                $previous::class,
                $previous->getMessage(),
                $previous->getFile(),
                $previous->getLine()
            ));
        }
    } elseif ($exception instanceof DatabaseConfigurationException || $exception instanceof DatabaseConnectionException) {
        error_log(sprintf(
            'API error: %s: %s in %s:%d',
            $exception::class,
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine()
        ));
    } else {
        error_log('API error: ' . $exception::class);
    }

    jsonResponse(false, 'An unexpected error occurred.', [], 500);
});

startAuthSession();

function requireFamPortalUser(array $user): void
{
    if (($user['persona']['is_fam_portal_allowed'] ?? false) !== true) {
        jsonResponse(false, 'FAM portal access is restricted to authorized administrative and operational personas.', [
            'access_denied_reason' => 'persona_not_fam_portal',
            'persona' => $user['persona']['code'] ?? PersonaService::UNAUTHORIZED_OR_UNRESOLVED,
        ], 403);
    }
}

