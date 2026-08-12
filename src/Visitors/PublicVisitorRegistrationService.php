<?php

declare(strict_types=1);

final class PublicVisitorRegistrationService
{
    private const PUBLIC_TYPES = ['APPLICANT', 'GUEST', 'VENDOR', 'CONTRACTOR', 'DELIVERY', 'OTHER'];

    public function __construct(private readonly PDO $pdo, private readonly VisitorOtpService $otp) {}

    public function options(): array
    {
        return [
            'visitor_types' => self::PUBLIC_TYPES,
            'departments' => $this->rows("SELECT department_reference_id id, department_code code, department_name name FROM department_reference WHERE status='ACTIVE' ORDER BY department_name"),
            'facility_spaces' => $this->rows("SELECT fs.facility_space_id id, fs.space_code code, fs.space_name name FROM facility_space fs WHERE fs.status='ACTIVE' AND fs.deleted_at IS NULL ORDER BY fs.space_name"),
            'consent_version' => 'visitor-registration-v1',
        ];
    }

    public function start(array $data): array
    {
        $payload = $this->validatedPayload($data);
        if (trim((string) ($data['website'] ?? '')) !== '') {
            throw new DomainException('Registration could not be processed.', 400);
        }
        if ((int) ($data['form_started_at'] ?? 0) > 0 && time() - (int) $data['form_started_at'] < 3) {
            throw new DomainException('Please review the form before submitting.', 429);
        }
        $result = $this->otp->create($payload);
        $this->activity('PUBLIC_REGISTRATION_STARTED', 'Visitor public registration started.', null, null);
        $this->activity('EMAIL_OTP_SENT', 'Visitor registration OTP sent.', null, null);
        return $result + ['masked_email' => $this->maskEmail($payload['email_address'])];
    }

