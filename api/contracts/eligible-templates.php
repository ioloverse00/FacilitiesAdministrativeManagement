<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('GET');
$user = currentApiUser();
jsonResponse(true, 'Eligible contract templates retrieved.', contractService()->eligibleTemplates($_GET, $user));
