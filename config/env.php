<?php

declare(strict_types=1);

final class Env
{
    private static bool $loaded = false;

    public static function load(?string $path = null): void
    {
        if (self::$loaded) {
            return;
        }

        $envPath = $path ?? dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';

        if (!is_file($envPath) || !is_readable($envPath)) {
            self::$loaded = true;
            return;
        }

        $lines = file($envPath, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            self::$loaded = true;
            return;
        }

        foreach ($lines as $line) {
            self::loadLine($line);
        }

        self::$loaded = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === null) {
            return $default;
        }

        if (is_string($value)) {
            return self::normalize($value);
        }

        return $value;
    }

    private static function loadLine(string $line): void
    {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            return;
        }

        if (!str_contains($line, '=')) {
            return;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);

        if ($key === '' || self::hasExistingValue($key)) {
            return;
        }

        $value = self::stripInlineComment(trim($value));
        $value = self::unquote($value);

        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv($key . '=' . $value);
    }

    private static function hasExistingValue(string $key): bool
    {
        foreach ([$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)] as $value) {
            if ($value !== false && $value !== null && trim((string) $value) !== '') {
                return true;
            }
        }

        return false;
    }

    private static function stripInlineComment(string $value): string
    {
        if ($value === '' || $value[0] === '"' || $value[0] === "'") {
            return $value;
        }

        $commentPosition = strpos($value, ' #');

        if ($commentPosition === false) {
            return $value;
        }

        return rtrim(substr($value, 0, $commentPosition));
    }

    private static function unquote(string $value): string
    {
        if (strlen($value) < 2) {
            return $value;
        }

        $first = $value[0];
        $last = $value[strlen($value) - 1];

        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            return substr($value, 1, -1);
        }

        return $value;
    }

    private static function normalize(string $value): mixed
    {
        $trimmed = trim($value);
        $lower = strtolower($trimmed);

        return match ($lower) {
            'true', '1', 'yes' => true,
            'false', '0', 'no' => false,
            default => $trimmed,
        };
    }
}

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        Env::load();

        return Env::get($key, $default);
    }
}
