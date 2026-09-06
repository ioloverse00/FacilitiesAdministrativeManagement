<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';

requireMethod('POST');
$user = currentApiUser();
requireCsrfToken();

googleOAuthService()->disconnect($user);
jsonResponse(true, 'Google account disconnected.');
