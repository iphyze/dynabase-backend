<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../includes/ownership.php';
require_once __DIR__ . '/../../includes/dbHelpers.php';
require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/permissions.php';

requireMethod('GET');

$authUser = authenticateUser();
$query = trim((string) ($_GET['q'] ?? ''));
$limit = max(2, min(8, (int) ($_GET['limit'] ?? 5)));

if (mb_strlen($query) < 2) {
    jsonResponse([
        'status' => 'Success',
        'data' => [
            'query' => $query,
            'groups' => [],
            'total' => 0,
        ],
    ]);
}

$like = '%' . $query . '%';
$groups = [];
$total = 0;
$canSearch = static fn (string $permission): bool => userHasPermission($conn, $authUser, $permission);

$pushGroup = static function (string $key, string $label, array $items) use (&$groups, &$total): void {
    if ($items === []) {
        return;
    }

    $normalised = array_map(static function (array $item) use ($key): array {
        return [
            'id' => (string) ($item['id'] ?? ''),
            'type' => $key,
            'title' => (string) ($item['title'] ?? ''),
            'subtitle' => (string) ($item['subtitle'] ?? ''),
            'meta' => (string) ($item['meta'] ?? ''),
            'path' => (string) ($item['path'] ?? ''),
        ];
    }, $items);

    $groups[] = [
        'key' => $key,
        'label' => $label,
        'items' => $normalised,
    ];
    $total += count($normalised);
};

if ($canSearch('clients.view')) {
[$clientScopeSql, $clientScopeParams] = buildPmsOwnershipWhereClause($authUser, 'c');
$clientTypes = 'sss';
$clientParams = [$like, $like, $like];
foreach ($clientScopeParams as $scopeParam) {
    $clientTypes .= 'i';
    $clientParams[] = $scopeParam;
}
$clientTypes .= 'i';
$clientParams[] = $limit;
$clientRows = dbFetchAll(
    $conn,
    "SELECT c.id,
            c.clients_name AS title,
            CONCAT_WS(' • ', NULLIF(c.clients_category, ''), NULLIF(c.clients_hq_location, '')) AS subtitle,
            COALESCE(NULLIF(c.clients_email, ''), NULLIF(c.clients_website, ''), 'Client record') AS meta
     FROM clients_table c
     WHERE c.status <> 'deactivated'
       AND (c.clients_name LIKE ? OR c.clients_email LIKE ? OR c.clients_category LIKE ?)
       {$clientScopeSql}
     ORDER BY c.updated_at DESC, c.id DESC
     LIMIT ?",
    $clientTypes,
    $clientParams
);
$pushGroup('clients', 'Clients', array_map(static fn (array $row): array => $row + ['path' => '/clients/' . (int) $row['id']], $clientRows));
}

if ($canSearch('keypersons.view')) {
[$keypersonScopeSql, $keypersonScopeParams] = buildPmsOwnershipWhereClause($authUser, 'k');
$keypersonTypes = 'ssss';
$keypersonParams = [$like, $like, $like, $like];
foreach ($keypersonScopeParams as $scopeParam) {
    $keypersonTypes .= 'i';
    $keypersonParams[] = $scopeParam;
}
$keypersonTypes .= 'i';
$keypersonParams[] = $limit;
$keypersonRows = dbFetchAll(
    $conn,
    "SELECT k.id,
            k.key_person AS title,
            CONCAT_WS(' • ', NULLIF(k.title, ''), NULLIF(k.clients_name, '')) AS subtitle,
            COALESCE(NULLIF(k.key_persons_email, ''), NULLIF(k.key_persons_tel, ''), 'Key-person record') AS meta
     FROM keypersons_table k
     WHERE k.status <> 'deactivated'
       AND (k.key_person LIKE ? OR k.key_persons_email LIKE ? OR k.clients_name LIKE ? OR k.title LIKE ?)
       {$keypersonScopeSql}
     ORDER BY k.updated_at DESC, k.id DESC
     LIMIT ?",
    $keypersonTypes,
    $keypersonParams
);
$pushGroup('keypersons', 'Key Persons', array_map(static fn (array $row): array => $row + ['path' => '/keypersons/' . (int) $row['id']], $keypersonRows));
}

