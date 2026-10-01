<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

requireMethod('GET');
$user = currentApiUser();
requireVisitorBlacklistAdmin($user);

try {
    jsonResponse(true, 'Visitor records retrieved.', settingsVisitorService()->visitorBlacklistCandidates((string)($_GET['search'] ?? $_GET['q'] ?? '')));
} catch (Throwable $e) {
    settingsValidation($e);
}
