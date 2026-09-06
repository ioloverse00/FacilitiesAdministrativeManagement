<?php

declare(strict_types=1);

final class GoogleDriveService
{
    private const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    private const GOOGLE_DOC_MIME = 'application/vnd.google-apps.document';

    public function __construct(private readonly GoogleOAuthService $oauth)
    {
    }

    public function uploadDocxAsGoogleDoc(int $userId, string $name, string $content): array
    {
        $token = $this->oauth->accessTokenForUser($userId);
        $boundary = 'fam_google_' . bin2hex(random_bytes(12));
        $metadata = json_encode(['name' => $name, 'mimeType' => self::GOOGLE_DOC_MIME], JSON_THROW_ON_ERROR);
        $body = "--$boundary\r\n"
            . "Content-Type: application/json; charset=UTF-8\r\n\r\n"
            . $metadata . "\r\n"
            . "--$boundary\r\n"
            . "Content-Type: " . self::DOCX_MIME . "\r\n\r\n"
            . $content . "\r\n"
            . "--$boundary--";

        return $this->request('POST', 'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id,name,mimeType,webViewLink', $token, [
            'Content-Type: multipart/related; boundary=' . $boundary,
        ], $body);
    }

    public function exportDocx(int $userId, string $fileId): string
    {
        return $this->export($userId, $fileId, self::DOCX_MIME);
    }

    public function exportPdf(int $userId, string $fileId): string
    {
        return $this->export($userId, $fileId, 'application/pdf');
    }

    private function export(int $userId, string $fileId, string $mimeType): string
    {
        $token = $this->oauth->accessTokenForUser($userId);
        $encodedId = rawurlencode($fileId);
        $url = 'https://www.googleapis.com/drive/v3/files/' . $encodedId . '/export?mimeType=' . rawurlencode($mimeType);
        return $this->rawRequest('GET', $url, $token);
    }

    private function request(string $method, string $url, string $token, array $headers = [], ?string $body = null): array
    {
        $response = $this->rawRequest($method, $url, $token, array_merge(['Accept: application/json'], $headers), $body);
        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new GoogleIntegrationException('google_api', 'Google Drive returned an unreadable response.');
        }
        return $decoded;
    }

    private function rawRequest(string $method, string $url, string $token, array $headers = [], ?string $body = null): string
    {
        if (!function_exists('curl_init')) {
            throw new GoogleIntegrationException('google_api', 'Google API HTTP support is not available on this server.');
        }
        $curl = curl_init($url);
        $headers[] = 'Authorization: Bearer ' . $token;
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 60,
        ]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if (!is_string($response) || $status < 200 || $status >= 300) {
            throw new GoogleIntegrationException('google_api', 'Google Drive operation could not be completed.');
        }
        return $response;
    }
}
