<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$user = currentApiUser();
$spaceIdValue = $_GET['space_id'] ?? $_POST['space_id'] ?? null;
if ($spaceIdValue === null || !ctype_digit((string) $spaceIdValue)) {
    jsonResponse(false, 'Validation failed.', ['errors' => ['facility_space_id' => 'A valid room is required.']], 422);
}

$spaceId = (int) $spaceIdValue;
$service = reservationService();

if ($method === 'GET') {
    if (!ReservationPolicy::hasPermission($user, 'reservations.view')
        && !ReservationPolicy::hasPermission($user, 'reservations.view_own')
        && !ReservationPolicy::hasPermission($user, 'reservations.create')) {
        jsonResponse(false, 'You do not have permission to perform this action.', [], 403);
    }

    $file = $service->roomImageFile($spaceId);
    if ($file === null) {
        jsonResponse(false, 'Room image not found.', [], 404);
    }

    header('Content-Type: ' . $file['mime_type']);
    header('Content-Length: ' . (string) $file['file_size']);
    header('Content-Disposition: inline; filename="' . addslashes($file['download_name']) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($file['absolute_path']);
    exit;
}

if ($method === 'POST') {
    requireCsrfToken();
    try {
        $room = $service->uploadRoomImage($spaceId, $_FILES['room_image'] ?? [], $user);
        jsonResponse(true, 'Room image saved.', ['room' => $room]);
    } catch (Throwable $e) {
        validationResponse($e);
    }
}

if ($method === 'DELETE') {
    requireCsrfToken();
    try {
        $room = $service->removeRoomImage($spaceId, $user);
        jsonResponse(true, 'Room image removed.', ['room' => $room]);
    } catch (Throwable $e) {
        validationResponse($e);
    }
}

header('Allow: GET, POST, DELETE');
jsonResponse(false, 'Method not allowed.', [], 405);
