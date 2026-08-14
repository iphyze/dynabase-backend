<?php
declare(strict_types=1);

require_once __DIR__ . '/request.php';
require_once __DIR__ . '/authorization.php';
require_once __DIR__ . '/ownership.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/dbHelpers.php';
require_once __DIR__ . '/documents.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/audit.php';

const DYNABASE_AGREEMENT_TYPES = ['NDA', 'MOU'];
const DYNABASE_AGREEMENT_ISSUED_BY = ['Lambert', 'Client'];
const DYNABASE_AGREEMENT_RENEWAL_OPTIONS = ['Yes', 'No'];
const DYNABASE_AGREEMENT_STATUSES = [
    'Draft',
    'Under Review',
    'Sent',
    'Awaiting Client Signature',
    'Awaiting Lambert Signature',
    'Fully Executed',
    'Active',
    'Expiring Soon',
    'Renewed',
    'Expired',
    'Terminated',
    'Archived',
];

const DYNABASE_AGREEMENT_TERMINAL_STATUSES = ['Renewed', 'Terminated', 'Archived'];
const DYNABASE_AGREEMENT_AUTOMATED_STATUSES = ['Fully Executed', 'Active', 'Expiring Soon'];

function agreementExpiringSoonDays(mysqli $conn): int
{
    $days = (int) appSettingValue($conn, 'agreement_expiring_soon_days', 30);
    return max(1, min(365, $days));
}

function agreementLifecycleTargetStatus(array $record, DateTimeImmutable $today, int $warningDays): string
{
    $status = cleanString($record['status'] ?? '');
    if (!in_array($status, DYNABASE_AGREEMENT_AUTOMATED_STATUSES, true)) {
        return $status;
    }

    $effectiveDate = cleanString($record['effective_date'] ?? '');
    $expiryDate = cleanString($record['expiry_date'] ?? '');
    $expiry = $expiryDate !== '' ? DateTimeImmutable::createFromFormat('!Y-m-d', $expiryDate) : false;

    if (in_array($status, ['Active', 'Expiring Soon'], true) && $expiry) {
        $daysToExpiry = (int) $today->diff($expiry)->format('%r%a');
        if ($daysToExpiry < 0) {
            return 'Expired';
        }
        if ($daysToExpiry <= $warningDays) {
            return 'Expiring Soon';
        }
        return 'Active';
    }

    if ($status === 'Fully Executed' && $effectiveDate !== '') {
        $effective = DateTimeImmutable::createFromFormat('!Y-m-d', $effectiveDate);
        if ($effective && $effective <= $today) {
            if ($expiry) {
                $daysToExpiry = (int) $today->diff($expiry)->format('%r%a');
                if ($daysToExpiry < 0) {
                    return 'Expired';
                }
                if ($daysToExpiry <= $warningDays) {
                    return 'Expiring Soon';
                }
            }
            return 'Active';
        }
    }

    return $status;
}

function syncAgreementLifecycleStatuses(mysqli $conn, ?array $scopeUser = null, ?int $agreementId = null): array
{
    $where = " WHERE a.record_status = 'active' AND a.status IN ('Fully Executed', 'Active', 'Expiring Soon')";
    $types = '';
    $params = [];

    if ($agreementId !== null && $agreementId > 0) {
        $where .= ' AND a.id = ?';
        $types .= 'i';
        $params[] = $agreementId;
    }

    if ($scopeUser !== null) {
        [$scopeSql, $scopeTypes, $scopeParams] = appendScopedWhere($scopeUser, 'a');
        $where .= $scopeSql;
        $types .= $scopeTypes;
        $params = array_merge($params, $scopeParams);
    }

    $rows = dbFetchAll(
        $conn,
        "SELECT a.id, a.document_ref_no, a.status, a.effective_date, a.expiry_date
         FROM agreement_registers a{$where}",
        $types,
        $params
    );

    if ($rows === []) {
        return ['checked' => 0, 'updated' => 0];
    }

    $todayRow = dbFetchOne($conn, 'SELECT CURRENT_DATE() AS today');
    $todayValue = cleanString($todayRow['today'] ?? date('Y-m-d'));
    $today = DateTimeImmutable::createFromFormat('!Y-m-d', $todayValue) ?: new DateTimeImmutable('today');
    $warningDays = agreementExpiringSoonDays($conn);
    $updated = 0;

    foreach ($rows as $row) {
        $currentStatus = cleanString($row['status'] ?? '');
        $nextStatus = agreementLifecycleTargetStatus($row, $today, $warningDays);
        if ($nextStatus === '' || $nextStatus === $currentStatus) {
            continue;
        }

        $stmt = dbExecute(
            $conn,
            "UPDATE agreement_registers
             SET status = ?, updated_by = 'system@dynabase.local', updated_by_id = NULL,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND record_status = 'active' AND status = ?",
            'sis',
            [$nextStatus, (int) $row['id'], $currentStatus]
        );
        $changed = $stmt->affected_rows > 0;
        $stmt->close();
        if (!$changed) {
            continue;
        }

        $updated++;
        writeAuditLog($conn, null, 'agreement_register.status_automated', 'agreement_register', (int) $row['id'], [
            'document_ref_no' => $row['document_ref_no'] ?? null,
            'previous_status' => $currentStatus,
            'status' => $nextStatus,
            'effective_date' => $row['effective_date'] ?? null,
            'expiry_date' => $row['expiry_date'] ?? null,
            'expiring_soon_days' => $warningDays,
        ]);
    }

    return ['checked' => count($rows), 'updated' => $updated];
}

