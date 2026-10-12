<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

const BUILDING_IMAGE_MAX_SIZE = 5242880;
const BUILDING_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];
const BUILDING_IMAGE_MIME = ['image/jpeg', 'image/png', 'image/webp'];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$user = currentApiUser();
$buildingIdValue = $_GET['building_id'] ?? $_POST['building_id'] ?? null;
if ($buildingIdValue === null || !ctype_digit((string) $buildingIdValue)) {
    jsonResponse(false, 'Validation failed.', ['errors' => ['building_id' => 'A valid building is required.']], 422);
}

$buildingId = (int) $buildingIdValue;
$pdo = Database::connection();

function buildingImageRequireManage(array $user): void
{
    requirePermission($user, 'reservations.manage');
}

function buildingImageRow(PDO $pdo, int $buildingId, bool $lock = false): ?array
{
    $sql = "SELECT building_id id, building_code code, building_name name, address, description, status,
        primary_image_original_file_name, primary_image_storage_path, primary_image_mime_type,
        primary_image_file_size, primary_image_uploaded_at
        FROM building WHERE building_id=:id AND deleted_at IS NULL";
    $sql .= ' LIMIT 1';
    if ($lock) {
        $sql .= ' FOR UPDATE';
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['id' => $buildingId]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function buildingImageMetadata(array $row): array
{
    $buildingId = (int) ($row['id'] ?? $row['building_id'] ?? 0);
    $hasImage = !empty($row['primary_image_storage_path']) && !empty($row['primary_image_mime_type']);
    return [
        'id' => $buildingId,
        'code' => $row['code'] ?? $row['building_code'] ?? null,
        'name' => $row['name'] ?? $row['building_name'] ?? null,
        'address' => $row['address'] ?? null,
        'description' => $row['description'] ?? null,
        'status' => $row['status'] ?? null,
        'hasImage' => $hasImage,
        'imageUrl' => $hasImage ? 'settings/building-image.php?building_id=' . $buildingId : null,
        'imageFileName' => $hasImage ? (string) ($row['primary_image_original_file_name'] ?? '') : null,
        'imageMimeType' => $hasImage ? (string) ($row['primary_image_mime_type'] ?? '') : null,
        'imageFileSize' => $hasImage ? (int) ($row['primary_image_file_size'] ?? 0) : null,
        'imageUploadedAt' => $hasImage ? ($row['primary_image_uploaded_at'] ?? null) : null,
    ];
}

function buildingImageAssertMutable(?array $building): array
{
    if ($building === null) {
        throw new InvalidArgumentException(json_encode(['building_id' => 'Building record is required.']));
    }

    $status = strtoupper((string) ($building['status'] ?? ''));
    if (!in_array($status, ['ACTIVE', 'INACTIVE'], true)) {
        throw new InvalidArgumentException(json_encode(['building_id' => 'Building image cannot be changed for this building status.']));
    }

    return $building;
}

function buildingImageValidate(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException(json_encode(['building_image' => 'Upload a building image.']));
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > BUILDING_IMAGE_MAX_SIZE) {
        throw new InvalidArgumentException(json_encode(['building_image' => 'Building image must be 5 MB or smaller.']));
    }
    $original = basename((string) ($file['name'] ?? 'building-image'));
    $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if (!in_array($extension, BUILDING_IMAGE_EXTENSIONS, true)) {
        throw new InvalidArgumentException(json_encode(['building_image' => 'Upload a JPEG, PNG, or WebP image.']));
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    $mime = $tmp !== '' ? ((new finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '') : '';
    if (!in_array($mime, BUILDING_IMAGE_MIME, true)) {
        throw new InvalidArgumentException(json_encode(['building_image' => 'Building image file type is not supported.']));
    }
    return [
        'tmp_name' => $tmp,
        'original_name' => buildingImageSafeFileName($original),
        'extension' => $extension,
        'mime_type' => $mime,
        'size' => $size,
    ];
}

function buildingImageStore(array $upload, int $buildingId): array
{
    $relativeDir = 'buildings/' . $buildingId . '/primary-image';
    $absoluteDir = StoragePath::resolveWithin('buildings', $relativeDir);
    if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0775, true) && !is_dir($absoluteDir)) {
        throw new RuntimeException('Unable to prepare building image storage.');
    }
    $storedName = bin2hex(random_bytes(16)) . '.' . $upload['extension'];
    $absolutePath = $absoluteDir . DIRECTORY_SEPARATOR . $storedName;
    $stored = is_uploaded_file($upload['tmp_name'])
        ? move_uploaded_file($upload['tmp_name'], $absolutePath)
        : (PHP_SAPI === 'cli' && copy($upload['tmp_name'], $absolutePath));
    if (!$stored) {
        throw new RuntimeException('Unable to store building image.');
    }
    return ['relative_path' => $relativeDir . '/' . $storedName, 'absolute_path' => $absolutePath];
}

function buildingImageDeleteFile(string $relativePath): void
{
    $absolute = StoragePath::resolveExistingWithin('buildings', $relativePath);
    if ($absolute !== null && is_file($absolute)) {
        @unlink($absolute);
    }
}

function buildingImageSafeFileName(string $name): string
{
    $name = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $name) ?? 'building-image';
    $name = trim($name, " .\t\n\r\0\x0B");
    $name = $name === '' ? 'building-image' : $name;
    return function_exists('mb_substr') ? mb_substr($name, 0, 180) : substr($name, 0, 180);
}

