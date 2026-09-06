<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bootstrap.php';

requireMethod('POST');

$service = new AuthService(Database::connection());
$result = $service->resendLoginMfa();

jsonResponse($result->success, $result->message, $result->data, $result->status);
