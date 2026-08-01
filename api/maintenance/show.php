<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('GET');
$user=currentApiUser(); requirePermission($user,'maintenance.view');
$item=liveDataService()->maintenanceShow(idParam());
if ($item===null) jsonResponse(false,'Record not found.',[],404);
jsonResponse(true,'Record retrieved.',['item'=>$item]);
