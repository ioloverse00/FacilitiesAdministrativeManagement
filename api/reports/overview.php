<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('GET');
$user=currentApiUser(); requirePermission($user,'reports.view');
jsonResponse(true,'Reports overview retrieved.',['overview'=>liveDataService()->reportsOverview()]);