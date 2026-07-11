<?php
declare(strict_types=1);

require_once __DIR__ . '/dbHelpers.php';
require_once __DIR__ . '/authorization.php';
require_once __DIR__ . '/settings.php';

function notificationsTableExists(mysqli $conn): bool
{
    static $available = null;
    if ($available !== null) {
        return $available;
    }

    try {
        $row = dbFetchOne($conn, "SHOW TABLES LIKE 'notifications'");
        $available = $row !== null;
    } catch (Throwable) {
        $available = false;
    }

    return $available;
}

function notificationActorName(mysqli $conn, ?array $actor): string
{
    if (!$actor) {
        return 'A client';
    }

    $fullName = trim((string) ($actor['full_name'] ?? ''));
    if ($fullName !== '') {
        return $fullName;
    }

    $joined = trim(((string) ($actor['first_name'] ?? '')) . ' ' . ((string) ($actor['last_name'] ?? '')));
    if ($joined !== '') {
        return $joined;
    }

    $actorId = (int) ($actor['id'] ?? 0);
    if ($actorId > 0) {
        $row = dbFetchOne(
            $conn,
            "SELECT TRIM(CONCAT(first_name, ' ', last_name)) AS full_name FROM users WHERE id = ? LIMIT 1",
            'i',
            [$actorId]
        );
        if (trim((string) ($row['full_name'] ?? '')) !== '') {
            return trim((string) $row['full_name']);
        }
    }

    return 'A Dynabase user';
}

function notificationAdminRecipientIds(mysqli $conn): array
{
    $rows = dbFetchAll(
        $conn,
        "SELECT id FROM users WHERE status = 'active' AND role IN ('super_admin', 'admin')"
    );
    return array_map(static fn (array $row): int => (int) $row['id'], $rows);
}

function notificationScopedRecipientIds(mysqli $conn, ?int $ownerPmsAdminId): array
{
    $ids = notificationAdminRecipientIds($conn);
    if ($ownerPmsAdminId !== null && $ownerPmsAdminId > 0) {
        $rows = dbFetchAll(
            $conn,
            "SELECT id
             FROM users
             WHERE status = 'active'
               AND (id = ? OR parent_pms_admin_id = ?)",
            'ii',
            [$ownerPmsAdminId, $ownerPmsAdminId]
        );
        foreach ($rows as $row) {
            $ids[] = (int) $row['id'];
        }
    }

    return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
}

