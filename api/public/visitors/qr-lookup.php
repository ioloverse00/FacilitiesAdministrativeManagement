<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
requireMethod('GET');
try {
    jsonResponse(true, 'Visitor pass is valid.', publicVisitorService()->lookupQr((string) ($_GET['token'] ?? '')));
} catch (Throwable $e) {
    publicVisitorHandle($e);
}

