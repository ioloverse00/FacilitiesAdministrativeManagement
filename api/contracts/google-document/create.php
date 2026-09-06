<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';

requireMethod('POST');
$user = currentApiUser();
requireCsrfToken();
$id = idParam();

try {
    jsonResponse(true, 'Google working document created.', ['item' => contractGoogleDocumentService()->createWorkingDocument($id, $user)]);
} catch (GoogleIntegrationException $exception) {
    jsonResponse(false, $exception->getMessage(), ['errors' => $exception->validationErrors()], 422);
} catch (DomainException $exception) {
    jsonResponse(false, $exception->getMessage(), [], 409);
} catch (Throwable $exception) {
    validationResponse($exception);
}
