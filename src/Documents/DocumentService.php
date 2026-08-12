<?php

declare(strict_types=1);

final class DocumentPolicy
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

    public static function requireAnyPermission(array $user, array $permissions): void
    {
        foreach ($permissions as $permission) {
            if (self::hasPermission($user, $permission)) {
                return;
            }
        }

        jsonResponse(false, 'You do not have permission to perform this action.', [], 403);
    }
}

final class DocumentService
{
    private const MAX_FILE_SIZE = 10485760;
    private const ALLOWED_EXTENSIONS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'png', 'jpg', 'jpeg'];
    private const ALLOWED_MIME = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'image/png',
        'image/jpeg',
    ];

    private const CATEGORY_MAP = [
        'FACILITY_RESERVATION' => ['DOC-RES', 'Facility & Reservation'],
        'VISITOR' => ['DOC-VIS', 'Visitor'],
        'ADMINISTRATIVE' => ['DOC-ADM', 'Administrative'],
        'LEGAL' => ['DOC-LEGAL', 'Legal'],
        'CONTRACT' => ['DOC-CON', 'Contract'],
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function list(array $query): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($query['per_page'] ?? 10)));
        $sortMap = [
            'document_number' => 'd.document_number',
            'documentNo' => 'd.document_number',
            'confidentiality' => 'd.confidentiality_level',
            'title' => 'd.document_title',
            'category' => 'dc.category_name',
            'status' => 'd.document_status',
            'updated_at' => 'd.updated_at',
            'created_at' => 'd.created_at',
        ];
        $sort = $sortMap[(string) ($query['sort'] ?? '')] ?? 'd.updated_at';
        $direction = strtolower((string) ($query['direction'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
        [$where, $params] = $this->filters($query);

        $count = $this->pdo->prepare($this->baseSelect('COUNT(DISTINCT d.document_id)') . $where);
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $sql = $this->baseSelect($this->selectColumns()) . $where . " GROUP BY d.document_id ORDER BY $sort $direction LIMIT :limit OFFSET :offset";
        $statement = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->bindValue('limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue('offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $statement->execute();

        return [
            'items' => array_map(fn (array $row): array => $this->shape($row), $statement->fetchAll()),
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
        $statement = $this->pdo->prepare($this->baseSelect($this->selectColumns()) . ' WHERE d.deleted_at IS NULL AND d.document_id = :id GROUP BY d.document_id LIMIT 1');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }

        $item = $this->shape($row);
        $item['versions'] = array_map(fn (array $version): array => $this->shapeVersion($version), $this->versions($id));
        $item['currentVersion'] = $this->currentVersion($id);
        $item['retention'] = $this->retentionForDocument($id);
        return $item;
    }

    public function options(): array
    {
        return [
            'categories' => $this->rows("SELECT document_category_id id, category_code code, category_name name, default_confidentiality_level default_confidentiality FROM document_category WHERE status='ACTIVE' AND category_code IN ('DOC-RES','DOC-VIS','DOC-ADM','DOC-LEGAL','DOC-CON') ORDER BY FIELD(category_code,'DOC-RES','DOC-VIS','DOC-ADM','DOC-LEGAL','DOC-CON'), category_name"),
            'confidentiality_levels' => ['PUBLIC', 'INTERNAL', 'CONFIDENTIAL', 'RESTRICTED'],
            'statuses' => ['ACTIVE', 'ARCHIVED'],
            'related_modules' => [
                ['code' => 'GENERAL_ADMINISTRATIVE', 'name' => 'General Administrative'],
                ['code' => 'FACILITY_RESERVATION', 'name' => 'Facilities Reservation'],
                ['code' => 'VISITOR_MANAGEMENT', 'name' => 'Visitor Management'],
                ['code' => 'LEGAL_MANAGEMENT', 'name' => 'Legal Management'],
                ['code' => 'CONTRACT_MANAGEMENT', 'name' => 'Contract Management'],
            ],
        ];
    }

    public function create(array $data, array $file, array $user): array
    {
        $clean = $this->validateMetadata($data, true);
        $upload = $this->validateUpload($file);
        $stored = null;

        $this->pdo->beginTransaction();
        try {
            $documentNumber = $this->nextDocumentNumber();
            $statement = $this->pdo->prepare('INSERT INTO document (document_number, document_category_id, document_title, document_description, document_status, confidentiality_level, current_version_number, document_date, uploaded_by_user_id, owner_employee_reference_id, created_at, updated_at) VALUES (:number, :category, :title, :description, :status, :confidentiality, 1, :document_date, :uploaded_by, :owner, NOW(), NOW())');
            $statement->execute([
                'number' => $documentNumber,
                'category' => $clean['document_category_id'],
                'title' => $clean['title'],
                'description' => $clean['description'],
                'status' => $clean['status'],
                'confidentiality' => $clean['confidentiality_level'],
                'document_date' => $clean['document_date'],
                'uploaded_by' => (int) $user['id'],
                'owner' => (int) ($user['employee_id'] ?? 0) ?: null,
            ]);
            $documentId = (int) $this->pdo->lastInsertId();
            $stored = $this->storeUploadedFile($upload, $documentId, 1);
            $this->insertVersion($documentId, 1, $stored, $upload, $clean['change_summary'], (int) $user['id']);
            $this->createLinkedRecord($documentId, $documentNumber, $clean, $user);
            $this->logActivity('DOCUMENT_CREATED', 'Document Created', $documentId, (int) $user['id']);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            if ($stored !== null && is_file($stored['absolute_path'])) {
                @unlink($stored['absolute_path']);
            }
            throw $exception;
        }

        return $this->show($documentId) ?? [];
    }

    public function uploadVersion(int $documentId, array $data, array $file, array $user): ?array
    {
        if ($this->show($documentId) === null) {
            return null;
        }

        $upload = $this->validateUpload($file);
        $summary = $this->text($data['change_summary'] ?? '', 1000);
        $stored = null;
        $this->pdo->beginTransaction();
        try {
            $next = (int) $this->scalar('SELECT COALESCE(MAX(version_number),0) + 1 FROM document_version WHERE document_id = :id AND deleted_at IS NULL', ['id' => $documentId]);
            $stored = $this->storeUploadedFile($upload, $documentId, $next);
            $this->pdo->prepare('UPDATE document_version SET is_current = FALSE WHERE document_id = :id')->execute(['id' => $documentId]);
            $this->insertVersion($documentId, $next, $stored, $upload, $summary, (int) $user['id']);
            $this->pdo->prepare('UPDATE document SET current_version_number = :version, updated_at = NOW() WHERE document_id = :id')->execute(['version' => $next, 'id' => $documentId]);
            $this->logActivity('DOCUMENT_VERSION_UPLOADED', 'New Version Uploaded', $documentId, (int) $user['id']);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            if ($stored !== null && is_file($stored['absolute_path'])) {
                @unlink($stored['absolute_path']);
            }
            throw $exception;
        }

        return $this->show($documentId);
    }

    public function archive(int $documentId, array $user): ?array
    {
        if ($this->show($documentId) === null) {
            return null;
        }

        $statement = $this->pdo->prepare("UPDATE document SET document_status = 'ARCHIVED', updated_at = NOW() WHERE document_id = :id AND deleted_at IS NULL");
        $statement->execute(['id' => $documentId]);
        $this->logActivity('DOCUMENT_ARCHIVED', 'Document Archived', $documentId, (int) $user['id']);

        return $this->show($documentId);
    }

    public function downloadVersion(int $documentId, ?int $versionId): ?array
    {
        $sql = 'SELECT dv.*, d.document_number, d.document_title FROM document_version dv INNER JOIN document d ON d.document_id = dv.document_id WHERE d.deleted_at IS NULL AND dv.deleted_at IS NULL AND dv.document_id = :document_id';
        $params = ['document_id' => $documentId];
        if ($versionId !== null) {
            $sql .= ' AND dv.document_version_id = :version_id';
            $params['version_id'] = $versionId;
        } else {
            $sql .= ' AND dv.is_current = TRUE';
        }
        $sql .= ' LIMIT 1';
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }
        $absolute = $this->storageRoot() . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $row['storage_path']);
        if (!is_file($absolute)) {
            return null;
        }

        return [
            'absolute_path' => $absolute,
            'download_name' => (string) $row['file_name'],
            'mime_type' => (string) ($row['mime_type'] ?: 'application/octet-stream'),
            'file_size' => (int) ($row['file_size'] ?? filesize($absolute)),
        ];
    }

    private function validateMetadata(array $data, bool $creating): array
    {
        $errors = [];
        $title = $this->text($data['title'] ?? '', 255);
        $description = $this->text($data['description'] ?? '', 4000);
        $categoryId = (int) ($data['document_category_id'] ?? 0);
        $confidentiality = strtoupper($this->text($data['confidentiality_level'] ?? 'INTERNAL', 30));
        $status = strtoupper($this->text($data['status'] ?? 'ACTIVE', 30));
        $documentDate = $this->date($data['document_date'] ?? null);
        $changeSummary = $this->text($data['change_summary'] ?? ($creating ? 'Initial upload' : ''), 1000);
        $relatedModule = strtoupper($this->text($data['related_module'] ?? 'GENERAL_ADMINISTRATIVE', 50));
        $relatedReference = $this->text($data['related_reference'] ?? '', 100);

        if ($title === '') {
            $errors['title'] = 'Enter a document title.';
        }
        if (!$this->categoryExists($categoryId)) {
            $errors['document_category_id'] = 'Choose a valid document category.';
        }
        if (!in_array($confidentiality, ['PUBLIC', 'INTERNAL', 'CONFIDENTIAL', 'RESTRICTED'], true)) {
            $errors['confidentiality_level'] = 'Choose a valid confidentiality level.';
        }
        if (!in_array($status, ['ACTIVE', 'ARCHIVED'], true)) {
            $errors['status'] = 'Choose a valid document status.';
        }
        if (!in_array($relatedModule, ['GENERAL_ADMINISTRATIVE', 'FACILITY_RESERVATION', 'VISITOR_MANAGEMENT', 'LEGAL_MANAGEMENT', 'CONTRACT_MANAGEMENT'], true)) {
            $errors['related_module'] = 'Choose a valid related module.';
        }

        if ($errors) {
            throw new InvalidArgumentException(json_encode($errors, JSON_THROW_ON_ERROR));
        }

        return compact('title', 'description', 'categoryId', 'confidentiality', 'status', 'documentDate', 'changeSummary', 'relatedModule', 'relatedReference') + [
            'document_category_id' => $categoryId,
            'confidentiality_level' => $confidentiality,
            'document_date' => $documentDate,
            'change_summary' => $changeSummary,
            'related_module' => $relatedModule,
            'related_reference' => $relatedReference,
        ];
    }

    private function validateUpload(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException(json_encode(['file' => 'Choose a file to upload.'], JSON_THROW_ON_ERROR));
        }
        if ((int) ($file['size'] ?? 0) <= 0 || (int) $file['size'] > self::MAX_FILE_SIZE) {
            throw new InvalidArgumentException(json_encode(['file' => 'File exceeds the allowed size.'], JSON_THROW_ON_ERROR));
        }
        $original = basename((string) ($file['name'] ?? 'document'));
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new InvalidArgumentException(json_encode(['file' => 'This file type is not supported.'], JSON_THROW_ON_ERROR));
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']) ?: '';
        if (!in_array($mime, self::ALLOWED_MIME, true)) {
            throw new InvalidArgumentException(json_encode(['file' => 'This file type is not supported.'], JSON_THROW_ON_ERROR));
        }

        return [
            'tmp_name' => (string) $file['tmp_name'],
            'original_name' => $this->safeFileName($original),
            'extension' => $extension,
            'mime_type' => $mime,
            'size' => (int) $file['size'],
        ];
    }

    private function storeUploadedFile(array $upload, int $documentId, int $version): array
    {
        $relativeDir = 'documents/' . $documentId . '/v' . $version;
        $absoluteDir = $this->storageRoot() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDir);
        if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0775, true) && !is_dir($absoluteDir)) {
            throw new RuntimeException('Unable to prepare document storage.');
        }
        $storedName = bin2hex(random_bytes(16)) . '.' . $upload['extension'];
        $absolutePath = $absoluteDir . DIRECTORY_SEPARATOR . $storedName;
        if (!move_uploaded_file($upload['tmp_name'], $absolutePath)) {
            throw new RuntimeException('Unable to store uploaded document.');
        }

        return [
            'relative_path' => $relativeDir . '/' . $storedName,
            'absolute_path' => $absolutePath,
            'stored_name' => $storedName,
            'hash' => hash_file('sha256', $absolutePath) ?: null,
        ];
    }

    private function insertVersion(int $documentId, int $version, array $stored, array $upload, string $summary, int $userId): void
    {
        $statement = $this->pdo->prepare('INSERT INTO document_version (document_id, version_number, file_name, file_extension, mime_type, file_size, storage_path, file_hash, change_summary, uploaded_by_user_id, uploaded_at, is_current) VALUES (:document_id, :version, :file_name, :extension, :mime, :size, :path, :hash, :summary, :user_id, NOW(), TRUE)');
        $statement->execute([
            'document_id' => $documentId,
            'version' => $version,
            'file_name' => $upload['original_name'],
            'extension' => $upload['extension'],
            'mime' => $upload['mime_type'],
            'size' => $upload['size'],
            'path' => $stored['relative_path'],
            'hash' => $stored['hash'],
            'summary' => $summary,
            'user_id' => $userId,
        ]);
    }

    private function createLinkedRecord(int $documentId, string $documentNumber, array $clean, array $user): void
    {
        $scheduleId = $this->retentionScheduleFor($clean['related_module']);
        if ($scheduleId === null || empty($user['employee_id'])) {
            return;
        }
        $recordNumber = $this->nextRecordNumber();
        $statement = $this->pdo->prepare('INSERT INTO record (record_number, record_title, record_description, record_type, retention_schedule_id, originating_department_reference_id, record_owner_employee_reference_id, source_module, source_entity_type, source_entity_id, record_date, retention_start_date, scheduled_disposition_date, record_status, confidentiality_level, created_by_user_id, updated_by_user_id, created_at, updated_at) VALUES (:record_number, :title, :description, :type, :schedule_id, :department_id, :owner_id, :source_module, :source_entity_type, NULL, :record_date, :retention_start, NULL, :status, :confidentiality, :created_by, :updated_by, NOW(), NOW())');
        $recordType = ucwords(strtolower(str_replace('_', ' ', $clean['related_module'])));
        $statement->execute([
            'record_number' => $recordNumber,
            'title' => $clean['title'],
            'description' => $clean['description'],
            'type' => $recordType,
            'schedule_id' => $scheduleId,
            'department_id' => $user['department']['id'] ?? null,
            'owner_id' => (int) $user['employee_id'],
            'source_module' => strtolower($clean['related_module']),
            'source_entity_type' => $clean['related_reference'] ?: 'DOCUMENT',
            'record_date' => $clean['document_date'] ?? date('Y-m-d'),
            'retention_start' => $clean['document_date'] ?? date('Y-m-d'),
            'status' => $clean['status'],
            'confidentiality' => $clean['confidentiality_level'],
            'created_by' => (int) $user['id'],
            'updated_by' => (int) $user['id'],
        ]);
        $recordId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT IGNORE INTO record_document (record_id, document_id, is_primary_document, added_by_user_id) VALUES (:record_id, :document_id, TRUE, :user_id)')->execute([
            'record_id' => $recordId,
            'document_id' => $documentId,
            'user_id' => (int) $user['id'],
        ]);
    }

    private function nextDocumentNumber(): string
    {
        $year = date('Y');
        $statement = $this->pdo->prepare('SELECT document_number FROM document WHERE document_number LIKE :prefix ORDER BY document_number DESC LIMIT 1');
        $statement->execute(['prefix' => "DOC-$year-%"]);
        $last = (string) ($statement->fetchColumn() ?: '');
        $next = $last !== '' ? ((int) substr($last, -4)) + 1 : 1;
        return sprintf('DOC-%s-%04d', $year, $next);
    }

    private function nextRecordNumber(): string
    {
        $year = date('Y');
        $statement = $this->pdo->prepare('SELECT record_number FROM record WHERE record_number LIKE :prefix ORDER BY record_number DESC LIMIT 1');
        $statement->execute(['prefix' => "REC-$year-%"]);
        $last = (string) ($statement->fetchColumn() ?: '');
        $next = $last !== '' ? ((int) substr($last, -4)) + 1 : 1;
        return sprintf('REC-%s-%04d', $year, $next);
    }

    private function retentionScheduleFor(string $relatedModule): ?int
    {
        $code = match ($relatedModule) {
            'FACILITY_RESERVATION' => 'RET-ADM-005',
            'VISITOR_MANAGEMENT' => 'RET-ADM-005',
            'LEGAL_MANAGEMENT' => 'RET-CON-010',
            'CONTRACT_MANAGEMENT' => 'RET-CON-010',
            default => 'RET-ADM-005',
        };
        $id = $this->scalar("SELECT retention_schedule_id FROM retention_schedule WHERE schedule_code = :code AND status = 'ACTIVE' LIMIT 1", ['code' => $code]);
        if ($id !== false && $id !== null) {
            return (int) $id;
        }
        $fallback = $this->scalar("SELECT retention_schedule_id FROM retention_schedule WHERE status = 'ACTIVE' ORDER BY retention_schedule_id LIMIT 1");
        return $fallback === false || $fallback === null ? null : (int) $fallback;
    }

    private function baseSelect(string $columns): string
    {
        return "SELECT $columns FROM document d INNER JOIN document_category dc ON dc.document_category_id = d.document_category_id LEFT JOIN user_account uu ON uu.user_account_id = d.uploaded_by_user_id LEFT JOIN employee_reference uploader ON uploader.employee_reference_id = uu.employee_reference_id LEFT JOIN employee_reference owner ON owner.employee_reference_id = d.owner_employee_reference_id LEFT JOIN record_document rd ON rd.document_id = d.document_id LEFT JOIN record rec ON rec.record_id = rd.record_id AND rec.deleted_at IS NULL ";
    }

    private function selectColumns(): string
    {
        return "d.*, dc.category_code, dc.category_name, uploader.full_name uploaded_by_name, owner.full_name owner_name, GROUP_CONCAT(DISTINCT CONCAT(rec.source_module, '|', COALESCE(rec.source_entity_type,''), '|', rec.record_number) ORDER BY rec.record_number SEPARATOR ';;') related_records";
    }

    private function filters(array $query): array
    {
        $where = ['d.deleted_at IS NULL'];
        $params = [];
        if (($query['search'] ?? '') !== '') {
            $where[] = '(d.document_number LIKE :search OR d.document_title LIKE :search OR d.document_description LIKE :search)';
            $params['search'] = '%' . trim((string) $query['search']) . '%';
        }
        foreach (['document_category_id' => 'd.document_category_id', 'confidentiality_level' => 'd.confidentiality_level', 'document_status' => 'd.document_status'] as $key => $column) {
            if (($query[$key] ?? '') !== '' && ($query[$key] ?? 'all') !== 'all') {
                $where[] = "$column = :$key";
                $params[$key] = $query[$key];
            }
        }

        return [' WHERE ' . implode(' AND ', $where), $params];
    }

    private function summary(): array
    {
        return [
            'active' => (int) $this->scalar("SELECT COUNT(*) FROM document WHERE deleted_at IS NULL AND document_status = 'ACTIVE'"),
            'archived' => (int) $this->scalar("SELECT COUNT(*) FROM document WHERE deleted_at IS NULL AND document_status = 'ARCHIVED'"),
            'recent' => (int) $this->scalar('SELECT COUNT(*) FROM document WHERE deleted_at IS NULL AND updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)'),
        ];
    }

    private function shape(array $row): array
    {
        $related = $this->relatedLabel((string) ($row['related_records'] ?? ''));
        return [
            'id' => (int) $row['document_id'],
            'documentNo' => (string) $row['document_number'],
            'title' => (string) $row['document_title'],
            'description' => (string) ($row['document_description'] ?? ''),
            'category' => (string) $row['category_name'],
            'categoryCode' => (string) $row['category_code'],
            'relatedTo' => $related,
            'version' => 'v' . (int) $row['current_version_number'],
            'currentVersionNumber' => (int) $row['current_version_number'],
            'confidentiality' => (string) $row['confidentiality_level'],
            'status' => (string) $row['document_status'],
            'documentDate' => (string) ($row['document_date'] ?? ''),
            'createdBy' => (string) ($row['uploaded_by_name'] ?? 'System'),
            'owner' => (string) ($row['owner_name'] ?? ''),
            'createdAt' => (string) $row['created_at'],
            'updatedAt' => (string) $row['updated_at'],
        ];
    }

    private function shapeVersion(array $row): array
    {
        return [
            'id' => (int) $row['document_version_id'],
            'version' => 'v' . (int) $row['version_number'],
            'versionNumber' => (int) $row['version_number'],
            'fileName' => (string) $row['file_name'],
            'mimeType' => (string) ($row['mime_type'] ?? ''),
            'fileSize' => (int) ($row['file_size'] ?? 0),
            'changeSummary' => (string) ($row['change_summary'] ?? ''),
            'uploadedBy' => (string) ($row['uploaded_by_name'] ?? 'System'),
            'uploadedAt' => (string) $row['uploaded_at'],
            'isCurrent' => (bool) $row['is_current'],
        ];
    }

    private function versions(int $documentId): array
    {
        return $this->rows('SELECT dv.*, e.full_name uploaded_by_name FROM document_version dv LEFT JOIN user_account u ON u.user_account_id = dv.uploaded_by_user_id LEFT JOIN employee_reference e ON e.employee_reference_id = u.employee_reference_id WHERE dv.document_id = :id AND dv.deleted_at IS NULL ORDER BY dv.version_number DESC', ['id' => $documentId]);
    }

    private function currentVersion(int $documentId): ?array
    {
        $statement = $this->pdo->prepare('SELECT dv.*, e.full_name uploaded_by_name FROM document_version dv LEFT JOIN user_account u ON u.user_account_id = dv.uploaded_by_user_id LEFT JOIN employee_reference e ON e.employee_reference_id = u.employee_reference_id WHERE dv.document_id = :id AND dv.deleted_at IS NULL AND dv.is_current = TRUE LIMIT 1');
        $statement->execute(['id' => $documentId]);
        $row = $statement->fetch();
        return is_array($row) ? $this->shapeVersion($row) : null;
    }

    private function retentionForDocument(int $documentId): ?array
    {
        $statement = $this->pdo->prepare('SELECT r.record_id, r.record_number, r.record_status, r.legal_hold_status, r.retention_start_date, r.scheduled_disposition_date, rs.schedule_code, rs.schedule_name, rs.record_category, rs.retention_period_value, rs.retention_period_unit, rs.disposition_action FROM record_document rd INNER JOIN record r ON r.record_id = rd.record_id LEFT JOIN retention_schedule rs ON rs.retention_schedule_id = r.retention_schedule_id WHERE rd.document_id = :id AND r.deleted_at IS NULL ORDER BY rd.is_primary_document DESC, r.record_id LIMIT 1');
        $statement->execute(['id' => $documentId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }
        return [
            'recordId' => (int) $row['record_id'],
            'recordNo' => (string) $row['record_number'],
            'status' => (string) ($row['record_status'] ?? ''),
            'legalHoldStatus' => (string) ($row['legal_hold_status'] ?? 'NONE'),
            'retentionStartDate' => (string) ($row['retention_start_date'] ?? ''),
            'scheduledDispositionDate' => (string) ($row['scheduled_disposition_date'] ?? ''),
            'scheduleCode' => (string) ($row['schedule_code'] ?? ''),
            'scheduleName' => (string) ($row['schedule_name'] ?? ''),
            'category' => (string) ($row['record_category'] ?? ''),
            'period' => trim((string) ($row['retention_period_value'] ?? '') . ' ' . (string) ($row['retention_period_unit'] ?? '')),
            'dispositionAction' => (string) ($row['disposition_action'] ?? ''),
        ];
    }

    private function relatedLabel(string $packed): string
    {
        if ($packed === '') {
            return 'General Administrative';
        }
        $first = explode(';;', $packed)[0] ?? '';
        [$module, $entity, $record] = array_pad(explode('|', $first), 3, '');
        $moduleLabel = match (strtolower($module ?: '')) {
            'facility_reservation' => 'Room Reservation',
            'visitor_management' => 'Visitor Record',
            'legal_management' => 'Legal Matter',
            'contract_management' => 'Contract',
            default => 'General Administrative',
        };
        $reference = trim((string) $entity);
        if ($reference === '' || strtoupper($reference) === 'DOCUMENT' || str_starts_with(strtoupper($reference), 'REC-')) {
            return $moduleLabel;
        }

        return trim($moduleLabel . ' ' . $reference);
    }

    private function categoryExists(int $id): bool
    {
        return (bool) $this->scalar("SELECT COUNT(*) FROM document_category WHERE document_category_id = :id AND status = 'ACTIVE'", ['id' => $id]);
    }

    private function safeFileName(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $name) ?: 'document';
        $name = preg_replace('/\s+/', ' ', $name) ?: 'document';
        return substr(trim($name, " .\t\n\r\0\x0B"), 0, 180) ?: 'document';
    }

    private function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (new DateTimeImmutable((string) $value))->format('Y-m-d');
    }

    private function text(mixed $value, int $max): string
    {
        return mb_substr(trim((string) $value), 0, $max);
    }

    private function storageRoot(): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage';
    }

    private function scalar(string $sql, array $params = []): mixed
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement->fetchColumn();
    }

    private function rows(string $sql, array $params = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll();
    }

    private function logActivity(string $eventType, string $title, int $documentId, int $userId): void
    {
        try {
            $statement = $this->pdo->prepare("INSERT INTO activity_event (event_uuid, module_code, entity_type, entity_id, event_type, event_title, event_description, actor_user_id, visibility_scope, metadata_json) VALUES (:uuid, 'documents', 'document', :id, :event_type, :title, :description, :user_id, 'INTERNAL', :metadata)");
            $statement->execute([
                'uuid' => self::uuidV4(),
                'id' => $documentId,
                'event_type' => $eventType,
                'title' => $title,
                'description' => $title,
                'user_id' => $userId,
                'metadata' => json_encode(['document_id' => $documentId], JSON_THROW_ON_ERROR),
            ]);
        } catch (Throwable $exception) {
            error_log('Document activity logging failed: ' . $exception::class);
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
