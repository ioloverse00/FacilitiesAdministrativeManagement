<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
requireMethod('POST');
try {
    jsonResponse(true, 'Visitor registration submitted.', publicVisitorService()->submit(publicVisitorBody()), 201);
} catch (Throwable $e) {
    publicVisitorHandle($e);
}

