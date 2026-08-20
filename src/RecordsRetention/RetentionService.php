<?php

declare(strict_types=1);

final class RetentionPolicy
{
    public static function hasPermission(array $user, string $permission): bool
    {
        return in_array($permission, $user['permissions'] ?? [], true);
    }

    public static function requirePermission(array $user, string $permission): void
    {
        if (!self::hasPermission($user, $permission)) {
            jsonResponse(false, 'You do not have permission to perform this action.', [], 403);
        }
    }
}

final class RetentionService
{
    private const DUE_REVIEW_DAYS = 30;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function countRecordsDueForReview(PDO $pdo): int
    {
        $sql = self::baseSelectStatic('COUNT(DISTINCT r.record_id)') . ' WHERE r.deleted_at IS NULL AND ' . self::dueStateSql('DUE_FOR_REVIEW');
        return (int) $pdo->query($sql)->fetchColumn();
    }

    public static function calculateScheduledDispositionDate(?string $startDate, ?array $schedule): ?string
    {
        if ($startDate === null || $startDate === '' || $schedule === null || self::isPermanentSchedule($schedule)) {
            return null;
        }

        $value = max(0, (int) ($schedule['retention_period_value'] ?? $schedule['periodValue'] ?? 0));
        $unit = strtoupper((string) ($schedule['retention_period_unit'] ?? $schedule['periodUnit'] ?? 'YEARS'));
        $date = new DateTimeImmutable($startDate);
        return match ($unit) {
            'DAYS' => $date->modify("+$value days")->format('Y-m-d'),
            'MONTHS' => $date->modify("+$value months")->format('Y-m-d'),
            default => $date->modify("+$value years")->format('Y-m-d'),
        };
    }

    public static function isPermanentSchedule(?array $schedule): bool
    {
        if ($schedule === null) {
            return false;
        }

        $unit = strtoupper((string) ($schedule['retention_period_unit'] ?? $schedule['periodUnit'] ?? ''));
        $action = strtoupper((string) ($schedule['disposition_action'] ?? $schedule['dispositionAction'] ?? ''));
        return $unit === 'PERMANENT' || $action === 'PERMANENT';
    }

