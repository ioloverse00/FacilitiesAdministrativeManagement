<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
requireMethod('GET');
$user=currentApiUser(); VisitorPolicy::require($user,'visitors.manage_badges');
jsonResponse(true,'Visitor badges retrieved.',['items'=>Database::connection()->query("SELECT visitor_badge_id id,badge_number,badge_status,issued_to_visit_id,issued_at,returned_at FROM visitor_badge ORDER BY badge_number")->fetchAll()]);
