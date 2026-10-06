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
    requirePermission($user, 'reservations.manage');
}

function settingsFacilitiesStatuses(): array
{
    return ['ACTIVE', 'INACTIVE'];
}

function settingsFacilitiesCapacityUnits(): array
{
    return ['PAX', 'VEHICLES'];
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
    $spaces = $pdo->query("SELECT fs.facility_space_id id, fs.building_id buildingId, fs.parent_space_id parentSpaceId, fs.space_code code, fs.space_name name, fs.space_type type, fs.floor_number floor, fs.capacity, fs.capacity_unit capacityUnit, fs.location_description location, fs.is_reservable reservable, fs.status, fs.updated_at updatedAt, fs.primary_image_original_file_name imageFileName, fs.primary_image_mime_type imageMimeType, fs.primary_image_file_size imageFileSize, fs.primary_image_uploaded_at imageUploadedAt, b.building_name buildingName FROM facility_space fs INNER JOIN building b ON b.building_id=fs.building_id WHERE fs.deleted_at IS NULL ORDER BY b.building_name, fs.space_name")->fetchAll();
    return [
        'buildings' => $buildings,
        'spaces' => array_map(static function (array $row): array {
            $row['reservable'] = (int)$row['reservable'] === 1;
            $row['hasImage'] = !empty($row['imageFileName']);
            $row['capacityUnit'] = $row['capacityUnit'] ?: 'PAX';
            $row['capacityLabel'] = $row['capacity'] === null ? null : ((int)$row['capacity']) . ' ' . strtolower((string)$row['capacityUnit']);
            return $row;
        }, $spaces),
        'statuses' => settingsFacilitiesStatuses(),
        'capacityUnits' => settingsFacilitiesCapacityUnits(),
        'priorities' => settingsFacilitiesPriorities(),
        'canManageReservations' => settingsFacilitiesCan($user, 'reservations.manage'),
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
    if ($name === '') throw new InvalidArgumentException(json_encode(['name' => 'Facility name is required.']));
    if ($type === '') throw new InvalidArgumentException(json_encode(['space_type' => 'Space type is required.']));
    if (!settingsFacilitiesUnique($pdo, 'facility_space', 'space_code', $code, 'facility_space_id', $id, true)) {
        throw new InvalidArgumentException(json_encode(['code' => 'Space code already exists.']));
    }
    $capacityRaw = $body['capacity'] ?? null;
    $capacity = ($capacityRaw === '' || $capacityRaw === null) ? null : max(0, (int)$capacityRaw);
    $capacityUnit = strtoupper(trim((string)($body['capacity_unit'] ?? $body['capacityUnit'] ?? 'PAX')));
    if (!in_array($capacityUnit, settingsFacilitiesCapacityUnits(), true)) {
        throw new InvalidArgumentException(json_encode(['capacity_unit' => 'Capacity unit must be PAX or VEHICLES.']));
    }
    $params = [
        'building_id' => $buildingId,
        'code' => $code,
        'name' => mb_substr($name, 0, 200),
        'type' => mb_substr($type, 0, 100),
        'floor' => settingsFacilitiesNullableString($body['floor'] ?? $body['floor_number'] ?? null, 20),
        'capacity' => $capacity,
        'capacity_unit' => $capacityUnit,
        'location' => settingsFacilitiesNullableString($body['location'] ?? $body['location_description'] ?? null, 500),
        'reservable' => !empty($body['reservable']) && !in_array($body['reservable'], ['0', 'false', 'FALSE'], true) ? 1 : 0,
        'status' => settingsFacilitiesStatus($body['status'] ?? 'ACTIVE'),
    ];
    if ($id > 0) {
        $params['id'] = $id;
        $pdo->prepare('UPDATE facility_space SET building_id=:building_id, space_code=:code, space_name=:name, space_type=:type, floor_number=:floor, capacity=:capacity, capacity_unit=:capacity_unit, location_description=:location, is_reservable=:reservable, status=:status, updated_at=NOW() WHERE facility_space_id=:id AND deleted_at IS NULL')->execute($params);
    } else {
        $pdo->prepare('INSERT INTO facility_space (building_id, space_code, space_name, space_type, floor_number, capacity, capacity_unit, location_description, is_reservable, status, created_at, updated_at) VALUES (:building_id,:code,:name,:type,:floor,:capacity,:capacity_unit,:location,:reservable,:status,NOW(),NOW())')->execute($params);
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
            default => throw new InvalidArgumentException(json_encode(['action' => 'Unsupported Facilities settings action.'])),
        };
        jsonResponse(true, 'Facilities settings saved.', ['item' => $result]);
    }

    header('Allow: GET, POST');
    jsonResponse(false, 'Method not allowed.', [], 405);
} catch (Throwable $e) {
    settingsValidation($e);
}
