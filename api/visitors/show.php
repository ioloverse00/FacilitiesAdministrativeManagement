<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
requireMethod('GET');
$user=currentApiUser(); VisitorPolicy::require($user,'visitors.view');
$item=visitorService()->show(visitorId());
if(!$item) jsonResponse(false,'Visitor visit was not found.',[],404);
jsonResponse(true,'Visitor record retrieved.',['item'=>$item]);
