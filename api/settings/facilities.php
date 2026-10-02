<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$user = currentApiUser();
requirePermission($user, 'administration.view');

function settingsFacilitiesPdo(): PDO
{
    return Database::connection();
}

function settingsFacilitiesCan(array $user, string $permission): bool
{
    return in_array($permission, $user['permissions'] ?? [], true);
}

function settingsFacilitiesRequireManage(array $user, string $area): void
{
    $permission = $area === 'reservations' ? 'reservations.manage' : 'facility_requests.manage';
    requirePermission($user, $permission);
}

function settingsFacilitiesStatuses(): array
{
    return ['ACTIVE', 'INACTIVE'];
}

function settingsFacilitiesPriorities(): array
{
    return ['LOW', 'NORMAL', 'MEDIUM', 'HIGH', 'CRITICAL'];
}

function settingsFacilitiesStatus(mixed $value): string
{
    $status = strtoupper(trim((string)($value ?: 'ACTIVE')));
    if (!in_array($status, settingsFacilitiesStatuses(), true)) {
        throw new InvalidArgumentException(json_encode(['status' => 'Status must be ACTIVE or INACTIVE.']));
    }
    return $status;
}

function settingsFacilitiesNullableString(mixed $value, int $max = 500): ?string
{
    $text = trim((string)($value ?? ''));
    if ($text === '') return null;
    return mb_substr($text, 0, $max);
}

function settingsFacilitiesUnique(PDO $pdo, string $table, string $column, string $value, string $idColumn, int $ignoreId = 0, bool $hasDeletedAt = false): bool
{
    $sql = "SELECT 1 FROM $table WHERE $column=:value";
    if ($hasDeletedAt) $sql .= ' AND deleted_at IS NULL';
    $params = ['value' => $value];
    if ($ignoreId > 0) {
        $sql .= " AND $idColumn<>:id";
        $params['id'] = $ignoreId;
    }
    $stmt = $pdo->prepare($sql . ' LIMIT 1');
    $stmt->execute($params);
    return $stmt->fetchColumn() === false;
}

function settingsFacilitiesCode(mixed $value, string $field, int $max = 50): string
{
    $code = strtoupper(trim((string)$value));
    if ($code === '') throw new InvalidArgumentException(json_encode([$field => 'Code is required.']));
    if (mb_strlen($code) > $max || !preg_match('/^[A-Z0-9_-]+$/', $code)) {
        throw new InvalidArgumentException(json_encode([$field => 'Code may contain letters, numbers, hyphens, and underscores only.']));
    }
    return $code;
}

function settingsFacilitiesList(array $user): array
{
    $pdo = settingsFacilitiesPdo();
    $buildings = $pdo->query("SELECT building_id id, building_code code, building_name name, address, description, status, updated_at FROM building WHERE deleted_at IS NULL ORDER BY building_name")->fetchAll();
    $spaces = $pdo->query("SELECT fs.facility_space_id id, fs.building_id buildingId, fs.parent_space_id parentSpaceId, fs.space_code code, fs.space_name name, fs.space_type type, fs.floor_number floor, fs.capacity, fs.location_description location, fs.is_reservable reservable, fs.status, fs.updated_at updatedAt, fs.primary_image_original_file_name imageFileName, fs.primary_image_mime_type imageMimeType, fs.primary_image_file_size imageFileSize, fs.primary_image_uploaded_at imageUploadedAt, b.building_name buildingName FROM facility_space fs INNER JOIN building b ON b.building_id=fs.building_id WHERE fs.deleted_at IS NULL ORDER BY b.building_name, fs.space_name")->fetchAll();
    $categories = $pdo->query("SELECT request_category_id id, category_code code, category_name name, description, default_priority defaultPriority, responsible_role_code responsibleRole, status FROM request_category ORDER BY category_name")->fetchAll();
    $sla = $pdo->query("SELECT sp.sla_policy_id id, sp.policy_code code, sp.policy_name name, sp.request_category_id categoryId, rc.category_name categoryName, sp.priority, sp.acknowledgement_minutes acknowledgementMinutes, sp.assignment_minutes assignmentMinutes, sp.resolution_minutes resolutionMinutes, sp.escalation_minutes escalationMinutes, sp.status, sp.effective_from effectiveFrom, sp.effective_to effectiveTo FROM sla_policy sp LEFT JOIN request_category rc ON rc.request_category_id=sp.request_category_id ORDER BY sp.policy_name")->fetchAll();
    return [
        'buildings' => $buildings,
        'spaces' => array_map(static function (array $row): array {
            $row['reservable'] = (int)$row['reservable'] === 1;
            $row['hasImage'] = !empty($row['imageFileName']);
            return $row;
        }, $spaces),
        'categories' => $categories,
        'slaPolicies' => $sla,
        'statuses' => settingsFacilitiesStatuses(),
        'priorities' => settingsFacilitiesPriorities(),
        'canManageReservations' => settingsFacilitiesCan($user, 'reservations.manage'),
        'canManageFacilityRequests' => settingsFacilitiesCan($user, 'facility_requests.manage'),
        'canManageRoomImages' => settingsFacilitiesCan($user, 'reservations.manage') || settingsFacilitiesCan($user, 'reservations.edit'),
    ];
}

