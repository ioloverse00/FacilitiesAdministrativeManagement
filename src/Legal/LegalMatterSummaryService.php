<?php

declare(strict_types=1);

final class LegalMatterSummaryService
{
    private const READABLE_MIME = ['application/pdf', 'image/jpeg', 'image/png'];
    private const MAX_SOURCE_BYTES = 12_000_000;
    private const STATUSES = ['NOT_REQUESTED', 'PENDING', 'READY', 'FAILED', 'NO_READABLE_SOURCE', 'STALE'];

    public function __construct(private readonly PDO $pdo, private readonly ?DocumentService $documentService = null)
    {
    }

    public function markStaleIfReady(int $matterId, array $user): void
    {
        $matter = $this->matter($matterId);
        if ($matter === null || !in_array((string) ($matter['ai_summary_status'] ?? ''), ['READY', 'FAILED', 'NO_READABLE_SOURCE'], true)) {
            return;
        }
        $this->assertMatterNotClosed($matter);

        $sources = $this->readableSources($matter);
        $fingerprint = $sources ? $this->fingerprint($sources) : null;
        if (($matter['ai_summary_status'] ?? '') === 'READY' && $fingerprint !== null && $fingerprint === (string) ($matter['ai_summary_source_fingerprint'] ?? '')) {
            return;
        }

        $this->updateAiFields($matterId, [
            'ai_summary_status' => 'STALE',
            'ai_summary_source_fingerprint' => $fingerprint,
        ]);
        $this->history($matterId, 'LEGAL_AI_SUMMARY_STALE', 'Supporting documents changed. AI matter summary marked stale.', ['source_count' => count($sources)], $user);
    }

    private function matter(int $matterId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM legal_matter WHERE deleted_at IS NULL AND legal_matter_id = :id LIMIT 1');
        $statement->execute(['id' => $matterId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    private function assertMatterNotClosed(array $matter): void
    {
        if (($matter['status'] ?? '') === 'CLOSED') {
            throw new InvalidArgumentException(json_encode(['status' => 'This legal matter is closed and is read-only.'], JSON_THROW_ON_ERROR));
        }
    }

    private function readableSources(array $matter): array
    {
        $service = $this->documentService ?? new DocumentService($this->pdo);
        $documents = $service->currentRelatedFiles('LEGAL_MANAGEMENT', (string) $matter['matter_number']);
        $selected = [];
        $total = 0;
        foreach ($documents as $document) {
            $path = (string) ($document['absolutePath'] ?? '');
            $mime = (string) ($document['mimeType'] ?? '');
            $size = (int) ($document['fileSize'] ?? 0);
            if (!in_array($mime, self::READABLE_MIME, true) || $path === '' || !is_file($path) || $size <= 0) {
                continue;
            }
            if ($total + $size > self::MAX_SOURCE_BYTES) {
                break;
            }
            $selected[] = $document;
            $total += $size;
        }
        return $selected;
    }

    private function fingerprint(array $sources): string
    {
        $parts = array_map(static fn (array $source): string => implode('|', [
            $source['documentNo'] ?? '',
            $source['versionNumber'] ?? '',
            $source['fileHash'] ?? '',
            $source['fileSize'] ?? '',
        ]), $sources);
        sort($parts);
        return hash('sha256', implode(';;', $parts));
    }

    private function updateAiFields(int $matterId, array $fields): void
    {
        $allowed = array_intersect_key($fields, array_flip([
            'ai_summary_status',
            'ai_summary_source_fingerprint',
        ]));
        if (isset($allowed['ai_summary_status']) && !in_array((string) $allowed['ai_summary_status'], self::STATUSES, true)) {
            throw new InvalidArgumentException('Invalid AI summary status.');
        }
        $sets = [];
        $params = ['id' => $matterId];
        foreach ($allowed as $column => $value) {
            $sets[] = "$column = :$column";
            $params[$column] = $value;
        }
        if (!$sets) {
            return;
        }
        $sets[] = 'updated_at = NOW()';
        $this->pdo->prepare('UPDATE legal_matter SET ' . implode(', ', $sets) . ' WHERE legal_matter_id = :id AND deleted_at IS NULL')->execute($params);
    }

    private function history(int $matterId, string $event, string $description, array $metadata, array $user): void
    {
        $this->pdo->prepare('INSERT INTO legal_matter_history (legal_matter_id, event_type, from_status, to_status, description, metadata_json, actor_user_id, created_at) VALUES (:id, :event, NULL, NULL, :description, :metadata, :user_id, NOW())')->execute([
            'id' => $matterId,
            'event' => $event,
            'description' => $description,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'user_id' => (int) $user['id'],
        ]);
    }
}
