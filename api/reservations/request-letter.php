<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';

requireMethod('GET');
$user = currentApiUser();
ReservationPolicy::requirePermission($user, 'reservations.view');
$mode = strtolower((string)($_GET['mode'] ?? 'view'));
$file = reservationService()->requestLetterFile(reservationIdentifier(), $user);
if ($file === null) jsonResponse(false, 'Request letter not found.', [], 404);

header('Content-Type: ' . $file['mime_type']);
header('Content-Length: ' . (string)$file['file_size']);
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: ' . ($mode === 'download' ? 'attachment' : 'inline') . '; filename="' . addcslashes($file['download_name'], '"\\') . '"');
readfile($file['absolute_path']);
