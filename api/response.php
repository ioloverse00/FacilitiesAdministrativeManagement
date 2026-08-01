<?php

declare(strict_types=1);

/**
 * @param array<string, mixed> $data
 */
function jsonResponse(bool $success, string $message, array $data = [], int $status = 200): never
{
    http_response_code($status);

    $payload = [
        'success' => $success,
        'message' => $message,
        'data' => (object) $data,
    ];

    $json = json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
    );

    echo $json;
    exit;
}

/**
 * @return array<string, mixed>
 */
function readJsonBody(int $maxBytes = 16384): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';

    if ($contentType === '' || stripos($contentType, 'application/json') !== 0) {
        jsonResponse(false, 'Content-Type must be application/json.', [], 415);
    }

    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

    if ($contentLength > $maxBytes) {
        jsonResponse(false, 'Request body is too large.', [], 413);
    }

    $rawBody = file_get_contents('php://input');

    if ($rawBody === false) {
        jsonResponse(false, 'Unable to read request body.', [], 400);
    }

    if (strlen($rawBody) > $maxBytes) {
        jsonResponse(false, 'Request body is too large.', [], 413);
    }

    if (trim($rawBody) === '') {
        return [];
    }

    try {
        $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        jsonResponse(false, 'Malformed JSON request body.', [], 400);
    }

    if (!is_array($decoded) || array_is_list($decoded)) {
        jsonResponse(false, 'JSON request body must be an object.', [], 400);
    }

    return $decoded;
}

function requireMethod(string $method): void
{
    $actualMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($actualMethod === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    if ($actualMethod !== $method) {
        header('Allow: ' . $method);
        jsonResponse(false, 'Method not allowed.', [], 405);
    }
}