function agreementValue(array $payload, ?array $existing, string $key, mixed $default = null): mixed
{
    if (array_key_exists($key, $payload)) {
        return $payload[$key];
    }

    return $existing !== null && array_key_exists($key, $existing)
        ? $existing[$key]
        : $default;
}

function agreementStringValue(array $payload, ?array $existing, string $key): string
{
    return cleanString(agreementValue($payload, $existing, $key, ''));
}

function agreementEnumValue(array $payload, ?array $existing, string $key, string $label, array $allowed): string
{
    $value = agreementStringValue($payload, $existing, $key);
    if ($value === '') {
        throw new RuntimeException("{$label} is required.", 422);
    }
    if (!in_array($value, $allowed, true)) {
        throw new RuntimeException("Invalid {$label}.", 422);
    }

    return $value;
}

function agreementRequiredString(array $payload, ?array $existing, string $key, string $label, int $maxLength): string
{
    $value = agreementStringValue($payload, $existing, $key);
    if ($value === '') {
        throw new RuntimeException("{$label} is required.", 422);
    }

    $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    if ($length > $maxLength) {
        throw new RuntimeException("{$label} must not exceed {$maxLength} characters.", 422);
    }

    return $value;
}

function agreementOptionalString(array $payload, ?array $existing, string $key, string $label, int $maxLength): string
{
    $value = agreementStringValue($payload, $existing, $key);
    $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    if ($length > $maxLength) {
        throw new RuntimeException("{$label} must not exceed {$maxLength} characters.", 422);
    }

    return $value;
}

function agreementDateValue(array $payload, ?array $existing, string $key, string $label, bool $required = false): ?string
{
    $value = cleanString(agreementValue($payload, $existing, $key, ''));
    if ($value === '') {
        if ($required) {
            throw new RuntimeException("{$label} is required.", 422);
        }
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        throw new RuntimeException("{$label} must be a valid date.", 422);
    }

    return $value;
}

function normalizeAgreementReminderEmails(mixed $value): array
{
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        $value = is_array($decoded) ? $decoded : [$value];
    }

    if (!is_array($value)) {
        throw new RuntimeException('At least one reminder email is required.', 422);
    }

    $emails = [];
    foreach ($value as $candidate) {
        $email = cleanEmail($candidate);
        if ($email === '') {
            continue;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException("Invalid reminder email: {$email}", 422);
        }
        $emails[$email] = true;
    }

    $emails = array_keys($emails);
    if ($emails === []) {
        throw new RuntimeException('At least one reminder email is required.', 422);
    }
    if (count($emails) > 20) {
        throw new RuntimeException('A maximum of 20 reminder email recipients is allowed.', 422);
    }

    return $emails;
}

