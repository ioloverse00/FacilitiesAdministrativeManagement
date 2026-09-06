<?php

declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

final class MailService
{
    public function sendLoginOtp(string $email, string $otp, DateTimeImmutable $expiresAt): void
    {
        $this->sendOtpMessage(
            $email,
            'FAM sign-in verification code',
            "Your FAM sign-in verification code is:\n\n{$otp}\n\nThis code expires at {$expiresAt->format('Y-m-d H:i:s')}.\nIf you did not request this code, you may ignore this email."
        );
    }

    public function sendDocumentOtp(string $email, string $otp, DateTimeImmutable $expiresAt): void
    {
        $this->sendOtpMessage(
            $email,
            'FAM secure document verification code',
            "Your FAM secure document access code is:\n\n{$otp}\n\nThis code expires at {$expiresAt->format('Y-m-d H:i:s')}.\nIf you did not request this code, contact the administrator."
        );
    }

    public function sendOtp(string $email, string $otp, DateTimeImmutable $expiresAt): void
    {
        $this->sendLoginOtp($email, $otp, $expiresAt);
    }

    private function sendOtpMessage(string $email, string $subject, string $body): void
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Verification email is unavailable.');
        }

        if (!$this->mailEnabled()) {
            throw new RuntimeException('Verification email is not configured.');
        }

        $autoload = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        }
        if (!class_exists(PHPMailer::class)) {
            error_log('Mail delivery failed: PHPMailer dependency is unavailable.');
            throw new RuntimeException('Verification email is not configured.');
        }

        $host = trim((string) env('SMTP_HOST', env('MAIL_HOST', '')));
        $username = trim((string) env('SMTP_USERNAME', env('MAIL_USERNAME', '')));
        $password = (string) env('SMTP_PASSWORD', env('MAIL_PASSWORD', ''));
        $from = trim((string) env('SMTP_FROM_ADDRESS', env('MAIL_FROM_ADDRESS', '')));
        $fromName = trim((string) env('SMTP_FROM_NAME', env('MAIL_FROM_NAME', 'FAM Security')));
        if ($host === '' || $username === '' || $password === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Verification email is not configured.');
        }

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $host;
            $mail->Port = max(1, (int) env('SMTP_PORT', env('MAIL_PORT', 587)));
            $mail->SMTPAuth = true;
            $mail->Username = $username;
            $mail->Password = $password;
            $mail->CharSet = 'UTF-8';
            $mail->SMTPDebug = SMTP::DEBUG_OFF;

            $encryption = strtolower(trim((string) env('SMTP_ENCRYPTION', env('MAIL_ENCRYPTION', 'tls'))));
            if ($encryption === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } elseif ($encryption === 'ssl' || $encryption === 'smtps') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            }

            $mail->setFrom($from, $fromName !== '' ? $fromName : 'FAM Security');
            $mail->addAddress($email);
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->AltBody = $body;
            $mail->send();
        } catch (Throwable $exception) {
            error_log('Mail delivery failed: ' . $exception::class);
            throw new RuntimeException('Unable to send verification email.');
        }
    }

    private function mailEnabled(): bool
    {
        $enabled = env('MAIL_ENABLED', null);
        if ($enabled !== null) {
            return $enabled === true || $enabled === 'true' || $enabled === '1' || $enabled === 1;
        }

        return strtolower((string) env('MAIL_MODE', 'smtp')) === 'smtp';
    }
}
