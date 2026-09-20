<?php

declare(strict_types=1);

final class StoragePath
{
    public static function root(): string
    {
        $configured = self::configuredRoot();
        if ($configured !== '') {
            if (!self::isAbsolutePath($configured)) {
                throw new RuntimeException('FAM_STORAGE_PATH must be an absolute path.');
            }
            return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $configured), DIRECTORY_SEPARATOR);
        }

        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage';
    }

    public static function resolve(string $relativePath): string
    {
        return self::root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::normalizeRelativePath($relativePath));
    }

    public static function resolveWithin(string $category, string $relativePath): string
    {
        $category = self::normalizeCategory($category);
        $normalized = self::normalizeRelativePath($relativePath);
        if ($normalized !== $category && !str_starts_with($normalized, $category . '/')) {
            throw new InvalidArgumentException('Storage path is outside the expected category.');
        }

        return self::root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
    }

    public static function resolveExistingWithin(string $category, string $relativePath): ?string
    {
        try {
            $path = self::resolveWithin($category, $relativePath);
        } catch (InvalidArgumentException | RuntimeException) {
            return null;
        }

        $base = realpath(self::root() . DIRECTORY_SEPARATOR . self::normalizeCategory($category));
        $absolute = realpath($path);
        if ($base === false || $absolute === false || !is_file($absolute)) {
            return null;
        }

        $basePrefix = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return str_starts_with($absolute, $basePrefix) ? $absolute : null;
    }

    private static function configuredRoot(): string
    {
        $value = function_exists('env') ? env('FAM_STORAGE_PATH', '') : getenv('FAM_STORAGE_PATH');
        return trim((string) ($value === false ? '' : $value));
    }

    private static function normalizeRelativePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '' || str_contains($path, "\0") || self::isAbsolutePath($path)) {
            throw new InvalidArgumentException('Storage path must be relative.');
        }

        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new InvalidArgumentException('Storage path contains an invalid segment.');
            }
            $parts[] = $part;
        }

        return implode('/', $parts);
    }

    private static function normalizeCategory(string $category): string
    {
        $category = trim(str_replace('\\', '/', $category), '/');
        if ($category === '' || str_contains($category, '/') || $category === '.' || $category === '..') {
            throw new InvalidArgumentException('Storage category is invalid.');
        }
        return $category;
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1;
    }
}
