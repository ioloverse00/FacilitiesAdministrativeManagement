<?php

declare(strict_types=1);

final class DocumentStepUpService
{
    private const PURPOSE = 'CONFIDENTIAL_DOCUMENT_ACCESS';

    public function __construct(private readonly PDO $pdo, private readonly MailService $mail)
    {
    }

    public function hasValidStepUp(array $user, int $documentId): bool
    {
        $entry = $_SESSION['document_step_up'][$documentId] ?? null;
        if (!is_array($entry)) {
            return false;
        }

        return (int) ($entry['user_id'] ?? 0) === (int) $user['id']
            && hash_equals((string) ($entry['session_id'] ?? ''), session_id())
            && (int) ($entry['expires_at'] ?? 0) > time();
    }

    public function requestChallenge(array $user, int $documentId): array
    {
        $this->ensureTrustedEmail($user);
        $ttl = $this->otpTtl();
        $cooldown = $this->resendCooldown();
        $sessionHash = $this->sessionHash();
        $existing = $this->activeChallenge((int) $user['id'], $sessionHash, $documentId);
        if ($existing !== null && time() - strtotime((string) $existing['last_sent_at']) < $cooldown) {
            $this->audit('CONFIDENTIAL_DOCUMENT_OTP_FAILED', (int) $user['id'], $documentId, null, 'COOLDOWN');
            jsonResponse(false, 'Verification is temporarily unavailable. Please try again shortly.', [
                'retry_after_seconds' => $cooldown - (time() - strtotime((string) $existing['last_sent_at'])),
                'resend_cooldown_seconds' => $cooldown,
                'expires_in_seconds' => max(0, strtotime((string) $existing['expires_at']) - time()),
            ], 429);
        }

        $otp = $this->generateOtp();
        $expiresAt = new DateTimeImmutable('+' . $ttl . ' seconds');
        $this->consumeActiveChallenges((int) $user['id'], $sessionHash, $documentId);
        $challengeId = $this->uuidV4();
        $statement = $this->pdo->prepare("INSERT INTO document_otp_challenge (challenge_uuid, user_account_id, session_hash, purpose, document_id, otp_hash, expires_at, attempt_count, max_attempts, last_sent_at, created_at) VALUES (:uuid, :user_id, :session_hash, :purpose, :document_id, :otp_hash, :expires_at, 0, :max_attempts, NOW(), NOW())");
        $statement->execute([
            'uuid' => $challengeId,
            'user_id' => (int) $user['id'],
            'session_hash' => $sessionHash,
            'purpose' => self::PURPOSE,
            'document_id' => $documentId,
            'otp_hash' => password_hash($otp, PASSWORD_DEFAULT),
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            'max_attempts' => $this->maxAttempts(),
        ]);

        try {
            $this->mail->sendDocumentOtp((string) $user['email'], $otp, $expiresAt);
        } catch (Throwable $exception) {
            $this->pdo->prepare('UPDATE document_otp_challenge SET consumed_at = NOW() WHERE challenge_uuid = :uuid')->execute(['uuid' => $challengeId]);
            $this->audit('CONFIDENTIAL_DOCUMENT_OTP_FAILED', (int) $user['id'], $documentId, null, 'MAIL_UNAVAILABLE');
            jsonResponse(false, 'Verification is temporarily unavailable. Please try again later.', [], 503);
        }
        $this->audit('CONFIDENTIAL_DOCUMENT_OTP_REQUESTED', (int) $user['id'], $documentId, null, 'SUCCESS');

        return [
            'challenge_id' => $challengeId,
            'expires_in_seconds' => $ttl,
            'resend_cooldown_seconds' => $cooldown,
            'max_attempts' => $this->maxAttempts(),
            'email_hint' => $this->maskEmail((string) $user['email']),
        ];
    }

