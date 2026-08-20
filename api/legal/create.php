<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('POST');
$user = currentApiUser();
LegalPolicy::requirePermission($user, 'legal.create');
requireCsrfToken();
try {
    $isMultipart = str_contains(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'multipart/form-data');
    if (!$isMultipart || !isset($_FILES['supporting_documents'])) {
        jsonResponse(false, 'Validation failed.', ['errors' => ['supporting_documents' => 'Attach at least one supporting document or evidence file to create this legal matter.']], 422);
    }
    $files = normalizeUploadedFiles($_FILES['supporting_documents']);
    $files = array_values(array_filter($files, static fn (array $file): bool => ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE));
    if (!$files) {
        jsonResponse(false, 'Validation failed.', ['errors' => ['supporting_documents' => 'Attach at least one supporting document or evidence file to create this legal matter.']], 422);
    }
    DocumentPolicy::requirePermission($user, 'records.create');
    $validationErrors = [];
    foreach ($files as $index => $file) {
        try {
            documentService()->validateLegalEvidenceFile($file);
        } catch (Throwable $fileException) {
            $name = (string) ($file['name'] ?? ('Attachment ' . ($index + 1)));
            $errors = $fileException instanceof InvalidArgumentException ? json_decode($fileException->getMessage(), true) : null;
            $validationErrors['supporting_documents'] = $name . ': ' . (is_array($errors) ? (string) reset($errors) : 'This file could not be validated.');
            break;
        }
    }
    if ($validationErrors) {
        jsonResponse(false, 'Validation failed.', ['errors' => $validationErrors], 422);
    }

    $service = legalMatterService();
    $item = $service->create($_POST, $user);
    $attachmentErrors = [];
    $attachedCount = 0;

    if ($files) {
        foreach ($files as $index => $file) {
            try {
                $service->attachSupportingDocument((int) $item['id'], $_POST, $file, $user, false);
                $attachedCount++;
            } catch (Throwable $attachmentException) {
                $attachmentErrors[] = [
                    'file' => (string) ($file['name'] ?? ('Attachment ' . ($index + 1))),
                    'message' => $attachmentException instanceof InvalidArgumentException ? 'Attachment validation failed.' : 'Attachment upload failed.',
                ];
            }
        }
        if ($attachedCount > 0) {
            try {
                legalMatterSummaryService()->markPending((int) $item['id'], $user);
            } catch (Throwable) {
                // AI status preparation is secondary. Matter and document creation remain authoritative.
            }
        }
        $item = $service->show((int) $item['id']) ?? $item;
    }

    if ($attachmentErrors) {
        jsonResponse(true, 'Legal matter created, but one or more supporting documents could not be attached.', ['item' => $item, 'attachment_errors' => $attachmentErrors], 207);
    }
    jsonResponse(true, 'Legal matter created.', ['item' => $item], 201);
} catch (Throwable $e) {
    validationResponse($e);
}

function normalizeUploadedFiles(array $files): array
{
    if (!is_array($files['name'] ?? null)) {
        return [$files];
    }

    $normalized = [];
    foreach ($files['name'] as $index => $name) {
        $normalized[] = [
            'name' => $name,
            'type' => $files['type'][$index] ?? '',
            'tmp_name' => $files['tmp_name'][$index] ?? '',
            'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
            'size' => $files['size'][$index] ?? 0,
        ];
    }
    return $normalized;
}