    public function list(array $query): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($query['per_page'] ?? 10)));
        $sortMap = [
            'record_number' => 'r.record_number',
            'title' => 'r.record_title',
            'category' => 'rs.record_category',
            'schedule' => 'rs.schedule_name',
            'retention_start' => 'r.retention_start_date',
            'disposition_date' => 'r.scheduled_disposition_date',
            'status' => 'r.record_status',
            'updated_at' => 'r.updated_at',
        ];
        $sort = $sortMap[(string) ($query['sort'] ?? '')] ?? 'r.scheduled_disposition_date';
        $direction = strtolower((string) ($query['direction'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
        [$where, $params] = $this->filters($query);
        $dueState = strtoupper((string) ($query['due_state'] ?? 'all'));
        if ($dueState !== '' && $dueState !== 'ALL') {
            $where .= ' AND ' . self::dueStateSql($dueState);
        }

        $count = $this->pdo->prepare($this->baseSelect('COUNT(DISTINCT r.record_id)') . $where);
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $sql = $this->baseSelect($this->selectColumns()) . $where . " GROUP BY r.record_id ORDER BY $sort $direction, r.record_id DESC LIMIT :limit OFFSET :offset";
        $statement = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->bindValue('limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue('offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $statement->execute();

        $items = array_map(fn (array $row): array => $this->shape($row), $statement->fetchAll());

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) max(1, ceil($total / max(1, $perPage))),
            ],
            'summary' => $this->summary(),
        ];
    }

    public function show(int $id): ?array
    {
        $statement = $this->pdo->prepare($this->baseSelect($this->selectColumns()) . ' WHERE r.deleted_at IS NULL AND r.record_id = :id GROUP BY r.record_id LIMIT 1');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }

        $item = $this->shape($row);
        $item['documents'] = array_map(fn (array $doc): array => [
            'id' => (int) $doc['document_id'],
            'documentNo' => (string) $doc['document_number'],
            'title' => (string) $doc['document_title'],
            'status' => (string) $doc['document_status'],
            'version' => 'v' . (int) $doc['current_version_number'],
            'confidentiality' => (string) $doc['confidentiality_level'],
        ], $this->rows('SELECT d.* FROM record_document rd INNER JOIN document d ON d.document_id = rd.document_id WHERE rd.record_id = :id AND d.deleted_at IS NULL ORDER BY rd.is_primary_document DESC, d.document_number', ['id' => $id]));
        $item['history'] = $this->history($id);
        return $item;
    }

    public function options(): array
    {
        return [
            'schedules' => $this->schedules(),
            'categories' => array_values(array_filter(array_unique(array_map(fn (array $row): string => (string) $row['record_category'], $this->rows("SELECT DISTINCT record_category FROM retention_schedule WHERE record_category IS NOT NULL AND record_category <> '' ORDER BY record_category"))))),
            'statuses' => ['ACTIVE', 'ARCHIVED', 'DISPOSED', 'ON_HOLD'],
            'due_states' => ['ACTIVE', 'DUE_FOR_REVIEW', 'OVERDUE', 'ON_HOLD', 'ARCHIVED', 'DISPOSED', 'PERMANENT'],
            'hold_states' => ['NONE', 'ACTIVE', 'RELEASED'],
        ];
    }

    public function schedules(): array
    {
        return array_map(fn (array $row): array => [
            'id' => (int) $row['retention_schedule_id'],
            'code' => (string) $row['schedule_code'],
            'name' => (string) $row['schedule_name'],
            'category' => (string) $row['record_category'],
            'trigger' => (string) $row['retention_trigger'],
            'periodValue' => (int) $row['retention_period_value'],
            'periodUnit' => (string) $row['retention_period_unit'],
            'dispositionAction' => (string) $row['disposition_action'],
            'legalBasis' => (string) ($row['legal_basis'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'status' => (string) $row['status'],
            'effectiveDate' => (string) ($row['effective_date'] ?? ''),
        ], $this->rows('SELECT * FROM retention_schedule ORDER BY status DESC, record_category, schedule_name'));
    }

    public function saveSchedule(array $data, array $user): array
    {
        $clean = $this->validateSchedule($data);
        $id = (int) ($data['retention_schedule_id'] ?? 0);
        if ($id > 0) {
            $statement = $this->pdo->prepare('UPDATE retention_schedule SET schedule_code = :code, schedule_name = :name, record_category = :category, retention_trigger = :trigger, retention_period_value = :period_value, retention_period_unit = :period_unit, disposition_action = :action, legal_basis = :legal_basis, description = :description, status = :status, effective_date = :effective_date WHERE retention_schedule_id = :id');
            $statement->execute($clean + ['id' => $id]);
        } else {
            $statement = $this->pdo->prepare('INSERT INTO retention_schedule (schedule_code, schedule_name, record_category, retention_trigger, retention_period_value, retention_period_unit, disposition_action, legal_basis, description, status, effective_date) VALUES (:code, :name, :category, :trigger, :period_value, :period_unit, :action, :legal_basis, :description, :status, :effective_date)');
            $statement->execute($clean);
            $id = (int) $this->pdo->lastInsertId();
        }
        $this->logActivity('RETENTION_SCHEDULE_SAVED', 'Retention Schedule Saved', 0, (int) $user['id'], ['schedule_id' => $id]);
        return ['schedule' => $this->schedule($id)];
    }

    public function assign(int $id, array $data, array $user): ?array
    {
        $scheduleId = (int) ($data['retention_schedule_id'] ?? 0);
        $startDate = $this->date($data['retention_start_date'] ?? null);
        $schedule = $this->schedule($scheduleId);
        if ($schedule === null || $schedule['status'] !== 'ACTIVE' || $startDate === null) {
            throw new InvalidArgumentException(json_encode(['retention_schedule_id' => 'Choose an active retention schedule and start date.'], JSON_THROW_ON_ERROR));
        }
        $item = $this->show($id);
        if ($item === null) {
            return null;
        }
        if (in_array($item['recordStatus'], ['ARCHIVED', 'DISPOSED'], true)) {
            throw new InvalidArgumentException(json_encode(['record' => 'Terminal records cannot be reassigned.'], JSON_THROW_ON_ERROR));
        }
        if ($item['legalHoldStatus'] === 'ACTIVE') {
            throw new InvalidArgumentException(json_encode(['legal_hold' => 'Release the legal hold before changing this record schedule.'], JSON_THROW_ON_ERROR));
        }
        $disposition = self::calculateScheduledDispositionDate($startDate, $schedule);
        $statement = $this->pdo->prepare('UPDATE record SET retention_schedule_id = :schedule_id, retention_start_date = :start_date, scheduled_disposition_date = :disposition_date, updated_by_user_id = :user_id, updated_at = NOW() WHERE record_id = :id AND deleted_at IS NULL');
        $statement->execute(['schedule_id' => $scheduleId, 'start_date' => $startDate, 'disposition_date' => $disposition, 'user_id' => (int) $user['id'], 'id' => $id]);
        $this->logActivity('RETENTION_ASSIGNED', 'Retention Schedule Assigned', $id, (int) $user['id'], ['schedule_id' => $scheduleId, 'retention_start_date' => $startDate, 'scheduled_disposition_date' => $disposition]);
        return $this->show($id);
    }

    public function extend(int $id, array $data, array $user): ?array
    {
        $newDate = $this->date($data['scheduled_disposition_date'] ?? null);
        $reason = $this->text($data['reason'] ?? '', 1000);
        if ($newDate === null || $reason === '') {
            throw new InvalidArgumentException(json_encode(['scheduled_disposition_date' => 'Enter a new review date and reason.'], JSON_THROW_ON_ERROR));
        }
        $item = $this->show($id);
        if ($item === null) {
            return null;
        }
        if ($item['recordStatus'] === 'DISPOSED') {
            throw new InvalidArgumentException(json_encode(['record' => 'Disposed records cannot be extended.'], JSON_THROW_ON_ERROR));
        }
        if ($item['legalHoldStatus'] === 'ACTIVE') {
            throw new InvalidArgumentException(json_encode(['legal_hold' => 'Release the legal hold before extending this record.'], JSON_THROW_ON_ERROR));
        }
        if ($item['dueState'] === 'PERMANENT') {
            throw new InvalidArgumentException(json_encode(['record' => 'Permanent retention records cannot be extended.'], JSON_THROW_ON_ERROR));
        }
        $this->pdo->prepare('UPDATE record SET scheduled_disposition_date = :date, last_reviewed_at = NOW(), disposition_reason = :reason, updated_by_user_id = :user_id, updated_at = NOW() WHERE record_id = :id')->execute(['date' => $newDate, 'reason' => $reason, 'user_id' => (int) $user['id'], 'id' => $id]);
        $this->logActivity('RETENTION_EXTENDED', 'Retention Extended', $id, (int) $user['id'], ['new_date' => $newDate, 'reason' => $reason]);
        return $this->show($id);
    }

    public function archive(int $id, array $data, array $user): ?array
    {
        $item = $this->show($id);
        if ($item === null) {
            return null;
        }
        if ($item['legalHoldStatus'] === 'ACTIVE') {
            throw new InvalidArgumentException(json_encode(['legal_hold' => 'Release the legal hold before archiving this record.'], JSON_THROW_ON_ERROR));
        }
        return $this->transition($id, 'ARCHIVED', 'RETENTION_ARCHIVED', 'Record Archived', $data, $user, false);
    }

    public function dispose(int $id, array $data, array $user): ?array
    {
        $item = $this->show($id);
        if ($item === null) {
            return null;
        }
        if ($item['legalHoldStatus'] === 'ACTIVE') {
            throw new InvalidArgumentException(json_encode(['legal_hold' => 'Release the legal hold before disposing this record.'], JSON_THROW_ON_ERROR));
        }
        if ($item['dueState'] === 'PERMANENT') {
            throw new InvalidArgumentException(json_encode(['record' => 'Permanent records cannot be disposed.'], JSON_THROW_ON_ERROR));
        }
        return $this->transition($id, 'DISPOSED', 'RETENTION_DISPOSED', 'Record Disposed', $data, $user, true);
    }

    public function placeHold(int $id, array $data, array $user): ?array
    {
        $reason = $this->requiredReason($data);
        $item = $this->show($id);
        if ($item === null) {
            return null;
        }
        if (in_array($item['recordStatus'], ['ARCHIVED', 'DISPOSED'], true)) {
            throw new InvalidArgumentException(json_encode(['record' => 'Terminal records cannot be placed on legal hold.'], JSON_THROW_ON_ERROR));
        }
        $this->pdo->prepare("UPDATE record SET legal_hold_status = 'ACTIVE', legal_hold_reason = :reason, legal_hold_placed_by_user_id = :placed_by, legal_hold_placed_at = NOW(), legal_hold_released_by_user_id = NULL, legal_hold_released_at = NULL, legal_hold_release_reason = NULL, record_status = IF(record_status IN ('DISPOSED','ARCHIVED'), record_status, 'ON_HOLD'), updated_by_user_id = :updated_by, updated_at = NOW() WHERE record_id = :id")->execute(['reason' => $reason, 'placed_by' => (int) $user['id'], 'updated_by' => (int) $user['id'], 'id' => $id]);
        $this->logActivity('LEGAL_HOLD_PLACED', 'Legal Hold Placed', $id, (int) $user['id'], ['reason' => $reason]);
        return $this->show($id);
    }

    public function releaseHold(int $id, array $data, array $user): ?array
    {
        $reason = $this->requiredReason($data);
        if ($this->show($id) === null) {
            return null;
        }
        $this->pdo->prepare("UPDATE record SET legal_hold_status = 'RELEASED', legal_hold_released_by_user_id = :released_by, legal_hold_released_at = NOW(), legal_hold_release_reason = :reason, record_status = IF(record_status = 'ON_HOLD', 'ACTIVE', record_status), updated_by_user_id = :updated_by, updated_at = NOW() WHERE record_id = :id")->execute(['reason' => $reason, 'released_by' => (int) $user['id'], 'updated_by' => (int) $user['id'], 'id' => $id]);
        $this->logActivity('LEGAL_HOLD_RELEASED', 'Legal Hold Released', $id, (int) $user['id'], ['reason' => $reason]);
        return $this->show($id);
    }

    private function transition(int $id, string $status, string $event, string $title, array $data, array $user, bool $dispositioned): ?array
    {
        $reason = $this->requiredReason($data);
        if ($this->show($id) === null) {
            return null;
        }
        $sql = 'UPDATE record SET record_status = :status, last_reviewed_at = NOW(), disposition_reason = :reason, updated_by_user_id = :updated_by, updated_at = NOW()';
        if ($dispositioned) {
            $sql .= ', dispositioned_by_user_id = :dispositioned_by, dispositioned_at = NOW()';
        }
        $sql .= ' WHERE record_id = :id AND deleted_at IS NULL';
        $params = ['status' => $status, 'reason' => $reason, 'updated_by' => (int) $user['id'], 'id' => $id];
        if ($dispositioned) {
            $params['dispositioned_by'] = (int) $user['id'];
        }
        $this->pdo->prepare($sql)->execute($params);
        $this->logActivity($event, $title, $id, (int) $user['id'], ['reason' => $reason]);
        return $this->show($id);
    }

    private function filters(array $query): array
    {
        $where = ['r.deleted_at IS NULL'];
        $params = [];
        if (($query['search'] ?? '') !== '') {
            $search = '%' . trim((string) $query['search']) . '%';
            $where[] = '(r.record_number LIKE :search_record OR r.record_title LIKE :search_title OR r.record_description LIKE :search_description OR rs.schedule_name LIKE :search_schedule)';
            $params['search_record'] = $search;
            $params['search_title'] = $search;
            $params['search_description'] = $search;
            $params['search_schedule'] = $search;
        }
        foreach (['status' => 'r.record_status', 'schedule_id' => 'r.retention_schedule_id', 'category' => 'rs.record_category', 'legal_hold_status' => 'r.legal_hold_status'] as $key => $column) {
            if (($query[$key] ?? '') !== '' && ($query[$key] ?? 'all') !== 'all') {
                $where[] = "$column = :$key";
                $params[$key] = $query[$key];
            }
        }
        return [' WHERE ' . implode(' AND ', $where), $params];
    }

    private function baseSelect(string $columns): string
    {
        return self::baseSelectStatic($columns);
    }

    public function attentionQueue(int $limit = 5): array
    {
        $limit = max(1, min(20, $limit));
        $where = "r.deleted_at IS NULL AND (" . self::dueStateSql('OVERDUE') . ' OR ' . self::dueStateSql('DUE_FOR_REVIEW') . ')';
        $rows = $this->rows($this->baseSelect($this->selectColumns()) . " WHERE $where ORDER BY r.scheduled_disposition_date ASC, r.updated_at DESC LIMIT $limit");
        return array_map(fn (array $row): array => $this->shape($row), $rows);
    }

    private static function baseSelectStatic(string $columns): string
    {
        return "SELECT $columns FROM record r LEFT JOIN retention_schedule rs ON rs.retention_schedule_id = r.retention_schedule_id LEFT JOIN department_reference d ON d.department_reference_id = r.originating_department_reference_id LEFT JOIN employee_reference owner ON owner.employee_reference_id = r.record_owner_employee_reference_id ";
    }

    private function selectColumns(): string
    {
        return 'r.*, rs.schedule_code, rs.schedule_name, rs.record_category, rs.retention_trigger, rs.retention_period_value, rs.retention_period_unit, rs.disposition_action, rs.legal_basis, rs.status schedule_status, d.department_name, owner.full_name owner_name';
    }

    private function shape(array $row): array
    {
        $status = (string) ($row['record_status'] ?? 'ACTIVE');
        $hold = (string) ($row['legal_hold_status'] ?? 'NONE');
        $dueState = $this->dueState($row);
        return [
            'id' => (int) $row['record_id'],
            'recordNo' => (string) $row['record_number'],
            'title' => (string) $row['record_title'],
            'description' => (string) ($row['record_description'] ?? ''),
            'type' => (string) ($row['record_type'] ?? ''),
            'category' => (string) ($row['record_category'] ?? 'Uncategorized'),
            'sourceModule' => (string) ($row['source_module'] ?? ''),
            'sourceEntityType' => (string) ($row['source_entity_type'] ?? ''),
            'sourceEntityId' => $row['source_entity_id'] ?? null,
            'recordDate' => (string) ($row['record_date'] ?? ''),
            'retentionStartDate' => (string) ($row['retention_start_date'] ?? ''),
            'scheduledDispositionDate' => (string) ($row['scheduled_disposition_date'] ?? ''),
            'recordStatus' => $status,
            'retentionStatus' => $dueState,
            'dueState' => $dueState,
            'legalHoldStatus' => $hold,
            'legalHoldReason' => (string) ($row['legal_hold_reason'] ?? ''),
            'legalHoldPlacedAt' => (string) ($row['legal_hold_placed_at'] ?? ''),
            'legalHoldReleasedAt' => (string) ($row['legal_hold_released_at'] ?? ''),
            'dispositionReason' => (string) ($row['disposition_reason'] ?? ''),
            'dispositionedAt' => (string) ($row['dispositioned_at'] ?? ''),
            'confidentiality' => (string) ($row['confidentiality_level'] ?? ''),
            'owner' => (string) ($row['owner_name'] ?? ''),
            'department' => (string) ($row['department_name'] ?? ''),
            'schedule' => [
                'id' => (int) ($row['retention_schedule_id'] ?? 0),
                'code' => (string) ($row['schedule_code'] ?? ''),
                'name' => (string) ($row['schedule_name'] ?? 'Unassigned'),
                'trigger' => (string) ($row['retention_trigger'] ?? ''),
                'periodValue' => (int) ($row['retention_period_value'] ?? 0),
                'periodUnit' => (string) ($row['retention_period_unit'] ?? ''),
                'dispositionAction' => (string) ($row['disposition_action'] ?? ''),
                'legalBasis' => (string) ($row['legal_basis'] ?? ''),
            ],
            'allowedActions' => $this->allowedActions($status, $hold, $dueState),
            'createdAt' => (string) ($row['created_at'] ?? ''),
            'updatedAt' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    private function allowedActions(string $recordStatus, string $legalHoldStatus, string $dueState): array
    {
        $status = strtoupper($recordStatus);
        $hold = strtoupper($legalHoldStatus);
        if (in_array($status, ['ARCHIVED', 'DISPOSED'], true)) {
            return ['view'];
        }
        if ($hold === 'ACTIVE' || $status === 'ON_HOLD') {
            return ['view', 'release-hold'];
        }
        $actions = ['view', 'assign', 'archive', 'place-hold'];
        if ($dueState !== 'PERMANENT') {
            $actions[] = 'extend';
            $actions[] = 'dispose';
        }
        return $actions;
    }

    private function dueState(array $row): string
    {
        $status = strtoupper((string) ($row['record_status'] ?? 'ACTIVE'));
        if (in_array($status, ['ARCHIVED', 'DISPOSED'], true)) {
            return $status;
        }
        if (strtoupper((string) ($row['legal_hold_status'] ?? 'NONE')) === 'ACTIVE' || $status === 'ON_HOLD') {
            return 'ON_HOLD';
        }
        if (self::isPermanentSchedule($row)) {
            return 'PERMANENT';
        }
        $date = (string) ($row['scheduled_disposition_date'] ?? '');
        if ($date === '') {
            return 'ACTIVE';
        }
        $today = new DateTimeImmutable('today');
        $due = new DateTimeImmutable($date);
        if ($due < $today) {
            return 'OVERDUE';
        }
        if ($due <= $today->modify('+' . self::DUE_REVIEW_DAYS . ' days')) {
            return 'DUE_FOR_REVIEW';
        }
        return 'ACTIVE';
    }

    private function summary(): array
    {
        $rows = $this->rows($this->baseSelect($this->selectColumns()) . ' WHERE r.deleted_at IS NULL GROUP BY r.record_id');
        $summary = ['active' => 0, 'due' => 0, 'hold' => 0, 'archived' => 0, 'disposed' => 0];
        foreach ($rows as $row) {
            $state = $this->dueState($row);
            if ($state === 'ON_HOLD') {
                $summary['hold']++;
            } elseif (in_array($state, ['DUE_FOR_REVIEW', 'OVERDUE'], true)) {
                $summary['due']++;
            } elseif ($state === 'ARCHIVED') {
                $summary['archived']++;
            } elseif ($state === 'DISPOSED') {
                $summary['disposed']++;
            } else {
                $summary['active']++;
            }
        }
        return $summary;
    }

    private function validateSchedule(array $data): array
    {
        $errors = [];
        $clean = [
            'code' => strtoupper($this->text($data['schedule_code'] ?? '', 50)),
            'name' => $this->text($data['schedule_name'] ?? '', 150),
            'category' => $this->text($data['record_category'] ?? '', 100),
            'trigger' => strtoupper($this->text($data['retention_trigger'] ?? 'CREATION_DATE', 50)),
            'period_value' => max(0, (int) ($data['retention_period_value'] ?? 0)),
            'period_unit' => strtoupper($this->text($data['retention_period_unit'] ?? 'YEARS', 30)),
            'action' => strtoupper($this->text($data['disposition_action'] ?? 'ARCHIVE', 50)),
            'legal_basis' => $this->text($data['legal_basis'] ?? '', 1000),
            'description' => $this->text($data['description'] ?? '', 2000),
            'status' => strtoupper($this->text($data['status'] ?? 'ACTIVE', 30)),
            'effective_date' => $this->date($data['effective_date'] ?? null) ?? date('Y-m-d'),
        ];
        if ($clean['code'] === '') $errors['schedule_code'] = 'Enter a schedule code.';
        if ($clean['name'] === '') $errors['schedule_name'] = 'Enter a schedule name.';
        if ($clean['category'] === '') $errors['record_category'] = 'Enter a record category.';
        if (!in_array($clean['period_unit'], ['DAYS', 'MONTHS', 'YEARS', 'PERMANENT'], true)) $errors['retention_period_unit'] = 'Choose a valid retention period unit.';
        if (!in_array($clean['action'], ['ARCHIVE', 'DISPOSE', 'REVIEW', 'PERMANENT'], true)) $errors['disposition_action'] = 'Choose a valid disposition action.';
        if (!in_array($clean['status'], ['ACTIVE', 'INACTIVE'], true)) $errors['status'] = 'Choose a valid status.';
        if ($errors) {
            throw new InvalidArgumentException(json_encode($errors, JSON_THROW_ON_ERROR));
        }
        return $clean;
    }

    private function isPermanent(array $schedule): bool
    {
        return self::isPermanentSchedule($schedule);
    }

    private static function dueStateSql(string $state): string
    {
        $terminal = "UPPER(COALESCE(r.record_status,'ACTIVE'))";
        $hold = "UPPER(COALESCE(r.legal_hold_status,'NONE'))";
        $permanent = "(UPPER(COALESCE(rs.retention_period_unit,'')) = 'PERMANENT' OR UPPER(COALESCE(rs.disposition_action,'')) = 'PERMANENT')";
        $reviewCutoff = 'DATE_ADD(CURRENT_DATE(), INTERVAL ' . self::DUE_REVIEW_DAYS . ' DAY)';

        return match ($state) {
            'ARCHIVED' => "$terminal = 'ARCHIVED'",
            'DISPOSED' => "$terminal = 'DISPOSED'",
            'ON_HOLD' => "$terminal NOT IN ('ARCHIVED','DISPOSED') AND ($hold = 'ACTIVE' OR $terminal = 'ON_HOLD')",
            'PERMANENT' => "$terminal NOT IN ('ARCHIVED','DISPOSED') AND $hold <> 'ACTIVE' AND $terminal <> 'ON_HOLD' AND $permanent",
            'OVERDUE' => "$terminal NOT IN ('ARCHIVED','DISPOSED') AND $hold <> 'ACTIVE' AND $terminal <> 'ON_HOLD' AND NOT $permanent AND r.scheduled_disposition_date IS NOT NULL AND r.scheduled_disposition_date < CURRENT_DATE()",
            'DUE_FOR_REVIEW' => "$terminal NOT IN ('ARCHIVED','DISPOSED') AND $hold <> 'ACTIVE' AND $terminal <> 'ON_HOLD' AND NOT $permanent AND r.scheduled_disposition_date IS NOT NULL AND r.scheduled_disposition_date BETWEEN CURRENT_DATE() AND $reviewCutoff",
            default => "$terminal NOT IN ('ARCHIVED','DISPOSED') AND $hold <> 'ACTIVE' AND $terminal <> 'ON_HOLD' AND NOT $permanent AND (r.scheduled_disposition_date IS NULL OR r.scheduled_disposition_date > $reviewCutoff)",
        };
    }

    private function schedule(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM retention_schedule WHERE retention_schedule_id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    private function history(int $id): array
    {
        return array_map(fn (array $row): array => [
            'title' => (string) $row['event_title'],
            'description' => (string) ($row['event_description'] ?? ''),
            'type' => (string) $row['event_type'],
            'actor' => (string) ($row['actor_name'] ?? 'System'),
            'createdAt' => (string) $row['occurred_at'],
        ], $this->rows("SELECT ae.*, er.full_name actor_name FROM activity_event ae LEFT JOIN user_account ua ON ua.user_account_id = ae.actor_user_id LEFT JOIN employee_reference er ON er.employee_reference_id = ua.employee_reference_id WHERE ae.module_code = 'retention' AND ae.entity_type = 'record' AND ae.entity_id = :id ORDER BY ae.occurred_at DESC LIMIT 30", ['id' => $id]));
    }

    private function requiredReason(array $data): string
    {
        $reason = $this->text($data['reason'] ?? $data['remarks'] ?? '', 1000);
        if ($reason === '') {
            throw new InvalidArgumentException(json_encode(['reason' => 'Enter a reason for this action.'], JSON_THROW_ON_ERROR));
        }
        return $reason;
    }

    private function date(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        return (new DateTimeImmutable((string) $value))->format('Y-m-d');
    }

    private function text(mixed $value, int $max): string
    {
        return mb_substr(trim((string) $value), 0, $max);
    }

    private function rows(string $sql, array $params = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll();
    }

    private function logActivity(string $eventType, string $title, int $recordId, int $userId, array $metadata = []): void
    {
        try {
            $statement = $this->pdo->prepare("INSERT INTO activity_event (event_uuid, module_code, entity_type, entity_id, event_type, event_title, event_description, actor_user_id, visibility_scope, metadata_json) VALUES (:uuid, 'retention', 'record', :id, :event_type, :title, :description, :user_id, 'INTERNAL', :metadata)");
            $statement->execute([
                'uuid' => self::uuidV4(),
                'id' => $recordId,
                'event_type' => $eventType,
                'title' => $title,
                'description' => $title,
                'user_id' => $userId,
                'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            ]);
        } catch (Throwable $exception) {
            error_log('Retention activity logging failed: ' . $exception::class);
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