function buildingImageValidation(Throwable $e): void
{
    if ($e instanceof InvalidArgumentException) {
        $errors = json_decode($e->getMessage(), true);
        jsonResponse(false, 'Validation failed.', ['errors' => is_array($errors) ? $errors : ['request' => $e->getMessage()]], 422);
    }
    throw $e;
}

try {
    if ($method === 'GET') {
        requirePermission($user, 'administration.view');
        $building = buildingImageRow($pdo, $buildingId);
        if ($building === null || empty($building['primary_image_storage_path'])) {
            jsonResponse(false, 'Building image not found.', [], 404);
        }
        $absolute = StoragePath::resolveExistingWithin('buildings', (string) $building['primary_image_storage_path']);
        if ($absolute === null) {
            jsonResponse(false, 'Building image not found.', [], 404);
        }
        header('Content-Type: ' . (string) $building['primary_image_mime_type']);
        header('Content-Length: ' . (string) ((int) $building['primary_image_file_size']));
        header('Content-Disposition: inline; filename="' . addslashes((string) ($building['primary_image_original_file_name'] ?: 'building-image')) . '"');
        header('X-Content-Type-Options: nosniff');
        readfile($absolute);
        exit;
    }

    if ($method === 'POST') {
        buildingImageRequireManage($user);
        requireCsrfToken();
        $upload = buildingImageValidate($_FILES['building_image'] ?? []);
        $stored = buildingImageStore($upload, $buildingId);
        $previous = '';
        try {
            $pdo->beginTransaction();
            $building = buildingImageAssertMutable(buildingImageRow($pdo, $buildingId, true));
            $previous = (string) ($building['primary_image_storage_path'] ?? '');
            $pdo->prepare("UPDATE building SET primary_image_original_file_name=:original, primary_image_storage_path=:path, primary_image_mime_type=:mime, primary_image_file_size=:size, primary_image_uploaded_by_user_id=:user_id, primary_image_uploaded_at=NOW(), updated_at=NOW() WHERE building_id=:id AND deleted_at IS NULL")->execute([
                'original' => $upload['original_name'],
                'path' => $stored['relative_path'],
                'mime' => $upload['mime_type'],
                'size' => $upload['size'],
                'user_id' => (int) $user['id'],
                'id' => $buildingId,
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (is_file($stored['absolute_path'])) {
                @unlink($stored['absolute_path']);
            }
            throw $e;
        }
        if ($previous !== '' && $previous !== $stored['relative_path']) {
            buildingImageDeleteFile($previous);
        }
        jsonResponse(true, 'Building image saved.', ['building' => buildingImageMetadata(buildingImageRow($pdo, $buildingId) ?? [])]);
    }

    if ($method === 'DELETE') {
        buildingImageRequireManage($user);
        requireCsrfToken();
        $previous = '';
        try {
            $pdo->beginTransaction();
            $building = buildingImageAssertMutable(buildingImageRow($pdo, $buildingId, true));
            $previous = (string) ($building['primary_image_storage_path'] ?? '');
            $pdo->prepare("UPDATE building SET primary_image_original_file_name=NULL, primary_image_storage_path=NULL, primary_image_mime_type=NULL, primary_image_file_size=NULL, primary_image_uploaded_by_user_id=NULL, primary_image_uploaded_at=NULL, updated_at=NOW() WHERE building_id=:id AND deleted_at IS NULL")->execute(['id' => $buildingId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        if ($previous !== '') {
            buildingImageDeleteFile($previous);
        }
        jsonResponse(true, 'Building image removed.', ['building' => buildingImageMetadata(buildingImageRow($pdo, $buildingId) ?? [])]);
    }

    header('Allow: GET, POST, DELETE');
    jsonResponse(false, 'Method not allowed.', [], 405);
} catch (Throwable $e) {
    buildingImageValidation($e);
}
