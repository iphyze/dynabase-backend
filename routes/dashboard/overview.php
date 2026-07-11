<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../includes/dbHelpers.php';
require_once __DIR__ . '/../../includes/tenderAnalytics.php';

requireMethod('GET');
$authUser = authenticateUser();
requireRole(
    $authUser,
    [DYNABASE_ROLE_SUPER_ADMIN, DYNABASE_ROLE_ADMIN],
    'This dashboard is only available to Super Admins and Admins.'
);

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
try {
    $tenderIntelligence = dynabaseTenderAnalyticsOverview($conn, $selectedTenderYear);
} catch (Throwable $exception) {
    // A malformed legacy tender date or an older database schema must not take
    // down the complete Admin dashboard. Log the private error and return a
    // stable empty analytics payload while the remaining dashboard loads.
    error_log('[Dynabase Dashboard Tender Analytics] ' . $exception->getMessage());
    $tenderIntelligence = dynabaseTenderAnalyticsEmptyOverview($selectedTenderYear);
}

$clientSummary = dashboardSafeOne(
    $conn,
    "SELECT COUNT(*) AS total,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active,
            SUM(CASE WHEN status <> 'active' THEN 1 ELSE 0 END) AS inactive
     FROM clients_table"
);
$keypersonSummary = dashboardSafeOne(
    $conn,
    "SELECT COUNT(*) AS total,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active,
            SUM(CASE WHEN status <> 'active' THEN 1 ELSE 0 END) AS inactive
     FROM keypersons_table"
);
$tenderSummary = $tenderIntelligence['summary'] ?? [];
$userSummary = dashboardSafeOne(
    $conn,
    "SELECT COUNT(*) AS total,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN role = 'pms_admin' OR is_pms_admin = 1 THEN 1 ELSE 0 END) AS pms_admins
     FROM users"
);

$documentSummary = ['active' => 0, 'recent' => 0];
if (dashboardTableExists($conn, 'document_table')) {
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

$prequalificationSummary = ['active' => 0, 'linked' => 0];
if (dashboardTableExists($conn, 'prequalification_table')) {
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
if (dashboardTableExists($conn, 'clients_survey_form')) {
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
}
if (dashboardTableExists($conn, 'client_survey_invitations')) {
    $linkSummary = dashboardSafeOne(
        $conn,
        "SELECT COUNT(*) AS active_links
         FROM client_survey_invitations
         WHERE status = 'active' AND expires_at >= NOW() AND submission_count < max_submissions"
    );
    $surveySummary['active_links'] = (int) ($linkSummary['active_links'] ?? 0);
}

$influenceSummary = ['mapped' => 0, 'high_influence' => 0, 'recommenders' => 0, 'projects' => 0, 'logs' => 0];
if (dashboardTableExists($conn, 'web_of_influence_table')) {
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
if (dashboardTableExists($conn, 'log_table')) {
    $logSummary = dashboardSafeOne($conn, 'SELECT COUNT(*) AS logs FROM log_table');
    $influenceSummary['logs'] = (int) ($logSummary['logs'] ?? 0);
}

$giftSummary = ['recipients' => 0, 'lists' => 0, 'clients' => 0];
if (dashboardTableExists($conn, 'gift_lists') && dashboardTableExists($conn, 'gift_list_items')) {
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

$pipelineStages = $tenderIntelligence['progress'] ?? [];
$pipelineTotal = (int) (($tenderIntelligence['summary']['total'] ?? 0));

$recentTenderCandidates = dashboardSafeAll(
    $conn,
    "SELECT p.id, p.code, p.tender_code, p.project_title, p.project_client, p.project_city, p.project_country,
            p.project_importance, p.progress, p.project_status, p.tender_due, p.tender_received_date,
            p.updated_at, p.created_at
     FROM project_info_table p
     WHERE p.record_status = 'active'
     ORDER BY COALESCE(p.updated_at, p.created_at) DESC, p.id DESC"
);
$recentTenders = array_slice(
    dynabaseTenderAnalyticsFilterRows($recentTenderCandidates, $selectedTenderYear),
    0,
    6
);
foreach ($recentTenders as &$tender) {
    $tender['id'] = (int) ($tender['id'] ?? 0);
    $tender['code'] = isset($tender['code']) ? (int) $tender['code'] : null;
}
unset($tender);

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

$recentActivity = [];
if (dashboardTableExists($conn, 'audit_logs')) {
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
        'selected_tender_year' => $selectedTenderYear > 0 ? $selectedTenderYear : 'all',
        'available_tender_years' => $tenderIntelligence['available_years'] ?? [],
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
        'tender_intelligence' => $tenderIntelligence,
        'pipeline' => [
            'total' => $pipelineTotal,
            'stages' => $pipelineStages,
            'trend' => $tenderIntelligence['trend'] ?? [],
            'selected_year' => $selectedTenderYear > 0 ? $selectedTenderYear : 'all',
        ],
        'recent_tenders' => $recentTenders,
        'recent_clients' => $recentClients,
        'recent_activity' => $recentActivity,
    ],
]);
