<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bootstrap.php';

requireMethod('POST');

$body = readJsonBody();
$challengeId = trim((string) ($body['challenge_id'] ?? ''));
$otp = trim((string) ($body['otp'] ?? ''));

if ($challengeId === '' || $otp === '') {
    jsonResponse(false, 'Verification failed. Check the code and try again.', [], 422);
}

$service = new AuthService(Database::connection());
$result = $service->verifyLoginMfa($challengeId, $otp);

jsonResponse($result->success, $result->message, $result->data, $result->status);
