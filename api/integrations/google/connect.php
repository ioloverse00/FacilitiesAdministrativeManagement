<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';

requireMethod('POST');
$user = currentApiUser();
requireCsrfToken();

try {
    jsonResponse(true, 'Google authorization URL created.', [
        'authorization_url' => googleOAuthService()->authorizationUrl($user),
    ]);
} catch (GoogleIntegrationException $exception) {
    jsonResponse(false, $exception->getMessage(), ['errors' => $exception->validationErrors()], 422);
}
