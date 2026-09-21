<?php

declare(strict_types=1);

final class AccountProvisioningService
{
    private const TOKEN_BYTES = 32;
    private const PENDING_STATUS = 'INACTIVE';

    public function __construct(private readonly PDO $pdo, private readonly MailService $mail)
    {
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public function provisionFamStaff(array $data, array $actor): array
    {
        $this->requireSuperAdmin($actor);
        $clean = $this->validateProvisionData($data);
        $expiresAt = new DateTimeImmutable('+' . $this->setupTokenTtlHours() . ' hours');
        $rawToken = bin2hex(random_bytes(self::TOKEN_BYTES));
        $tokenHash = hash('sha256', $rawToken);
        $setupUrl = $this->setupUrl($rawToken);

        $this->pdo->beginTransaction();
        try {
            $this->assertUniqueIdentity($clean);
            $departmentId = $this->departmentId((string) $clean['department_code']);

            $employeeStatement = $this->pdo->prepare(<<<'SQL'
INSERT INTO employee_reference (
    external_employee_id,
    employee_number,
    full_name,
    department_reference_id,
    position_title,
    email_address,
    contact_number,
    employment_status,
    source_system,
    sync_status,
    last_synced_at,
    external_updated_at
) VALUES (
    :external_employee_id,
    :employee_number,
    :full_name,
    :department_reference_id,
    :position_title,
    :email_address,
    NULL,
    :employment_status,
    'FAM_PROVISIONING',
    'PENDING',
    NULL,
    NULL
)
SQL);
            $employeeStatement->execute([
                'external_employee_id' => 'FAM-PROVISIONED-' . $clean['employee_number'],
                'employee_number' => $clean['employee_number'],
                'full_name' => $clean['full_name'],
                'department_reference_id' => $departmentId,
                'position_title' => $clean['position_title'],
                'email_address' => $clean['email'],
                'employment_status' => self::PENDING_STATUS,
            ]);
            $employeeId = (int) $this->pdo->lastInsertId();

            $accountStatement = $this->pdo->prepare(<<<'SQL'
INSERT INTO user_account (
    employee_reference_id,
    username,
    email,
    password_hash,
    account_status,
    last_login_at
) VALUES (
    :employee_reference_id,
    :username,
    :email,
    '',
    :account_status,
    NULL
)
SQL);
            $accountStatement->execute([
                'employee_reference_id' => $employeeId,
                'username' => $clean['username'],
                'email' => $clean['email'],
                'account_status' => self::PENDING_STATUS,
            ]);
            $userId = (int) $this->pdo->lastInsertId();

            $this->expirePriorSetupTokens($userId);
            $tokenStatement = $this->pdo->prepare(<<<'SQL'
INSERT INTO account_setup_token (
    user_account_id,
    token_hash,
    requested_by_user_id,
    expires_at,
    request_ip,
    request_user_agent
) VALUES (
    :user_account_id,
    :token_hash,
    :requested_by_user_id,
    :expires_at,
    :request_ip,
    :request_user_agent
)
SQL);
            $tokenStatement->execute([
                'user_account_id' => $userId,
                'token_hash' => $tokenHash,
                'requested_by_user_id' => (int) $actor['id'],
                'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
                'request_ip' => $this->clientIp(),
                'request_user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
            ]);

            $this->audit('ACCOUNT_SETUP_TOKEN_ISSUED', (int) $actor['id'], (string) $actor['username'], $userId, (string) $clean['username'], 'SUCCESS', [
                'employee_reference_id' => $employeeId,
                'email' => $clean['email'],
                'expires_at' => $expiresAt->format(DateTimeInterface::ATOM),
            ]);

            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }

        try {
            $this->mail->sendAccountSetupLink((string) $clean['email'], $setupUrl, $expiresAt);
        } catch (Throwable $exception) {
            $this->audit('ACCOUNT_SETUP_EMAIL_FAILED', (int) $actor['id'], (string) $actor['username'], $userId, (string) $clean['username'], 'FAILED', [
                'reason' => 'mail_unavailable',
            ]);
            throw new RuntimeException('Account was prepared, but the setup email could not be sent. Resolve mail configuration and reissue setup from the pending account.');
        }

        return [
            'username' => $clean['username'],
            'email' => $clean['email'],
            'employee_number' => $clean['employee_number'],
            'account_status' => self::PENDING_STATUS,
            'employment_status' => self::PENDING_STATUS,
            'setup_expires_at' => $expiresAt->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public function reissueSetup(int $userAccountId, array $actor): array
    {
        $this->requireSuperAdmin($actor);
        if ($userAccountId < 1) {
            throw new InvalidArgumentException(json_encode(['user_account_id' => 'A valid pending account is required.'], JSON_THROW_ON_ERROR));
        }

        $expiresAt = new DateTimeImmutable('+' . $this->setupTokenTtlHours() . ' hours');
        $rawToken = bin2hex(random_bytes(self::TOKEN_BYTES));
        $tokenHash = hash('sha256', $rawToken);
        $setupUrl = $this->setupUrl($rawToken);

        $this->pdo->beginTransaction();
        try {
            $row = $this->pendingAccountForReissue($userAccountId);
            if ($row === null) {
                throw new InvalidArgumentException(json_encode(['user_account_id' => 'Choose a pending account that has not completed password setup.'], JSON_THROW_ON_ERROR));
            }

            $email = strtolower(trim((string) $row['email']));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException(json_encode(['email' => 'Pending account email is not valid.'], JSON_THROW_ON_ERROR));
            }

            $this->expirePriorSetupTokens($userAccountId);
            $tokenStatement = $this->pdo->prepare(<<<'SQL'
INSERT INTO account_setup_token (
    user_account_id,
    token_hash,
    requested_by_user_id,
    expires_at,
    request_ip,
    request_user_agent
) VALUES (
    :user_account_id,
    :token_hash,
    :requested_by_user_id,
    :expires_at,
    :request_ip,
    :request_user_agent
)
SQL);
            $tokenStatement->execute([
                'user_account_id' => $userAccountId,
                'token_hash' => $tokenHash,
                'requested_by_user_id' => (int) $actor['id'],
                'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
                'request_ip' => $this->clientIp(),
                'request_user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
            ]);

            $this->audit('ACCOUNT_SETUP_TOKEN_REISSUED', (int) $actor['id'], (string) $actor['username'], $userAccountId, (string) $row['username'], 'SUCCESS', [
                'employee_reference_id' => (int) $row['employee_reference_id'],
                'email' => $email,
                'expires_at' => $expiresAt->format(DateTimeInterface::ATOM),
            ]);

            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }

        try {
            $this->mail->sendAccountSetupLink($email, $setupUrl, $expiresAt);
        } catch (Throwable) {
            $this->audit('ACCOUNT_SETUP_EMAIL_FAILED', (int) $actor['id'], (string) $actor['username'], $userAccountId, (string) $row['username'], 'FAILED', [
                'reason' => 'mail_unavailable',
                'reissue' => true,
            ]);
            throw new RuntimeException('Setup link was reissued, but the setup email could not be sent. Resolve mail configuration and reissue again.');
        }

        return [
            'username' => (string) $row['username'],
            'email' => $email,
            'account_status' => self::PENDING_STATUS,
            'employment_status' => self::PENDING_STATUS,
            'setup_expires_at' => $expiresAt->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function tokenStatus(string $rawToken): array
    {
        $row = $this->findUsableToken($rawToken, false);
        if ($row === null) {
            return ['valid' => false];
        }

        return [
            'valid' => true,
            'expires_at' => (new DateTimeImmutable((string) $row['expires_at']))->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function completeSetup(string $rawToken, string $password, string $confirmPassword): array
    {
        $errors = [];
        if ($rawToken === '' || strlen($rawToken) > 256) {
            $errors['token'] = 'Setup link is invalid or expired.';
        }
        if ($password !== $confirmPassword) {
            $errors['confirm_password'] = 'Passwords do not match.';
        }
        if (strlen($password) < 12) {
            $errors['password'] = 'Password must be at least 12 characters.';
        } elseif (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            $errors['password'] = 'Password must include uppercase, lowercase, and number characters.';
        }
        if ($errors !== []) {
            throw new InvalidArgumentException(json_encode($errors, JSON_THROW_ON_ERROR));
        }

        $this->pdo->beginTransaction();
        try {
            $row = $this->findUsableToken($rawToken, true);
            if ($row === null) {
                throw new RuntimeException('Setup link is invalid or expired.');
            }

            $userId = (int) $row['user_account_id'];
            $employeeId = (int) $row['employee_reference_id'];
            $username = (string) $row['username'];
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            $accountUpdate = $this->pdo->prepare(<<<'SQL'
UPDATE user_account
SET password_hash = :password_hash,
    account_status = 'ACTIVE',
    failed_login_count = 0,
    locked_until = NULL,
    deleted_at = NULL,
    updated_at = NOW()
WHERE user_account_id = :user_account_id
  AND account_status = 'INACTIVE'
  AND deleted_at IS NULL
SQL);
            $accountUpdate->execute([
                'password_hash' => $passwordHash,
                'user_account_id' => $userId,
            ]);

            if ($accountUpdate->rowCount() !== 1) {
                throw new RuntimeException('Unable to activate account.');
            }

            $this->pdo->prepare(<<<'SQL'
UPDATE employee_reference
SET employment_status = 'ACTIVE',
    deleted_at = NULL,
    updated_at = NOW()
WHERE employee_reference_id = :employee_reference_id
  AND employment_status = 'INACTIVE'
  AND deleted_at IS NULL
SQL)->execute(['employee_reference_id' => $employeeId]);

            $this->pdo->prepare(<<<'SQL'
UPDATE account_setup_token
SET consumed_at = NOW(),
    consumed_ip = :consumed_ip,
    updated_at = NOW()
WHERE account_setup_token_id = :token_id
  AND consumed_at IS NULL
SQL)->execute([
                'consumed_ip' => $this->clientIp(),
                'token_id' => (int) $row['account_setup_token_id'],
            ]);

            $this->audit('ACCOUNT_SETUP_COMPLETED', $userId, $username, $userId, $username, 'SUCCESS', [
                'employee_reference_id' => $employeeId,
            ]);

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($exception instanceof RuntimeException && $exception->getMessage() === 'Setup link is invalid or expired.') {
                $this->audit('ACCOUNT_SETUP_FAILED', null, null, null, null, 'FAILED', ['reason' => 'invalid_or_expired_token']);
            }
            throw $exception;
        }

        return ['login_url' => $this->loginUrl()];
    }

    /**
     * @param array<string, mixed> $actor
     */
    private function requireSuperAdmin(array $actor): void
    {
        foreach (($actor['roles'] ?? []) as $role) {
            if (strtoupper((string) ($role['code'] ?? '')) === PersonaService::FAM_SUPER_ADMIN) {
                return;
            }
        }

        jsonResponse(false, 'Only FAM Super Admin can provision staff accounts.', [], 403);
    }

    /**
     * @param array<string, mixed> $data
     * @return array{username:string,email:string,employee_number:string,full_name:string,position_title:string,department_code:string}
     */
    private function validateProvisionData(array $data): array
    {
        $username = strtolower(trim((string) ($data['username'] ?? '')));
        $email = strtolower(trim((string) ($data['email'] ?? $data['account_email'] ?? '')));
        $employeeNumber = strtoupper(trim((string) ($data['employee_number'] ?? '')));
        $fullName = trim((string) ($data['full_name'] ?? $data['display_name'] ?? ''));
        $positionTitle = trim((string) ($data['position_title'] ?? ''));
        $departmentCode = strtoupper(trim((string) ($data['department_code'] ?? 'DEP-FAC')));
        $errors = [];

        if (!preg_match('/^[a-z0-9][a-z0-9._-]{2,99}$/', $username)) {
            $errors['username'] = 'Username must be 3-100 lowercase letters, numbers, dots, underscores, or hyphens.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            $errors['email'] = 'Enter a valid account email.';
        }
        if (!preg_match('/^[A-Z0-9][A-Z0-9._-]{2,49}$/', $employeeNumber)) {
            $errors['employee_number'] = 'Employee number must be 3-50 uppercase letters, numbers, dots, underscores, or hyphens.';
        }
        if ($fullName === '' || strlen($fullName) > 200) {
            $errors['full_name'] = 'Full name is required and must fit the employee profile.';
        }
        if ($positionTitle === '' || strlen($positionTitle) > 150) {
            $errors['position_title'] = 'Position title is required and must fit the employee profile.';
        }
        if (!preg_match('/^[A-Z0-9-]{3,50}$/', $departmentCode)) {
            $errors['department_code'] = 'Choose a valid department code.';
        }
        if ($errors !== []) {
            throw new InvalidArgumentException(json_encode($errors, JSON_THROW_ON_ERROR));
        }

        return [
            'username' => $username,
            'email' => $email,
            'employee_number' => $employeeNumber,
            'full_name' => $fullName,
            'position_title' => $positionTitle,
            'department_code' => $departmentCode,
        ];
    }

    /**
     * @param array<string, string> $clean
     */
    private function assertUniqueIdentity(array $clean): void
    {
        $errors = [];
        $checks = [
            'username' => ['SELECT COUNT(*) FROM user_account WHERE username = :value AND deleted_at IS NULL', $clean['username']],
            'email' => ['SELECT COUNT(*) FROM user_account WHERE LOWER(email) = :value AND deleted_at IS NULL', $clean['email']],
            'employee_number' => ['SELECT COUNT(*) FROM employee_reference WHERE employee_number = :value AND deleted_at IS NULL', $clean['employee_number']],
            'employee_email' => ['SELECT COUNT(*) FROM employee_reference WHERE LOWER(email_address) = :value AND deleted_at IS NULL', $clean['email']],
        ];

        foreach ($checks as $field => [$sql, $value]) {
            $statement = $this->pdo->prepare($sql);
            $params = ['value' => $value];
            $statement->execute($params);
            if ((int) $statement->fetchColumn() > 0) {
                $errors[$field] = 'This value is already in use.';
            }
        }

        if ($errors !== []) {
            throw new InvalidArgumentException(json_encode($errors, JSON_THROW_ON_ERROR));
        }
    }

    private function departmentId(string $departmentCode): int
    {
        $statement = $this->pdo->prepare("SELECT department_reference_id FROM department_reference WHERE department_code = :code AND status = 'ACTIVE' LIMIT 1");
        $statement->execute(['code' => $departmentCode]);
        $id = $statement->fetchColumn();
        if ($id === false) {
            throw new InvalidArgumentException(json_encode(['department_code' => 'Choose an active department.'], JSON_THROW_ON_ERROR));
        }

        return (int) $id;
    }

    private function expirePriorSetupTokens(int $userId): void
    {
        $this->pdo->prepare('UPDATE account_setup_token SET consumed_at = COALESCE(consumed_at, NOW()), updated_at = NOW() WHERE user_account_id = :user_id AND consumed_at IS NULL')->execute(['user_id' => $userId]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function pendingAccountForReissue(int $userAccountId): ?array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT ua.user_account_id, ua.username, ua.email, ua.password_hash,
       e.employee_reference_id, e.employment_status, e.deleted_at AS employee_deleted_at
FROM user_account ua
INNER JOIN employee_reference e ON e.employee_reference_id = ua.employee_reference_id
WHERE ua.user_account_id = :user_account_id
  AND ua.account_status = 'INACTIVE'
  AND ua.deleted_at IS NULL
  AND e.employment_status = 'INACTIVE'
  AND e.deleted_at IS NULL
  AND ua.password_hash = ''
LIMIT 1
FOR UPDATE
SQL);
        $statement->execute(['user_account_id' => $userAccountId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findUsableToken(string $rawToken, bool $lock): ?array
    {
        if ($rawToken === '' || strlen($rawToken) > 256) {
            return null;
        }
        $suffix = $lock ? ' FOR UPDATE' : '';
        $statement = $this->pdo->prepare(<<<SQL
SELECT ast.account_setup_token_id, ast.user_account_id, ast.expires_at,
       ua.username, ua.email, ua.account_status, ua.deleted_at AS account_deleted_at,
       e.employee_reference_id, e.employment_status, e.deleted_at AS employee_deleted_at
FROM account_setup_token ast
INNER JOIN user_account ua ON ua.user_account_id = ast.user_account_id
INNER JOIN employee_reference e ON e.employee_reference_id = ua.employee_reference_id
WHERE ast.token_hash = :token_hash
  AND ast.consumed_at IS NULL
  AND ast.expires_at > NOW()
  AND ua.account_status = 'INACTIVE'
  AND ua.deleted_at IS NULL
  AND e.employment_status = 'INACTIVE'
  AND e.deleted_at IS NULL
LIMIT 1{$suffix}
SQL);
        $statement->execute(['token_hash' => hash('sha256', $rawToken)]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    private function setupTokenTtlHours(): int
    {
        return max(1, min(168, (int) env('ACCOUNT_SETUP_TOKEN_TTL_HOURS', 24)));
    }

    private function setupUrl(string $rawToken): string
    {
        return rtrim($this->baseUrl(), '/') . '/pages/reset-password.html?token=' . rawurlencode($rawToken);
    }

    private function loginUrl(): string
    {
        return rtrim($this->baseUrl(), '/') . '/pages/login.html';
    }

    private function baseUrl(): string
    {
        $configured = trim((string) env('APP_URL', env('APP_BASE_URL', '')));
        if ($configured === '') {
            throw new RuntimeException('Account setup requires APP_URL or APP_BASE_URL to be configured.');
        }

        $parts = parse_url($configured);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = rtrim((string) ($parts['path'] ?? ''), '/');
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $environment = strtolower((string) env('APP_ENV', 'production'));
        $localAllowed = in_array($environment, ['local', 'development'], true)
            && $scheme === 'http'
            && in_array($host, ['localhost', '127.0.0.1', '::1'], true);

        if ($host === ''
            || isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])
            || ($scheme !== 'https' && !$localAllowed)) {
            throw new RuntimeException('Account setup requires a valid HTTPS APP_URL or APP_BASE_URL.');
        }

        return $scheme . '://' . $host . $port . $path;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function audit(string $action, ?int $actorUserId, ?string $actorUsername, ?int $entityId, ?string $entityReference, string $status, array $metadata): void
    {
        try {
            $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO audit_log (
    audit_uuid,
    actor_user_id,
    actor_username,
    action_code,
    module_code,
    entity_type,
    entity_id,
    entity_reference,
    result_status,
    result_message,
    ip_address,
    user_agent,
    request_method,
    request_path,
    metadata_json
) VALUES (
    :audit_uuid,
    :actor_user_id,
    :actor_username,
    :action_code,
    'AUTH',
    'user_account',
    :entity_id,
    :entity_reference,
    :result_status,
    :result_message,
    :ip_address,
    :user_agent,
    :request_method,
    :request_path,
    :metadata_json
)
SQL);
            $statement->execute([
                'audit_uuid' => self::uuidV4(),
                'actor_user_id' => $actorUserId,
                'actor_username' => $actorUsername,
                'action_code' => $action,
                'entity_id' => $entityId,
                'entity_reference' => $entityReference,
                'result_status' => $status,
                'result_message' => $action,
                'ip_address' => $this->clientIp(),
                'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
                'request_method' => $_SERVER['REQUEST_METHOD'] ?? '',
                'request_path' => parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '',
                'metadata_json' => json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
            ]);
        } catch (Throwable) {
            error_log('Account provisioning audit failed.');
        }
    }

    private function clientIp(): string
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
