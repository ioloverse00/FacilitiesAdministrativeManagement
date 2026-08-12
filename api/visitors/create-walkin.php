<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
requireMethod('POST');
$user=currentApiUser(); VisitorPolicy::require($user,'visitors.create_walkin'); requireCsrfToken();
try { jsonResponse(true,'Walk-in visitor registered.',['item'=>visitorService()->createWalkIn(visitorBody(),$user)],201); } catch(Throwable $e) { visitorValidation($e); }
