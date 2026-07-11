<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/authorization.php';

requireMethod('GET');

$actor = authenticateUser();
requireRole($actor, [DYNABASE_ROLE_SUPER_ADMIN, DYNABASE_ROLE_ADMIN], 'Only Super Admins and Admins can view PMS Admin options.');

$sql = "SELECT DISTINCT u.id, u.first_name, u.last_name, u.email, u.role, u.is_pms_admin
        FROM users u
        WHERE u.status = 'active'
          AND (
            u.role = 'pms_admin'
            OR u.is_pms_admin = 1
            OR u.id IN (
              SELECT DISTINCT owner_pms_admin_id
              FROM clients_table
              WHERE owner_pms_admin_id IS NOT NULL
            )
            OR u.id IN (
              SELECT DISTINCT parent_pms_admin_id
              FROM users
              WHERE parent_pms_admin_id IS NOT NULL
            )
          )
        ORDER BY u.first_name ASC, u.last_name ASC, u.email ASC";

$stmt = $conn->prepare($sql);
$stmt->execute();
$result = $stmt->get_result();
$items = [];
while ($row = $result->fetch_assoc()) {
    $fullName = trim((string) $row['first_name'] . ' ' . (string) $row['last_name']);
    $items[] = [
        'id' => (int) $row['id'],
        'name' => $fullName !== '' ? $fullName : (string) $row['email'],
        'email' => $row['email'],
        'role' => $row['role'],
        'is_pms_admin' => (int) $row['is_pms_admin'] === 1,
    ];
}
$stmt->close();

jsonResponse([
    'status' => 'Success',
    'data' => $items
]);
