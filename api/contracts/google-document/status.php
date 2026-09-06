<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';

requireMethod('GET');
$user = currentApiUser();
$id = idParam();

try {
    jsonResponse(true, 'Google document status loaded.', ['item' => contractGoogleDocumentService()->status($id, $user)]);
} catch (GoogleIntegrationException $exception) {
    jsonResponse(false, $exception->getMessage(), ['errors' => $exception->validationErrors()], 422);
} catch (Throwable $exception) {
    validationResponse($exception);
}
