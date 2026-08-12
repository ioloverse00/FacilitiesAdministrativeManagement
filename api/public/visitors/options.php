<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
requireMethod('GET');
jsonResponse(true, 'Visitor registration options retrieved.', publicVisitorService()->options());