function normalizeAgreementPayload(mysqli $conn, array $authUser, array $payload, ?array $existing = null): array
{
    $documentRefType = agreementEnumValue(
        $payload,
        $existing,
        'document_ref_type',
        'Document reference type',
        DYNABASE_AGREEMENT_TYPES
    );

    if ($existing !== null && $documentRefType !== (string) $existing['document_ref_type']) {
        throw new RuntimeException('Document reference type cannot be changed after the reference number is created.', 422);
    }

    $clientId = (int) agreementValue($payload, $existing, 'client_id', 0);
    $client = null;
    if ($clientId > 0) {
        $client = assertClientAccessible($conn, $authUser, $clientId);
    } else {
        $clientId = 0;
    }

    $clientCompany = $client !== null
        ? cleanString($client['clients_name'] ?? '')
        : agreementRequiredString($payload, $existing, 'client_company', 'Client / Company', 255);

    $keypersonId = (int) agreementValue($payload, $existing, 'counterparty_keyperson_id', 0);
    $keyperson = null;
    if ($keypersonId > 0) {
        $keyperson = assertKeypersonAccessible($conn, $authUser, $keypersonId);
        $keypersonClientId = (int) ($keyperson['clients_id'] ?? 0);

        if ($clientId > 0 && $keypersonClientId !== $clientId) {
            throw new RuntimeException('The selected counterparty contact does not belong to the selected client.', 422);
        }

        if ($clientId <= 0 && $keypersonClientId > 0) {
            $client = assertClientAccessible($conn, $authUser, $keypersonClientId);
            $clientId = $keypersonClientId;
            $clientCompany = cleanString($client['clients_name'] ?? $clientCompany);
        }
    } else {
        $keypersonId = 0;
    }

    $counterpartyContact = $keyperson !== null
        ? cleanString($keyperson['key_person'] ?? '')
        : agreementRequiredString(
            $payload,
            $existing,
            'counterparty_contact_person',
            'Counter Party Contact Person',
            255
        );

    $status = agreementEnumValue($payload, $existing, 'status', 'Status', DYNABASE_AGREEMENT_STATUSES);
    $dateIssued = agreementDateValue($payload, $existing, 'date_issued', 'Date Issued', true);
    $dateSent = agreementDateValue($payload, $existing, 'date_sent', 'Date Sent');
    if ($status === 'Sent' && $dateSent === null) {
        $today = dbFetchOne($conn, 'SELECT CURRENT_DATE() AS today');
        $dateSent = cleanString($today['today'] ?? date('Y-m-d'));
    }

    $dateReceived = agreementDateValue($payload, $existing, 'date_received', 'Date Received');
    $effectiveDate = agreementDateValue($payload, $existing, 'effective_date', 'Effective Date');
    $expiryDate = agreementDateValue($payload, $existing, 'expiry_date', 'Expiry Date');
    $reminderDate = agreementDateValue($payload, $existing, 'reminder_date', 'Reminder Date', true);

    if ($effectiveDate !== null && $expiryDate !== null && $expiryDate < $effectiveDate) {
        throw new RuntimeException('Expiry Date cannot be earlier than Effective Date.', 422);
    }

    $reminderEmailsRaw = agreementValue($payload, $existing, 'reminder_emails', []);
    $reminderEmails = normalizeAgreementReminderEmails($reminderEmailsRaw);

    $linkedDocumentId = (int) agreementValue($payload, $existing, 'linked_document_id', 0);
    if ($linkedDocumentId > 0) {
        assertDocumentAccessible($conn, $authUser, $linkedDocumentId);
    } else {
        $linkedDocumentId = 0;
    }

    $requestedOwner = agreementValue($payload, $existing, 'owner_pms_admin_id', null);
    $requestedOwner = $requestedOwner !== null && (int) $requestedOwner > 0 ? (int) $requestedOwner : null;
    $ownerPmsAdminId = $client !== null && $client['owner_pms_admin_id'] !== null
        ? (int) $client['owner_pms_admin_id']
        : resolveAssignableOwnerPmsAdminId($conn, $authUser, $requestedOwner);

    return [
        'document_ref_type' => $documentRefType,
        'project_subject' => agreementOptionalString($payload, $existing, 'project_subject', 'Project / Subject', 500),
        'client_id' => $clientId > 0 ? $clientId : null,
        'client_company' => $clientCompany,
        'counterparty_keyperson_id' => $keypersonId > 0 ? $keypersonId : null,
        'counterparty_contact_person' => $counterpartyContact,
        'issued_by' => agreementEnumValue($payload, $existing, 'issued_by', 'Issued By', DYNABASE_AGREEMENT_ISSUED_BY),
        'date_issued' => $dateIssued,
        'date_sent' => $dateSent,
        'date_received' => $dateReceived,
        'lambert_signatory' => agreementOptionalString($payload, $existing, 'lambert_signatory', 'Lambert Signatory', 255),
        'client_signatory' => agreementOptionalString($payload, $existing, 'client_signatory', 'Client Signatory', 255),
        'effective_date' => $effectiveDate,
        'expiry_date' => $expiryDate,
        'duration' => agreementRequiredString($payload, $existing, 'duration', 'Duration', 255),
        'renewal' => agreementEnumValue($payload, $existing, 'renewal', 'Renewal', DYNABASE_AGREEMENT_RENEWAL_OPTIONS),
        'status' => $status,
        'purpose' => agreementRequiredString($payload, $existing, 'purpose', 'Purpose', 5000),
        'department' => agreementRequiredString($payload, $existing, 'department', 'Department', 255),
        'reminder_date' => $reminderDate,
        'reminder_emails' => $reminderEmails,
        'remark' => agreementOptionalString($payload, $existing, 'remark', 'Remark', 5000),
        'linked_document_id' => $linkedDocumentId > 0 ? $linkedDocumentId : null,
        'owner_pms_admin_id' => $ownerPmsAdminId,
    ];
}