function settingsFacilitiesSaveBuilding(array $body, array $user): array
{
    settingsFacilitiesRequireManage($user, 'reservations');
    $pdo = settingsFacilitiesPdo();
    $id = (int)($body['id'] ?? 0);
    $code = settingsFacilitiesCode($body['code'] ?? '', 'code');
    $name = trim((string)($body['name'] ?? ''));
    if ($name === '') throw new InvalidArgumentException(json_encode(['name' => 'Building name is required.']));
    if (!settingsFacilitiesUnique($pdo, 'building', 'building_code', $code, 'building_id', $id, true)) {
        throw new InvalidArgumentException(json_encode(['code' => 'Building code already exists.']));
    }
    $params = [
        'code' => $code,
        'name' => mb_substr($name, 0, 200),
        'address' => settingsFacilitiesNullableString($body['address'] ?? null, 500),
        'description' => settingsFacilitiesNullableString($body['description'] ?? null, 4000),
        'status' => settingsFacilitiesStatus($body['status'] ?? 'ACTIVE'),
    ];
    if ($id > 0) {
        $params['id'] = $id;
        $pdo->prepare('UPDATE building SET building_code=:code, building_name=:name, address=:address, description=:description, status=:status, updated_at=NOW() WHERE building_id=:id AND deleted_at IS NULL')->execute($params);
    } else {
        $pdo->prepare('INSERT INTO building (building_code, building_name, address, description, status, created_at, updated_at) VALUES (:code,:name,:address,:description,:status,NOW(),NOW())')->execute($params);
        $id = (int)$pdo->lastInsertId();
    }
    return ['id' => $id];
}

