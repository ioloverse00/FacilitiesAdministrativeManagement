<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
requireMethod('POST');
try {
    jsonResponse(true, 'Verification code resent.', publicVisitorService()->resend(publicVisitorBody()));
} catch (Throwable $e) {
    publicVisitorHandle($e);
}

