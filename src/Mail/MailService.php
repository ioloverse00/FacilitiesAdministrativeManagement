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

    public function sendAccountSetupLink(string $email, string $setupUrl, DateTimeImmutable $expiresAt): void
    {
        $this->sendOtpMessage(
            $email,
            'Set up your FAM account password',
            "A FAM account has been prepared for you.\n\nSet your password using this one-time link:\n\n{$setupUrl}\n\nThis link expires at {$expiresAt->format('Y-m-d H:i:s')}.\nIf you did not expect this message, contact the administrator."
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

        if ($this->usesMailtrapSandboxApi()) {
            $this->sendViaMailtrapSandboxApi($email, $subject, $body);
            return;
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
            $mail->Timeout = $this->smtpTimeoutSeconds();
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
            $this->logSmtpFailure($exception, $mail, $host, $from, $email, $encryption);
            throw new RuntimeException('Unable to send verification email.');
        }
    }

    private function smtpTimeoutSeconds(): int
    {
        $timeout = (int) env('SMTP_TIMEOUT_SECONDS', env('MAIL_TIMEOUT_SECONDS', 10));

        return max(1, min(60, $timeout));
    }

    private function sendViaMailtrapSandboxApi(string $email, string $subject, string $body): void
    {
        if (!function_exists('curl_init')) {
            error_log('Mailtrap Sandbox API delivery failed: ' . json_encode([
                'category' => 'configuration_failure',
                'message' => 'PHP cURL extension is unavailable.',
                'transport' => 'mailtrap_sandbox_api',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            throw new RuntimeException('Verification email is not configured.');
        }

        $token = trim((string) env('MAILTRAP_API_TOKEN', ''));
        $inboxId = trim((string) env('MAILTRAP_SANDBOX_INBOX_ID', env('MAILTRAP_INBOX_ID', '')));
        $from = trim((string) env('MAILTRAP_FROM_ADDRESS', env('SMTP_FROM_ADDRESS', env('MAIL_FROM_ADDRESS', ''))));
        $fromName = trim((string) env('MAILTRAP_FROM_NAME', env('SMTP_FROM_NAME', env('MAIL_FROM_NAME', 'FAM Security'))));

        if ($token === '' || !ctype_digit($inboxId) || (int) $inboxId < 1 || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $this->logMailtrapApiFailure('configuration_failure', [
                'message' => 'Mailtrap Sandbox API configuration is incomplete.',
                'api_token_configured' => $token !== '',
                'inbox_id_configured' => $inboxId !== '',
                'inbox_id_valid' => ctype_digit($inboxId) && (int) $inboxId > 0,
                'from_domain' => $this->emailDomain($from),
                'recipient_domain' => $this->emailDomain($email),
            ]);
            throw new RuntimeException('Verification email is not configured.');
        }

        $endpoint = 'https://sandbox.api.mailtrap.io/api/send/' . $inboxId;
        $payload = json_encode([
            'from' => [
                'email' => $from,
                'name' => $fromName !== '' ? $fromName : 'FAM Security',
            ],
            'to' => [
                ['email' => $email],
            ],
            'subject' => $subject,
            'text' => $body,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($payload === false) {
            $this->logMailtrapApiFailure('configuration_failure', [
                'message' => 'Mailtrap Sandbox API payload could not be encoded.',
                'recipient_domain' => $this->emailDomain($email),
            ]);
            throw new RuntimeException('Unable to send verification email.');
        }

        $curl = curl_init($endpoint);
        if ($curl === false) {
            $this->logMailtrapApiFailure('connection_failure', [
                'message' => 'Mailtrap Sandbox API client could not be initialized.',
                'recipient_domain' => $this->emailDomain($email),
            ]);
            throw new RuntimeException('Unable to send verification email.');
        }

        $timeout = $this->mailtrapApiTimeoutSeconds();
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($curl);
        $curlErrorNumber = curl_errno($curl);
        $curlError = curl_error($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($response === false || $curlErrorNumber !== 0) {
            $category = $curlErrorNumber === CURLE_OPERATION_TIMEDOUT
                ? 'connection_timeout'
                : ($this->curlTlsError($curlErrorNumber, $curlError) ? 'tls_failure' : 'connection_failure');
            $this->logMailtrapApiFailure($category, [
                'message' => $this->sanitizeDiagnosticText($curlError),
                'curl_errno' => $curlErrorNumber,
                'http_status' => $statusCode,
                'timeout_seconds' => $timeout,
                'recipient_domain' => $this->emailDomain($email),
            ]);
            throw new RuntimeException('Unable to send verification email.');
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            $this->logMailtrapApiFailure($this->mailtrapHttpFailureCategory($statusCode), [
                'message' => 'Mailtrap Sandbox API returned a non-success status.',
                'http_status' => $statusCode,
                'timeout_seconds' => $timeout,
                'recipient_domain' => $this->emailDomain($email),
            ]);
            throw new RuntimeException('Unable to send verification email.');
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded) || ($decoded['success'] ?? null) !== true) {
            $this->logMailtrapApiFailure('invalid_response', [
                'message' => 'Mailtrap Sandbox API returned an unexpected response.',
                'http_status' => $statusCode,
                'timeout_seconds' => $timeout,
                'recipient_domain' => $this->emailDomain($email),
            ]);
            throw new RuntimeException('Unable to send verification email.');
        }
    }

    private function mailtrapApiTimeoutSeconds(): int
    {
        $timeout = (int) env('MAILTRAP_API_TIMEOUT_SECONDS', 10);

        return max(1, min(60, $timeout));
    }

    private function logMailtrapApiFailure(string $category, array $metadata): void
    {
        error_log('Mailtrap Sandbox API delivery failed: ' . json_encode(array_merge([
            'category' => $category,
            'transport' => 'mailtrap_sandbox_api',
            'api_host' => 'sandbox.api.mailtrap.io',
        ], $metadata), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function mailtrapHttpFailureCategory(int $statusCode): string
    {
        if ($statusCode === 401 || $statusCode === 403) {
            return 'authentication_failure';
        }

        if ($statusCode === 429) {
            return 'rate_limited';
        }

        return 'http_failure';
    }

    private function curlTlsError(int $errorNumber, string $error): bool
    {
        $tlsErrorNumbers = array_filter([
            defined('CURLE_SSL_CONNECT_ERROR') ? constant('CURLE_SSL_CONNECT_ERROR') : null,
            defined('CURLE_PEER_FAILED_VERIFICATION') ? constant('CURLE_PEER_FAILED_VERIFICATION') : null,
            defined('CURLE_SSL_CACERT_BADFILE') ? constant('CURLE_SSL_CACERT_BADFILE') : null,
        ], static fn (?int $value): bool => $value !== null);

        return in_array($errorNumber, $tlsErrorNumbers, true)
            || str_contains(strtolower($error), 'ssl')
            || str_contains(strtolower($error), 'tls')
            || str_contains(strtolower($error), 'certificate');
    }

    private function logSmtpFailure(Throwable $exception, PHPMailer $mail, string $host, string $from, string $recipient, string $encryption): void
    {
        $message = $this->sanitizeDiagnosticText($exception->getMessage());
        $errorInfo = $this->sanitizeDiagnosticText((string) $mail->ErrorInfo);
        $diagnosticText = strtolower($message . ' ' . $errorInfo);

        error_log('Mail delivery failed: ' . json_encode([
            'category' => $this->smtpFailureCategory($diagnosticText),
            'exception' => $exception::class,
            'message' => $message,
            'error_info' => $errorInfo,
            'smtp_host' => $this->sanitizeHost($host),
            'smtp_port' => $mail->Port,
            'smtp_encryption' => $encryption !== '' ? $encryption : 'none',
            'smtp_timeout_seconds' => $mail->Timeout,
            'smtp_username_configured' => trim((string) $mail->Username) !== '',
            'smtp_from_domain' => $this->emailDomain($from),
            'recipient_domain' => $this->emailDomain($recipient),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function smtpFailureCategory(string $diagnosticText): string
    {
        if (str_contains($diagnosticText, 'timed out') || str_contains($diagnosticText, 'timeout')) {
            return 'connection_timeout';
        }

        if (str_contains($diagnosticText, 'authenticate') || str_contains($diagnosticText, 'authentication') || str_contains($diagnosticText, 'username') || str_contains($diagnosticText, 'password')) {
            return 'authentication_failure';
        }

        if (str_contains($diagnosticText, 'starttls') || str_contains($diagnosticText, 'tls') || str_contains($diagnosticText, 'ssl') || str_contains($diagnosticText, 'certificate')) {
            return 'tls_failure';
        }

        if (str_contains($diagnosticText, 'could not connect') || str_contains($diagnosticText, 'connection refused') || str_contains($diagnosticText, 'network is unreachable')) {
            return 'connection_failure';
        }

        return 'smtp_failure';
    }

    private function sanitizeDiagnosticText(string $value): string
    {
        $value = preg_replace('/\s+/', ' ', trim($value)) ?? '';
        $value = preg_replace('/(password|passwd|pwd|token|secret|authorization)\s*[=:]\s*[^;\s,]+/i', '$1=[redacted]', $value) ?? $value;
        $value = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[email]', $value) ?? $value;

        return substr($value, 0, 300);
    }

    private function sanitizeHost(string $host): string
    {
        $host = strtolower(trim($host));

        return preg_match('/^[a-z0-9.-]+$/', $host) === 1 ? substr($host, 0, 190) : '[invalid-host]';
    }

    private function emailDomain(string $email): string
    {
        $parts = explode('@', strtolower(trim($email)), 2);
        $domain = $parts[1] ?? '';

        return preg_match('/^[a-z0-9.-]+$/', $domain) === 1 ? substr($domain, 0, 190) : '';
    }

    private function mailEnabled(): bool
    {
        $enabled = env('MAIL_ENABLED', null);
        if ($enabled !== null) {
            return $enabled === true || $enabled === 'true' || $enabled === '1' || $enabled === 1;
        }

        return strtolower((string) env('MAIL_MODE', 'smtp')) === 'smtp';
    }

    private function usesMailtrapSandboxApi(): bool
    {
        return strtolower(trim((string) env('MAIL_MODE', 'smtp'))) === 'mailtrap_sandbox_api';
    }
}