function settingsFacilitiesSaveSpace(array $body, array $user): array
{
    settingsFacilitiesRequireManage($user, 'reservations');
    $pdo = settingsFacilitiesPdo();
    $id = (int)($body['id'] ?? 0);
    $buildingId = (int)($body['building_id'] ?? $body['buildingId'] ?? 0);
    $building = $pdo->prepare("SELECT 1 FROM building WHERE building_id=:id AND deleted_at IS NULL LIMIT 1");
    $building->execute(['id' => $buildingId]);
    if ($buildingId < 1 || $building->fetchColumn() === false) {
        throw new InvalidArgumentException(json_encode(['building_id' => 'A valid building is required.']));
    }
    $code = settingsFacilitiesCode($body['code'] ?? '', 'code');
    $name = trim((string)($body['name'] ?? ''));
    $type = strtoupper(trim((string)($body['space_type'] ?? $body['type'] ?? '')));
    if ($name === '') throw new InvalidArgumentException(json_encode(['name' => 'Room name is required.']));
    if ($type === '') throw new InvalidArgumentException(json_encode(['space_type' => 'Space type is required.']));
    if (!settingsFacilitiesUnique($pdo, 'facility_space', 'space_code', $code, 'facility_space_id', $id, true)) {
        throw new InvalidArgumentException(json_encode(['code' => 'Space code already exists.']));
    }
    $capacityRaw = $body['capacity'] ?? null;
    $capacity = ($capacityRaw === '' || $capacityRaw === null) ? null : max(0, (int)$capacityRaw);
    $params = [
        'building_id' => $buildingId,
        'code' => $code,
        'name' => mb_substr($name, 0, 200),
        'type' => mb_substr($type, 0, 100),
        'floor' => settingsFacilitiesNullableString($body['floor'] ?? $body['floor_number'] ?? null, 20),
        'capacity' => $capacity,
        'location' => settingsFacilitiesNullableString($body['location'] ?? $body['location_description'] ?? null, 500),
        'reservable' => !empty($body['reservable']) && !in_array($body['reservable'], ['0', 'false', 'FALSE'], true) ? 1 : 0,
        'status' => settingsFacilitiesStatus($body['status'] ?? 'ACTIVE'),
    ];
    if ($id > 0) {
        $params['id'] = $id;
        $pdo->prepare('UPDATE facility_space SET building_id=:building_id, space_code=:code, space_name=:name, space_type=:type, floor_number=:floor, capacity=:capacity, location_description=:location, is_reservable=:reservable, status=:status, updated_at=NOW() WHERE facility_space_id=:id AND deleted_at IS NULL')->execute($params);
    } else {
        $pdo->prepare('INSERT INTO facility_space (building_id, space_code, space_name, space_type, floor_number, capacity, location_description, is_reservable, status, created_at, updated_at) VALUES (:building_id,:code,:name,:type,:floor,:capacity,:location,:reservable,:status,NOW(),NOW())')->execute($params);
        $id = (int)$pdo->lastInsertId();
    }
    return ['id' => $id];
}

function settingsFacilitiesSaveCategory(array $body, array $user): array
{
    settingsFacilitiesRequireManage($user, 'facility_requests');
    $pdo = settingsFacilitiesPdo();
    $id = (int)($body['id'] ?? 0);
    $code = settingsFacilitiesCode($body['code'] ?? '', 'code');
    $name = trim((string)($body['name'] ?? ''));
    if ($name === '') throw new InvalidArgumentException(json_encode(['name' => 'Category name is required.']));
    if (!settingsFacilitiesUnique($pdo, 'request_category', 'category_code', $code, 'request_category_id', $id)) {
        throw new InvalidArgumentException(json_encode(['code' => 'Category code already exists.']));
    }
    $priority = strtoupper(trim((string)($body['default_priority'] ?? $body['defaultPriority'] ?? '')));
    if ($priority !== '' && !in_array($priority, settingsFacilitiesPriorities(), true)) {
        throw new InvalidArgumentException(json_encode(['default_priority' => 'Default priority is invalid.']));
    }
    $params = [
        'code' => $code,
        'name' => mb_substr($name, 0, 150),
        'description' => settingsFacilitiesNullableString($body['description'] ?? null, 4000),
        'priority' => $priority === '' ? null : $priority,
        'role' => settingsFacilitiesNullableString($body['responsible_role_code'] ?? $body['responsibleRole'] ?? null, 80),
        'status' => settingsFacilitiesStatus($body['status'] ?? 'ACTIVE'),
    ];
    if ($id > 0) {
        $params['id'] = $id;
        $pdo->prepare('UPDATE request_category SET category_code=:code, category_name=:name, description=:description, default_priority=:priority, responsible_role_code=:role, status=:status WHERE request_category_id=:id')->execute($params);
    } else {
        $pdo->prepare('INSERT INTO request_category (category_code, category_name, description, default_priority, responsible_role_code, status) VALUES (:code,:name,:description,:priority,:role,:status)')->execute($params);
        $id = (int)$pdo->lastInsertId();
    }
    return ['id' => $id];
}

