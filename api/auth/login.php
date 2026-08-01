<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bootstrap.php';

requireMethod('POST');

$body = readJsonBody();
$username = trim((string) ($body['username'] ?? ''));
$password = (string) ($body['password'] ?? '');
$errors = [];

if ($username === '') {
    $errors['username'] = 'Username is required.';
} elseif (strlen($username) > 100) {
    $errors['username'] = 'Username is too long.';
}

if ($password === '') {
    $errors['password'] = 'Password is required.';
} elseif (strlen($password) > 1024) {
    $errors['password'] = 'Password is too long.';
}

if ($errors !== []) {
    jsonResponse(false, 'Please check the submitted credentials.', ['errors' => $errors], 422);
}

$service = new AuthService(Database::connection());
$result = $service->login($username, $password);

jsonResponse($result->success, $result->message, $result->data, $result->status);
