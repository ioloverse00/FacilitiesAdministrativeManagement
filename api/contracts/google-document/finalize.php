<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';

requireMethod('POST');
$user = currentApiUser();
requireCsrfToken();
$id = idParam();

try {
    contractGoogleDocumentService()->finalizeForReview($id, $user);
    jsonResponse(true, 'Google working document finalized.', ['item' => contractGoogleDocumentService()->status($id, $user)]);
} catch (GoogleIntegrationException $exception) {
    jsonResponse(false, $exception->getMessage(), ['errors' => $exception->validationErrors()], 422);
} catch (DomainException $exception) {
    jsonResponse(false, $exception->getMessage(), [], 409);
} catch (Throwable $exception) {
    validationResponse($exception);
}
