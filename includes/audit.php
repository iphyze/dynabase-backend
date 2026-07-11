<?php
declare(strict_types=1);

require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/notifications.php';

function writeAuditLog(mysqli $conn, ?array $actor, string $action, ?string $entityType = null, mixed $entityId = null, array $metadata = []): void
{
    try {
        $actorId = $actor ? (int) ($actor['id'] ?? 0) : null;
        $actorRole = $actor ? (string) ($actor['role'] ?? '') : null;
        $entityIdValue = $entityId !== null ? (string) $entityId : null;
        $metadataJson = $metadata ? json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
        $ipHash = hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

        $stmt = $conn->prepare(
            'INSERT INTO audit_logs (actor_user_id, actor_role, action, entity_type, entity_id, metadata, ip_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('issssss', $actorId, $actorRole, $action, $entityType, $entityIdValue, $metadataJson, $ipHash);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $exception) {
        error_log('[Dynabase Audit] ' . $exception->getMessage());
    }

    try {
        dispatchNotificationForAudit($conn, $actor, $action, $entityType, $entityId, $metadata);
    } catch (Throwable $exception) {
        error_log('[Dynabase Notifications] ' . $exception->getMessage());
    }
}
