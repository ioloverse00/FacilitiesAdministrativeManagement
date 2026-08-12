<?php

declare(strict_types=1);

final class MailService
{
    public function sendOtp(string $email, string $otp, DateTimeImmutable $expiresAt): void
    {
        $mode = strtolower((string) env('MAIL_MODE', 'log'));
        if ($mode === 'smtp') {
            $this->sendBasicSmtp($email, $otp, $expiresAt);
            return;
        }
        $this->logOtp($email, $otp, $expiresAt);
    }

    private function logOtp(string $email, string $otp, DateTimeImmutable $expiresAt): void
    {
        $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $line = sprintf("[%s] Visitor Registration OTP for %s: %s (expires %s)\n", date('c'), $email, $otp, $expiresAt->format('Y-m-d H:i:s'));
        file_put_contents($dir . DIRECTORY_SEPARATOR . 'mail.log', $line, FILE_APPEND | LOCK_EX);
    }

    private function sendBasicSmtp(string $email, string $otp, DateTimeImmutable $expiresAt): void
    {
        $from = (string) env('MAIL_FROM_ADDRESS', 'no-reply@example.local');
        $fromName = (string) env('MAIL_FROM_NAME', 'FAM Visitor Registration');
        $subject = 'Your visitor registration verification code';
        $body = "Your verification code is {$otp}.\n\nIt expires at {$expiresAt->format('Y-m-d H:i')}.\nDo not share this code. If you did not request it, you may ignore this email.";
        $headers = "From: {$fromName} <{$from}>\r\nContent-Type: text/plain; charset=utf-8";

        if (!mail($email, $subject, $body, $headers)) {
            error_log('Visitor OTP mail send failed.');
            throw new RuntimeException('Unable to send verification email.');
        }
    }
}

