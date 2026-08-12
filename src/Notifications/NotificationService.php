<?php

declare(strict_types=1);

final class NotificationService
{
    public function __construct(private readonly PDO $pdo) {}

    public function createForUser(int $userId, array $payload): void
    {
        if ($userId < 1) {
            return;
        }

        $metadata = $payload['metadata'] ?? [];
        if (!is_array($metadata)) {
            $metadata = [];
        }

        if ($this->existsRecentDuplicate($userId, $payload, $metadata)) {
            return;
        }

        $stmt = $this->pdo->prepare(<<<'SQL'
INSERT INTO notification (
    recipient_user_id, event_code, module_code, notification_type, title, message, priority,
    related_entity_type, related_entity_id, related_reference, action_url, metadata_json,
    is_read, is_dismissed, created_at
) VALUES (
    :recipient_user_id, :event_code, :module_code, :notification_type, :title, :message, :priority,
    :related_entity_type, :related_entity_id, :related_reference, :action_url, :metadata_json,
    0, 0, NOW()
)
SQL);

        $stmt->execute([
            'recipient_user_id' => $userId,
            'event_code' => (string) ($payload['event_code'] ?? 'GENERAL'),
            'module_code' => (string) ($payload['module_code'] ?? 'SYSTEM'),
            'notification_type' => (string) ($payload['notification_type'] ?? 'IN_APP'),
            'title' => (string) ($payload['title'] ?? 'Notification'),
            'message' => (string) ($payload['message'] ?? ''),
            'priority' => (string) ($payload['priority'] ?? 'NORMAL'),
            'related_entity_type' => $payload['related_entity_type'] ?? null,
            'related_entity_id' => $payload['related_entity_id'] ?? null,
            'related_reference' => $payload['related_reference'] ?? null,
            'action_url' => $payload['action_url'] ?? null,
            'metadata_json' => json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function createForUsers(array $userIds, array $payload): void
    {
        foreach (array_values(array_unique(array_map('intval', $userIds))) as $userId) {
            $this->createForUser($userId, $payload);
        }
    }

    public function notifyFacilityAdmins(array $request, string $eventCode, string $title, string $message): void
    {
        $this->createForUsers($this->facilityAdminRecipients(), $this->facilityPayload($request, $eventCode, $title, $message, 'admin'));
    }

    public function notifyFacilityRequester(array $request, string $eventCode, string $title, string $message): void
    {
        $userId = $this->userIdForEmployee((int) ($request['requested_by_employee_reference_id'] ?? 0));
        if ($userId === null) {
            return;
        }

        $this->createForUser($userId, $this->facilityPayload($request, $eventCode, $title, $message, 'employee'));
    }

    public function facilityAdminRecipients(): array
    {
        $rows = $this->pdo->query(<<<'SQL'
SELECT DISTINCT ua.user_account_id
FROM user_account ua
INNER JOIN user_role ur ON ur.user_account_id = ua.user_account_id
INNER JOIN role r ON r.role_id = ur.role_id
LEFT JOIN role_permission rp ON rp.role_id = r.role_id
LEFT JOIN permission p ON p.permission_id = rp.permission_id
WHERE ua.account_status = 'ACTIVE'
  AND ua.deleted_at IS NULL
  AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
  AND (
    p.permission_code IN ('facility_requests.manage','facility_requests.assign','facility_requests.approve')
    OR r.role_code IN ('FAM_ADMIN','SYSTEM_ADMIN')
  )
ORDER BY ua.user_account_id
SQL)->fetchAll();

        return array_map(static fn (array $row): int => (int) $row['user_account_id'], $rows);
    }

    public function listForUser(int $userId, array $query): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(50, max(1, (int) ($query['per_page'] ?? 10)));
        $direction = strtolower((string) ($query['direction'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
        $where = ['recipient_user_id = :user_id', 'is_dismissed = 0'];
        $params = ['user_id' => $userId];

        if (!empty($query['unread_only'])) {
            $where[] = 'is_read = 0';
        }

        if (($query['read_state'] ?? '') === 'read') {
            $where[] = 'is_read = 1';
        } elseif (($query['read_state'] ?? '') === 'unread') {
            $where[] = 'is_read = 0';
        }

        if (($query['module_code'] ?? '') !== '') {
            $where[] = 'module_code = :module_code';
            $params['module_code'] = strtoupper((string) $query['module_code']);
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM notification $whereSql");
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $stmt = $this->pdo->prepare("SELECT * FROM notification $whereSql ORDER BY created_at $direction, notification_id $direction LIMIT :limit OFFSET :offset");
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue('offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => array_map([$this, 'shape'], $stmt->fetchAll()),
            'unread_count' => $this->unreadCount($userId),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) max(1, ceil($total / $perPage)),
            ],
        ];
    }

    public function markRead(int $userId, int $notificationId): bool
    {
        $stmt = $this->pdo->prepare('UPDATE notification SET is_read = 1, read_at = COALESCE(read_at, NOW()) WHERE notification_id = :id AND recipient_user_id = :user_id AND is_dismissed = 0');
        $stmt->execute(['id' => $notificationId, 'user_id' => $userId]);
        return $stmt->rowCount() > 0;
    }

    public function markAllRead(int $userId): int
    {
        $stmt = $this->pdo->prepare('UPDATE notification SET is_read = 1, read_at = COALESCE(read_at, NOW()) WHERE recipient_user_id = :user_id AND is_dismissed = 0 AND is_read = 0');
        $stmt->execute(['user_id' => $userId]);
        return $stmt->rowCount();
    }

    public function unreadCount(int $userId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM notification WHERE recipient_user_id = :user_id AND is_dismissed = 0 AND is_read = 0');
        $stmt->execute(['user_id' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    private function userIdForEmployee(int $employeeId): ?int
    {
        if ($employeeId < 1) {
            return null;
        }

        $stmt = $this->pdo->prepare("SELECT user_account_id FROM user_account WHERE employee_reference_id = :employee_id AND account_status = 'ACTIVE' AND deleted_at IS NULL LIMIT 1");
        $stmt->execute(['employee_id' => $employeeId]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (int) $value;
    }

    private function facilityPayload(array $request, string $eventCode, string $title, string $message, string $portal): array
    {
        $id = (int) ($request['facility_request_id'] ?? $request['id'] ?? 0);
        $reference = (string) ($request['request_number'] ?? '');
        $status = (string) ($request['status'] ?? '');

        return [
            'event_code' => $eventCode,
            'module_code' => 'FACILITY_REQUESTS',
            'notification_type' => 'IN_APP',
            'title' => $title,
            'message' => $message,
            'priority' => (string) ($request['priority'] ?? 'NORMAL'),
            'related_entity_type' => 'facility_request',
            'related_entity_id' => $id,
            'related_reference' => $reference,
            'action_url' => $portal === 'employee'
                ? 'pages/employee/facility-requests.html?request=' . $id
                : 'pages/facility-requests.html?request=' . $id,
            'metadata' => [
                'portal' => $portal,
                'resulting_status' => $status,
                'request_number' => $reference,
            ],
        ];
    }

    private function existsRecentDuplicate(int $userId, array $payload, array $metadata): bool
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
SELECT metadata_json
FROM notification
WHERE recipient_user_id = :user_id
  AND event_code = :event_code
  AND module_code = :module_code
  AND related_entity_type <=> :entity_type
  AND related_entity_id <=> :entity_id
  AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
ORDER BY notification_id DESC
LIMIT 10
SQL);
        $stmt->execute([
            'user_id' => $userId,
            'event_code' => (string) ($payload['event_code'] ?? 'GENERAL'),
            'module_code' => (string) ($payload['module_code'] ?? 'SYSTEM'),
            'entity_type' => $payload['related_entity_type'] ?? null,
            'entity_id' => $payload['related_entity_id'] ?? null,
        ]);

        $status = (string) ($metadata['resulting_status'] ?? '');
        foreach ($stmt->fetchAll() as $row) {
            $existing = json_decode((string) ($row['metadata_json'] ?? ''), true);
            if (!is_array($existing)) {
                if ($status === '') {
                    return true;
                }
                continue;
            }
            if ($status === '' || (string) ($existing['resulting_status'] ?? '') === $status) {
                return true;
            }
        }

        return false;
    }

    private function shape(array $row): array
    {
        $metadata = json_decode((string) ($row['metadata_json'] ?? ''), true);
        if (!is_array($metadata)) {
            $metadata = [];
        }

        return [
            'id' => (int) $row['notification_id'],
            'event_code' => (string) $row['event_code'],
            'module_code' => (string) $row['module_code'],
            'type' => (string) $row['notification_type'],
            'title' => (string) $row['title'],
            'message' => (string) $row['message'],
            'priority' => (string) $row['priority'],
            'related_entity_type' => $row['related_entity_type'],
            'related_entity_id' => $row['related_entity_id'] === null ? null : (int) $row['related_entity_id'],
            'related_reference' => $row['related_reference'],
            'action_url' => $row['action_url'],
            'metadata' => $metadata,
            'is_read' => (bool) $row['is_read'],
            'read_at' => $row['read_at'],
            'created_at' => $row['created_at'],
        ];
    }
}
