<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
requireMethod('GET');
$user=currentApiUser(); VisitorPolicy::require($user,'visitors.view');
try { jsonResponse(true,'Visitor records retrieved.',visitorService()->list($_GET)); } catch(Throwable $e) { visitorValidation($e); }
