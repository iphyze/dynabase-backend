<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../includes/dbHelpers.php';
require_once __DIR__ . '/../../includes/tenderAnalytics.php';
require_once __DIR__ . '/../../includes/permissions.php';

requireMethod('GET');
$authUser = authenticateUser();
if (isPmsWorkspaceUser($authUser)) {
    throw new RuntimeException('Use the PMS dashboard for this workspace account.', 403);
}

function dashboardTableExists(mysqli $conn, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    $row = dbFetchOne(
        $conn,
        'SELECT COUNT(*) AS total FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
        's',
        [$table]
    );

    return $cache[$table] = (int) ($row['total'] ?? 0) > 0;
}

function dashboardColumnExists(mysqli $conn, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $row = dbFetchOne(
        $conn,
        'SELECT COUNT(*) AS total FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        'ss',
        [$table, $column]
    );

    return $cache[$key] = (int) ($row['total'] ?? 0) > 0;
}

function dashboardSafeOne(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    try {
        return dbFetchOne($conn, $sql, $types, $params) ?? [];
    } catch (Throwable $exception) {
        error_log('[Dynabase Dashboard] ' . $exception->getMessage());
        return [];
    }
}

function dashboardSafeAll(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    try {
        return dbFetchAll($conn, $sql, $types, $params);
    } catch (Throwable $exception) {
        error_log('[Dynabase Dashboard] ' . $exception->getMessage());
        return [];
    }
}

function dashboardSixMonthTrend(array $rows): array
{
    $totals = [];
    foreach ($rows as $row) {
        $totals[(string) ($row['month_key'] ?? '')] = (int) ($row['total'] ?? 0);
    }

    $trend = [];
    $cursor = new DateTimeImmutable('first day of this month');
    for ($offset = 5; $offset >= 0; $offset--) {
        $month = $cursor->modify("-{$offset} months");
        $key = $month->format('Y-m');
        $trend[] = [
            'month' => $key,
            'label' => $month->format('M'),
            'total' => $totals[$key] ?? 0,
        ];
    }

    return $trend;
}

$currentYear = (int) date('Y');
$selectedTenderYear = dynabaseTenderAnalyticsYearFilter($_GET['year'] ?? 'all');

$access = [
    'clients' => userHasPermission($conn, $authUser, 'clients.view'),
    'keypersons' => userHasPermission($conn, $authUser, 'keypersons.view'),
    'tenders' => userHasPermission($conn, $authUser, 'tenders.view'),
    'users' => userHasPermission($conn, $authUser, 'users.view'),
    'documents' => userHasPermission($conn, $authUser, 'documents.view'),
    'submission_register' => userHasPermission($conn, $authUser, 'submission_register.view'),
    'prequalifications' => userHasPermission($conn, $authUser, 'prequalifications.view'),
    'client_surveys' => userHasPermission($conn, $authUser, 'client_surveys.view'),
    'web_of_influence' => userHasPermission($conn, $authUser, 'web_of_influence.view'),
    'influence_logs' => userHasPermission($conn, $authUser, 'influence_logs.view'),
    'gift_lists' => userHasPermission($conn, $authUser, 'gift_lists.view'),
    'audit' => userHasPermission($conn, $authUser, 'audit.view'),
];

$tenderIntelligence = dynabaseTenderAnalyticsEmptyOverview($selectedTenderYear);
if ($access['tenders']) {
    try {
        $tenderIntelligence = dynabaseTenderAnalyticsOverview($conn, $selectedTenderYear);
    } catch (Throwable $exception) {
        error_log('[Dynabase Dashboard Tender Analytics] ' . $exception->getMessage());
    }
}

$clientSummary = ['total' => 0, 'active' => 0, 'inactive' => 0];
if ($access['clients']) {
    $clientSummary = dashboardSafeOne(
        $conn,
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active,
                SUM(CASE WHEN status <> 'active' THEN 1 ELSE 0 END) AS inactive
         FROM clients_table"
    );
}

$keypersonSummary = ['total' => 0, 'active' => 0, 'inactive' => 0];
if ($access['keypersons']) {
    $keypersonSummary = dashboardSafeOne(
        $conn,
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active,
                SUM(CASE WHEN status <> 'active' THEN 1 ELSE 0 END) AS inactive
         FROM keypersons_table"
    );
}

