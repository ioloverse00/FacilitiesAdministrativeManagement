<?php

declare(strict_types=1);

final class VisitorOtpService
{
    private const OTP_TTL_SECONDS = 600;
    private const RESEND_COOLDOWN_SECONDS = 60;
    private const MAX_ATTEMPTS = 5;

    public function __construct(private readonly PDO $pdo, private readonly MailService $mail) {}

    public function create(array $payload): array
    {
        $email = strtolower((string) $payload['email_address']);
        $this->assertStartRate($email);
        $otp = (string) random_int(100000, 999999);
        $expires = new DateTimeImmutable('+' . self::OTP_TTL_SECONDS . ' seconds');
        $uuid = $this->uuid();

        $stmt = $this->pdo->prepare("INSERT INTO visitor_registration_challenge (challenge_uuid,email_address,payload_json,otp_hash,otp_expires_at,otp_attempt_count,otp_max_attempts,resend_count,resend_available_at,status,ip_address,user_agent,created_at,updated_at) VALUES (:uuid,:email,:payload,:hash,:expires,0,:max,0,DATE_ADD(NOW(), INTERVAL 60 SECOND),'PENDING',:ip,:ua,NOW(),NOW())");
        $stmt->execute([
            'uuid' => $uuid,
            'email' => $email,
            'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'hash' => password_hash($otp, PASSWORD_DEFAULT),
            'expires' => $expires->format('Y-m-d H:i:s'),
            'max' => self::MAX_ATTEMPTS,
            'ip' => $this->ip(),
            'ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
        ]);
        $this->mail->sendOtp($email, $otp, $expires);
        return ['challenge_id' => $uuid, 'expires_in_seconds' => self::OTP_TTL_SECONDS, 'resend_available_in_seconds' => self::RESEND_COOLDOWN_SECONDS];
    }

    public function resend(string $uuid): array
    {
        $row = $this->challenge($uuid, true);
        $this->assertUsable($row, false);
        if ((int) $row['resend_count'] >= 5) {
            throw new DomainException('Resend limit reached. Please start again.', 429);
        }
        if (strtotime((string) $row['resend_available_at']) > time()) {
            throw new DomainException('Please wait before requesting another code.', 429);
        }
        $otp = (string) random_int(100000, 999999);
        $expires = new DateTimeImmutable('+' . self::OTP_TTL_SECONDS . ' seconds');
        $this->pdo->prepare("UPDATE visitor_registration_challenge SET otp_hash=:hash, otp_expires_at=:expires, otp_attempt_count=0, resend_count=resend_count+1, resend_available_at=DATE_ADD(NOW(), INTERVAL 60 SECOND), status='PENDING', updated_at=NOW() WHERE visitor_registration_challenge_id=:id")->execute(['hash' => password_hash($otp, PASSWORD_DEFAULT), 'expires' => $expires->format('Y-m-d H:i:s'), 'id' => (int) $row['visitor_registration_challenge_id']]);
        $this->mail->sendOtp((string) $row['email_address'], $otp, $expires);
        return ['expires_in_seconds' => self::OTP_TTL_SECONDS, 'resend_available_in_seconds' => self::RESEND_COOLDOWN_SECONDS];
    }

    public function verify(string $uuid, string $otp): array
    {
        if (!preg_match('/^\d{6}$/', $otp)) {
            throw new InvalidArgumentException(json_encode(['otp' => 'Enter the six-digit code.']));
        }
        $row = $this->challenge($uuid, true);
        $this->assertUsable($row, true);
        if (!password_verify($otp, (string) $row['otp_hash'])) {
            $attempts = (int) $row['otp_attempt_count'] + 1;
            $status = $attempts >= (int) $row['otp_max_attempts'] ? 'LOCKED' : (string) $row['status'];
            $this->pdo->prepare('UPDATE visitor_registration_challenge SET otp_attempt_count=:attempts,status=:status,updated_at=NOW() WHERE visitor_registration_challenge_id=:id')->execute(['attempts' => $attempts, 'status' => $status, 'id' => (int) $row['visitor_registration_challenge_id']]);
            throw new DomainException($status === 'LOCKED' ? 'Too many incorrect codes. Please start again.' : 'The verification code is incorrect.', $status === 'LOCKED' ? 429 : 422);
        }
        $token = bin2hex(random_bytes(24));
        $this->pdo->prepare("UPDATE visitor_registration_challenge SET verified_at=NOW(), verification_token_hash=:hash, status='VERIFIED', updated_at=NOW() WHERE visitor_registration_challenge_id=:id")->execute(['hash' => password_hash($token, PASSWORD_DEFAULT), 'id' => (int) $row['visitor_registration_challenge_id']]);
        return ['verification_token' => $token];
    }

    public function verifiedChallenge(string $uuid, string $token): array
    {
        $row = $this->challenge($uuid, true);
        $this->assertUsable($row, false);
        if (empty($row['verified_at']) || empty($row['verification_token_hash']) || !password_verify($token, (string) $row['verification_token_hash'])) {
            throw new DomainException('Email verification is required before submission.', 409);
        }
        return $row;
    }

    private function assertStartRate(string $email): void
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM visitor_registration_challenge WHERE email_address=:email AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)');
        $stmt->execute(['email' => $email]);
        if ((int) $stmt->fetchColumn() >= 5) {
            throw new DomainException('Too many verification requests. Please try again later.', 429);
        }
    }

    private function assertUsable(array $row, bool $forVerify): void
    {
        if (!empty($row['consumed_at'])) {
            throw new DomainException('This registration has already been submitted.', 409);
        }
        if ($row['status'] === 'LOCKED' || $row['status'] === 'CONSUMED') {
            throw new DomainException('This verification request can no longer be used.', 409);
        }
        if (strtotime((string) $row['otp_expires_at']) < time()) {
            throw new DomainException('The verification code has expired. Please request a new code.', 422);
        }
        if ($forVerify && (int) $row['otp_attempt_count'] >= (int) $row['otp_max_attempts']) {
            throw new DomainException('Too many incorrect codes. Please start again.', 429);
        }
    }

    private function challenge(string $uuid, bool $lock): array
    {
        if (!preg_match('/^[a-f0-9-]{36}$/i', $uuid)) {
            throw new DomainException('Verification request was not found.', 404);
        }
        $stmt = $this->pdo->prepare('SELECT * FROM visitor_registration_challenge WHERE challenge_uuid=:uuid' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute(['uuid' => $uuid]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new DomainException('Verification request was not found.', 404);
        }
        return $row;
    }

    private function ip(): string
    {
        return substr($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1', 0, 45);
    }

    private function uuid(): string
    {
        $d = random_bytes(16); $d[6] = chr((ord($d[6]) & 0x0f) | 0x40); $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}