if ($canSearch('gift_lists.view')) {
$giftOwnerId = resolveOwnerPmsAdminId($authUser);
$giftWhere = '';
$giftTypes = 'sssss';
$giftParams = [$like, $like, $like, $like, $like];
if ($giftOwnerId !== null) {
    $giftWhere = ' AND gl.owner_pms_admin_id = ?';
    $giftTypes .= 'i';
    $giftParams[] = $giftOwnerId;
} elseif (!isGlobalDataUser($authUser)) {
    $giftWhere = ' AND 1 = 0';
}
$giftTypes .= 'i';
$giftParams[] = $limit;
$giftRows = dbFetchAll(
    $conn,
    "SELECT gl.id,
            CONCAT(gl.gift_year, ' annual gift list') AS title,
            TRIM(CONCAT(owner.first_name, ' ', owner.last_name)) AS subtitle,
            CONCAT(COUNT(items.id), ' recipient', CASE WHEN COUNT(items.id) = 1 THEN '' ELSE 's' END) AS meta
     FROM gift_lists gl
     INNER JOIN users owner ON owner.id = gl.owner_pms_admin_id
     LEFT JOIN gift_list_items items ON items.gift_list_id = gl.id
     WHERE (
        CAST(gl.gift_year AS CHAR) LIKE ?
        OR owner.first_name LIKE ?
        OR owner.last_name LIKE ?
        OR owner.email LIKE ?
        OR EXISTS (
            SELECT 1 FROM gift_list_items search_items
            WHERE search_items.gift_list_id = gl.id
              AND (search_items.keyperson_name_snapshot LIKE ? OR search_items.client_name_snapshot LIKE ?)
        )
     ){$giftWhere}
     GROUP BY gl.id, gl.gift_year, owner.first_name, owner.last_name
     ORDER BY gl.gift_year DESC, gl.updated_at DESC
     LIMIT ?",
    // One extra LIKE is needed for the item snapshots.
    substr($giftTypes, 0, 5) . 's' . substr($giftTypes, 5),
    array_merge(array_slice($giftParams, 0, 5), [$like], array_slice($giftParams, 5))
);
$pushGroup('gift_lists', 'Gift Lists', array_map(static fn (array $row): array => $row + ['path' => '/gift-lists/' . (int) $row['id']], $giftRows));
}

if ($canSearch('tenders.view')) {
    $projectRows = dbFetchAll(
        $conn,
        "SELECT p.id,
                COALESCE(NULLIF(p.project_title, ''), CONCAT('Tender ', p.code)) AS title,
                CONCAT_WS(' • ', NULLIF(p.project_client, ''), NULLIF(p.project_country, '')) AS subtitle,
                CONCAT_WS(' • ', NULLIF(p.project_status, ''), NULLIF(p.progress, '')) AS meta,
                p.code
         FROM project_info_table p
         WHERE p.record_status <> 'deactivated'
           AND (p.project_title LIKE ? OR p.project_client LIKE ? OR p.tender_code LIKE ? OR CAST(p.code AS CHAR) LIKE ?)
         ORDER BY p.updated_at DESC, p.id DESC
         LIMIT ?",
        'ssssi',
        [$like, $like, $like, $like, $limit]
    );
    $pushGroup('tenders', 'Tenders', array_map(static function (array $row): array {
        $recordCode = trim((string) ($row['code'] ?? ''));
        $row['path'] = '/tenders/' . rawurlencode($recordCode !== '' ? $recordCode : (string) $row['id']);
        unset($row['code']);
        return $row;
    }, $projectRows));
}

if ($canSearch('documents.view')) {
    $documentRows = dbFetchAll(
        $conn,
        "SELECT d.id,
                d.document_title AS title,
                CONCAT_WS(' • ', NULLIF(d.document_category, ''), NULLIF(d.document_type, '')) AS subtitle,
                COALESCE(NULLIF(d.presentation_code, ''), NULLIF(d.original_name, ''), 'Document record') AS meta
         FROM document_table d
         WHERE d.status = 'active'
           AND (d.document_title LIKE ? OR d.presentation_code LIKE ? OR d.original_name LIKE ? OR d.document_category LIKE ?)
         ORDER BY d.updated_at DESC, d.id DESC
         LIMIT ?",
        'ssssi',
        [$like, $like, $like, $like, $limit]
    );
    $pushGroup('documents', 'Documents', array_map(static fn (array $row): array => $row + ['path' => '/documents/' . (int) $row['id']], $documentRows));
}

if ($canSearch('prequalifications.view')) {
    $prequalificationRows = dbFetchAll(
        $conn,
        "SELECT p.id,
                COALESCE(NULLIF(p.prospective_project, ''), CONCAT('Prequalification #', p.id)) AS title,
                CONCAT_WS(' • ', NULLIF(p.clients_name, ''), NULLIF(p.key_person, '')) AS subtitle,
                COALESCE(NULLIF(p.services, ''), NULLIF(p.budget, ''), 'Readiness checklist') AS meta
         FROM prequalification_table p
         WHERE p.record_status = 'active'
           AND (p.prospective_project LIKE ? OR p.clients_name LIKE ? OR p.key_person LIKE ? OR p.services LIKE ?)
         ORDER BY p.updated_at DESC, p.id DESC
         LIMIT ?",
        'ssssi',
        [$like, $like, $like, $like, $limit]
    );
    $pushGroup('prequalifications', 'Prequalifications', array_map(static fn (array $row): array => $row + ['path' => '/prequalifications/' . (int) $row['id']], $prequalificationRows));
}

