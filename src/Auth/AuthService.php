<?php

declare(strict_types=1);

final class AuthResult
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly int $status,
        public readonly array $data = []
    ) {
    }
}

final class AuthService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function login(string $username, string $password): AuthResult
    {
        $account = $this->findAccountByUsername($username);

        if ($account === null) {
            $this->safeAudit('AUTH_LOGIN_FAILED', null, [
                'attempted_username' => $username,
                'result' => 'unknown_account',
            ]);

            return new AuthResult(false, 'Invalid username or password.', 401);
        }

        $userId = (int) $account['user_account_id'];

        if ($this->isLocked($account)) {
            $this->safeAudit('AUTH_LOGIN_FAILED', $userId, [
                'attempted_username' => $username,
                'result' => 'locked',
            ]);

            return new AuthResult(false, 'Account is temporarily locked. Try again later.', 423);
        }

        if (!$this->hasAvailableAccountState($account)) {
            $this->safeAudit('AUTH_LOGIN_FAILED', $userId, [
                'attempted_username' => $username,
                'result' => 'unavailable',
            ]);

            return new AuthResult(false, 'Account is unavailable. Contact the administrator.', 403);
        }

        if (!password_verify($password, (string) $account['password_hash'])) {
            $this->registerFailedLogin($account, $username);

            return new AuthResult(false, 'Invalid username or password.', 401);
        }

        $this->pdo->beginTransaction();

        try {
            if (password_needs_rehash((string) $account['password_hash'], PASSWORD_DEFAULT)) {
                $newHash = password_hash($password, PASSWORD_DEFAULT);
                $this->updatePasswordHash($userId, $newHash);
            }
            $this->registerSuccessfulLogin($userId);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }

        $this->safeAudit('AUTH_LOGIN_SUCCESS', $userId, ['result' => 'success']);
        $this->safeActivity($userId, 'AUTH_LOGIN_SUCCESS', 'User signed in.');

        session_regenerate_id(true);

        $_SESSION['user_account_id'] = $userId;
        $_SESSION['authenticated_at'] = time();
        $_SESSION['last_activity_at'] = time();
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        return new AuthResult(true, 'Login successful.', 200, [
            'user' => $this->buildAuthenticatedUser($userId)->toArray(),
            'csrf_token' => $_SESSION['csrf_token'],
        ]);
    }

    public function currentUser(): ?AuthenticatedUser
    {
        $userId = $_SESSION['user_account_id'] ?? null;

        if (!is_int($userId) && !(is_string($userId) && ctype_digit($userId))) {
            return null;
        }

        return $this->buildAuthenticatedUser((int) $userId);
    }

    public function enforceSessionLifetime(): bool
    {
        $userId = $_SESSION['user_account_id'] ?? null;
        $lastActivity = $_SESSION['last_activity_at'] ?? null;

        if (!is_int($lastActivity)) {
            return true;
        }

        if ((time() - $lastActivity) <= authSessionLifetime()) {
            return true;
        }

        if (is_int($userId) || (is_string($userId) && ctype_digit($userId))) {
            $this->safeAudit('AUTH_SESSION_EXPIRED', (int) $userId, ['result' => 'expired']);
        }

        clearAuthSession();

        return false;
    }

    public function touchSession(): void
    {
        $_SESSION['last_activity_at'] = time();
    }

    public function logout(): void
    {
        $userId = $_SESSION['user_account_id'] ?? null;

        if (is_int($userId) || (is_string($userId) && ctype_digit($userId))) {
            $this->safeAudit('AUTH_LOGOUT', (int) $userId, ['result' => 'success']);
            $this->safeActivity((int) $userId, 'AUTH_LOGOUT', 'User signed out.');
        }

        clearAuthSession();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findAccountByUsername(string $username): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT
                u.user_account_id,
                u.employee_reference_id,
                u.username,
                u.password_hash,
                u.account_status,
                u.failed_login_count,
                u.locked_until,
                u.deleted_at AS user_deleted_at,
                e.employee_number,
                e.full_name,
                e.position_title,
                e.email_address,
                e.employment_status,
                e.deleted_at AS employee_deleted_at,
                d.department_reference_id,
                d.department_code,
                d.department_name
            FROM user_account u
            INNER JOIN employee_reference e ON e.employee_reference_id = u.employee_reference_id
            LEFT JOIN department_reference d ON d.department_reference_id = e.department_reference_id
            WHERE u.username = :username
            LIMIT 1'
        );
        $statement->execute(['username' => $username]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $account
     */
    private function hasAvailableAccountState(array $account): bool
    {
        return (string) $account['account_status'] === 'ACTIVE'
            && (string) $account['employment_status'] === 'ACTIVE'
            && $account['user_deleted_at'] === null
            && $account['employee_deleted_at'] === null;
    }

    /**
     * @param array<string, mixed> $account
     */
    private function isLocked(array $account): bool
    {
        $lockedUntil = $account['locked_until'];

        if ($lockedUntil === null || $lockedUntil === '') {
            return false;
        }

        return new DateTimeImmutable((string) $lockedUntil) > new DateTimeImmutable('now');
    }

    /**
     * @param array<string, mixed> $account
     */
    private function registerFailedLogin(array $account, string $username): void
    {
        $userId = (int) $account['user_account_id'];
        $failedCount = ((int) $account['failed_login_count']) + 1;
        $maxAttempts = max(1, (int) env('AUTH_MAX_FAILED_ATTEMPTS', 5));
        $lockMinutes = max(1, (int) env('AUTH_LOCK_MINUTES', 15));
        $accountLocked = false;

        $this->pdo->beginTransaction();

        try {
            if ($failedCount >= $maxAttempts) {
                $statement = $this->pdo->prepare(
                    'UPDATE user_account
                     SET failed_login_count = :failed_login_count,
                         locked_until = DATE_ADD(NOW(), INTERVAL :lock_minutes MINUTE)
                     WHERE user_account_id = :user_account_id'
                );
                $statement->bindValue('failed_login_count', $failedCount, PDO::PARAM_INT);
                $statement->bindValue('lock_minutes', $lockMinutes, PDO::PARAM_INT);
                $statement->bindValue('user_account_id', $userId, PDO::PARAM_INT);
                $statement->execute();
                $accountLocked = true;
            } else {
                $statement = $this->pdo->prepare(
                    'UPDATE user_account
                     SET failed_login_count = :failed_login_count
                     WHERE user_account_id = :user_account_id'
                );
                $statement->execute([
                    'failed_login_count' => $failedCount,
                    'user_account_id' => $userId,
                ]);
            }

            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }

        if ($accountLocked) {
            $this->safeAudit('AUTH_ACCOUNT_LOCKED', $userId, [
                'attempted_username' => $username,
                'result' => 'locked',
            ]);
        }

        $this->safeAudit('AUTH_LOGIN_FAILED', $userId, [
            'attempted_username' => $username,
            'result' => 'invalid_credentials',
        ]);
    }

    private function registerSuccessfulLogin(int $userId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE user_account
             SET last_login_at = NOW(), failed_login_count = 0, locked_until = NULL
             WHERE user_account_id = :user_account_id'
        );
        $statement->execute(['user_account_id' => $userId]);
    }

    private function updatePasswordHash(int $userId, string $hash): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE user_account SET password_hash = :password_hash WHERE user_account_id = :user_account_id'
        );
        $statement->execute([
            'password_hash' => $hash,
            'user_account_id' => $userId,
        ]);
    }

    private function buildAuthenticatedUser(int $userId): AuthenticatedUser
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT
    u.user_account_id,
    u.username,
    e.employee_reference_id,
    e.employee_number,
    e.full_name,
    e.position_title,
    e.email_address,
    d.department_reference_id,
    d.department_code,
    d.department_name
