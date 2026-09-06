<?php

declare(strict_types=1);

final class GoogleOAuthService
{
    public const SCOPES = [
        'openid',
        'https://www.googleapis.com/auth/userinfo.email',
        'https://www.googleapis.com/auth/drive.file',
    ];

    public function __construct(private readonly PDO $pdo, private readonly GoogleTokenCrypto $crypto = new GoogleTokenCrypto())
    {
    }

    public function configuration(): array
    {
        $enabled = env('GOOGLE_DOCS_INTEGRATION_ENABLED', false) === true;
        $clientId = trim((string) env('GOOGLE_CLIENT_ID', ''));
        $clientSecret = trim((string) env('GOOGLE_CLIENT_SECRET', ''));
        $redirectUri = trim((string) env('GOOGLE_REDIRECT_URI', ''));
        $validRedirectUri = $redirectUri !== '' && filter_var($redirectUri, FILTER_VALIDATE_URL) !== false;
        return [
            'enabled' => $enabled,
            'configured' => $enabled && $clientId !== '' && $clientSecret !== '' && $validRedirectUri && $this->crypto->configured(),
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'valid_redirect_uri' => $validRedirectUri,
            'scopes' => self::SCOPES,
        ];
    }

    public function authorizationUrl(array $user): string
    {
        $config = $this->requireConfigured();
        $state = bin2hex(random_bytes(32));
        $_SESSION['google_oauth_state'] = $state;
        $_SESSION['google_oauth_user_id'] = (int) $user['id'];
        $_SESSION['google_oauth_return'] = $this->safeReturnUrl((string) ($_SERVER['HTTP_REFERER'] ?? ''));
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => $config['client_id'],
            'redirect_uri' => $config['redirect_uri'],
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'state' => $state,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
        ]);
    }

    public function handleCallback(array $query): string
    {
        $config = $this->requireConfigured();
        $state = (string) ($query['state'] ?? '');
        $expectedState = (string) ($_SESSION['google_oauth_state'] ?? '');
        $userId = (int) ($_SESSION['google_oauth_user_id'] ?? 0);
        $return = (string) ($_SESSION['google_oauth_return'] ?? $this->defaultReturnUrl());
        unset($_SESSION['google_oauth_state'], $_SESSION['google_oauth_user_id'], $_SESSION['google_oauth_return']);
        if ($state === '' || !hash_equals($expectedState, $state)) {
            throw new GoogleIntegrationException('google_oauth_state', 'Google authorization state could not be verified.');
        }
        if (($query['error'] ?? '') !== '') {
            throw new GoogleIntegrationException('google_oauth', 'Google authorization was not completed.');
        }
        $code = (string) ($query['code'] ?? '');
        if ($userId < 1 || $code === '') {
            throw new GoogleIntegrationException('google_oauth', 'Google authorization response was incomplete.');
        }
        $token = $this->tokenRequest('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'redirect_uri' => $config['redirect_uri'],
            'grant_type' => 'authorization_code',
        ]);
        $refreshToken = (string) ($token['refresh_token'] ?? '');
        if ($refreshToken === '') {
            throw new GoogleIntegrationException('google_oauth', 'Google did not return a refresh token. Revoke access and connect again.');
        }
        $profile = $this->userInfo((string) ($token['access_token'] ?? ''));
        $this->storeConnection($userId, $profile, $refreshToken);
        return $return;
    }

    public function connectionForUser(int $userId): ?array
    {
        if (!$this->tableExists('google_account_connection')) {
            return null;
        }
        return $this->row("SELECT * FROM google_account_connection WHERE user_account_id = :user_id AND status = 'ACTIVE' LIMIT 1", ['user_id' => $userId]);
    }

    public function accessTokenForUser(int $userId): string
    {
        $connection = $this->connectionForUser($userId);
        if ($connection === null) {
            throw new GoogleIntegrationException('google_connection', 'Connect your Google account before using Google Docs authoring.');
        }
        $config = $this->requireConfigured();
        $refreshToken = $this->crypto->decrypt((string) $connection['encrypted_refresh_token']);
        $token = $this->tokenRequest('https://oauth2.googleapis.com/token', [
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);
        $accessToken = (string) ($token['access_token'] ?? '');
        if ($accessToken === '') {
            throw new GoogleIntegrationException('google_authorization', 'Google authorization could not be refreshed. Connect your Google account again.');
        }
        return $accessToken;
    }

    public function disconnect(array $user): void
    {
        if (!$this->tableExists('google_account_connection')) {
            return;
        }
        $this->pdo->prepare("UPDATE google_account_connection SET status = 'REVOKED', revoked_at = NOW(), updated_at = NOW() WHERE user_account_id = :user_id AND status = 'ACTIVE'")->execute(['user_id' => (int) $user['id']]);
    }

    private function requireConfigured(): array
    {
        $config = $this->configuration();
        if (!$config['enabled']) {
            throw new GoogleIntegrationException('google_configuration', 'Google Docs authoring is disabled.');
        }
        if (!$config['configured']) {
            throw new GoogleIntegrationException('google_configuration', 'Google Docs authoring is not fully configured.');
        }
        return $config;
    }

    private function storeConnection(int $userId, array $profile, string $refreshToken): void
    {
        if (!$this->tableExists('google_account_connection')) {
            throw new GoogleIntegrationException('google_schema', 'Google integration tables have not been installed.');
        }
        $this->pdo->prepare("INSERT INTO google_account_connection (user_account_id, google_subject_id, google_email, encrypted_refresh_token, scopes, status, connected_at, updated_at) VALUES (:user_id, :subject, :email, :token, :scopes, 'ACTIVE', NOW(), NOW()) ON DUPLICATE KEY UPDATE google_subject_id = VALUES(google_subject_id), google_email = VALUES(google_email), encrypted_refresh_token = VALUES(encrypted_refresh_token), scopes = VALUES(scopes), status = 'ACTIVE', revoked_at = NULL, updated_at = NOW()")->execute([
            'user_id' => $userId,
            'subject' => (string) ($profile['sub'] ?? ''),
            'email' => (string) ($profile['email'] ?? ''),
            'token' => $this->crypto->encrypt($refreshToken),
            'scopes' => implode(' ', self::SCOPES),
        ]);
    }

    private function userInfo(string $accessToken): array
    {
        if ($accessToken === '') {
            throw new GoogleIntegrationException('google_oauth', 'Google authorization response did not include an access token.');
        }
        return $this->jsonRequest('GET', 'https://openidconnect.googleapis.com/v1/userinfo', [], $accessToken);
    }

    private function tokenRequest(string $url, array $fields): array
    {
        return $this->jsonRequest('POST', $url, $fields);
    }

    private function jsonRequest(string $method, string $url, array $fields = [], ?string $bearer = null): array
    {
        if (!function_exists('curl_init')) {
            throw new GoogleIntegrationException('google_api', 'Google API HTTP support is not available on this server.');
        }
        $curl = curl_init($url);
        $headers = ['Accept: application/json'];
        if ($bearer !== null) {
            $headers[] = 'Authorization: Bearer ' . $bearer;
        }
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
        ]);
        if ($method === 'POST') {
            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($fields));
        }
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        $decoded = is_string($body) ? json_decode($body, true) : null;
        if ($status < 200 || $status >= 300 || !is_array($decoded)) {
            throw new GoogleIntegrationException('google_api', 'Google authorization service returned an error.');
        }
        return $decoded;
    }

    private function safeReturnUrl(string $url): string
    {
        if ($url === '') {
            return $this->defaultReturnUrl();
        }
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return $this->defaultReturnUrl();
        }
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if (($parts['host'] ?? $host) !== $host) {
            return $this->defaultReturnUrl();
        }
        $path = (string) ($parts['path'] ?? '/');
        $query = isset($parts['query']) ? '?' . (string) $parts['query'] : '';
        return $path . $query;
    }

    private function defaultReturnUrl(): string
    {
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        $apiPosition = strpos($script, '/api/');
        $base = $apiPosition === false ? '' : substr($script, 0, $apiPosition);
        return $base . '/pages/contract-management.html';
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table');
        $statement->execute(['table' => $table]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function row(string $sql, array $params = []): ?array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}