    public function resend(array $data): array
    {
        $uuid = (string) ($data['challenge_id'] ?? '');
        $this->pdo->beginTransaction();
        try {
            $result = $this->otp->resend($uuid);
            $this->pdo->commit();
            $this->activity('EMAIL_OTP_SENT', 'Visitor registration OTP resent.', null, null);
            return $result;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function verify(array $data): array
    {
        $uuid = (string) ($data['challenge_id'] ?? '');
        $code = (string) ($data['otp'] ?? '');
        $this->pdo->beginTransaction();
        try {
            $result = $this->otp->verify($uuid, $code);
            $this->pdo->commit();
            $this->activity('EMAIL_OTP_VERIFIED', 'Visitor registration email verified.', null, null);
            return $result;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function submit(array $data): array
    {
        $uuid = (string) ($data['challenge_id'] ?? '');
        $token = (string) ($data['verification_token'] ?? '');
        if ($token === '') {
            throw new DomainException('Email verification is required before submission.', 409);
        }
        $this->pdo->beginTransaction();
        try {
            $challenge = $this->otp->verifiedChallenge($uuid, $token);
            if (!empty($challenge['submitted_visit_id'])) {
                throw new DomainException('This registration has already been submitted.', 409);
            }
            $payload = json_decode((string) $challenge['payload_json'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                throw new RuntimeException('Invalid registration payload.');
            }
            $this->assertNoActiveVisitForEmail((string) $payload['email_address']);
            $visitorId = $this->createOrUpdateVisitor($payload);
            $reference = $this->nextReference();
            $scheduled = $payload['scheduled_start_at'] ?? null;
            $stmt = $this->pdo->prepare("INSERT INTO visit (visit_number, visitor_id, visitor_type, host_employee_reference_id, destination_department_reference_id, destination_space_id, purpose, visit_description, scheduled_arrival, scheduled_departure, actual_time_in, actual_time_out, visit_status, approval_status, registration_source, applicant_reference, company_or_school, privacy_consent, consented_at, consent_version, remarks, created_by_user_id, updated_by_user_id, created_at, updated_at) VALUES (:ref,:visitor_id,:type,:host,:dept,:space,:purpose,:description,:start,:end,NULL,NULL,'PENDING_REVIEW','PENDING','PUBLIC_PRE_REGISTRATION',:applicant,:company,1,NOW(),:consent_version,NULL,NULL,NULL,NOW(),NOW())");
            $stmt->execute([
                'ref' => $reference,
                'visitor_id' => $visitorId,
                'type' => $payload['visitor_type'],
                'host' => $this->nullableInt($payload['host_employee_reference_id'] ?? null),
                'dept' => $this->nullableInt($payload['destination_department_reference_id'] ?? null),
                'space' => $this->nullableInt($payload['facility_space_id'] ?? null),
                'purpose' => $payload['visit_purpose'],
                'description' => $payload['visit_description'] ?? null,
                'start' => $scheduled ?: date('Y-m-d H:i:s'),
                'end' => $payload['scheduled_end_at'] ?? null,
                'applicant' => $payload['applicant_reference'] ?? null,
                'company' => $payload['company_or_school'] ?? $payload['organization_name'] ?? null,
                'consent_version' => $payload['consent_version'],
            ]);
            $visitId = (int) $this->pdo->lastInsertId();
            [$qrToken, $qrExpiresAt] = $this->issueQrToken($visitId, $payload);
            $this->history($visitId, 'PUBLIC_REGISTRATION_SUBMITTED', 'Public visitor registration submitted.');
            $this->history($visitId, 'VISITOR_PENDING_REVIEW', 'Visitor is pending reception review.');
            $this->activity('PUBLIC_REGISTRATION_SUBMITTED', 'Public visitor registration submitted.', $visitId, $reference);
            $this->activity('VISITOR_PENDING_REVIEW', 'Visitor pending review.', $visitId, $reference);
            $this->pdo->prepare("UPDATE visitor_registration_challenge SET consumed_at=NOW(), submitted_visit_id=:visit_id, status='CONSUMED', updated_at=NOW() WHERE visitor_registration_challenge_id=:challenge_id")->execute(['visit_id' => $visitId, 'challenge_id' => (int) $challenge['visitor_registration_challenge_id']]);
            $this->pdo->commit();
            return [
                'visitor_reference_number' => $reference,
                'visitor_name' => $payload['full_name'],
                'destination' => $this->destinationLabel($payload),
                'scheduled_start_at' => $scheduled,
                'scheduled_end_at' => $payload['scheduled_end_at'] ?? null,
                'status' => 'PENDING_REVIEW',
                'qr_token' => $qrToken,
                'qr_url' => $this->publicQrUrl($qrToken),
                'qr_expires_at' => $qrExpiresAt,
            ];
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function validatedPayload(array $d): array
    {
        $e = [];
        $type = (string) ($d['visitor_type'] ?? '');
        $email = strtolower(trim((string) ($d['email_address'] ?? '')));
        if (trim((string) ($d['full_name'] ?? '')) === '') $e['full_name'] = 'Full name is required.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $e['email_address'] = 'Enter a valid email address.';
        if (!in_array($type, self::PUBLIC_TYPES, true)) $e['visitor_type'] = 'Select a valid visitor type.';
        if (trim((string) ($d['visit_purpose'] ?? '')) === '') $e['visit_purpose'] = 'Visit purpose is required.';
        if (empty($d['privacy_consent'])) $e['privacy_consent'] = 'Consent is required to continue.';
        if (($d['mobile_number'] ?? '') !== '' && !preg_match('/^[0-9+() .-]{7,30}$/', (string) $d['mobile_number'])) $e['mobile_number'] = 'Enter a valid mobile number.';
        $start = $this->dateValue($d['scheduled_start_at'] ?? null);
        $end = $this->dateValue($d['scheduled_end_at'] ?? null);
        if ($start && strtotime($start) < strtotime('-10 minutes')) $e['scheduled_start_at'] = 'Schedule cannot be in the past.';
        if ($start && $end && strtotime($end) <= strtotime($start)) $e['scheduled_end_at'] = 'End must be after start.';
        foreach (['destination_department_reference_id' => ['department_reference','department_reference_id'], 'facility_space_id' => ['facility_space','facility_space_id'], 'host_employee_reference_id' => ['employee_reference','employee_reference_id']] as $field => $target) {
            if (($d[$field] ?? '') !== '' && !$this->exists($target[0], $target[1], (int) $d[$field])) $e[$field] = 'Selected value is invalid.';
        }
        foreach (['full_name' => 160, 'visit_purpose' => 240, 'organization_name' => 200, 'company_or_school' => 200, 'applicant_reference' => 120, 'visit_description' => 1000] as $field => $max) {
            if (strlen(trim((string) ($d[$field] ?? ''))) > $max) $e[$field] = "Use {$max} characters or fewer.";
        }
        if ($e) throw new InvalidArgumentException(json_encode($e));
        return [
            'full_name' => trim(preg_replace('/\s+/', ' ', (string) $d['full_name'])),
            'email_address' => $email,
            'visitor_type' => $type,
            'visit_purpose' => trim((string) $d['visit_purpose']),
            'destination_department_reference_id' => $this->blankNull($d['destination_department_reference_id'] ?? null),
            'host_employee_reference_id' => $this->blankNull($d['host_employee_reference_id'] ?? null),
            'facility_space_id' => $this->blankNull($d['facility_space_id'] ?? null),
            'mobile_number' => $this->blankNull($d['mobile_number'] ?? null),
            'organization_name' => $this->blankNull($d['organization_name'] ?? null),
            'company_or_school' => $this->blankNull($d['company_or_school'] ?? null),
            'visit_description' => $this->blankNull($d['visit_description'] ?? null),
            'scheduled_start_at' => $start,
            'scheduled_end_at' => $end,
            'applicant_reference' => $this->blankNull($d['applicant_reference'] ?? null),
            'privacy_consent' => true,
            'consent_version' => 'visitor-registration-v1',
        ];
    }

    private function createOrUpdateVisitor(array $d): int
    {
        [$first, $last] = $this->nameParts($d['full_name']);
        $existing = $this->pdo->prepare('SELECT visitor_id FROM visitor WHERE LOWER(email_address)=:email AND deleted_at IS NULL ORDER BY visitor_id DESC LIMIT 1 FOR UPDATE');
        $existing->execute(['email' => $d['email_address']]);
        $id = $existing->fetchColumn();
        $params = ['first' => $first, 'last' => $last, 'org' => $d['organization_name'] ?? $d['company_or_school'] ?? null, 'type' => $d['visitor_type'], 'email' => $d['email_address'], 'mobile' => $d['mobile_number'] ?? null];
        if ($id) {
            $this->pdo->prepare('UPDATE visitor SET first_name=:first,last_name=:last,organization_name=COALESCE(:org,organization_name),visitor_type=:type,contact_number=COALESCE(:mobile,contact_number),email_verified_at=NOW(),updated_at=NOW() WHERE visitor_id=:id')->execute([
                'first' => $params['first'],
                'last' => $params['last'],
                'org' => $params['org'],
                'type' => $params['type'],
                'mobile' => $params['mobile'],
                'id' => (int) $id,
            ]);
            return (int) $id;
        }
        $this->pdo->prepare("INSERT INTO visitor (visitor_uuid, first_name, middle_name, last_name, organization_name, visitor_type, email_address, contact_number, id_type, identification_last4, email_verified_at, status, created_at, updated_at) VALUES (UUID(),:first,NULL,:last,:org,:type,:email,:mobile,NULL,NULL,NOW(),'ACTIVE',NOW(),NOW())")->execute($params);
        return (int) $this->pdo->lastInsertId();
    }

    private function assertNoActiveVisitForEmail(string $email): void
    {
        $stmt = $this->pdo->prepare("SELECT vi.visit_id FROM visit vi INNER JOIN visitor v ON v.visitor_id=vi.visitor_id WHERE LOWER(v.email_address)=:email AND vi.deleted_at IS NULL AND v.deleted_at IS NULL AND vi.visit_status IN ('PENDING_REVIEW','APPROVED','ARRIVED','CHECKED_IN') LIMIT 1 FOR UPDATE");
        $stmt->execute(['email' => strtolower(trim($email))]);
        if ($stmt->fetchColumn() !== false) {
            throw new DomainException('You already have an active visitor registration. Please complete or check out from your current visit before registering another visit.', 409);
        }
    }

    private function nextReference(): string
    {
        $year = (int) date('Y');
        $this->pdo->exec("INSERT INTO visitor_sequence (sequence_year,last_number) VALUES ($year,0) ON DUPLICATE KEY UPDATE sequence_year=sequence_year");
        $stmt = $this->pdo->query("SELECT last_number FROM visitor_sequence WHERE sequence_year=$year FOR UPDATE");
        $next = (int) $stmt->fetchColumn() + 1;
        $this->pdo->exec("UPDATE visitor_sequence SET last_number=$next WHERE sequence_year=$year");
        return sprintf('VIS-%d-%04d', $year, $next);
    }

    public function lookupQr(string $token): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{32,128}$/', $token)) {
            throw new DomainException('Visitor pass was not found.', 404);
        }
        $stmt = $this->pdo->prepare("SELECT vi.visit_number, vi.visit_status, vi.qr_token_expires_at, vi.qr_token_revoked_at, vi.deleted_at FROM visit vi WHERE vi.qr_token_hash=:hash LIMIT 1");
        $stmt->execute(['hash' => hash('sha256', $token)]);
        $row = $stmt->fetch();
        if (!is_array($row) || !empty($row['deleted_at'])) {
            throw new DomainException('Visitor pass was not found.', 404);
        }
        $expiresAt = strtotime((string) $row['qr_token_expires_at']);
        $valid = empty($row['qr_token_revoked_at']) && $expiresAt !== false && $expiresAt >= time() && !in_array((string) $row['visit_status'], ['REJECTED','CANCELLED','NO_SHOW','EXPIRED'], true);
        return [
            'visitor_reference_number' => $row['visit_number'],
            'status' => $row['visit_status'],
            'valid' => $valid,
        ];
    }

    public function qrPayloadForToken(string $token): string
    {
        $this->lookupQr($token);
        return $this->publicQrUrl($token);
    }

    private function issueQrToken(int $visitId, array $payload): array
    {
        do {
            $token = $this->base64Url(random_bytes(32));
            $hash = hash('sha256', $token);
            $exists = (int) $this->scalar('SELECT COUNT(*) FROM visit WHERE qr_token_hash=:hash', ['hash' => $hash]) > 0;
        } while ($exists);
        $base = $payload['scheduled_end_at'] ?: date('Y-m-d H:i:s');
        $expires = date('Y-m-d H:i:s', strtotime($base . ' +24 hours'));
        $this->pdo->prepare('UPDATE visit SET qr_token_hash=:hash, qr_token_created_at=NOW(), qr_token_expires_at=:expires, qr_token_revoked_at=NULL WHERE visit_id=:id')->execute(['hash' => $hash, 'expires' => $expires, 'id' => $visitId]);
        return [$token, $expires];
    }

    private function publicQrUrl(string $token): string
    {
        $base = $this->publicBaseUrl();
        return $base . 'api/public/visitors/qr-lookup.php?token=' . rawurlencode($token);
    }

    private function publicBaseUrl(): string
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
        $scheme = $https ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/FacilitiesAdministrativeManagement/api/public/visitors/submit.php');
        $marker = '/api/public/visitors/';
        $pos = strpos($script, $marker);
        $basePath = $pos === false ? '/FacilitiesAdministrativeManagement/' : substr($script, 0, $pos + 1);
        return $scheme . '://' . $host . $basePath;
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function history(int $visitId, string $event, string $remarks): void
    {
        $this->pdo->prepare("INSERT INTO visitor_visit_history (visit_id,old_status,new_status,event_type,remarks,changed_by_user_id,changed_at) VALUES (:id,NULL,'PENDING_REVIEW',:event,:remarks,NULL,NOW())")->execute(['id' => $visitId, 'event' => $event, 'remarks' => $remarks]);
    }

    private function activity(string $event, string $description, ?int $visitId, ?string $reference): void
    {
        try {
            $this->pdo->prepare("INSERT INTO activity_event (module_code,entity_type,entity_id,event_type,event_description,actor_user_id,event_status,visibility_level,occurred_at) VALUES ('VISITORS','visit',:id,:event,:description,NULL,'SUCCESS','INTERNAL',NOW())")->execute(['id' => $visitId ?? 0, 'event' => $event, 'description' => $reference ? "{$description} {$reference}" : $description]);
        } catch (Throwable $e) {
            error_log('Public visitor activity failed.');
        }
    }

    private function destinationLabel(array $payload): string
    {
        if (!empty($payload['destination_department_reference_id'])) {
            $name = $this->scalar('SELECT department_name FROM department_reference WHERE department_reference_id=:id', ['id' => (int) $payload['destination_department_reference_id']]);
            if ($name) return (string) $name;
        }
        return 'Reception / Security Desk';
    }

    private function rows(string $sql, array $params = []): array { $s = $this->pdo->prepare($sql); $s->execute($params); return $s->fetchAll(); }
    private function scalar(string $sql, array $params = []): mixed { $s = $this->pdo->prepare($sql); $s->execute($params); return $s->fetchColumn(); }
    private function exists(string $table, string $key, int $id): bool { $s = $this->pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE `$key`=:id"); $s->execute(['id' => $id]); return (int) $s->fetchColumn() > 0; }
    private function nullableInt(mixed $v): ?int { return ($v === null || $v === '' || $v === 'all') ? null : (int) $v; }
    private function blankNull(mixed $v): ?string { $v = trim((string) ($v ?? '')); return $v === '' ? null : $v; }
    private function dateValue(mixed $v): ?string { if ($v === null || trim((string) $v) === '') return null; $t = strtotime((string) $v); return $t ? date('Y-m-d H:i:s', $t) : null; }
    private function nameParts(string $name): array { $parts = explode(' ', trim($name)); if (count($parts) === 1) return [$name, 'Visitor']; $last = array_pop($parts); return [implode(' ', $parts), $last]; }
    private function maskEmail(string $email): string { [$local, $domain] = explode('@', $email, 2); return substr($local, 0, 1) . str_repeat('*', max(2, strlen($local) - 1)) . '@' . $domain; }
}