FROM user_account u
INNER JOIN employee_reference e ON e.employee_reference_id = u.employee_reference_id
LEFT JOIN department_reference d ON d.department_reference_id = e.department_reference_id
WHERE u.user_account_id = :user_account_id
  AND u.account_status = 'ACTIVE'
  AND u.deleted_at IS NULL
  AND e.employment_status = 'ACTIVE'
  AND e.deleted_at IS NULL
LIMIT 1
SQL);
        $statement->execute(['user_account_id' => $userId]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            throw new RuntimeException('Authenticated account is no longer available.');
        }

        $profile = [
            'id' => (int) $row['user_account_id'],
            'username' => (string) $row['username'],
            'employee_id' => (int) $row['employee_reference_id'],
            'employee_number' => (string) $row['employee_number'],
            'full_name' => (string) $row['full_name'],
            'position' => (string) ($row['position_title'] ?? ''),
            'email' => (string) ($row['email_address'] ?? ''),
            'department' => [
                'id' => $row['department_reference_id'] === null ? null : (int) $row['department_reference_id'],
                'code' => (string) ($row['department_code'] ?? ''),
                'name' => (string) ($row['department_name'] ?? ''),
            ],
        ];

        return new AuthenticatedUser($profile, $this->loadRoles($userId), $this->loadPermissions($userId));
    }

    /**
     * @return list<array{code:string,name:string}>
     */
    private function loadRoles(int $userId): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT DISTINCT r.role_code, r.role_name
