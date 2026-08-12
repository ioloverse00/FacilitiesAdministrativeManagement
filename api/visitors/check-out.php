<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
requireMethod('POST');
$user=currentApiUser(); VisitorPolicy::require($user,'visitors.checkout'); requireCsrfToken();
$body=visitorBody();
try { jsonResponse(true,'Visitor checked out.',['item'=>visitorService()->checkOut(visitorId(),$body['remarks'] ?? null,$user)]); } catch(Throwable $e) { visitorValidation($e); }
