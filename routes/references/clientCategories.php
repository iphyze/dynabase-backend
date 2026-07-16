<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../includes/dbHelpers.php';
require_once __DIR__ . '/../../includes/ownership.php';
require_once __DIR__ . '/../../includes/validation.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/permissions.php';

$authUser = authenticateUser();
$method = requestMethod();

if ($method === 'GET') {
    $q = cleanString($_GET['q'] ?? '');
    $where = '';
    $types = '';
    $params = [];

    if ($q !== '') {
        $where = ' WHERE category_name LIKE ?';
        $types = 's';
        $params[] = '%' . $q . '%';
    }

    $categories = dbFetchAll(
        $conn,
        "SELECT id, category_name
         FROM clients_category_table{$where}
         ORDER BY category_name ASC",
        $types,
        $params
    );

    jsonResponse([
        'status' => 'Success',
        'message' => 'Client categories retrieved successfully.',
        'data' => $categories,
    ]);
}

if ($method === 'POST') {
    requirePermission($conn, $authUser, 'clients.create');
    $payload = readJsonBody();
    $categoryName = requireStringField($payload, 'category_name', 'Category name');

    $existing = dbFetchOne(
        $conn,
        'SELECT id FROM clients_category_table WHERE LOWER(TRIM(category_name)) = LOWER(TRIM(?)) LIMIT 1',
        's',
        [$categoryName]
    );

    if ($existing) {
        throw new RuntimeException('This category already exists.', 409);
    }

    $actorEmail = actorEmail($authUser);
    $stmt = dbExecute(
        $conn,
        'INSERT INTO clients_category_table (category_name, created_by, updated_by) VALUES (?, ?, ?)',
        'sss',
        [$categoryName, $actorEmail, $actorEmail]
    );
    $categoryId = $stmt->insert_id;
    $stmt->close();

    writeAuditLog($conn, $authUser, 'client_category.created', 'client_category', $categoryId, [
        'category_name' => $categoryName,
    ]);

    $category = dbFetchOne($conn, 'SELECT * FROM clients_category_table WHERE id = ? LIMIT 1', 'i', [(int) $categoryId]);

    jsonResponse([
        'status' => 'Success',
        'message' => 'Client category created successfully.',
        'data' => $category,
    ], 201);
}

jsonResponse([
    'status' => 'Failed',
    'message' => 'Method not allowed.'
], 405);
