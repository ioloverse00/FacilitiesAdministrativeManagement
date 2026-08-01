<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'env.php';

final class DatabaseConfigurationException extends RuntimeException
{
}

final class DatabaseConnectionException extends RuntimeException
{
}

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        Env::load();

        $config = self::configuration();
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $config['database'],
            $config['charset']
        );

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];

        if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
            $options[PDO::MYSQL_ATTR_INIT_COMMAND] = 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci';
        }

        try {
            self::$connection = new PDO(
                $dsn,
                $config['username'],
                $config['password'],
                $options
            );

            self::configureSession(self::$connection);

            return self::$connection;
        } catch (PDOException $exception) {
            self::logConnectionFailure($exception);

            $message = self::debugEnabled()
                ? 'Database connection failed. Check local database configuration.'
                : 'Database connection failed.';

            throw new DatabaseConnectionException($message, 0, $exception);
        }
    }

    /**
     * @return array{host:string,port:int,database:string,username:string,password:string,charset:string}
     */
    private static function configuration(): array
    {
        $host = self::stringValue('DB_HOST');
        $port = self::stringValue('DB_PORT');
        $database = self::stringValue('DB_DATABASE');
        $username = self::stringValue('DB_USERNAME');
        $password = self::stringValue('DB_PASSWORD', '');
        $charset = self::stringValue('DB_CHARSET', 'utf8mb4');

        self::requireValue('DB_HOST', $host);
        self::requireValue('DB_PORT', $port);
        self::requireValue('DB_DATABASE', $database);
        self::requireValue('DB_USERNAME', $username);

        if (!ctype_digit($port)) {
            throw new DatabaseConfigurationException('Invalid database configuration: DB_PORT must be numeric.');
        }

        $portNumber = (int) $port;

        if ($portNumber < 1 || $portNumber > 65535) {
            throw new DatabaseConfigurationException('Invalid database configuration: DB_PORT is outside the valid TCP port range.');
        }

        if ($charset === '') {
            $charset = 'utf8mb4';
        }

        return [
            'host' => $host,
            'port' => $portNumber,
            'database' => $database,
            'username' => $username,
            'password' => $password,
            'charset' => $charset,
        ];
    }

    private static function configureSession(PDO $pdo): void
    {
        $pdo->exec("SET time_zone = '+08:00'");
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    }

    private static function stringValue(string $key, ?string $default = null): string
    {
        $value = env($key, $default);

        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return trim((string) $value);
    }

    private static function requireValue(string $key, string $value): void
    {
        if ($value === '') {
            throw new DatabaseConfigurationException(sprintf(
                'Invalid database configuration: %s is required.',
                $key
            ));
        }
    }

    private static function debugEnabled(): bool
    {
        return env('APP_DEBUG', false) === true;
    }

    private static function logConnectionFailure(PDOException $exception): void
    {
        if (self::debugEnabled()) {
            error_log(sprintf(
                'Database connection error [%s]: %s',
                $exception::class,
                $exception->getMessage()
            ));

            return;
        }

        error_log('Database connection error.');
    }
}
