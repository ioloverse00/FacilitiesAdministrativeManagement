<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('GET');
$user = currentApiUser();
$items = contractService()->list($_GET, $user);
jsonResponse(true, 'Contracts retrieved.', $items);
