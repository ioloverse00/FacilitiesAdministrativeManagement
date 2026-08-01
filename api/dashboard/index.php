<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('GET');
$user=currentApiUser(); requirePermission($user,'dashboard.view');
jsonResponse(true,'Dashboard data retrieved.',['dashboard'=>liveDataService()->dashboard()]);