function settingsFacilitiesSaveSla(array $body, array $user): array
{
    settingsFacilitiesRequireManage($user, 'facility_requests');
    $pdo = settingsFacilitiesPdo();
    $id = (int)($body['id'] ?? 0);
    $code = settingsFacilitiesCode($body['code'] ?? '', 'code');
    $name = trim((string)($body['name'] ?? ''));
    if ($name === '') throw new InvalidArgumentException(json_encode(['name' => 'Policy name is required.']));
    $categoryId = (int)($body['request_category_id'] ?? $body['categoryId'] ?? 0);
    if ($categoryId > 0) {
        $stmt = $pdo->prepare('SELECT 1 FROM request_category WHERE request_category_id=:id LIMIT 1');
        $stmt->execute(['id' => $categoryId]);
        if ($stmt->fetchColumn() === false) throw new InvalidArgumentException(json_encode(['request_category_id' => 'Request category is invalid.']));
    }
    if (!settingsFacilitiesUnique($pdo, 'sla_policy', 'policy_code', $code, 'sla_policy_id', $id)) {
        throw new InvalidArgumentException(json_encode(['code' => 'SLA policy code already exists.']));
    }
    $priority = strtoupper(trim((string)($body['priority'] ?? 'NORMAL')));
    if (!in_array($priority, settingsFacilitiesPriorities(), true)) {
        throw new InvalidArgumentException(json_encode(['priority' => 'Priority is invalid.']));
    }
    $effectiveFrom = trim((string)($body['effective_from'] ?? $body['effectiveFrom'] ?? ''));
    if ($effectiveFrom === '') throw new InvalidArgumentException(json_encode(['effective_from' => 'Effective from date is required.']));
    $effectiveTo = trim((string)($body['effective_to'] ?? $body['effectiveTo'] ?? ''));
    $minutes = static fn(string $key, string $alt): ?int => (($body[$key] ?? $body[$alt] ?? '') === '') ? null : max(0, (int)($body[$key] ?? $body[$alt]));
    $params = [
        'code' => $code,
        'name' => mb_substr($name, 0, 150),
        'category_id' => $categoryId > 0 ? $categoryId : null,
        'priority' => $priority,
        'ack' => $minutes('acknowledgement_minutes', 'acknowledgementMinutes'),
        'assign' => $minutes('assignment_minutes', 'assignmentMinutes'),
        'resolve' => $minutes('resolution_minutes', 'resolutionMinutes'),
        'escalation' => $minutes('escalation_minutes', 'escalationMinutes'),
        'status' => settingsFacilitiesStatus($body['status'] ?? 'ACTIVE'),
        'from' => $effectiveFrom,
        'to' => $effectiveTo === '' ? null : $effectiveTo,
    ];
    if ($id > 0) {
        $params['id'] = $id;
        $pdo->prepare('UPDATE sla_policy SET policy_code=:code, policy_name=:name, request_category_id=:category_id, priority=:priority, acknowledgement_minutes=:ack, assignment_minutes=:assign, resolution_minutes=:resolve, escalation_minutes=:escalation, status=:status, effective_from=:from, effective_to=:to WHERE sla_policy_id=:id')->execute($params);
    } else {
        $pdo->prepare('INSERT INTO sla_policy (policy_code, policy_name, request_category_id, priority, acknowledgement_minutes, assignment_minutes, resolution_minutes, escalation_minutes, status, effective_from, effective_to) VALUES (:code,:name,:category_id,:priority,:ack,:assign,:resolve,:escalation,:status,:from,:to)')->execute($params);
        $id = (int)$pdo->lastInsertId();
    }
    return ['id' => $id];
}

try {
    if ($method === 'GET') {
        jsonResponse(true, 'Facilities settings retrieved.', settingsFacilitiesList($user));
    }

    if ($method === 'POST') {
        requireCsrfToken();
        $body = settingsBody();
        $action = (string)($body['action'] ?? $_GET['action'] ?? '');
        $result = match ($action) {
            'save-building' => settingsFacilitiesSaveBuilding($body, $user),
            'save-space' => settingsFacilitiesSaveSpace($body, $user),
            'save-category' => settingsFacilitiesSaveCategory($body, $user),
            'save-sla' => settingsFacilitiesSaveSla($body, $user),
            default => throw new InvalidArgumentException(json_encode(['action' => 'Unsupported Facilities settings action.'])),
        };
        jsonResponse(true, 'Facilities settings saved.', ['item' => $result]);
    }

    header('Allow: GET, POST');
    jsonResponse(false, 'Method not allowed.', [], 405);
} catch (Throwable $e) {
    settingsValidation($e);
}
