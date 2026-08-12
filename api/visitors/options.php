<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
requireMethod('GET');
$user=currentApiUser(); if(!VisitorPolicy::can($user,'visitors.view') && !VisitorPolicy::can($user,'visitors.create_walkin')) jsonResponse(false,'You do not have permission to perform this action.',[],403);
jsonResponse(true,'Visitor options retrieved.',visitorService()->options($user));
