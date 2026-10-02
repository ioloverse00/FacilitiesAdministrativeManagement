<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_live_bootstrap.php';

$user = currentApiUser();
$action = strtolower(trim((string)($_GET['action'] ?? 'list')));
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

try {
    if ($method === 'GET') {
        LegalPolicy::requirePermission($user, 'legal.view');
        $service = legalRulePolicyService();
        if ($action === 'show') {
            $item = $service->showPolicy(idParam('policy_id'));
            if ($item === null) jsonResponse(false, 'Rule policy not found.', [], 404);
            jsonResponse(true, 'Rule policy retrieved.', ['item' => $item]);
        }
        if ($action === 'provisions') {
            jsonResponse(true, 'Active rule provisions retrieved.', ['items' => $service->activeProvisionOptions($_GET)]);
        }
        jsonResponse(true, 'Rule policies retrieved.', $service->listPolicies($_GET));
    }

    requireMethod('POST');
    LegalPolicy::requirePermission($user, 'legal.manage');
    requireCsrfToken();
    $service = legalRulePolicyService();
    $body = readJsonBody();

    $result = match ($action) {
        'create-policy' => $service->createPolicy($body, $user),
        'update-policy' => $service->updatePolicy(idParam('policy_id'), $body, $user),
        'deactivate-policy' => $service->deactivatePolicy(idParam('policy_id'), $user),
        'add-provision' => $service->addProvision(idParam('policy_id'), $body, $user),
        'revise-provision' => $service->reviseProvision(idParam('provision_id'), $body, $user),
        'deactivate-provision' => $service->deactivateProvision(idParam('provision_id'), $user),
        'link-provision' => $service->linkProvision(idParam('matter_id'), idParam('provision_id'), $body, $user),
        'unlink-basis' => $service->unlinkBasis(idParam('matter_id'), idParam('basis_id'), $user),
        default => throw new InvalidArgumentException(json_encode(['action' => 'Choose a valid Rules & Regulations action.'], JSON_THROW_ON_ERROR)),
    };

    if ($result === null) {
        jsonResponse(false, 'Requested Rules & Regulations record not found.', [], 404);
    }
    jsonResponse(true, 'Rules & Regulations updated.', ['item' => $result]);
} catch (Throwable $e) {
    validationResponse($e);
}