FROM user_role ur
INNER JOIN role r ON r.role_id = ur.role_id
WHERE ur.user_account_id = :user_account_id
  AND r.status = 'ACTIVE'
  AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
ORDER BY r.role_code
SQL);
        $statement->execute(['user_account_id' => $userId]);

        return array_map(static fn (array $row): array => [
            'code' => (string) $row['role_code'],
            'name' => (string) $row['role_name'],
        ], $statement->fetchAll());
    }

    /**
     * @return list<string>
     */
    private function loadPermissions(int $userId): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT DISTINCT p.permission_code
FROM user_role ur
INNER JOIN role r ON r.role_id = ur.role_id
INNER JOIN role_permission rp ON rp.role_id = r.role_id
INNER JOIN permission p ON p.permission_id = rp.permission_id
WHERE ur.user_account_id = :user_account_id
  AND r.status = 'ACTIVE'
  AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
ORDER BY p.permission_code
SQL);
        $statement->execute(['user_account_id' => $userId]);

        return array_map(static fn (array $row): string => (string) $row['permission_code'], $statement->fetchAll());
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function safeAudit(string $action, ?int $actorUserId, array $metadata): void
    {
        try {
            $this->audit($action, $actorUserId, $metadata);
        } catch (Throwable $exception) {
            $this->logTelemetryFailure('audit_log', $exception);
        }
    }

    private function safeActivity(int $actorUserId, string $eventType, string $description): void
    {
        try {
            $this->activity($actorUserId, $eventType, $description);
        } catch (Throwable $exception) {
            $this->logTelemetryFailure('activity_event', $exception);
        }
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function audit(string $action, ?int $actorUserId, array $metadata): void
    {
        $safeMetadata = array_merge([
            'event_uuid' => self::uuidV4(),
        ], $metadata);

        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO audit_log
    (audit_uuid, actor_user_id, action_code, module_code, entity_type, entity_id, result_status, ip_address, user_agent, request_method, request_path, metadata_json)
VALUES
    (:audit_uuid, :actor_user_id, :action_code, 'AUTH', 'user_account', :entity_id, :result_status, :ip_address, :user_agent, :request_method, :request_path, :metadata_json)
SQL);
        $statement->execute([
            'audit_uuid' => self::uuidV4(),
            'actor_user_id' => $actorUserId,
            'action_code' => $action,
            'entity_id' => $actorUserId,
            'result_status' => self::auditResultStatus($action),
            'ip_address' => self::clientIp(),
            'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? '',
            'request_path' => parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '',
            'metadata_json' => json_encode($safeMetadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
        ]);
    }

    private function activity(int $actorUserId, string $eventType, string $description): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO activity_event
    (event_uuid, module_code, entity_type, entity_id, event_type, event_title, event_description, actor_user_id, visibility_scope, metadata_json)
VALUES
    (:event_uuid, 'AUTH', 'user_account', :entity_id, :event_type, :event_title, :event_description, :actor_user_id, 'INTERNAL', :metadata_json)
SQL);
        $statement->execute([
            'event_uuid' => self::uuidV4(),
            'entity_id' => $actorUserId,
            'event_type' => $eventType,
            'event_title' => $description,
            'event_description' => $description,
            'actor_user_id' => $actorUserId,
            'metadata_json' => json_encode(['event_uuid' => self::uuidV4()], JSON_THROW_ON_ERROR),
        ]);
    }

    private function logTelemetryFailure(string $target, Throwable $exception): void
    {
        $isLocalDebug = env('APP_ENV', 'production') === 'local' && env('APP_DEBUG', false) === true;

        if ($isLocalDebug) {
            error_log(sprintf(
                'Auth telemetry error [%s]: %s: %s in %s:%d',
                $target,
                $exception::class,
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine()
            ));
            return;
        }

        error_log(sprintf('Auth telemetry error [%s]: %s', $target, $exception::class));
    }

    private static function auditResultStatus(string $action): string
    {
        return match ($action) {
            'AUTH_LOGIN_SUCCESS', 'AUTH_LOGOUT' => 'SUCCESS',
            'AUTH_ACCOUNT_LOCKED' => 'LOCKED',
            'AUTH_SESSION_EXPIRED' => 'EXPIRED',
            'AUTH_LOGIN_FAILED' => 'FAILED',
            default => 'DENIED',
        };
    }

    private static function clientIp(): string
    {
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    }

    private static function uuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
