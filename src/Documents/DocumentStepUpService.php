<?php

declare(strict_types=1);

final class DocumentStepUpService
{
    private const PURPOSE = 'CONFIDENTIAL_DOCUMENT_ACCESS';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function hasValidStepUp(array $user, int $documentId, array $context = []): bool
    {
        $context = $this->normalizeContext($context);
        if ($context !== []) {
            $contextKey = $this->contextKey($context);
            $entry = $_SESSION['document_context_step_up'][$documentId][$contextKey] ?? null;
            if (!is_array($entry)) {
                return false;
            }

            return (int) ($entry['user_id'] ?? 0) === (int) $user['id']
                && hash_equals((string) ($entry['session_id'] ?? ''), session_id())
                && hash_equals((string) ($entry['context_key'] ?? ''), $contextKey)
                && (int) ($entry['expires_at'] ?? 0) > time();
        }

        $entry = $_SESSION['document_step_up'][$documentId] ?? null;
        if (!is_array($entry)) {
            return false;
        }

        return (int) ($entry['user_id'] ?? 0) === (int) $user['id']
            && hash_equals((string) ($entry['session_id'] ?? ''), session_id())
            && (int) ($entry['expires_at'] ?? 0) > time();
    }

    public function verifyPasswordAndGrantStepUp(array $user, int $documentId, string $password, array $context = []): array
    {
        if ($password === '') {
            $this->audit('CONFIDENTIAL_DOCUMENT_PASSWORD_FAILED', (int) $user['id'], $documentId, null, 'EMPTY_PASSWORD');
            jsonResponse(false, 'Password verification failed.', [], 422);
        }

        try {
            (new AuthService($this->pdo))->verifyCurrentUserPassword((int) $user['id'], $password);
        } catch (DomainException $exception) {
            $this->audit('CONFIDENTIAL_DOCUMENT_PASSWORD_FAILED', (int) $user['id'], $documentId, null, 'FAILED');
            jsonResponse(false, 'Password verification failed.', [], 422);
        }

        $stepUpTtl = $this->grantStepUp($user, $documentId, $context);
        $this->audit('CONFIDENTIAL_DOCUMENT_PASSWORD_VERIFIED', (int) $user['id'], $documentId, null, 'SUCCESS');

        return ['step_up_expires_in_seconds' => $stepUpTtl];
    }

    public function auditDocumentAccess(string $eventType, array $user, int $documentId, ?int $versionId, string $result = 'SUCCESS'): void
    {
        $this->audit($eventType, (int) $user['id'], $documentId, $versionId, $result);
    }

    private function grantStepUp(array $user, int $documentId, array $context): int
    {
        $context = $this->normalizeContext($context);
        $stepUpTtl = $this->stepUpTtl();
        if ($context === []) {
            $_SESSION['document_step_up'][$documentId] = [
                'user_id' => (int) $user['id'],
                'session_id' => session_id(),
                'purpose' => self::PURPOSE,
                'expires_at' => time() + $stepUpTtl,
            ];
            return $stepUpTtl;
        }

        $contextKey = $this->contextKey($context);
        $_SESSION['document_context_step_up'][$documentId][$contextKey] = [
            'user_id' => (int) $user['id'],
            'session_id' => session_id(),
            'purpose' => self::PURPOSE,
            'context_key' => $contextKey,
            'expires_at' => time() + $stepUpTtl,
        ];

        return $stepUpTtl;
    }

    private function normalizeContext(array $context): array
    {
        $normalized = [];
        foreach ($context as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $normalized[(string) $key] = is_bool($value) ? $value : (string) $value;
        }
        ksort($normalized);
        return $normalized;
    }

    private function contextKey(array $context): string
    {
        return hash('sha256', json_encode($this->normalizeContext($context), JSON_THROW_ON_ERROR));
    }

    private function audit(string $eventType, int $userId, int $documentId, ?int $versionId, string $result): void
    {
        try {
            $this->pdo->prepare("INSERT INTO activity_event (event_uuid, module_code, entity_type, entity_id, event_type, event_title, event_description, actor_user_id, visibility_scope, metadata_json) VALUES (:uuid, 'documents', 'document', :document_id, :event_type, :title, :description, :user_id, 'INTERNAL', :metadata)")->execute([
                'uuid' => $this->uuidV4(),
                'document_id' => $documentId,
                'event_type' => $eventType,
                'title' => ucwords(strtolower(str_replace('_', ' ', $eventType))),
                'description' => $eventType,
                'user_id' => $userId,
                'metadata' => json_encode(['document_id' => $documentId, 'document_version_id' => $versionId, 'result' => $result], JSON_THROW_ON_ERROR),
            ]);
        } catch (Throwable $exception) {
            error_log('Document security activity logging failed: ' . $exception::class);
        }
    }

    private function stepUpTtl(): int
    {
        return max(1, (int) env('DOCUMENT_STEP_UP_TTL_SECONDS', 600));
    }

    private function uuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
