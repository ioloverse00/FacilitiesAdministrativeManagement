<?php

declare(strict_types=1);

final class DispositionRecommendationService
{
    private const ACTIONS = ['RETAIN', 'ARCHIVE', 'REVIEW', 'DISPOSE'];
    private const REVIEW_STATUSES = ['APPROVED', 'MODIFIED', 'REJECTED'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function latestForRecord(int $recordId): ?array
    {
        try {
            $row = $this->row(
                'SELECT rdr.*, er.full_name reviewed_by_name
                 FROM record_disposition_recommendation rdr
                 LEFT JOIN user_account ua ON ua.user_account_id = rdr.reviewed_by_user_id
                 LEFT JOIN employee_reference er ON er.employee_reference_id = ua.employee_reference_id
                 WHERE rdr.record_id = :id
                 ORDER BY FIELD(rdr.status, "PENDING", "STALE", "APPROVED", "MODIFIED", "REJECTED"), rdr.evaluated_at DESC, rdr.recommendation_id DESC
                 LIMIT 1',
                ['id' => $recordId]
            );
        } catch (Throwable) {
            return null;
        }
        return $row ? $this->shape($row) : null;
    }

    public function analyze(int $recordId, array $user): ?array
    {
        $item = (new RetentionService($this->pdo))->show($recordId);
        if ($item === null) {
            return null;
        }

        $policyState = $this->policyAnalysisState($item);
        if ($policyState !== null) {
            if (($policyState['status'] ?? '') === 'TERMINAL_RECORD') {
                throw new InvalidArgumentException(json_encode(['record' => 'Disposition analysis is not available for terminal retention records.'], JSON_THROW_ON_ERROR));
            }
            $this->logActivity('RETENTION_AI_DISPOSITION_SKIPPED', 'AI Disposition Analysis Skipped', $recordId, (int) $user['id'], $policyState);
            return ['recommendation' => null, 'analysis' => $policyState];
        }
        $this->markPendingStale($recordId, 'New AI disposition recommendation requested.');

        try {
            $recommendation = $this->buildRecommendation($item);
        } catch (Throwable $exception) {
            $analysis = [
                'status' => 'UNAVAILABLE',
                'message' => 'AI-assisted disposition analysis could not be completed. Review the authoritative retention policy and record details manually.',
                'retryable' => true,
                'stage' => $this->safeFailureStage($exception->getMessage()),
            ];
            $this->logActivity('RETENTION_AI_DISPOSITION_UNAVAILABLE', 'AI Disposition Recommendation Unavailable', $recordId, (int) $user['id'], [
                'stage' => $analysis['stage'],
            ]);
            return ['recommendation' => null, 'analysis' => $analysis];
        }

        $id = $this->insert($recordId, $item, $recommendation);
        $this->logActivity('RETENTION_AI_DISPOSITION_ANALYZED', 'AI Disposition Recommendation Generated', $recordId, (int) $user['id'], [
            'recommendation_id' => $id,
            'recommended_action' => $recommendation['recommended_action'],
            'source_provider' => $recommendation['source_provider'],
            'needs_review' => $recommendation['needs_review'],
        ]);
        return ['recommendation' => $this->latestForRecord($recordId), 'analysis' => ['status' => 'AVAILABLE', 'message' => 'AI-assisted disposition recommendation generated.', 'retryable' => true]];
    }

    public function review(int $recommendationId, array $data, array $user): ?array
    {
        $row = $this->row('SELECT * FROM record_disposition_recommendation WHERE recommendation_id = :id LIMIT 1', ['id' => $recommendationId]);
        if ($row === null) {
            return null;
        }
        if ((string) $row['status'] !== 'PENDING') {
            throw new InvalidArgumentException(json_encode(['recommendation' => 'This recommendation has already been reviewed.'], JSON_THROW_ON_ERROR));
        }

        $decision = strtoupper($this->text($data['decision'] ?? '', 30));
        if (!in_array($decision, self::REVIEW_STATUSES, true)) {
            throw new InvalidArgumentException(json_encode(['decision' => 'Choose approve, modify, or reject.'], JSON_THROW_ON_ERROR));
        }

        $approvedAction = strtoupper($this->text($data['approved_action'] ?? $row['recommended_action'], 30));
        if ($decision === 'REJECTED') {
            $approvedAction = '';
        } elseif (!in_array($approvedAction, self::ACTIONS, true)) {
            throw new InvalidArgumentException(json_encode(['approved_action' => 'Choose a valid final action.'], JSON_THROW_ON_ERROR));
        }

        $reason = $this->text($data['review_reason'] ?? $data['reason'] ?? '', 1000);
        if (($decision === 'MODIFIED' || $decision === 'REJECTED') && $reason === '') {
            throw new InvalidArgumentException(json_encode(['review_reason' => 'Enter a reason for this decision.'], JSON_THROW_ON_ERROR));
        }

        $recordId = (int) $row['record_id'];
        $service = new RetentionService($this->pdo);
        $item = $service->show($recordId);
        if ($item === null) {
            return null;
        }
        if (in_array($item['recordStatus'], ['ARCHIVED', 'DISPOSED'], true)) {
            throw new InvalidArgumentException(json_encode(['record' => 'Disposition recommendation review is not available for terminal retention records.'], JSON_THROW_ON_ERROR));
        }
        if ($decision !== 'REJECTED') {
            $this->assertActionSafe($approvedAction, $item);
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('UPDATE record_disposition_recommendation SET status = :status, reviewed_by_user_id = :user_id, reviewed_at = NOW(), approved_action = :approved_action, review_reason = :reason, updated_at = NOW() WHERE recommendation_id = :id')->execute([
                'status' => $decision,
                'user_id' => (int) $user['id'],
                'approved_action' => $approvedAction !== '' ? $approvedAction : null,
                'reason' => $reason !== '' ? $reason : null,
                'id' => $recommendationId,
            ]);
            if ($decision !== 'REJECTED') {
                $officialReason = $reason !== '' ? $reason : 'Approved from AI-assisted retention disposition recommendation.';
                if ($approvedAction === 'ARCHIVE') {
                    $service->archive($recordId, ['reason' => $officialReason], $user);
                } elseif ($approvedAction === 'DISPOSE') {
                    $service->dispose($recordId, ['reason' => $officialReason], $user);
                } elseif ($approvedAction === 'REVIEW') {
                    $this->touchReview($recordId, $officialReason, (int) $user['id']);
                }
            }
            $this->logActivity('RETENTION_AI_DISPOSITION_REVIEWED', 'AI Disposition Recommendation Reviewed', $recordId, (int) $user['id'], [
                'recommendation_id' => $recommendationId,
                'decision' => $decision,
                'approved_action' => $approvedAction,
            ]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }

        return $this->latestForRecord($recordId);
    }

    public function markPendingStale(int $recordId, string $reason): void
    {
        try {
            $this->pdo->prepare("UPDATE record_disposition_recommendation SET status = 'STALE', review_reason = COALESCE(review_reason, :reason), updated_at = NOW() WHERE record_id = :id AND status = 'PENDING'")->execute([
                'id' => $recordId,
                'reason' => $reason,
            ]);
        } catch (Throwable) {
        }
    }

    private function buildRecommendation(array $item): array
    {
        $fallback = $this->deterministicRecommendation($item);
        $ai = $this->requestOpenAi($item);
        return [
            'recommended_action' => $this->validAction($ai['recommended_action'] ?? null, $fallback['recommended_action']),
            'reason' => $this->text($ai['reason'] ?? $fallback['reason'], 1000),
            'context_flags' => $this->flags($ai['context_flags'] ?? $fallback['context_flags']),
            'needs_review' => true,
            'source_provider' => 'OPENAI',
            'source_model' => $this->modelName(),
            'can_use_ai' => true,
        ];
    }

    private function policyAnalysisState(array $item): ?array
    {
        if ($item['legalHoldStatus'] === 'ACTIVE') {
            return [
                'status' => 'POLICY_BLOCKED',
                'message' => 'Active legal hold blocks disposition. Release the hold before archive or dispose decisions.',
                'retryable' => false,
            ];
        }
        if (in_array($item['recordStatus'], ['ARCHIVED', 'DISPOSED'], true)) {
            return [
                'status' => 'TERMINAL_RECORD',
                'message' => 'This record is already in a terminal retention state.',
                'retryable' => false,
            ];
        }
        if ($item['dueState'] === 'PERMANENT') {
            return [
                'status' => 'PERMANENT_RETENTION',
                'message' => 'This record is governed by a permanent retention schedule.',
                'retryable' => false,
            ];
        }
        if ($item['dueState'] === 'WAITING_FOR_TRIGGER' || empty($item['policyEligibilityDate'])) {
            return [
                'status' => 'WAITING_FOR_TRIGGER',
                'message' => 'Disposition analysis is unavailable until the authoritative retention trigger date is established.',
                'retryable' => true,
            ];
        }
        if (!$this->isEligible($item)) {
            return [
                'status' => 'NOT_POLICY_ELIGIBLE',
                'message' => 'The scheduled review or disposition date has not been reached. Retain the record according to policy.',
                'retryable' => false,
            ];
        }
        return null;
    }

    private function deterministicRecommendation(array $item): array
    {
        $flags = [];
        $schedule = $item['schedule'] ?? [];
        $action = strtoupper((string) ($schedule['dispositionAction'] ?? 'REVIEW'));
        if ($item['legalHoldStatus'] === 'ACTIVE') {
            return ['recommended_action' => 'RETAIN', 'reason' => 'Record is under active legal hold. Retention action is blocked until the hold is released.', 'context_flags' => ['active_legal_hold'], 'needs_review' => true, 'can_use_ai' => false];
        }
        if (in_array($item['recordStatus'], ['ARCHIVED', 'DISPOSED'], true)) {
            return ['recommended_action' => 'RETAIN', 'reason' => 'Record is already in a terminal retention state.', 'context_flags' => ['terminal_record'], 'needs_review' => false, 'can_use_ai' => false];
        }
        if ($item['dueState'] === 'PERMANENT') {
            return ['recommended_action' => 'RETAIN', 'reason' => 'Record is governed by a permanent retention schedule.', 'context_flags' => ['permanent_schedule'], 'needs_review' => false, 'can_use_ai' => false];
        }
        if (!$this->isEligible($item)) {
            return ['recommended_action' => 'RETAIN', 'reason' => 'The policy eligibility date has not been reached. Retain the record until the authoritative retention policy date.', 'context_flags' => ['not_policy_eligible'], 'needs_review' => true, 'can_use_ai' => false];
        }
        if (!in_array($action, self::ACTIONS, true)) {
            $action = 'REVIEW';
            $flags[] = 'unknown_schedule_action';
        }
        $flags[] = 'policy_eligible';
        return ['recommended_action' => $action, 'reason' => 'The record is policy-eligible for retention review based on its schedule and review date. Human review is required before any official action.', 'context_flags' => $flags, 'needs_review' => true, 'can_use_ai' => true];
    }

    private function assertActionSafe(string $action, array $item): void
    {
        if (in_array($action, ['ARCHIVE', 'DISPOSE'], true)) {
            (new RetentionService($this->pdo))->assertDispositionAllowed($item, $action);
            return;
        }
        if ($item['legalHoldStatus'] === 'ACTIVE') {
            throw new InvalidArgumentException(json_encode(['legal_hold' => 'Active legal hold blocks disposition decisions.'], JSON_THROW_ON_ERROR));
        }
        if (in_array($item['recordStatus'], ['ARCHIVED', 'DISPOSED'], true)) {
            throw new InvalidArgumentException(json_encode(['record' => 'This record has already reached a terminal retention state.'], JSON_THROW_ON_ERROR));
        }
        if ($action === 'RETAIN' && !$this->isEligible($item)) {
            throw new InvalidArgumentException(json_encode(['approved_action' => 'Retain decisions can only be reviewed after the deterministic retention review date is reached.'], JSON_THROW_ON_ERROR));
        }
    }

    private function isEligible(array $item): bool
    {
        $date = (string) ($item['effectiveReviewDate'] ?? $item['policyEligibilityDate'] ?? '');
        if ($date === '') {
            return false;
        }
        return new DateTimeImmutable($date) <= new DateTimeImmutable('today');
    }

    private function requestOpenAi(array $item): array
    {
        if ($this->apiKey() === '') {
            throw new RuntimeException('AI_KEY_MISSING');
        }
        $response = $this->postJson($this->openAiEndpoint(), $this->openAiPayload($item));
        $text = $this->extractOutputText($response);
        $decoded = json_decode(trim($text), true);
        if (!is_array($decoded)) {
            throw new RuntimeException('AI_RESPONSE_INVALID');
        }
        return $decoded;
    }

    private function openAiPayload(array $item): array
    {
        return [
            'model' => $this->modelName(),
            'store' => false,
            'input' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'input_text', 'text' => $this->instructions($item)],
                ],
            ]],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'retention_disposition_recommendation',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'recommended_action' => ['type' => 'string', 'enum' => self::ACTIONS],
                            'reason' => ['type' => 'string'],
                            'context_flags' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'needs_review' => ['type' => 'boolean'],
                        ],
                        'required' => ['recommended_action', 'reason', 'context_flags', 'needs_review'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
        ];
    }

    private function instructions(array $item): string
    {
        $schedule = $item['schedule'] ?? [];
        return "Provide an advisory Records Retention disposition recommendation. Valid recommended_action values: RETAIN, ARCHIVE, REVIEW, DISPOSE.\n\n"
            . "Use ONLY the supplied metadata. Do not invent retention policies, dates, legal basis, facts, or lifecycle events that are not supplied. The deterministic retention policy engine is authoritative for eligibility dates, schedule rules, legal hold blocks, and final disposition enforcement.\n\n"
            . "Legal hold must always be respected. This recommendation is advisory only and is not a disposition decision. Human Records Admin review and approval is mandatory before any official action. Never recommend physical deletion as an AI-executed action. AI cannot authorize archival or disposition.\n\n"
            . "Record metadata: record_no=" . (string) $item['recordNo'] . "; title=" . (string) $item['title'] . "; category=" . (string) $item['category'] . "; status=" . (string) $item['recordStatus'] . "; due_state=" . (string) $item['dueState'] . "; legal_hold_status=" . (string) $item['legalHoldStatus'] . "; trigger_basis=" . (string) $item['retentionTriggerBasis'] . "; trigger_date=" . (string) $item['retentionTriggerDate'] . "; policy_eligibility_date=" . (string) $item['policyEligibilityDate'] . "; effective_review_date=" . (string) $item['effectiveReviewDate'] . ".\n\n"
            . "Schedule metadata: code=" . (string) ($schedule['code'] ?? '') . "; name=" . (string) ($schedule['name'] ?? '') . "; trigger_basis=" . (string) ($schedule['triggerBasis'] ?? '') . "; trigger_label=" . (string) ($schedule['triggerLabel'] ?? '') . "; period=" . (string) ($schedule['periodValue'] ?? '') . ' ' . (string) ($schedule['periodUnit'] ?? '') . "; disposition_action=" . (string) ($schedule['dispositionAction'] ?? '') . "; legal_basis=" . (string) ($schedule['legalBasis'] ?? '') . ".\n\n"
            . "Keep the reason concise, factual, and grounded in the supplied schedule and record lifecycle state.";
    }

    private function insert(int $recordId, array $item, array $recommendation): int
    {
        $this->pdo->prepare('INSERT INTO record_disposition_recommendation (record_id, retention_schedule_id, recommended_action, reason, context_flags_json, policy_eligible_date, legal_hold_status, needs_review, status, source_provider, source_model) VALUES (:record_id, :schedule_id, :action, :reason, :flags, :eligible_date, :hold, :needs_review, "PENDING", :provider, :model)')->execute([
            'record_id' => $recordId,
            'schedule_id' => (int) ($item['schedule']['id'] ?? 0) ?: null,
            'action' => $recommendation['recommended_action'],
            'reason' => $recommendation['reason'],
            'flags' => json_encode($recommendation['context_flags'] ?? [], JSON_THROW_ON_ERROR),
            'eligible_date' => $item['policyEligibilityDate'] ?: null,
            'hold' => $item['legalHoldStatus'] ?: 'NONE',
            'needs_review' => !empty($recommendation['needs_review']) ? 1 : 0,
            'provider' => $recommendation['source_provider'],
            'model' => $recommendation['source_model'],
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private function touchReview(int $recordId, string $reason, int $userId): void
    {
        $this->pdo->prepare('UPDATE record SET last_reviewed_at = NOW(), disposition_reason = :reason, updated_by_user_id = :user_id, updated_at = NOW() WHERE record_id = :id AND deleted_at IS NULL')->execute([
            'id' => $recordId,
            'reason' => $reason,
            'user_id' => $userId,
        ]);
    }

    private function shape(array $row): array
    {
        return [
            'id' => (int) $row['recommendation_id'],
            'recordId' => (int) $row['record_id'],
            'scheduleId' => isset($row['retention_schedule_id']) ? (int) $row['retention_schedule_id'] : null,
            'recommendedAction' => (string) $row['recommended_action'],
            'reason' => (string) $row['reason'],
            'contextFlags' => $this->decodeJson($row['context_flags_json'] ?? null),
            'policyEligibleDate' => (string) ($row['policy_eligible_date'] ?? ''),
            'legalHoldStatus' => (string) $row['legal_hold_status'],
            'needsReview' => (bool) $row['needs_review'],
            'status' => (string) $row['status'],
            'reviewedBy' => (string) ($row['reviewed_by_name'] ?? ''),
            'reviewedAt' => (string) ($row['reviewed_at'] ?? ''),
            'approvedAction' => (string) ($row['approved_action'] ?? ''),
            'reviewReason' => (string) ($row['review_reason'] ?? ''),
            'sourceProvider' => (string) $row['source_provider'],
            'sourceModel' => (string) ($row['source_model'] ?? ''),
            'evaluatedAt' => (string) $row['evaluated_at'],
        ];
    }

    private function validAction(mixed $value, string $fallback): string
    {
        $action = strtoupper($this->text($value ?? '', 30));
        return in_array($action, self::ACTIONS, true) ? $action : $fallback;
    }

    private function flags(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_slice(array_filter(array_map(fn (mixed $flag): string => $this->text($flag, 50), $value)), 0, 8));
    }

    private function postJson(string $url, array $payload): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) {
            throw new RuntimeException('AI_REQUEST_FAILED');
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('AI_SERVICE_UNAVAILABLE');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey(),
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => max(5, (int) env('OPENAI_RETENTION_RECOMMENDATION_TIMEOUT_SECONDS', 30)),
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        $errorCode = (int) curl_errno($ch);
        curl_close($ch);
        if ($raw === false || $status < 200 || $status >= 300) {
            throw new RuntimeException($this->httpFailureReason((string) ($raw ?: ''), $status, $error, $errorCode));
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('AI_RESPONSE_INVALID');
        }
        return $decoded;
    }

    private function extractOutputText(array $response): string
    {
        $status = (string) ($response['status'] ?? '');
        if ($status !== '' && !in_array($status, ['completed', 'incomplete'], true)) {
            throw new RuntimeException($this->responseFailureReason($response));
        }
        if ($status === 'incomplete') {
            throw new RuntimeException('AI_RESPONSE_INVALID');
        }
        if (is_array($response['error'] ?? null)) {
            throw new RuntimeException($this->responseFailureReason($response));
        }
        if (isset($response['output_text']) && is_string($response['output_text']) && trim($response['output_text']) !== '') {
            return trim($response['output_text']);
        }
        $parts = [];
        foreach (($response['output'] ?? []) as $item) {
            foreach (($item['content'] ?? []) as $content) {
                if (isset($content['refusal']) && is_string($content['refusal']) && trim($content['refusal']) !== '') {
                    throw new RuntimeException('AI_RESPONSE_INVALID');
                }
                if (isset($content['text']) && is_string($content['text'])) {
                    $parts[] = $content['text'];
                }
            }
        }
        $text = trim(implode("\n", $parts));
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
            $text = preg_replace('/\s*```$/', '', $text) ?? $text;
        }
        $text = trim($text);
        if ($text === '') {
            throw new RuntimeException('AI_RESPONSE_INVALID');
        }
        return $text;
    }

    private function httpFailureReason(string $raw, int $status, string $transportError, int $transportErrorCode): string
    {
        $decoded = json_decode($raw, true);
        $error = is_array($decoded) && is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
        $providerType = is_string($error['type'] ?? null) ? $error['type'] : null;
        $providerCode = is_string($error['code'] ?? null) ? $error['code'] : null;
        $timedOut = $transportErrorCode > 0 && defined('CURLE_OPERATION_TIMEDOUT') && $transportErrorCode === CURLE_OPERATION_TIMEDOUT;
        return match (true) {
            $timedOut => 'AI_TIMEOUT',
            $transportError !== '' => 'AI_SERVICE_UNAVAILABLE',
            in_array($providerCode, ['model_not_found', 'invalid_model'], true) => 'AI_MODEL_INVALID',
            $providerType === 'authentication_error' || $providerType === 'permission_error' => 'AI_AUTH_ERROR',
            $providerType === 'rate_limit_error' => 'AI_RATE_LIMITED',
            $status === 401 || $status === 403 => 'AI_AUTH_ERROR',
            $status === 404 => 'AI_MODEL_INVALID',
            $status === 408 || $status === 504 => 'AI_TIMEOUT',
            $status === 429 => 'AI_RATE_LIMITED',
            $status === 0 || $status >= 500 => 'AI_SERVICE_UNAVAILABLE',
            default => 'AI_REQUEST_FAILED',
        };
    }

    private function responseFailureReason(array $response): string
    {
        $error = is_array($response['error'] ?? null) ? $response['error'] : [];
        $providerType = is_string($error['type'] ?? null) ? $error['type'] : null;
        $providerCode = is_string($error['code'] ?? null) ? $error['code'] : null;
        if (in_array($providerCode, ['model_not_found', 'invalid_model'], true)) {
            return 'AI_MODEL_INVALID';
        }
        if ($providerType === 'authentication_error' || $providerType === 'permission_error') {
            return 'AI_AUTH_ERROR';
        }
        if ($providerType === 'rate_limit_error') {
            return 'AI_RATE_LIMITED';
        }
        return 'AI_RESPONSE_INVALID';
    }

    private function openAiEndpoint(): string
    {
        return 'https://api.openai.com/v1/responses';
    }

    private function apiKey(): string
    {
        return trim((string) env('OPENAI_API_KEY', ''));
    }

    private function modelName(): string
    {
        $model = trim((string) env('OPENAI_RETENTION_RECOMMENDATION_MODEL', 'gpt-6-sol'));
        return $model !== '' ? $model : 'gpt-6-sol';
    }

    private function row(string $sql, array $params): ?array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    private function decodeJson(mixed $value): array
    {
        $decoded = json_decode((string) ($value ?? '[]'), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function text(mixed $value, int $max): string
    {
        return mb_substr(trim((string) $value), 0, $max);
    }

    private function safeFailureStage(string $message): string
    {
        $stage = strtok($message, ' ');
        return is_string($stage) && preg_match('/^[A-Z0-9_]+$/', $stage) ? $stage : 'AI_REQUEST_FAILED';
    }

    private function logActivity(string $eventType, string $title, int $recordId, int $userId, array $metadata = []): void
    {
        try {
            $this->pdo->prepare("INSERT INTO activity_event (event_uuid, module_code, entity_type, entity_id, event_type, event_title, event_description, actor_user_id, visibility_scope, metadata_json) VALUES (:uuid, 'retention', 'record', :id, :event_type, :title, :description, :user_id, 'INTERNAL', :metadata)")->execute([
                'uuid' => self::uuidV4(),
                'id' => $recordId,
                'event_type' => $eventType,
                'title' => $title,
                'description' => $title,
                'user_id' => $userId,
                'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            ]);
        } catch (Throwable $exception) {
            error_log('Retention recommendation activity logging failed: ' . $exception::class);
        }
    }

    private static function uuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
