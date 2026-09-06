<?php

declare(strict_types=1);

final class GoogleTokenCrypto
{
    public function configured(): bool
    {
        return $this->key() !== null;
    }

    public function encrypt(string $plaintext): string
    {
        $key = $this->key();
        if ($key === null) {
            throw new GoogleIntegrationException('google_encryption', 'Google token encryption is not configured.');
        }
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            throw new GoogleIntegrationException('google_encryption', 'Unable to protect Google authorization data.');
        }
        return base64_encode($iv . $tag . $ciphertext);
    }

    public function decrypt(string $payload): string
    {
        $key = $this->key();
        if ($key === null) {
            throw new GoogleIntegrationException('google_encryption', 'Google token encryption is not configured.');
        }
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < 29) {
            throw new GoogleIntegrationException('google_authorization', 'Stored Google authorization data is invalid.');
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);
        $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plaintext === false) {
            throw new GoogleIntegrationException('google_authorization', 'Stored Google authorization data could not be opened.');
        }
        return $plaintext;
    }

    private function key(): ?string
    {
        $configured = trim((string) env('GOOGLE_TOKEN_ENCRYPTION_KEY', ''));
        if ($configured === '') {
            return null;
        }
        $decoded = base64_decode($configured, true);
        if ($decoded !== false && strlen($decoded) === 32) {
            return $decoded;
        }
        return hash('sha256', $configured, true);
    }
}
