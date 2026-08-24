<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('GET');
$user = currentApiUser();
$value = $_GET['id'] ?? $_GET['contract_number'] ?? null;
if ($value === null || $value === '') {
    jsonResponse(false, 'Contract id or contract_number is required.', [], 422);
}
$item = contractService()->show(ctype_digit((string) $value) ? (int) $value : (string) $value, $user);
if ($item === null) {
    jsonResponse(false, 'Contract not found.', [], 404);
}
jsonResponse(true, 'Contract retrieved.', ['item' => $item]);
