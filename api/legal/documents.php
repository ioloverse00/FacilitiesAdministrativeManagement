<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('GET');
$user = currentApiUser();
LegalPolicy::requirePermission($user, 'legal.manage');
DocumentPolicy::requirePermission($user, 'records.view');
$item = legalMatterService()->show(idParam());
if ($item === null) {
    jsonResponse(false, 'Legal matter not found.', [], 404);
}
jsonResponse(true, 'Supporting documents retrieved.', ['items' => $item['supportingDocuments'] ?? []]);
