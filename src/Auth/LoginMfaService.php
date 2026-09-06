<?php

declare(strict_types=1);

final class LoginMfaService
{
    private const PURPOSE = 'LOGIN';

    public function __construct(private readonly PDO $pdo, private readonly MailService $mail)
    {
    }

    public function issueChallenge(int $userId, string $email): array
    {
        $this->ensureTrustedEmail($email);
        $sessionHash = $this->sessionHash();
        $cooldown = $this->resendCooldown();
        $existing = $this->activeChallenge($userId, $sessionHash);
        if ($existing !== null && time() - strtotime((string) $existing['last_sent_at']) < $cooldown) {
            return [
                'sent' => false,
                'retry_after_seconds' => $cooldown - (time() - strtotime((string) $existing['last_sent_at'])),
                'resend_cooldown_seconds' => $cooldown,
                'expires_in_seconds' => max(0, strtotime((string) $existing['expires_at']) - time()),
                'email_hint' => $this->maskEmail($email),
            ];
        }

        $otp = $this->generateOtp();
        $ttl = $this->otpTtl();
        $expiresAt = new DateTimeImmutable('+' . $ttl . ' seconds');
        $this->consumeActiveChallenges($userId, $sessionHash);
        $uuid = $this->uuidV4();
        $this->pdo->prepare("INSERT INTO login_mfa_challenge (challenge_uuid, user_account_id, pending_session_hash, otp_hash, expires_at, attempt_count, max_attempts, last_sent_at, created_at) VALUES (:uuid, :user_id, :session_hash, :otp_hash, :expires_at, 0, :max_attempts, NOW(), NOW())")->execute([
            'uuid' => $uuid,
            'user_id' => $userId,
            'session_hash' => $sessionHash,
            'otp_hash' => password_hash($otp, PASSWORD_DEFAULT),
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            'max_attempts' => $this->maxAttempts(),
        ]);

        try {
            $this->mail->sendLoginOtp($email, $otp, $expiresAt);
        } catch (Throwable $exception) {
            $this->pdo->prepare('UPDATE login_mfa_challenge SET consumed_at = NOW() WHERE challenge_uuid = :uuid')->execute(['uuid' => $uuid]);
            throw $exception;
        }

        return [
            'sent' => true,
            'challenge_id' => $uuid,
            'expires_in_seconds' => $ttl,
            'resend_cooldown_seconds' => $cooldown,
            'max_attempts' => $this->maxAttempts(),
            'email_hint' => $this->maskEmail($email),
        ];
    }

    public function verify(int $userId, string $challengeId, string $otp): bool
    {
        $challenge = $this->challenge($userId, $this->sessionHash(), $challengeId);
        if ($challenge === null || $challenge['consumed_at'] !== null || strtotime((string) $challenge['expires_at']) <= time()) {
            return false;
        }
        if ((int) $challenge['attempt_count'] >= (int) $challenge['max_attempts']) {
            return false;
        }

        $ok = preg_match('/^\d{6}$/', $otp) === 1 && password_verify($otp, (string) $challenge['otp_hash']);
        if (!$ok) {
            $nextAttempts = (int) $challenge['attempt_count'] + 1;
            if ($nextAttempts >= (int) $challenge['max_attempts']) {
                $this->pdo->prepare('UPDATE login_mfa_challenge SET attempt_count = :attempts, consumed_at = NOW() WHERE challenge_id = :id')->execute(['attempts' => $nextAttempts, 'id' => (int) $challenge['challenge_id']]);
            } else {
                $this->pdo->prepare('UPDATE login_mfa_challenge SET attempt_count = :attempts WHERE challenge_id = :id')->execute(['attempts' => $nextAttempts, 'id' => (int) $challenge['challenge_id']]);
            }
            return false;
        }

        $this->pdo->prepare('UPDATE login_mfa_challenge SET consumed_at = NOW() WHERE challenge_id = :id')->execute(['id' => (int) $challenge['challenge_id']]);
        return true;
    }

    public function consumePending(int $userId): void
    {
        $this->consumeActiveChallenges($userId, $this->sessionHash());
    }

    private function activeChallenge(int $userId, string $sessionHash): ?array
    {
        $statement = $this->pdo->prepare("SELECT * FROM login_mfa_challenge WHERE user_account_id = :user_id AND pending_session_hash = :session_hash AND purpose = :purpose AND consumed_at IS NULL ORDER BY challenge_id DESC LIMIT 1");
        $statement->execute(['user_id' => $userId, 'session_hash' => $sessionHash, 'purpose' => self::PURPOSE]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    private function challenge(int $userId, string $sessionHash, string $challengeId): ?array
    {
        $statement = $this->pdo->prepare("SELECT * FROM login_mfa_challenge WHERE challenge_uuid = :uuid AND user_account_id = :user_id AND pending_session_hash = :session_hash AND purpose = :purpose LIMIT 1");
        $statement->execute(['uuid' => $challengeId, 'user_id' => $userId, 'session_hash' => $sessionHash, 'purpose' => self::PURPOSE]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    private function consumeActiveChallenges(int $userId, string $sessionHash): void
    {
        $this->pdo->prepare("UPDATE login_mfa_challenge SET consumed_at = NOW() WHERE user_account_id = :user_id AND pending_session_hash = :session_hash AND purpose = :purpose AND consumed_at IS NULL")->execute([
            'user_id' => $userId,
            'session_hash' => $sessionHash,
            'purpose' => self::PURPOSE,
        ]);
    }

    private function ensureTrustedEmail(string $email): void
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Verification is unavailable for this account.');
        }
    }

    private function generateOtp(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function sessionHash(): string
    {
        return hash('sha256', session_id());
    }

    private function otpTtl(): int
    {
        return max(1, (int) env('LOGIN_OTP_TTL_SECONDS', 60));
    }

    private function resendCooldown(): int
    {
        return max(1, (int) env('LOGIN_OTP_RESEND_COOLDOWN_SECONDS', 30));
    }

    private function maxAttempts(): int
    {
        return max(1, (int) env('LOGIN_OTP_MAX_ATTEMPTS', 5));
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        return substr($local, 0, 1) . str_repeat('*', max(2, strlen($local) - 1)) . '@' . $domain;
    }

    private function uuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
