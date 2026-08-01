<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'env.php';

function authSessionLifetime(): int
{
    $value = (int) env('AUTH_SESSION_LIFETIME', 7200);

    return $value > 0 ? $value : 7200;
}

function configureAuthSession(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    $secureCookie = isHttpsRequest() || env('AUTH_COOKIE_SECURE', false) === true;
    $lifetime = authSessionLifetime();

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', (string) $lifetime);
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', $secureCookie ? '1' : '0');
    ini_set('session.cookie_samesite', 'Lax');

    session_name('ISMERS_FAM_SESSION');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secureCookie,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function startAuthSession(): void
{
    configureAuthSession();

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function clearAuthSession(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        startAuthSession();
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'] ?: '/',
            'domain' => $params['domain'] ?: '',
            'secure' => (bool) $params['secure'],
            'httponly' => true,
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }

    session_destroy();
}

function ensureCsrfToken(): string
{
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function requireCsrfToken(): void
{
    $expected = $_SESSION['csrf_token'] ?? '';
    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if (!is_string($expected) || !is_string($provided) || $expected === '' || !hash_equals($expected, $provided)) {
        jsonResponse(false, 'Invalid CSRF token.', [], 419);
    }
}

function isHttpsRequest(): bool
{
    $https = $_SERVER['HTTPS'] ?? '';
    $forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';

    return $https === 'on' || $https === '1' || strtolower((string) $forwardedProto) === 'https';
}
