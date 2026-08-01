<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('GET');
$user=currentApiUser(); requirePermission($user,'records.view');
$item=liveDataService()->recordShow(idParam());
if ($item===null) jsonResponse(false,'Record not found.',[],404);
jsonResponse(true,'Record retrieved.',['item'=>$item]);
