<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$user = currentApiUser();
requireVisitorBlacklistAdmin($user);

try {
    if ($method === 'GET') {
        jsonResponse(true, 'Visitor blacklist retrieved.', settingsVisitorService()->blacklistEntries($_GET));
    }
    if ($method === 'POST') {
        requireCsrfToken();
        $body = settingsBody();
        $visitorId = (int)($body['visitor_id'] ?? 0);
        $reason = (string)($body['reason'] ?? '');
        jsonResponse(true, 'Visitor blacklisted.', ['item' => settingsVisitorService()->blacklistVisitor($visitorId, $reason, $user)], 201);
    }
    if ($method === 'DELETE') {
        requireCsrfToken();
        $body = settingsBody();
        $blacklistId = (int)($body['blacklist_id'] ?? $body['id'] ?? 0);
        $reason = (string)($body['removal_reason'] ?? '');
        jsonResponse(true, 'Visitor removed from blacklist.', ['item' => settingsVisitorService()->unblacklistVisitor($blacklistId, $reason, $user)]);
    }
    header('Allow: GET, POST, DELETE');
    jsonResponse(false, 'Method not allowed.', [], 405);
} catch (Throwable $e) {
    settingsValidation($e);
}
