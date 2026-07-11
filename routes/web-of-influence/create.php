<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/webOfInfluence.php';
require_once __DIR__ . '/../../includes/audit.php';

requireMethod('POST');
$authUser = authenticateUser();
requireRole($authUser, [DYNABASE_ROLE_SUPER_ADMIN, DYNABASE_ROLE_ADMIN]);
$payload = readJsonBody();
$record = normalizeWoiPayload($conn, $authUser, $payload);
assertNoDuplicateWoi($conn, $record);

$actorEmail = actorEmail($authUser);
$actorId = (int) $authUser['id'];

$stmt = dbExecute(
    $conn,
    'INSERT INTO web_of_influence_table
        (client_id, project_id, keyperson_id, stakeholder_name, stakeholder_role, dlp_period,
         project_director, country_manager, phone, email, authority, influence_level,
         company_recommendation, personal_win, notes, owner_pms_admin_id,
         created_by, created_by_id, updated_by, updated_by_id)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
    'iiissssssssssssisisi',
    [
        $record['client_id'],
        $record['project_id'],
        $record['keyperson_id'],
        $record['stakeholder_name'],
        $record['stakeholder_role'],
        $record['dlp_period'],
        $record['project_director'],
        $record['country_manager'],
        $record['phone'],
        $record['email'],
        $record['authority'],
        $record['influence_level'],
        $record['company_recommendation'],
        $record['personal_win'],
        $record['notes'],
        $record['owner_pms_admin_id'],
        $actorEmail,
        $actorId,
        $actorEmail,
        $actorId,
    ]
);
$id = (int) $stmt->insert_id;
$stmt->close();

writeAuditLog($conn, $authUser, 'web_of_influence.created', 'web_of_influence', $id, [
    'client_id' => $record['client_id'],
    'project_id' => $record['project_id'],
    'keyperson_id' => $record['keyperson_id'],
    'stakeholder_name' => $record['stakeholder_name'],
    'authority' => $record['authority'],
    'influence_level' => $record['influence_level'],
]);

jsonResponse([
    'status' => 'Success',
    'message' => 'Web of Influence record created successfully.',
    'data' => assertWoiRecordAccessible($conn, $authUser, $id),
], 201);
