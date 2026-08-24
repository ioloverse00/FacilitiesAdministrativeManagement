<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '_bootstrap.php';

requireMethod('POST');
requireCsrfToken();
$user = currentEmployeeUser();
if (filter_var($_GET['analyze_ai'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
    try {
        $id = employeeReservationIdentifier();
        $service = employeeReservationService();
        $item = $service->details($id);
        if ($item === null || !$service->employeeOwns($item, $user)) {
            jsonResponse(false, 'Reservation not found.', [], 404);
        }
        $result = (new ReservationRequestSummaryService(Database::connection()))->generate((int)$item['id'], $user, false);
        $details = $service->details((int)$item['id']);
        jsonResponse(true, 'AI request summary processing finished.', [
            'item' => employeeVisibleReservation($details ?? $item, $user),
            'ai_request_summary_status' => $details['ai_request_summary']['status'] ?? ($result['status'] ?? 'FAILED'),
            'attempt_count' => $result['attempt_count'] ?? 0,
        ]);
    } catch (Throwable $e) { employeeReservationValidation($e); }
}
$body = str_starts_with((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data')
    ? $_POST
    : readJsonBody();
$file = $_FILES['request_letter'] ?? null;

$body['requested_by_employee_reference_id'] = (int)$user['employee_id'];
$body['department_reference_id'] = (int)($user['department']['id'] ?? 0) ?: null;
$body['status'] = 'SUBMITTED';
$body['approval_status'] = 'PENDING';

try {
    $item = employeeReservationService()->create($body, $user, is_array($file) ? $file : []);
    jsonResponse(true, 'Reservation request submitted.', ['item' => employeeVisibleReservation($item, $user)], 201);
} catch (Throwable $e) { employeeReservationValidation($e); }
