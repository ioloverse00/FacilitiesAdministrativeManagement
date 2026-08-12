<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
requireMethod('POST');
$user=currentApiUser(); VisitorPolicy::require($user,'visitors.checkin'); requireCsrfToken();
try { jsonResponse(true,'Visitor checked in.',['item'=>visitorService()->checkIn(visitorId(),visitorBody(),$user)]); } catch(Throwable $e) { visitorValidation($e); }