if ($canSearch('submission_register.view')) {
[$submissionScopeSql, $submissionScopeParams] = buildPmsOwnershipWhereClause($authUser, 'sr');
$submissionTypes = 'ssssssi';
$submissionParams = [$like, $like, $like, $like, $like, $like, $limit];
foreach ($submissionScopeParams as $scopeParam) {
    $submissionTypes = substr($submissionTypes, 0, -1) . 'i' . substr($submissionTypes, -1);
    array_splice($submissionParams, count($submissionParams) - 1, 0, [$scopeParam]);
}
$submissionRows = dbFetchAll(
    $conn,
    "SELECT sr.id,
            COALESCE(NULLIF(sr.project_company_name, ''), sr.submission_reference) AS title,
            CONCAT_WS(' • ', NULLIF(sr.client_name, ''), NULLIF(sr.category, '')) AS subtitle,
            CONCAT_WS(' • ', NULLIF(sr.status, ''), NULLIF(sr.mode_of_submission, ''), NULLIF(sr.submission_reference, '')) AS meta
     FROM submission_registers sr
     WHERE sr.record_status = 'active'
       AND (sr.submission_reference LIKE ? OR sr.project_company_name LIKE ? OR sr.client_name LIKE ?
            OR sr.category LIKE ? OR sr.mode_of_submission LIKE ? OR sr.status LIKE ?)
       {$submissionScopeSql}
     ORDER BY sr.updated_at DESC, sr.id DESC
     LIMIT ?",
    $submissionTypes,
    $submissionParams
);
$pushGroup('submission_register', 'Submission Register', array_map(static fn (array $row): array => $row + ['path' => '/submission-register/' . (int) $row['id']], $submissionRows));
}

if ($canSearch('web_of_influence.view')) {
    $influenceRows = dbFetchAll(
        $conn,
        "SELECT w.id,
                w.stakeholder_name AS title,
                CONCAT_WS(' • ', NULLIF(w.stakeholder_role, ''), NULLIF(c.clients_name, '')) AS subtitle,
                CONCAT_WS(' • ', NULLIF(w.authority, ''), NULLIF(w.influence_level, '')) AS meta
         FROM web_of_influence_table w
         LEFT JOIN clients_table c ON c.id = w.client_id
         LEFT JOIN project_info_table p ON p.id = w.project_id
         WHERE w.record_status = 'active'
           AND (w.stakeholder_name LIKE ? OR w.stakeholder_role LIKE ? OR c.clients_name LIKE ? OR p.project_title LIKE ?)
         ORDER BY w.updated_at DESC, w.id DESC
         LIMIT ?",
        'ssssi',
        [$like, $like, $like, $like, $limit]
    );
    $pushGroup('web_of_influence', 'Web of Influence', array_map(static fn (array $row): array => $row + ['path' => '/web-of-influence/' . (int) $row['id']], $influenceRows));
}

if ($canSearch('client_surveys.view')) {
    $surveyRows = dbFetchAll(
        $conn,
        "SELECT s.id,
                COALESCE(NULLIF(s.company, ''), 'Client survey response') AS title,
                CONCAT_WS(' • ', NULLIF(s.project_title, ''), NULLIF(s.filled_by, '')) AS subtitle,
                CONCAT(ROUND(s.overall_score, 1), '% • ', s.response_status) AS meta
         FROM clients_survey_form s
         WHERE s.deleted_at IS NULL
           AND (s.company LIKE ? OR s.project_title LIKE ? OR s.filled_by LIKE ? OR s.submission_reference LIKE ?)
         ORDER BY s.createdAt DESC, s.id DESC
         LIMIT ?",
        'ssssi',
        [$like, $like, $like, $like, $limit]
    );
    $pushGroup('client_surveys', 'Client Surveys', array_map(static fn (array $row): array => $row + ['path' => '/client-surveys/' . (int) $row['id']], $surveyRows));
}

if ($canSearch('users.view')) {
    $userScopeSql = '';
    $userTypes = 'ssss';
    $userParams = [$like, $like, $like, $like];
    if (userRole($authUser) === DYNABASE_ROLE_PMS_ADMIN) {
        $userScopeSql = " AND u.parent_pms_admin_id = ? AND u.role = 'pms_user'";
        $userTypes .= 'i';
        $userParams[] = (int) $authUser['id'];
    } elseif (userRole($authUser) !== DYNABASE_ROLE_SUPER_ADMIN) {
        $userScopeSql = ' AND 1 = 0';
    }
    $userTypes .= 'i';
    $userParams[] = $limit;

    $userRows = dbFetchAll(
        $conn,
        "SELECT u.id,
                TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS title,
                u.email AS subtitle,
                CONCAT(REPLACE(u.role, '_', ' '), ' • ', u.status) AS meta
         FROM users u
         WHERE (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR u.role LIKE ?)
           {$userScopeSql}
         ORDER BY u.updated_at DESC, u.id DESC
         LIMIT ?",
        $userTypes,
        $userParams
    );
    $pushGroup('users', 'Users & Access', array_map(static fn (array $row): array => $row + ['path' => '/users?search=' . rawurlencode($query)], $userRows));
}

jsonResponse([
    'status' => 'Success',
    'data' => [
        'query' => $query,
        'groups' => $groups,
        'total' => $total,
    ],
]);
