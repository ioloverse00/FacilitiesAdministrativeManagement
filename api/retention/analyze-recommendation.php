<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('POST');
$user = currentApiUser();
RetentionPolicy::requirePermission($user, 'retention.review');
requireCsrfToken();
$result = dispositionRecommendationService()->analyze(idParam(), $user);
if ($result === null) jsonResponse(false, 'Retention record not found.', [], 404);
$message = (string) ($result['analysis']['message'] ?? 'AI-assisted disposition analysis completed.');
jsonResponse(true, $message, $result);
