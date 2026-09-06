<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';

requireMethod('GET');

try {
    $returnUrl = googleOAuthService()->handleCallback($_GET);
    header('Location: ' . $returnUrl, true, 302);
    exit;
} catch (GoogleIntegrationException $exception) {
    http_response_code(400);
    echo 'Google authorization could not be completed.';
}