$tenderSummary = $tenderIntelligence['summary'] ?? [];
$userSummary = ['total' => 0, 'active' => 0, 'pending' => 0, 'pms_admins' => 0];
if ($access['users']) {
    $userSummary = dashboardSafeOne(
        $conn,
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN role = 'pms_admin' OR is_pms_admin = 1 THEN 1 ELSE 0 END) AS pms_admins
         FROM users"
    );
}

$documentSummary = ['active' => 0, 'recent' => 0];
if ($access['documents'] && dashboardTableExists($conn, 'document_table')) {
    $documentStatusColumn = dashboardColumnExists($conn, 'document_table', 'status');
    $documentSummary = dashboardSafeOne(
        $conn,
        $documentStatusColumn
            ? "SELECT SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active,
                      SUM(CASE WHEN status = 'active' AND updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS recent
               FROM document_table"
            : "SELECT COUNT(*) AS active,
                      SUM(CASE WHEN updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS recent
               FROM document_table"
    );
}

$submissionSummary = ['total' => 0, 'ongoing' => 0, 'completed' => 0, 'recent' => 0];
if ($access['submission_register'] && dashboardTableExists($conn, 'submission_registers')) {
    $submissionSummary = dashboardSafeOne(
        $conn,
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN status = 'Ongoing' THEN 1 ELSE 0 END) AS ongoing,
                SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) AS completed,
                SUM(CASE WHEN updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS recent
         FROM submission_registers
         WHERE record_status = 'active'"
    );
}

$prequalificationSummary = ['active' => 0, 'linked' => 0];
if ($access['prequalifications'] && dashboardTableExists($conn, 'prequalification_table')) {
    $hasRecordStatus = dashboardColumnExists($conn, 'prequalification_table', 'record_status');
    $prequalificationSummary = dashboardSafeOne(
        $conn,
        $hasRecordStatus
            ? "SELECT SUM(CASE WHEN record_status = 'active' THEN 1 ELSE 0 END) AS active,
                      SUM(CASE WHEN record_status = 'active' AND clients_id > 0 THEN 1 ELSE 0 END) AS linked
               FROM prequalification_table"
            : "SELECT COUNT(*) AS active, SUM(CASE WHEN clients_id > 0 THEN 1 ELSE 0 END) AS linked FROM prequalification_table"
    );
}

$surveySummary = ['responses' => 0, 'reviewed' => 0, 'pending_review' => 0, 'average_score' => 0, 'recent' => 0, 'active_links' => 0];
if ($access['client_surveys'] && dashboardTableExists($conn, 'clients_survey_form')) {
    $hasDeletedAt = dashboardColumnExists($conn, 'clients_survey_form', 'deleted_at');
    $hasScore = dashboardColumnExists($conn, 'clients_survey_form', 'overall_score');
    $hasResponseStatus = dashboardColumnExists($conn, 'clients_survey_form', 'response_status');
    $where = $hasDeletedAt ? 'WHERE deleted_at IS NULL' : '';
    $scoreSql = $hasScore ? 'ROUND(AVG(overall_score), 1)' : '0';
    $reviewedSql = $hasResponseStatus ? "SUM(CASE WHEN response_status = 'reviewed' THEN 1 ELSE 0 END)" : '0';
    $pendingSql = $hasResponseStatus ? "SUM(CASE WHEN response_status = 'submitted' THEN 1 ELSE 0 END)" : '0';
    $surveySummary = dashboardSafeOne(
        $conn,
        "SELECT COUNT(*) AS responses,
                {$reviewedSql} AS reviewed,
                {$pendingSql} AS pending_review,
                {$scoreSql} AS average_score,
                SUM(CASE WHEN createdAt >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS recent
         FROM clients_survey_form {$where}"
    );
    if (dashboardTableExists($conn, 'client_survey_invitations')) {
        $linkSummary = dashboardSafeOne(
            $conn,
            "SELECT COUNT(*) AS active_links
             FROM client_survey_invitations
             WHERE status = 'active' AND expires_at >= NOW() AND submission_count < max_submissions"
        );
        $surveySummary['active_links'] = (int) ($linkSummary['active_links'] ?? 0);
    }
}

$influenceSummary = ['mapped' => 0, 'high_influence' => 0, 'recommenders' => 0, 'projects' => 0, 'logs' => 0];
if ($access['web_of_influence'] && dashboardTableExists($conn, 'web_of_influence_table')) {
    $influenceSummary = dashboardSafeOne(
        $conn,
        "SELECT COUNT(*) AS mapped,
                SUM(CASE WHEN influence_level IN ('Med High','High') THEN 1 ELSE 0 END) AS high_influence,
                SUM(CASE WHEN company_recommendation = 'Yes' THEN 1 ELSE 0 END) AS recommenders,
                COUNT(DISTINCT project_id) AS projects
         FROM web_of_influence_table
         WHERE record_status = 'active'"
    );
}
if ($access['influence_logs'] && dashboardTableExists($conn, 'log_table')) {
    $logSummary = dashboardSafeOne($conn, 'SELECT COUNT(*) AS logs FROM log_table');
    $influenceSummary['logs'] = (int) ($logSummary['logs'] ?? 0);
}

$giftSummary = ['recipients' => 0, 'lists' => 0, 'clients' => 0];
if ($access['gift_lists'] && dashboardTableExists($conn, 'gift_lists') && dashboardTableExists($conn, 'gift_list_items')) {
    $giftSummary = dashboardSafeOne(
        $conn,
        "SELECT COUNT(DISTINCT gl.id) AS lists,
                COUNT(items.id) AS recipients,
                COUNT(DISTINCT items.client_id) AS clients
         FROM gift_lists gl
         LEFT JOIN gift_list_items items ON items.gift_list_id = gl.id
         WHERE gl.gift_year = ?",
        'i',
        [$currentYear]
    );
}

$pipelineStages = $access['tenders'] ? ($tenderIntelligence['progress'] ?? []) : [];
$pipelineTotal = $access['tenders'] ? (int) (($tenderIntelligence['summary']['total'] ?? 0)) : 0;
$recentTenders = [];
if ($access['tenders']) {
    $recentTenderCandidates = dashboardSafeAll(
        $conn,
        "SELECT p.id, p.code, p.tender_code, p.project_title, p.project_client, p.project_city, p.project_country,
                p.project_importance, p.progress, p.project_status, p.tender_due, p.tender_received_date,
                p.updated_at, p.created_at
         FROM project_info_table p
         WHERE p.record_status = 'active'
         ORDER BY COALESCE(p.updated_at, p.created_at) DESC, p.id DESC"
    );
    $recentTenders = array_slice(dynabaseTenderAnalyticsFilterRows($recentTenderCandidates, $selectedTenderYear), 0, 6);
    foreach ($recentTenders as &$tender) {
        $tender['id'] = (int) ($tender['id'] ?? 0);
        $tender['code'] = isset($tender['code']) ? (int) $tender['code'] : null;
    }
    unset($tender);
}

$recentClients = [];
if ($access['clients']) {
    $recentClients = dashboardSafeAll(
        $conn,
        "SELECT id, clients_name, clients_category, clients_hq_location, clients_email, status, updated_at, created_at
         FROM clients_table
         WHERE status = 'active'
         ORDER BY COALESCE(updated_at, created_at) DESC, id DESC
         LIMIT 5"
    );
    foreach ($recentClients as &$client) {
        $client['id'] = (int) ($client['id'] ?? 0);
    }
    unset($client);
}

$recentSubmissions = [];
if ($access['submission_register'] && dashboardTableExists($conn, 'submission_registers')) {
    $recentSubmissions = dashboardSafeAll(
        $conn,
        "SELECT id, submission_reference, project_company_name, client_name, category,
                date_submitted, mode_of_submission, status, updated_at, created_at
         FROM submission_registers
         WHERE record_status = 'active'
         ORDER BY COALESCE(updated_at, created_at) DESC, id DESC
         LIMIT 5"
    );
    foreach ($recentSubmissions as &$submission) {
        $submission['id'] = (int) ($submission['id'] ?? 0);
    }
    unset($submission);
}

$recentActivity = [];
if ($access['audit'] && dashboardTableExists($conn, 'audit_logs')) {
    $recentActivity = dashboardSafeAll(
        $conn,
        "SELECT a.id, a.action, a.entity_type, a.entity_id, a.created_at,
                u.first_name, u.last_name, u.email
         FROM audit_logs a
         LEFT JOIN users u ON u.id = a.actor_user_id
         WHERE a.action NOT LIKE 'auth.%'
         ORDER BY a.created_at DESC, a.id DESC
         LIMIT 8"
    );
    foreach ($recentActivity as &$activity) {
        $activity['id'] = (int) ($activity['id'] ?? 0);
        $activity['actor_name'] = trim((string) ($activity['first_name'] ?? '') . ' ' . (string) ($activity['last_name'] ?? ''));
        unset($activity['first_name'], $activity['last_name']);
    }
    unset($activity);
}

jsonResponse([
    'status' => 'Success',
    'message' => 'Dashboard overview retrieved successfully.',
    'data' => [
        'generated_at' => date(DATE_ATOM),
        'current_year' => $currentYear,
        'access' => $access,
        'selected_tender_year' => $access['tenders'] && $selectedTenderYear > 0 ? $selectedTenderYear : 'all',
        'available_tender_years' => $access['tenders'] ? ($tenderIntelligence['available_years'] ?? []) : [],
        'summary' => [
            'clients' => (int) ($clientSummary['active'] ?? 0),
            'all_clients' => (int) ($clientSummary['total'] ?? 0),
            'keypersons' => (int) ($keypersonSummary['active'] ?? 0),
            'all_keypersons' => (int) ($keypersonSummary['total'] ?? 0),
            'tenders' => (int) ($tenderSummary['total'] ?? 0),
            'open_tenders' => (int) ($tenderSummary['open_tenders'] ?? 0),
            'awarded_tenders' => (int) ($tenderSummary['awarded'] ?? 0),
            'high_priority_tenders' => (int) ($tenderSummary['high_priority'] ?? 0),
            'due_soon' => (int) ($tenderSummary['due_soon'] ?? 0),
            'users' => (int) ($userSummary['active'] ?? 0),
            'pending_users' => (int) ($userSummary['pending'] ?? 0),
            'pms_admins' => (int) ($userSummary['pms_admins'] ?? 0),
            'documents' => (int) ($documentSummary['active'] ?? 0),
            'recent_documents' => (int) ($documentSummary['recent'] ?? 0),
            'submissions' => (int) ($submissionSummary['total'] ?? 0),
            'ongoing_submissions' => (int) ($submissionSummary['ongoing'] ?? 0),
            'completed_submissions' => (int) ($submissionSummary['completed'] ?? 0),
            'recent_submissions' => (int) ($submissionSummary['recent'] ?? 0),
            'prequalifications' => (int) ($prequalificationSummary['active'] ?? 0),
            'linked_prequalifications' => (int) ($prequalificationSummary['linked'] ?? 0),
            'survey_responses' => (int) ($surveySummary['responses'] ?? 0),
            'survey_pending_review' => (int) ($surveySummary['pending_review'] ?? 0),
            'survey_average_score' => (float) ($surveySummary['average_score'] ?? 0),
            'active_survey_links' => (int) ($surveySummary['active_links'] ?? 0),
            'influence_records' => (int) ($influenceSummary['mapped'] ?? 0),
            'high_influence' => (int) ($influenceSummary['high_influence'] ?? 0),
            'recommenders' => (int) ($influenceSummary['recommenders'] ?? 0),
            'influence_logs' => (int) ($influenceSummary['logs'] ?? 0),
            'gift_recipients' => (int) ($giftSummary['recipients'] ?? 0),
            'gift_lists' => (int) ($giftSummary['lists'] ?? 0),
            'gift_clients' => (int) ($giftSummary['clients'] ?? 0),
        ],
        'tender_intelligence' => $access['tenders'] ? $tenderIntelligence : dynabaseTenderAnalyticsEmptyOverview($selectedTenderYear),
        'pipeline' => [
            'total' => $pipelineTotal,
            'stages' => $pipelineStages,
            'trend' => $access['tenders'] ? ($tenderIntelligence['trend'] ?? []) : [],
            'selected_year' => $access['tenders'] && $selectedTenderYear > 0 ? $selectedTenderYear : 'all',
        ],
        'recent_tenders' => $recentTenders,
        'recent_clients' => $recentClients,
        'recent_submissions' => $recentSubmissions,
        'recent_activity' => $recentActivity,
    ],
]);
