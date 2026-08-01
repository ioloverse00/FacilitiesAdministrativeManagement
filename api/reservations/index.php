<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('GET');
$user=currentApiUser(); requirePermission($user,'reservations.view');
jsonResponse(true,'List retrieved.',liveDataService()->reservationsList($_GET));