function agreementCurrentYear(mysqli $conn): int
{
    $row = dbFetchOne($conn, 'SELECT YEAR(CURRENT_DATE()) AS current_year');
    $year = (int) ($row['current_year'] ?? 0);
    if ($year < 2000 || $year > 9999) {
        throw new RuntimeException('Unable to determine the agreement reference year.', 500);
    }

    return $year;
}

function acquireAgreementReferenceLock(mysqli $conn, string $type, int $year): string
{
    $lockName = "dynabase_agreement_ref_{$type}_{$year}";
    $row = dbFetchOne($conn, 'SELECT GET_LOCK(?, 5) AS acquired', 's', [$lockName]);
    if ((int) ($row['acquired'] ?? 0) !== 1) {
        throw new RuntimeException('Agreement reference generation is busy. Please try again.', 409);
    }

    return $lockName;
}

function releaseAgreementReferenceLock(mysqli $conn, string $lockName): void
{
    try {
        dbFetchOne($conn, 'SELECT RELEASE_LOCK(?) AS released', 's', [$lockName]);
    } catch (Throwable $exception) {
        error_log('[Dynabase Agreement Reference Lock] ' . $exception->getMessage());
    }
}

function nextAgreementReference(mysqli $conn, string $type, int $year): array
{
    $row = dbFetchOne(
        $conn,
        'SELECT COALESCE(MAX(ref_sequence), 0) + 1 AS next_sequence
         FROM agreement_registers
         WHERE document_ref_type = ? AND ref_year = ?',
        'si',
        [$type, $year]
    );

    $sequence = max(1, (int) ($row['next_sequence'] ?? 1));
    return [
        'year' => $year,
        'sequence' => $sequence,
        'reference' => sprintf('%s-%04d-%03d', $type, $year, $sequence),
    ];
}

function agreementSelectSql(): string
{
    return "SELECT a.id, a.document_ref_type, a.document_ref_no, a.ref_year, a.ref_sequence,
            a.project_subject, a.client_id, a.client_company, a.counterparty_keyperson_id,
            a.counterparty_contact_person, a.issued_by, a.date_issued, a.date_sent, a.date_received,
            a.lambert_signatory, a.client_signatory, a.effective_date, a.expiry_date, a.duration,
            a.renewal, a.status, a.purpose, a.department, a.reminder_date, a.reminder_emails,
            a.reminder_sent_at, a.reminder_last_status, a.reminder_last_error, a.remark,
            CASE WHEN a.reminder_sent_at IS NULL AND a.reminder_date <= CURRENT_DATE() THEN 1 ELSE 0 END AS reminder_due,
            CASE WHEN a.expiry_date IS NULL THEN NULL ELSE DATEDIFF(a.expiry_date, CURRENT_DATE()) END AS days_to_expiry,
            CASE WHEN a.date_sent IS NULL THEN NULL ELSE DATEDIFF(CURRENT_DATE(), a.date_sent) END AS days_since_sent,
            a.linked_document_id, a.renewed_from_id, a.renewed_to_id, a.owner_pms_admin_id,
            a.record_status, a.created_by, a.created_by_id, a.created_at, a.updated_by,
            a.updated_by_id, a.updated_at,
            c.clients_name AS linked_client_name, c.status AS linked_client_status,
            k.key_person AS linked_keyperson_name, k.key_persons_email AS linked_keyperson_email,
            k.key_persons_tel AS linked_keyperson_phone, k.title AS linked_keyperson_title,
            d.document_title AS linked_document_title,
            previous_agreement.document_ref_no AS renewed_from_ref_no,
            previous_agreement.status AS renewed_from_status,
            next_agreement.document_ref_no AS renewed_to_ref_no,
            next_agreement.status AS renewed_to_status,
            NULLIF(TRIM(CONCAT(COALESCE(owner.first_name, ''), ' ', COALESCE(owner.last_name, ''))), '') AS owner_pms_admin_name,
            owner.email AS owner_pms_admin_email,
            NULLIF(TRIM(CONCAT(COALESCE(created_user.first_name, ''), ' ', COALESCE(created_user.last_name, ''))), '') AS created_by_name,
            NULLIF(TRIM(CONCAT(COALESCE(updated_user.first_name, ''), ' ', COALESCE(updated_user.last_name, ''))), '') AS updated_by_name
     FROM agreement_registers a
     LEFT JOIN clients_table c ON c.id = a.client_id
     LEFT JOIN keypersons_table k ON k.id = a.counterparty_keyperson_id
     LEFT JOIN document_table d ON d.id = a.linked_document_id
     LEFT JOIN agreement_registers previous_agreement ON previous_agreement.id = a.renewed_from_id
     LEFT JOIN agreement_registers next_agreement ON next_agreement.id = a.renewed_to_id
     LEFT JOIN users owner ON owner.id = a.owner_pms_admin_id
     LEFT JOIN users created_user ON created_user.id = a.created_by_id
     LEFT JOIN users updated_user ON updated_user.id = a.updated_by_id";
}

