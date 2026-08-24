<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';
requireMethod('GET');
$user = currentApiUser();
RetentionPolicy::requirePermission($user, 'retention.view');
$recommendation = dispositionRecommendationService()->latestForRecord(idParam());
jsonResponse(true, 'Retention disposition recommendation retrieved.', ['recommendation' => $recommendation]);
