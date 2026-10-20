<?php
declare(strict_types=1);

require_once __DIR__ . '/authorization.php';
require_once __DIR__ . '/ownership.php';
require_once __DIR__ . '/dbHelpers.php';
require_once __DIR__ . '/audit.php';

/**
 * Permanent removal is reserved for global administrators. PMS relationship users
 * continue to use deactivation / contact-list unlinking so shared data stays intact.
 */
function permanentlyDeleteDirectoryRecords(mysqli $conn, array $actor, string $type, array $requestedIds): int
{
    requireRole($actor, [DYNABASE_ROLE_SUPER_ADMIN, DYNABASE_ROLE_ADMIN],
        'Only global administrators can permanently delete directory records.');

    $config = match ($type) {
        'client' => ['table' => 'clients_table', 'column' => ['client_id', 'clients_id'], 'title' => 'client'],
        'keyperson' => ['table' => 'keypersons_table', 'column' => ['keyperson_id'], 'title' => 'key person'],
        default => throw new InvalidArgumentException('Unsupported record type.'),
    };

    if ($requestedIds === [] || count($requestedIds) > 250) {
        throw new RuntimeException('Select between 1 and 250 records to delete.', 422);
    }
    $ids = [];
    foreach ($requestedIds as $rawId) {
        if (!is_int($rawId) && (!is_string($rawId) || !ctype_digit($rawId))) {
            throw new RuntimeException('Invalid record ID.', 422);
        }
        $id = (int) $rawId;
        if ($id <= 0) {
            throw new RuntimeException('Invalid record ID.', 422);
        }
        $ids[$id] = $id;
    }
    $ids = array_values($ids);

    // Include legacy tables that reference directory records without formal FKs.
    // All references must be cleared explicitly before a destructive action.
    $columnPlaceholders = implode(',', array_fill(0, count($config['column']), '?'));
    $referencingColumns = dbFetchAll(
        $conn,
        "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME IN ({$columnPlaceholders}) AND TABLE_NAME <> ?",
        str_repeat('s', count($config['column']) + 1),
        [...$config['column'], $config['table']]
    );

    $conn->begin_transaction();
    try {
        foreach ($ids as $id) {
            $row = $type === 'client'
                ? assertClientAccessible($conn, $actor, $id, true)
                : assertKeypersonAccessible($conn, $actor, $id, true);
            $name = (string) ($row[$type === 'client' ? 'clients_name' : 'key_person'] ?? "#{$id}");

            foreach ($referencingColumns as $reference) {
                $table = (string) $reference['TABLE_NAME'];
                $column = (string) $reference['COLUMN_NAME'];
                // The assignment table is intentionally ON DELETE CASCADE and only
                // tracks who can see a contact; no business record is lost from it.
                if ($type === 'keyperson' && $table === 'keyperson_pms_assignments') {
                    continue;
                }
                if (!preg_match('/^[a-zA-Z0-9_]+$/D', $table) || !preg_match('/^[a-zA-Z0-9_]+$/D', $column)) {
                    throw new RuntimeException('Unable to validate linked records.', 500);
                }
                $linked = dbFetchOne($conn, "SELECT 1 AS linked FROM `{$table}` WHERE `{$column}` = ? LIMIT 1", 'i', [$id]);
                if ($linked !== null) {
                    throw new RuntimeException(
                        "Cannot permanently delete {$config['title']} '{$name}': it is referenced by {$table}. "
                        . 'Remove the linked records first, or deactivate this record instead.',
                        409
                    );
                }
            }

            $stmt = dbExecute($conn, "DELETE FROM `{$config['table']}` WHERE id = ?", 'i', [$id]);
            $deleted = $stmt->affected_rows;
            $stmt->close();
            if ($deleted !== 1) {
                throw new RuntimeException('Record could not be deleted. Please retry.', 409);
            }
            writeAuditLog($conn, $actor, "{$type}.permanently_deleted", $type, $id, [
                'name' => $name,
                'permanent' => true,
            ]);
        }
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        if ($error instanceof mysqli_sql_exception && (int) $error->getCode() === 1451) {
            throw new RuntimeException('A selected record is referenced by other data. Deactivate it or remove its linked records first.', 409);
        }
        throw $error;
    }

    return count($ids);
}