function castAgreementRecord(array $record): array
{
    $record['id'] = (int) $record['id'];
    $record['ref_year'] = (int) $record['ref_year'];
    $record['ref_sequence'] = (int) $record['ref_sequence'];
    foreach (['client_id', 'counterparty_keyperson_id', 'linked_document_id', 'renewed_from_id', 'renewed_to_id', 'owner_pms_admin_id', 'created_by_id', 'updated_by_id'] as $key) {
        $record[$key] = $record[$key] !== null ? (int) $record[$key] : null;
    }

    $decoded = json_decode((string) ($record['reminder_emails'] ?? '[]'), true);
    $record['reminder_emails'] = is_array($decoded) ? array_values($decoded) : [];
    $record['reminder_due'] = (int) ($record['reminder_due'] ?? 0) === 1;
    $record['days_to_expiry'] = $record['days_to_expiry'] !== null ? (int) $record['days_to_expiry'] : null;
    $record['days_since_sent'] = $record['days_since_sent'] !== null ? (int) $record['days_since_sent'] : null;

    return $record;
}

function agreementRenewalHistory(mysqli $conn, array $authUser, array $record): array
{
    $before = [];
    $after = [];
    $visited = [(int) $record['id'] => true];
    $cursorId = $record['renewed_from_id'] ?? null;

    for ($index = 0; $cursorId && $index < 50; $index++) {
        $cursorId = (int) $cursorId;
        if ($cursorId <= 0 || isset($visited[$cursorId])) {
            break;
        }
        try {
            $item = assertAgreementAccessible($conn, $authUser, $cursorId);
        } catch (Throwable) {
            break;
        }
        $visited[$cursorId] = true;
        array_unshift($before, $item);
        $cursorId = $item['renewed_from_id'] ?? null;
    }

    $cursorId = $record['renewed_to_id'] ?? null;
    for ($index = 0; $cursorId && $index < 50; $index++) {
        $cursorId = (int) $cursorId;
        if ($cursorId <= 0 || isset($visited[$cursorId])) {
            break;
        }
        try {
            $item = assertAgreementAccessible($conn, $authUser, $cursorId);
        } catch (Throwable) {
            break;
        }
        $visited[$cursorId] = true;
        $after[] = $item;
        $cursorId = $item['renewed_to_id'] ?? null;
    }

    $current = $record;
    $current['is_current'] = true;
    return array_merge($before, [$current], $after);
}

function assertAgreementAccessible(mysqli $conn, array $authUser, int $id, bool $includeDeleted = false): array
{
    [$scopeSql, $types, $params] = appendScopedWhere($authUser, 'a', 'i', [$id]);
    $statusSql = $includeDeleted ? '' : " AND a.record_status = 'active'";

    $record = dbFetchOne(
        $conn,
        agreementSelectSql() . " WHERE a.id = ?{$statusSql}{$scopeSql} LIMIT 1",
        $types,
        $params
    );

    if (!$record) {
        throw new RuntimeException('Agreement record not found or not accessible.', 404);
    }

    return castAgreementRecord($record);
}
