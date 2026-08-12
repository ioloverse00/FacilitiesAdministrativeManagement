<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
requireMethod('POST');
$user=currentApiUser(); if(!VisitorPolicy::can($user,'visitors.review') && !VisitorPolicy::can($user,'visitors.manage')) jsonResponse(false,'You do not have permission to perform this action.',[],403); requireCsrfToken();
try { jsonResponse(true,'Visitor record updated.',['item'=>visitorService()->update(visitorId(),visitorBody(),$user)]); } catch(Throwable $e) { visitorValidation($e); }
