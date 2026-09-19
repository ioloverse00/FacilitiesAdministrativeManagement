<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bootstrap.php';

requireMethod('POST');

$body = readJsonBody();
$email = trim((string) ($body['email'] ?? ''));
$password = (string) ($body['password'] ?? '');
$errors = [];

if ($email === '') {
    $errors['email'] = 'Email is required.';
} elseif (strlen($email) > 190) {
    $errors['email'] = 'Email is too long.';
} elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    $errors['email'] = 'Enter a valid email address.';
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
$result = $service->login($email, $password);

jsonResponse($result->success, $result->message, $result->data, $result->status);
