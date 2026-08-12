<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
requireMethod('POST');
$user=currentApiUser(); if(!VisitorPolicy::can($user,'visitors.review') && !VisitorPolicy::can($user,'visitors.approve')) jsonResponse(false,'You do not have permission to perform this action.',[],403); requireCsrfToken();
$body=visitorBody();
try { jsonResponse(true,'Visitor review action completed.',['item'=>visitorService()->review(visitorId(), strtoupper((string)($body['action'] ?? '')), $body['remarks'] ?? null, $user)]); } catch(Throwable $e) { visitorValidation($e); }