    public function verifyChallenge(array $user, int $documentId, string $challengeId, string $otp): array
    {
        $challenge = $this->challenge($challengeId, (int) $user['id'], $this->sessionHash(), $documentId);
        if ($challenge === null || $challenge['consumed_at'] !== null || strtotime((string) $challenge['expires_at']) <= time()) {
            $this->audit('CONFIDENTIAL_DOCUMENT_OTP_FAILED', (int) $user['id'], $documentId, null, 'INVALID');
            jsonResponse(false, 'Verification failed. Request a new code and try again.', [], 422);
        }
        if ((int) $challenge['attempt_count'] >= (int) $challenge['max_attempts']) {
            $this->audit('CONFIDENTIAL_DOCUMENT_OTP_FAILED', (int) $user['id'], $documentId, null, 'MAX_ATTEMPTS');
            jsonResponse(false, 'Verification failed. Request a new code and try again.', [], 422);
        }

        $ok = preg_match('/^\d{6}$/', $otp) === 1 && password_verify($otp, (string) $challenge['otp_hash']);
        if (!$ok) {
            $nextAttempts = (int) $challenge['attempt_count'] + 1;
            if ($nextAttempts >= (int) $challenge['max_attempts']) {
                $this->pdo->prepare('UPDATE document_otp_challenge SET attempt_count = :attempts, consumed_at = NOW() WHERE challenge_id = :id')->execute(['attempts' => $nextAttempts, 'id' => (int) $challenge['challenge_id']]);
            } else {
                $this->pdo->prepare('UPDATE document_otp_challenge SET attempt_count = :attempts WHERE challenge_id = :id')->execute(['attempts' => $nextAttempts, 'id' => (int) $challenge['challenge_id']]);
            }
            $this->audit('CONFIDENTIAL_DOCUMENT_OTP_FAILED', (int) $user['id'], $documentId, null, 'FAILED');
            jsonResponse(false, 'Verification failed. Check the code and try again.', [
                'attempts_remaining' => max(0, (int) $challenge['max_attempts'] - $nextAttempts),
            ], 422);
        }

        $this->pdo->prepare('UPDATE document_otp_challenge SET consumed_at = NOW() WHERE challenge_id = :id')->execute(['id' => (int) $challenge['challenge_id']]);
        $stepUpTtl = $this->stepUpTtl();
        $_SESSION['document_step_up'][$documentId] = [
            'user_id' => (int) $user['id'],
            'session_id' => session_id(),
            'purpose' => self::PURPOSE,
            'expires_at' => time() + $stepUpTtl,
        ];
        $this->audit('CONFIDENTIAL_DOCUMENT_OTP_VERIFIED', (int) $user['id'], $documentId, null, 'SUCCESS');

        return ['step_up_expires_in_seconds' => $stepUpTtl];
    }

    public function auditDocumentAccess(string $eventType, array $user, int $documentId, ?int $versionId, string $result = 'SUCCESS'): void
    {
        $this->audit($eventType, (int) $user['id'], $documentId, $versionId, $result);
    }

    private function ensureTrustedEmail(array $user): void
    {
        if (!filter_var((string) ($user['email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
            jsonResponse(false, 'Verification is unavailable for this account.', [], 409);
        }
    }

    private function activeChallenge(int $userId, string $sessionHash, int $documentId): ?array
    {
        $statement = $this->pdo->prepare("SELECT * FROM document_otp_challenge WHERE user_account_id = :user_id AND session_hash = :session_hash AND purpose = :purpose AND document_id = :document_id AND consumed_at IS NULL ORDER BY challenge_id DESC LIMIT 1");
        $statement->execute(['user_id' => $userId, 'session_hash' => $sessionHash, 'purpose' => self::PURPOSE, 'document_id' => $documentId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    private function challenge(string $challengeId, int $userId, string $sessionHash, int $documentId): ?array
    {
        $statement = $this->pdo->prepare("SELECT * FROM document_otp_challenge WHERE challenge_uuid = :uuid AND user_account_id = :user_id AND session_hash = :session_hash AND purpose = :purpose AND document_id = :document_id LIMIT 1");
        $statement->execute(['uuid' => $challengeId, 'user_id' => $userId, 'session_hash' => $sessionHash, 'purpose' => self::PURPOSE, 'document_id' => $documentId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    private function consumeActiveChallenges(int $userId, string $sessionHash, int $documentId): void
    {
        $this->pdo->prepare("UPDATE document_otp_challenge SET consumed_at = NOW() WHERE user_account_id = :user_id AND session_hash = :session_hash AND purpose = :purpose AND document_id = :document_id AND consumed_at IS NULL")->execute([
            'user_id' => $userId,
            'session_hash' => $sessionHash,
            'purpose' => self::PURPOSE,
            'document_id' => $documentId,
        ]);
    }

    private function audit(string $eventType, int $userId, int $documentId, ?int $versionId, string $result): void
    {
        try {
            $this->pdo->prepare("INSERT INTO activity_event (event_uuid, module_code, entity_type, entity_id, event_type, event_title, event_description, actor_user_id, visibility_scope, metadata_json) VALUES (:uuid, 'documents', 'document', :document_id, :event_type, :title, :description, :user_id, 'INTERNAL', :metadata)")->execute([
                'uuid' => $this->uuidV4(),
                'document_id' => $documentId,
                'event_type' => $eventType,
                'title' => ucwords(strtolower(str_replace('_', ' ', $eventType))),
                'description' => $eventType,
                'user_id' => $userId,
                'metadata' => json_encode(['document_id' => $documentId, 'document_version_id' => $versionId, 'result' => $result], JSON_THROW_ON_ERROR),
            ]);
        } catch (Throwable $exception) {
            error_log('Document security activity logging failed: ' . $exception::class);
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
        return max(1, (int) env('DOCUMENT_OTP_TTL_SECONDS', 60));
    }

    private function resendCooldown(): int
    {
        return max(1, (int) env('DOCUMENT_OTP_RESEND_COOLDOWN_SECONDS', 30));
    }

    private function maxAttempts(): int
    {
        return max(1, (int) env('DOCUMENT_OTP_MAX_ATTEMPTS', 5));
    }

    private function stepUpTtl(): int
    {
        return max(1, (int) env('DOCUMENT_STEP_UP_TTL_SECONDS', 600));
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
