<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('GET');
$user = currentApiUser();
$items = contractService()->documents(idParam(), $user);
if ($items === null) {
    jsonResponse(false, 'Contract not found.', [], 404);
}
jsonResponse(true, 'Contract documents retrieved.', $items);
