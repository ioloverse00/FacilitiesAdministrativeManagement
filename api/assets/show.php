<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('GET');
$user=currentApiUser(); requirePermission($user,'assets.view');
$item=liveDataService()->assetShow(idParam());
if ($item===null) jsonResponse(false,'Record not found.',[],404);
jsonResponse(true,'Record retrieved.',['item'=>$item]);