function createNotificationsForUsers(
    mysqli $conn,
    array $userIds,
    ?int $actorUserId,
    string $category,
    string $severity,
    string $title,
    string $message,
    ?string $actionUrl = null,
    ?string $entityType = null,
    mixed $entityId = null,
    array $metadata = []
): void {
    if (!notificationsTableExists($conn)) {
        return;
    }

    if (!appSettingBool($conn, 'notifications_enabled', true)
        || !appSettingBool($conn, 'notification_' . $category . '_enabled', true)) {
        return;
    }

    $recipientIds = array_values(array_unique(array_filter(
        array_map('intval', $userIds),
        static fn (int $id): bool => $id > 0 && ($actorUserId === null || $id !== $actorUserId)
    )));

    if ($recipientIds === []) {
        return;
    }

    $entityIdValue = $entityId !== null ? (string) $entityId : null;
    $metadataJson = $metadata !== []
        ? json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        : null;

    $stmt = $conn->prepare(
        'INSERT INTO notifications
            (user_id, actor_user_id, category, severity, title, message, action_url, entity_type, entity_id, metadata)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    foreach ($recipientIds as $userId) {
        $stmt->bind_param(
            'iissssssss',
            $userId,
            $actorUserId,
            $category,
            $severity,
            $title,
            $message,
            $actionUrl,
            $entityType,
            $entityIdValue,
            $metadataJson
        );
        $stmt->execute();
    }
    $stmt->close();
}

function notificationEntityContext(mysqli $conn, ?string $entityType, mixed $entityId): array
{
    $id = is_numeric($entityId) ? (int) $entityId : 0;
    $fallback = [
        'name' => $entityType ? ucfirst(str_replace('_', ' ', $entityType)) : 'record',
        'owner_pms_admin_id' => null,
        'path' => null,
    ];

    if ($entityType === 'project') {
        $code = trim((string) $entityId);
        $row = dbFetchOne(
            $conn,
            "SELECT project_title AS name, owner_pms_admin_id, code
             FROM project_info_table
             WHERE CAST(code AS CHAR) = ? OR tender_code = ?
             ORDER BY id DESC LIMIT 1",
            'ss',
            [$code, $code]
        );
        if ($row) {
            return [
                'name' => trim((string) ($row['name'] ?? '')) ?: ('Tender ' . $code),
                'owner_pms_admin_id' => isset($row['owner_pms_admin_id']) ? (int) $row['owner_pms_admin_id'] : null,
                'path' => '/tenders/' . rawurlencode((string) ($row['code'] ?? $code)),
            ];
        }
        return array_replace($fallback, ['path' => '/tenders']);
    }

    if ($id <= 0) {
        return $fallback;
    }

    $definitions = [
        'client' => ['table' => 'clients_table', 'name' => 'clients_name', 'owner' => 'owner_pms_admin_id', 'path' => '/clients/'],
        'keyperson' => ['table' => 'keypersons_table', 'name' => 'key_person', 'owner' => 'owner_pms_admin_id', 'path' => '/keypersons/'],
        'gift_list' => ['table' => 'gift_lists', 'name' => 'gift_year', 'owner' => 'owner_pms_admin_id', 'path' => '/gift-lists/'],
        'document' => ['table' => 'document_table', 'name' => 'document_title', 'owner' => null, 'path' => '/documents/'],
        'prequalification' => ['table' => 'prequalification_table', 'name' => 'prospective_project', 'owner' => 'owner_pms_admin_id', 'path' => '/prequalifications/'],
        'influence_log' => ['table' => 'log_table', 'name' => 'key_person', 'owner' => 'owner_pms_admin_id', 'path' => '/influence-logs'],
        'web_of_influence' => ['table' => 'web_of_influence_table', 'name' => 'stakeholder_name', 'owner' => 'owner_pms_admin_id', 'path' => '/web-of-influence/'],
        'client_survey' => ['table' => 'clients_survey_form', 'name' => 'company', 'owner' => 'owner_pms_admin_id', 'path' => '/client-surveys/'],
        'user' => ['table' => 'users', 'name' => "TRIM(CONCAT(first_name, ' ', last_name))", 'owner' => null, 'path' => '/users'],
    ];

    $definition = $definitions[$entityType ?? ''] ?? null;
    if (!$definition) {
        return $fallback;
    }

    $ownerSql = $definition['owner'] ? ', ' . $definition['owner'] . ' AS owner_pms_admin_id' : ', NULL AS owner_pms_admin_id';
    $row = dbFetchOne(
        $conn,
        "SELECT {$definition['name']} AS name{$ownerSql} FROM {$definition['table']} WHERE id = ? LIMIT 1",
        'i',
        [$id]
    );

    if (!$row) {
        return $fallback;
    }

    $name = trim((string) ($row['name'] ?? ''));
    if ($entityType === 'gift_list' && $name !== '') {
        $name .= ' annual gift list';
    }

    $basePath = (string) $definition['path'];
    $path = str_ends_with($basePath, '/') ? $basePath . $id : $basePath;

    return [
        'name' => $name !== '' ? $name : $fallback['name'],
        'owner_pms_admin_id' => isset($row['owner_pms_admin_id']) && (int) $row['owner_pms_admin_id'] > 0
            ? (int) $row['owner_pms_admin_id']
            : null,
        'path' => $path,
    ];
}

function notificationActionDefinition(string $action): ?array
{
    $definitions = [
        'client.created' => ['category' => 'relationship', 'severity' => 'success', 'title' => 'New client added', 'verb' => 'added'],
        'client.updated' => ['category' => 'relationship', 'severity' => 'info', 'title' => 'Client updated', 'verb' => 'updated'],
        'client.activated' => ['category' => 'relationship', 'severity' => 'success', 'title' => 'Client activated', 'verb' => 'activated'],
        'client.deactivated' => ['category' => 'relationship', 'severity' => 'warning', 'title' => 'Client deactivated', 'verb' => 'deactivated'],
        'keyperson.created' => ['category' => 'relationship', 'severity' => 'success', 'title' => 'New key person added', 'verb' => 'added'],
        'keyperson.updated' => ['category' => 'relationship', 'severity' => 'info', 'title' => 'Key person updated', 'verb' => 'updated'],
        'keyperson.activated' => ['category' => 'relationship', 'severity' => 'success', 'title' => 'Key person activated', 'verb' => 'activated'],
        'keyperson.deactivated' => ['category' => 'relationship', 'severity' => 'warning', 'title' => 'Key person deactivated', 'verb' => 'deactivated'],
        'gift_list.created' => ['category' => 'gift_list', 'severity' => 'success', 'title' => 'Gift list created', 'verb' => 'created'],
        'gift_list.updated' => ['category' => 'gift_list', 'severity' => 'info', 'title' => 'Gift list updated', 'verb' => 'updated'],
        'gift_list.keypersons_added' => ['category' => 'gift_list', 'severity' => 'info', 'title' => 'Gift-list recipients updated', 'verb' => 'updated'],
        'gift_list.deleted' => ['category' => 'gift_list', 'severity' => 'warning', 'title' => 'Gift list deleted', 'verb' => 'deleted'],
        'project.created' => ['category' => 'opportunity', 'severity' => 'success', 'title' => 'New tender created', 'verb' => 'created'],
        'project.updated' => ['category' => 'opportunity', 'severity' => 'info', 'title' => 'Tender updated', 'verb' => 'updated'],
        'project.deleted' => ['category' => 'opportunity', 'severity' => 'warning', 'title' => 'Tender deleted', 'verb' => 'deleted'],
        'document.created' => ['category' => 'document', 'severity' => 'success', 'title' => 'Document uploaded', 'verb' => 'uploaded'],
        'document.updated' => ['category' => 'document', 'severity' => 'info', 'title' => 'Document updated', 'verb' => 'updated'],
        'document.replaced' => ['category' => 'document', 'severity' => 'info', 'title' => 'Document replaced', 'verb' => 'replaced'],
        'document.deleted' => ['category' => 'document', 'severity' => 'warning', 'title' => 'Document deleted', 'verb' => 'deleted'],
        'prequalifications.created' => ['category' => 'opportunity', 'severity' => 'success', 'title' => 'Prequalification created', 'verb' => 'created'],
        'prequalifications.updated' => ['category' => 'opportunity', 'severity' => 'info', 'title' => 'Prequalification updated', 'verb' => 'updated'],
        'prequalifications.deleted' => ['category' => 'opportunity', 'severity' => 'warning', 'title' => 'Prequalification deleted', 'verb' => 'deleted'],
        'influence_log.created' => ['category' => 'relationship', 'severity' => 'success', 'title' => 'Influence log added', 'verb' => 'added'],
        'influence_log.updated' => ['category' => 'relationship', 'severity' => 'info', 'title' => 'Influence log updated', 'verb' => 'updated'],
        'influence_log.deleted' => ['category' => 'relationship', 'severity' => 'warning', 'title' => 'Influence log deleted', 'verb' => 'deleted'],
        'web_of_influence.created' => ['category' => 'relationship', 'severity' => 'success', 'title' => 'Influence map created', 'verb' => 'created'],
        'web_of_influence.updated' => ['category' => 'relationship', 'severity' => 'info', 'title' => 'Influence map updated', 'verb' => 'updated'],
        'web_of_influence.deleted' => ['category' => 'relationship', 'severity' => 'warning', 'title' => 'Influence map deleted', 'verb' => 'deleted'],
        'client_surveys.submitted' => ['category' => 'survey', 'severity' => 'success', 'title' => 'New client survey response', 'verb' => 'submitted'],
        'client_surveys.reviewed' => ['category' => 'survey', 'severity' => 'info', 'title' => 'Survey response reviewed', 'verb' => 'reviewed'],
        'client_surveys.invitation_created' => ['category' => 'survey', 'severity' => 'info', 'title' => 'Survey link created', 'verb' => 'created'],
        'users.invite' => ['category' => 'access', 'severity' => 'info', 'title' => 'Workspace invitation sent', 'verb' => 'invited'],
        'users.accept_invitation' => ['category' => 'access', 'severity' => 'success', 'title' => 'Invitation accepted', 'verb' => 'joined'],
        'users.activate' => ['category' => 'access', 'severity' => 'success', 'title' => 'User activated', 'verb' => 'activated'],
        'users.deactivate' => ['category' => 'access', 'severity' => 'warning', 'title' => 'User deactivated', 'verb' => 'deactivated'],
        'users.reset_password' => ['category' => 'access', 'severity' => 'warning', 'title' => 'Password reset issued', 'verb' => 'reset access for'],
        'users.update' => ['category' => 'access', 'severity' => 'info', 'title' => 'User access updated', 'verb' => 'updated'],
        'settings.updated' => ['category' => 'access', 'severity' => 'info', 'title' => 'Workspace settings updated', 'verb' => 'updated'],
    ];

    return $definitions[$action] ?? null;
}

function dispatchNotificationForAudit(
    mysqli $conn,
    ?array $actor,
    string $action,
    ?string $entityType,
    mixed $entityId,
    array $metadata = []
): void {
    if (!notificationsTableExists($conn)) {
        return;
    }

    $definition = notificationActionDefinition($action);
    if (!$definition) {
        return;
    }

    $context = notificationEntityContext($conn, $entityType, $entityId);
    $actorId = $actor ? (int) ($actor['id'] ?? 0) : null;
    $actorId = $actorId && $actorId > 0 ? $actorId : null;
    $actorName = notificationActorName($conn, $actor);
    $recordName = trim((string) ($context['name'] ?? '')) ?: 'a Dynabase record';

    if ($action === 'client_surveys.submitted') {
        $message = $recordName . ' submitted new project feedback.';
    } elseif ($action === 'users.accept_invitation') {
        $message = $recordName . ' joined the Dynabase workspace.';
    } else {
        $message = $actorName . ' ' . $definition['verb'] . ' ' . $recordName . '.';
    }

    $isScopedEntity = in_array($entityType, ['client', 'keyperson', 'gift_list', 'influence_log'], true);
    $recipients = $isScopedEntity
        ? notificationScopedRecipientIds($conn, $context['owner_pms_admin_id'] ?? null)
        : notificationAdminRecipientIds($conn);

    if ($entityType === 'user' && is_numeric($entityId)) {
        $recipients[] = (int) $entityId;
    }

    createNotificationsForUsers(
        $conn,
        $recipients,
        $actorId,
        (string) $definition['category'],
        (string) $definition['severity'],
        (string) $definition['title'],
        $message,
        $context['path'] ?? null,
        $entityType,
        $entityId,
        ['audit_action' => $action] + $metadata
    );
}
