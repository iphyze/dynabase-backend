<?php
declare(strict_types=1);

require_once __DIR__ . '/connection.php';

function bindStatementParams(mysqli_stmt $stmt, string $types, array $params): void
{
    if ($types === '' || $params === []) {
        return;
    }

    $refs = [];
    foreach ($params as $key => $value) {
        $refs[$key] = &$params[$key];
    }

    $stmt->bind_param($types, ...$refs);
}

function dbFetchAll(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);
    bindStatementParams($stmt, $types, $params);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

function dbFetchOne(mysqli $conn, string $sql, string $types = '', array $params = []): ?array
{
    $rows = dbFetchAll($conn, $sql, $types, $params);
    return $rows[0] ?? null;
}

function dbExecute(mysqli $conn, string $sql, string $types = '', array $params = []): mysqli_stmt
{
    $stmt = $conn->prepare($sql);
    bindStatementParams($stmt, $types, $params);
    $stmt->execute();
    return $stmt;
}

function dbScalarInt(mysqli $conn, string $sql, string $types = '', array $params = [], string $column = 'total'): int
{
    $row = dbFetchOne($conn, $sql, $types, $params);
    return (int) ($row[$column] ?? 0);
}
