<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

requireMethod('GET');
$user = currentApiUser();
requireVisitorBlacklistAdmin($user);

try {
    $name = trim((string)($_GET['name'] ?? $_GET['search'] ?? $_GET['q'] ?? ''));
    if ($name === '') {
        jsonResponse(true, 'Visitor records retrieved.', ['items' => []]);
    }
    $stmt = Database::connection()->prepare("SELECT v.visitor_id, v.first_name, v.middle_name, v.last_name, v.organization_name, v.visitor_type, v.email_address, v.contact_number, v.identification_last4, active_bl.visitor_blacklist_id active_blacklist_id FROM visitor v LEFT JOIN visitor_blacklist active_bl ON active_bl.visitor_id=v.visitor_id AND active_bl.status='ACTIVE' WHERE v.deleted_at IS NULL AND TRIM(CONCAT_WS(' ', v.first_name, v.middle_name, v.last_name)) LIKE :search ORDER BY v.updated_at DESC, v.visitor_id DESC LIMIT 20");
    $stmt->execute(['search' => '%' . $name . '%']);
    $items = array_map(static function (array $row): array {
        $full = trim(($row['first_name'] ?? '') . ' ' . ($row['middle_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        return [
            'id' => (int)$row['visitor_id'],
            'name' => $full,
            'full_name' => $full,
            'visitor_type' => $row['visitor_type'],
            'organization_name' => $row['organization_name'],
            'email' => $row['email_address'],
            'email_address' => $row['email_address'],
            'phone' => $row['contact_number'],
            'mobile_number' => $row['contact_number'],
            'identification_last4' => $row['identification_last4'],
            'is_blacklisted' => !empty($row['active_blacklist_id']),
        ];
    }, $stmt->fetchAll());
    jsonResponse(true, 'Visitor records retrieved.', ['items' => $items]);
} catch (Throwable $e) {
    settingsValidation($e);
}
