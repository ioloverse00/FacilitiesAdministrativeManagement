<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Organization' . DIRECTORY_SEPARATOR . 'FamEmployeeEligibilityService.php';

final class LegalMatterActionService
{
    public const ACTION_TYPES = [
        'REVIEW',
        'FOLLOW_UP',
        'DOCUMENT_SUBMISSION',
        'DOCUMENT_REQUEST',
        'MEETING',
        'INSPECTION',
        'COMPLIANCE',
        'RESPONSE',
        'OTHER',
    ];

    public const STATUSES = ['PENDING', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED'];
    public const DEADLINE_BASES = ['SOURCE_DERIVED', 'AI_RECOMMENDED', 'NO_DEADLINE'];
    public const PRIORITY_TARGET_WINDOWS = [
        'CRITICAL' => ['min' => 1, 'max' => 1],
        'HIGH' => ['min' => 2, 'max' => 3],
        'MEDIUM' => ['min' => 5, 'max' => 5],
        'LOW' => ['min' => 7, 'max' => 10],
    ];
    private const OPEN_STATUSES = ['PENDING', 'IN_PROGRESS'];
    private const DUE_SOON_DAYS = 3;

    public function __construct(private readonly PDO $pdo, private readonly ?FamEmployeeEligibilityService $employeeEligibility = null)
    {
    }

    public function actionsForMatter(int $matterId): array
    {
        return array_map(fn (array $row): array => $this->shapeAction($row), $this->rows(
            "SELECT a.*, assignee.full_name assigned_name, doc.document_number source_document_number
             FROM legal_matter_action a
             LEFT JOIN employee_reference assignee ON assignee.employee_reference_id = a.assigned_employee_reference_id
             LEFT JOIN document doc ON doc.document_id = a.source_document_id
             WHERE a.legal_matter_id = :id AND a.deleted_at IS NULL
             ORDER BY FIELD(a.status, 'PENDING', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED'), a.due_at IS NULL, a.due_at ASC, a.created_at ASC",
            ['id' => $matterId]
        ));
    }

    public function suggestionsForMatter(int $matterId): array
    {
        return array_map(fn (array $row): array => $this->shapeSuggestion($row), $this->rows(
            "SELECT s.*, doc.document_number source_document_number
             FROM legal_matter_action_suggestion s
             LEFT JOIN document doc ON doc.document_id = s.source_document_id
             WHERE s.legal_matter_id = :id AND s.status = 'PENDING'
             ORDER BY s.suggested_due_at IS NULL, s.suggested_due_at ASC, s.created_at ASC",
            ['id' => $matterId]
        ));
    }

    public function hasOpenActions(int $matterId): bool
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM legal_matter_action WHERE legal_matter_id = :id AND deleted_at IS NULL AND status IN ('PENDING','IN_PROGRESS')", ['id' => $matterId]) > 0;
    }

    public function addAction(int $matterId, array $data, array $user, string $source = 'MANUAL', ?int $suggestionId = null): ?array
    {
        $matter = $this->matter($matterId);
        if ($matter === null) {
            return null;
        }
        $this->assertMatterActionMutable($matter);
        $clean = $this->validateAction($data);
        if ($this->duplicateActionExists($matterId, $clean)) {
            throw new InvalidArgumentException(json_encode(['action' => 'This legal action already exists for this matter.'], JSON_THROW_ON_ERROR));
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("INSERT INTO legal_matter_action (legal_matter_id, title, action_type, description, assigned_employee_reference_id, due_at, status, source, suggestion_id, source_document_id, source_document_version_id, deadline_basis, recommendation_reason, created_by_user_id, created_at, updated_at) VALUES (:matter_id, :title, :type, :description, :assigned_id, :due_at, 'PENDING', :source, :suggestion_id, :source_document_id, :source_document_version_id, :deadline_basis, :recommendation_reason, :user_id, NOW(), NOW())")->execute([
                'matter_id' => $matterId,
                'title' => $clean['title'],
                'type' => $clean['action_type'],
                'description' => $clean['description'],
                'assigned_id' => $clean['assigned_employee_reference_id'],
                'due_at' => $clean['due_at'],
                'source' => $source,
                'suggestion_id' => $suggestionId,
                'source_document_id' => $clean['source_document_id'],
                'source_document_version_id' => $clean['source_document_version_id'],
                'deadline_basis' => $clean['deadline_basis'],
                'recommendation_reason' => $clean['recommendation_reason'],
                'user_id' => (int) $user['id'],
            ]);
            $actionId = (int) $this->pdo->lastInsertId();
            if ($suggestionId !== null) {
                $this->markSuggestion($suggestionId, 'ACCEPTED', (int) $user['id'], $actionId, null);
                $this->history($matterId, 'LEGAL_AI_ACTION_ACCEPTED', 'AI-recommended action reviewed and added as an official Legal Action.', ['suggestion_id' => $suggestionId, 'action_id' => $actionId, 'deadline_basis' => $clean['deadline_basis']], $user);
            } else {
                $this->history($matterId, 'LEGAL_ACTION_CREATED', 'Legal action created.', ['action_id' => $actionId], $user);
            }
            $this->pdo->commit();
            return $this->matterPayload($matterId);
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function updateAction(int $matterId, int $actionId, array $data, array $user): ?array
    {
        $action = $this->action($matterId, $actionId);
        if ($action === null) return null;
        $this->assertMatterActionMutable($this->matter($matterId));
        if (in_array((string) $action['status'], ['COMPLETED', 'CANCELLED'], true)) {
            throw new InvalidArgumentException(json_encode(['status' => 'Completed or cancelled actions cannot be edited.'], JSON_THROW_ON_ERROR));
        }
        $clean = $this->validateAction($data);
        if ($this->duplicateActionExists($matterId, $clean, $actionId)) {
            throw new InvalidArgumentException(json_encode(['action' => 'This legal action already exists for this matter.'], JSON_THROW_ON_ERROR));
        }
        $this->pdo->prepare('UPDATE legal_matter_action SET title = :title, action_type = :type, description = :description, assigned_employee_reference_id = :assigned_id, due_at = :due_at, source_document_id = :source_document_id, source_document_version_id = :source_document_version_id, updated_at = NOW() WHERE legal_matter_action_id = :action_id AND legal_matter_id = :matter_id AND deleted_at IS NULL')->execute([
            'title' => $clean['title'],
            'type' => $clean['action_type'],
            'description' => $clean['description'],
            'assigned_id' => $clean['assigned_employee_reference_id'],
            'due_at' => $clean['due_at'],
            'source_document_id' => $clean['source_document_id'],
            'source_document_version_id' => $clean['source_document_version_id'],
            'action_id' => $actionId,
            'matter_id' => $matterId,
        ]);
        $this->history($matterId, 'LEGAL_ACTION_UPDATED', 'Legal action updated.', ['action_id' => $actionId], $user);
        return $this->matterPayload($matterId);
    }

    public function acceptSuggestion(int $matterId, int $suggestionId, array $data, array $user): ?array
    {
        $suggestion = $this->suggestion($matterId, $suggestionId);
        if ($suggestion === null) return null;
        $this->assertMatterActionMutable($this->matter($matterId));
        $payload = [
            'title' => $data['title'] ?? $suggestion['suggested_title'],
            'action_type' => $data['action_type'] ?? $suggestion['suggested_action_type'],
            'description' => $data['description'] ?? $suggestion['suggested_description'],
            'assigned_employee_reference_id' => $data['assigned_employee_reference_id'] ?? null,
            'due_at' => $data['due_at'] ?? $suggestion['suggested_due_at'],
            'source_document_id' => $suggestion['source_document_id'] ?? null,
            'source_document_version_id' => $suggestion['source_document_version_id'] ?? null,
            'deadline_basis' => $suggestion['deadline_basis'] ?? $suggestion['date_basis'] ?? null,
            'recommendation_reason' => $suggestion['recommendation_reason'] ?? null,
        ];
        return $this->addAction($matterId, $payload, $user, 'AI', $suggestionId);
    }

    public function dismissSuggestion(int $matterId, int $suggestionId, array $data, array $user): ?array
    {
        $suggestion = $this->suggestion($matterId, $suggestionId);
        if ($suggestion === null) return null;
        $this->assertMatterActionMutable($this->matter($matterId));
        $reason = $this->nullableText($data['reason'] ?? null, 255);
        $this->markSuggestion($suggestionId, 'DISMISSED', (int) $user['id'], null, $reason);
        $this->history($matterId, 'LEGAL_AI_ACTION_DISMISSED', 'AI action suggestion dismissed.', ['suggestion_id' => $suggestionId, 'reason' => $reason], $user);
        return $this->matterPayload($matterId);
    }

    public function startAction(int $matterId, int $actionId, array $user): ?array
    {
        return $this->transitionAction($matterId, $actionId, 'IN_PROGRESS', $user);
    }

    public function completeAction(int $matterId, int $actionId, array $data, array $user): ?array
    {
        $completionNote = $this->text($data['completion_note'] ?? ($data['action_result'] ?? ''), 2000);
        if ($completionNote === '') {
            throw new InvalidArgumentException(json_encode(['completion_note' => 'Enter the completion note or action result.'], JSON_THROW_ON_ERROR));
        }
        return $this->transitionAction($matterId, $actionId, 'COMPLETED', $user, null, $completionNote);
    }

    public function cancelAction(int $matterId, int $actionId, array $data, array $user): ?array
    {
        $reason = $this->text($data['cancellation_reason'] ?? '', 2000);
        if ($reason === '') {
            throw new InvalidArgumentException(json_encode(['cancellation_reason' => 'Enter a cancellation reason.'], JSON_THROW_ON_ERROR));
        }
        return $this->transitionAction($matterId, $actionId, 'CANCELLED', $user, $reason);
    }

    public function storeSuggestion(int $matterId, array $clean): bool
    {
        $this->assertMatterActionMutable($this->matter($matterId));
        if ($this->duplicateSuggestionOrActionExists($matterId, $clean)) {
            return false;
        }
        $this->pdo->prepare("INSERT INTO legal_matter_action_suggestion (legal_matter_id, suggestion_hash, suggested_title, suggested_action_type, suggested_description, suggested_due_at, date_basis, deadline_basis, source_context, recommendation_reason, recommended_business_days, source_document_id, source_document_version_id, confidence, status, created_at, updated_at) VALUES (:matter_id, :hash, :title, :type, :description, :due_at, :date_basis, :deadline_basis, :source_context, :recommendation_reason, :recommended_business_days, :source_document_id, :source_document_version_id, :confidence, 'PENDING', NOW(), NOW())")->execute([
            'matter_id' => $matterId,
            'hash' => $this->suggestionHash($matterId, $clean),
            'title' => $clean['title'],
            'type' => $clean['action_type'],
            'description' => $clean['description'],
            'due_at' => $clean['due_at'],
            'date_basis' => $clean['date_basis'],
            'deadline_basis' => $clean['deadline_basis'],
            'source_context' => $clean['source_context'],
            'recommendation_reason' => $clean['recommendation_reason'],
            'recommended_business_days' => $clean['recommended_business_days'],
            'source_document_id' => $clean['source_document_id'],
            'source_document_version_id' => $clean['source_document_version_id'],
            'confidence' => $clean['confidence'],
        ]);
        $this->history($matterId, 'LEGAL_AI_ACTION_RECOMMENDED', 'AI action recommendation created for human review.', ['title' => $clean['title'], 'action_type' => $clean['action_type'], 'deadline_basis' => $clean['deadline_basis']], ['id' => 0]);
        return true;
    }

    public function actionTypes(): array
    {
        return self::ACTION_TYPES;
    }

    private function transitionAction(int $matterId, int $actionId, string $next, array $user, ?string $reason = null, ?string $completionNote = null): ?array
    {
        $action = $this->action($matterId, $actionId);
        if ($action === null) return null;
        $matter = $this->matter($matterId);
        $this->assertMatterActionMutable($matter);
        if (in_array($next, ['IN_PROGRESS', 'COMPLETED'], true) && ($matter['status'] ?? '') !== 'IN_PROGRESS') {
            throw new InvalidArgumentException(json_encode(['status' => 'Legal actions can only be started or completed after the matter begins processing.'], JSON_THROW_ON_ERROR));
        }
        $current = (string) $action['status'];
        $allowed = [
            'PENDING' => ['IN_PROGRESS', 'CANCELLED'],
            'IN_PROGRESS' => ['COMPLETED', 'CANCELLED'],
            'COMPLETED' => [],
            'CANCELLED' => [],
        ];
        if ($current === 'PENDING' && $next === 'COMPLETED') {
            throw new InvalidArgumentException(json_encode(['status' => 'Start this legal action before marking it completed.'], JSON_THROW_ON_ERROR));
        }
        if (!in_array($next, $allowed[$current] ?? [], true)) {
            throw new InvalidArgumentException(json_encode(['status' => "Cannot move action from $current to $next."], JSON_THROW_ON_ERROR));
        }
        $sets = ['status = :status', 'updated_at = NOW()'];
        $params = ['status' => $next, 'matter_id' => $matterId, 'action_id' => $actionId];
        $event = 'LEGAL_ACTION_STARTED';
        $description = 'Legal action started.';
        if ($next === 'COMPLETED') {
            $sets[] = 'completed_at = NOW()';
            $sets[] = 'completed_by_user_id = :user_id';
            $sets[] = 'completion_note = :completion_note';
            $params['user_id'] = (int) $user['id'];
            $params['completion_note'] = $completionNote;
            $event = 'LEGAL_ACTION_COMPLETED';
            $description = 'Legal action completed.';
        } elseif ($next === 'CANCELLED') {
            $sets[] = 'cancelled_at = NOW()';
            $sets[] = 'cancelled_by_user_id = :user_id';
            $sets[] = 'cancellation_reason = :reason';
            $params['user_id'] = (int) $user['id'];
            $params['reason'] = $reason;
            $event = 'LEGAL_ACTION_CANCELLED';
            $description = 'Legal action cancelled.';
        }
        $this->pdo->prepare('UPDATE legal_matter_action SET ' . implode(', ', $sets) . ' WHERE legal_matter_id = :matter_id AND legal_matter_action_id = :action_id AND deleted_at IS NULL')->execute($params);
        $metadata = ['action_id' => $actionId, 'from' => $current, 'to' => $next];
        if ($completionNote !== null) {
            $metadata['completion_note'] = $completionNote;
        }
        $this->history($matterId, $event, $description, $metadata, $user);
        return $this->matterPayload($matterId);
    }

    private function validateAction(array $data): array
    {
        $title = $this->text($data['title'] ?? '', 255);
        $type = strtoupper($this->text($data['action_type'] ?? '', 50));
        $description = $this->nullableText($data['description'] ?? null, 5000);
        $assignedId = $this->optionalId($data['assigned_employee_reference_id'] ?? null);
        $dueAt = $this->dateTime($data['due_at'] ?? null);
        $errors = [];
        if ($title === '') $errors['title'] = 'Enter an action title.';
        if (!in_array($type, self::ACTION_TYPES, true)) $errors['action_type'] = 'Choose a valid action type.';
        if ($errors) {
            throw new InvalidArgumentException(json_encode($errors, JSON_THROW_ON_ERROR));
        }
        ($this->employeeEligibility ?? new FamEmployeeEligibilityService($this->pdo))->assertCrossDepartmentContact($assignedId);
        return [
            'title' => $title,
            'action_type' => $type,
            'description' => $description,
            'assigned_employee_reference_id' => $assignedId,
            'due_at' => $dueAt,
            'source_document_id' => $this->optionalId($data['source_document_id'] ?? null),
            'source_document_version_id' => $this->optionalId($data['source_document_version_id'] ?? null),
            'deadline_basis' => $this->deadlineBasis($data['deadline_basis'] ?? null),
            'recommendation_reason' => $this->nullableText($data['recommendation_reason'] ?? null, 500),
        ];
    }

    private function deadlineBasis(mixed $value): ?string
    {
        $basis = strtoupper($this->text($value ?? '', 30));
        return in_array($basis, self::DEADLINE_BASES, true) ? $basis : null;
    }

    private function duplicateSuggestionOrActionExists(int $matterId, array $clean): bool
    {
        if ((int) $this->scalar('SELECT COUNT(*) FROM legal_matter_action_suggestion WHERE legal_matter_id = :matter_id AND suggestion_hash = :hash AND status IN (\'PENDING\',\'ACCEPTED\',\'DISMISSED\')', ['matter_id' => $matterId, 'hash' => $this->suggestionHash($matterId, $clean)]) > 0) {
            return true;
        }
        return $this->duplicateActionExists($matterId, [
            'title' => $clean['title'],
            'action_type' => $clean['action_type'],
            'due_at' => $clean['due_at'],
            'source_document_id' => $clean['source_document_id'],
            'source_document_version_id' => $clean['source_document_version_id'],
        ]);
    }

    private function duplicateActionExists(int $matterId, array $clean, ?int $ignoreActionId = null): bool
    {
        $ignoreSql = $ignoreActionId !== null ? ' AND legal_matter_action_id <> :ignore_id' : '';
        $params = [
            'matter_id' => $matterId,
            'title' => $this->normalize((string) ($clean['title'] ?? '')),
            'type' => strtoupper((string) ($clean['action_type'] ?? '')),
            'due_at' => $clean['due_at'] ?? null,
            'source_document_id' => $clean['source_document_id'] ?? null,
            'source_document_version_id' => $clean['source_document_version_id'] ?? null,
        ];
        if ($ignoreActionId !== null) $params['ignore_id'] = $ignoreActionId;
        return (int) $this->scalar("SELECT COUNT(*) FROM legal_matter_action WHERE legal_matter_id = :matter_id AND deleted_at IS NULL AND LOWER(REPLACE(REPLACE(REPLACE(TRIM(title), ' ', ''), '.', ''), ',', '')) = :title AND action_type = :type AND COALESCE(due_at, '1000-01-01 00:00:00') = COALESCE(:due_at, '1000-01-01 00:00:00') AND COALESCE(source_document_id, 0) = COALESCE(:source_document_id, 0) AND COALESCE(source_document_version_id, 0) = COALESCE(:source_document_version_id, 0)" . $ignoreSql, $params) > 0;
    }

    private function suggestionHash(int $matterId, array $clean): string
    {
        return hash('sha256', implode('|', [
            $matterId,
            $this->normalize($clean['title'] ?? ''),
            strtoupper((string) ($clean['action_type'] ?? '')),
            (string) ($clean['due_at'] ?? ''),
            (string) ($clean['source_document_id'] ?? ''),
            (string) ($clean['source_document_version_id'] ?? ''),
        ]));
    }

    private function shapeAction(array $row): array
    {
        $status = (string) $row['status'];
        $dueAt = (string) ($row['due_at'] ?? '');
        $isOpen = in_array($status, self::OPEN_STATUSES, true);
        $now = new DateTimeImmutable('now');
        $due = $dueAt !== '' ? new DateTimeImmutable($dueAt) : null;
        $isOverdue = $isOpen && $due !== null && $due < $now;
        $isDueSoon = $isOpen && !$isOverdue && $due !== null && $due <= $now->modify('+' . self::DUE_SOON_DAYS . ' days');
        $display = $isOverdue ? 'OVERDUE' : ($isDueSoon ? 'DUE_SOON' : $status);
        return [
            'id' => (int) $row['legal_matter_action_id'],
            'title' => (string) $row['title'],
            'actionType' => (string) $row['action_type'],
            'description' => (string) ($row['description'] ?? ''),
            'assignedEmployeeId' => $row['assigned_employee_reference_id'] === null ? null : (int) $row['assigned_employee_reference_id'],
            'assignedTo' => (string) ($row['assigned_name'] ?? ''),
            'dueAt' => $dueAt,
            'status' => $status,
            'displayStatus' => $display,
            'isDueSoon' => $isDueSoon,
            'isOverdue' => $isOverdue,
            'source' => (string) $row['source'],
            'sourceDocument' => (string) ($row['source_document_number'] ?? ''),
            'sourceDocumentId' => $row['source_document_id'] === null ? null : (int) $row['source_document_id'],
            'deadlineBasis' => (string) ($row['deadline_basis'] ?? ''),
            'recommendationReason' => (string) ($row['recommendation_reason'] ?? ''),
            'completionNote' => (string) ($row['completion_note'] ?? ''),
            'createdAt' => (string) $row['created_at'],
            'completedAt' => (string) ($row['completed_at'] ?? ''),
            'cancelledAt' => (string) ($row['cancelled_at'] ?? ''),
            'cancellationReason' => (string) ($row['cancellation_reason'] ?? ''),
        ];
    }

    private function shapeSuggestion(array $row): array
    {
        return [
            'id' => (int) $row['legal_matter_action_suggestion_id'],
            'title' => (string) $row['suggested_title'],
            'actionType' => (string) $row['suggested_action_type'],
            'description' => (string) ($row['suggested_description'] ?? ''),
            'dueAt' => (string) ($row['suggested_due_at'] ?? ''),
            'dateBasis' => (string) ($row['date_basis'] ?? ''),
            'deadlineBasis' => (string) ($row['deadline_basis'] ?? $row['date_basis'] ?? ''),
            'recommendationReason' => (string) ($row['recommendation_reason'] ?? ''),
            'recommendedBusinessDays' => $row['recommended_business_days'] === null ? null : (int) $row['recommended_business_days'],
            'sourceContext' => (string) ($row['source_context'] ?? ''),
            'sourceDocument' => (string) ($row['source_document_number'] ?? ''),
            'sourceDocumentId' => $row['source_document_id'] === null ? null : (int) $row['source_document_id'],
            'confidence' => (string) ($row['confidence'] ?? ''),
        ];
    }

    private function suggestion(int $matterId, int $suggestionId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM legal_matter_action_suggestion WHERE legal_matter_id = :matter_id AND legal_matter_action_suggestion_id = :id AND status = 'PENDING' LIMIT 1");
        $stmt->execute(['matter_id' => $matterId, 'id' => $suggestionId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    private function action(int $matterId, int $actionId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM legal_matter_action WHERE legal_matter_id = :matter_id AND legal_matter_action_id = :id AND deleted_at IS NULL LIMIT 1');
        $stmt->execute(['matter_id' => $matterId, 'id' => $actionId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    private function matter(int $matterId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM legal_matter WHERE legal_matter_id = :id AND deleted_at IS NULL LIMIT 1');
        $stmt->execute(['id' => $matterId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    private function matterPayload(int $matterId): array
    {
        return ['actions' => $this->actionsForMatter($matterId), 'actionSuggestions' => $this->suggestionsForMatter($matterId)];
    }

    private function assertMatterActionMutable(?array $matter): void
    {
        if (in_array((string) ($matter['status'] ?? ''), ['RESOLVED', 'CLOSED', 'CANCELLED'], true)) {
            throw new InvalidArgumentException(json_encode(['status' => 'This legal matter is read-only for legal action changes.'], JSON_THROW_ON_ERROR));
        }
    }

    private function markSuggestion(int $suggestionId, string $status, int $userId, ?int $actionId, ?string $reason): void
    {
        $this->pdo->prepare('UPDATE legal_matter_action_suggestion SET status = :status, dismissal_reason = :reason, reviewed_by_user_id = :user_id, reviewed_at = NOW(), accepted_action_id = :action_id, updated_at = NOW() WHERE legal_matter_action_suggestion_id = :id')->execute([
            'status' => $status,
            'reason' => $reason,
            'user_id' => $userId,
            'action_id' => $actionId,
            'id' => $suggestionId,
        ]);
    }

    private function employeeExists(int $id): bool
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM employee_reference WHERE employee_reference_id = :id AND employment_status = 'ACTIVE'", ['id' => $id]) > 0;
    }

    private function history(int $matterId, string $event, string $description, array $metadata, array $user): void
    {
        $userId = ((int) ($user['id'] ?? 0)) > 0 ? (int) $user['id'] : null;
        $this->pdo->prepare('INSERT INTO legal_matter_history (legal_matter_id, event_type, from_status, to_status, description, metadata_json, actor_user_id, created_at) VALUES (:id, :event, NULL, NULL, :description, :metadata, :user_id, NOW())')->execute([
            'id' => $matterId,
            'event' => $event,
            'description' => $description,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'user_id' => $userId,
        ]);
    }

    private function rows(string $sql, array $params = []): array { $stmt = $this->pdo->prepare($sql); $stmt->execute($params); return $stmt->fetchAll(); }
    private function scalar(string $sql, array $params = []): mixed { $stmt = $this->pdo->prepare($sql); $stmt->execute($params); return $stmt->fetchColumn(); }
    private function optionalId(mixed $value): ?int { return ($value === null || $value === '' || $value === 'all') ? null : (ctype_digit((string) $value) ? (int) $value : null); }
    private function text(mixed $value, int $max): string { return mb_substr(trim((string) $value), 0, $max); }
    private function nullableText(mixed $value, int $max): ?string { $text = $this->text($value ?? '', $max); return $text === '' ? null : $text; }
    private function normalize(string $value): string { return strtolower(preg_replace('/[^a-z0-9]+/i', '', trim($value)) ?? ''); }

    private function dateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        return (new DateTimeImmutable((string) $value))->format('Y-m-d H:i:s');
    }
}
