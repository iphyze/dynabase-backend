<?php
declare(strict_types=1);
/**
 * Narrow, server-owned, read-only Dynabase AI lookups.
 * No user-supplied SQL, IDs, table names, or model-generated query arguments.
 * All queries use the same authenticated user and scope/permission policies as the app.
 */
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/giftLists.php';
require_once __DIR__ . '/surveyAi.php';

function aiChatReadIntent(string $message): ?string
{
    $message = trim($message);
    // Only explicit record questions trigger a database read; ordinary advice and
    // uploaded-file analysis continue through the existing general chat path.
    if (preg_match('/\b(gift[ -]?lists?|annual gift|pms gift)\b/iu', $message)
        && preg_match('/\b(started|saved|submitted|completed|status|begun|begin|already|checked|check|has|have|did|yet)\b/iu', $message)) {
        return 'gift_list_start';
    }
    if (preg_match('/\b(client[ -]?surveys?|survey links?|survey invitations?|feedback responses?)\b/iu', $message)
        && preg_match('/\b(how many|number|count|created|links?|responses?|feedback|summari[sz]e|summary|analyse|analyze|statistics|average|score)\b/iu', $message)) {
        return 'survey_links_overview';
    }
    return null;
}

function aiChatGiftLookupName(string $message): ?string
{
    // Explicit patterns only: never let an LLM invent the account to query.
    $patterns = [
        '/\b(?:has|have|did|is)\s+([\p{L}][\p{L}\s\x{2019}\x{0027}.-]{1,75}?)\s+(?:already\s+)?(?:started|saved|submitted|completed|begun)\b/iu',
        '/\b(?:check|verify)\s+(?:whether\s+|if\s+)?([\p{L}][\p{L}\s\x{2019}\x{0027}.-]{1,75}?)\s+(?:has\s+)?(?:started|saved|submitted|completed)\b/iu',
        '/\bgift[ -]?list(?:\s+status)?\s+(?:for|of)\s+([\p{L}][\p{L}\s\x{2019}\x{0027}.-]{1,75}?)(?:\s+(?:in|for|during)\s+20\d{2}|\s*\?|\s*$)/iu',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $message, $matches)) {
            $name = trim(preg_replace('/\s+/u', ' ', (string) $matches[1]) ?? '');
            if (count(preg_split('/\s+/u', $name) ?: []) <= 5 && strlen($name) <= 75) return $name;
        }
    }
    return null;
}

function aiChatReadGiftStart(mysqli $conn, array $user, string $message): array
{
    if (!userHasPermission($conn, $user, 'gift_lists.view')) {
        return ['reply' => 'Your account does not have permission to view Gift Lists. Ask your administrator for Gift Lists view access.'];
    }
    $name = aiChatGiftLookupName($message);
    if ($name === null) {
        return ['reply' => 'Which PMS owner should I check? For example: “Has Tope Oloidi started his gift list for 2026?”'];
    }
    if (!preg_match('/\b(20\d{2})\b/', $message, $yearMatch)) {
        return ['reply' => 'Which gift-list year should I check for '.$name.'? Please include the year.'];
    }
    $year = (int) $yearMatch[1];
    if ($year < 2000 || $year > ((int) gmdate('Y') + 1)) {
        return ['reply' => 'Please provide a valid gift-list year.'];
    }
    $fixedOwner = giftListFixedOwnerId($user);
    if (!isGlobalDataUser($user) && ($fixedOwner === null || $fixedOwner <= 0)) {
        return ['reply' => 'Your account is not assigned to a PMS owner and cannot look up gift-list records.'];
    }
    $tokens = preg_split('/\s+/u', trim($name)) ?: [];
    $like = '%' . implode('%', array_map(static fn (string $t): string => str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $t), $tokens)) . '%';
    $scope = '';
    if (!isGlobalDataUser($user)) {
        $scope = ' AND u.id = ?';
    }
    $rows = dbFetchAll($conn,
        "SELECT u.id, u.first_name, u.last_name, gl.id AS gift_list_id, gl.updated_at AS list_updated_at,
                act.first_started_at, act.first_source,
                (SELECT COUNT(*) FROM gift_list_items gi
                 WHERE gi.gift_list_id = gl.id AND gi.gift_decision = 'selected') AS selected_recipients
         FROM users u
         LEFT JOIN gift_lists gl ON gl.owner_pms_admin_id = u.id AND gl.gift_year = ?
         LEFT JOIN gift_list_pms_activity act ON act.owner_pms_admin_id = u.id AND act.gift_year = ?
         WHERE u.status = 'active' AND (u.role = 'pms_admin' OR u.is_pms_admin = 1)
           AND CONCAT_WS(' ', u.first_name, u.last_name) LIKE ? {$scope}
         LIMIT 6",
        // Keep SQL placeholder positions consistent with the supplied values.
        'iis' . (isGlobalDataUser($user) ? '' : 'i'),
        array_merge([$year, $year, $like], isGlobalDataUser($user) ? [] : [$fixedOwner])
    );
    if (!$rows) return ['reply' => 'I could not find a matching PMS owner within the gift-list records your account is allowed to view. Please verify the name.'];
    if (count($rows) > 1) return ['reply' => 'More than one accessible PMS owner matches that name. Please provide the full, unique name.'];
    $row = $rows[0];
    $displayName = trim((string)$row['first_name'].' '.(string)$row['last_name']);
    $listExists = $row['gift_list_id'] !== null;
    $started = $row['first_started_at'] !== null;
    $reply = $started
        ? "Yes. {$displayName}'s {$year} gift list was started by their PMS team. The first saved activity was recorded on {$row['first_started_at']} (server time)."
        : "No PMS-team start has been recorded for {$displayName}'s {$year} gift list.";
    $reply .= $listExists
        ? " There is a gift-list record with ".(int)$row['selected_recipients']." selected recipient(s)." 
        : ' There is currently no gift-list record for that owner and year.';
    if (!$started) $reply .= ' An administrator may have prepared a list without the PMS team starting it.';
    return ['reply' => $reply, 'source' => 'gift_list_pms_activity', 'scope' => 'gift_lists.view'];
}

/**
 * Exact link/response counts are always computed by SQL, not by the model.
 * A larger response corpus is sampled transparently, never described as exhaustive.
 */
function aiChatReadSurveyOverview(mysqli $conn, array $user, string $message): array
{
    if (!userHasPermission($conn, $user, 'client_surveys.view')) {
        return ['reply' => 'Your account does not have permission to view Client Surveys. Ask your administrator for Client Surveys view access.'];
    }
    // A view grant is necessary, but does not override PMS ownership scope.
    $owner = isGlobalDataUser($user) ? null : giftListFixedOwnerId($user);
    if (!isGlobalDataUser($user) && ($owner === null || $owner <= 0)) {
        return ['reply' => 'Your account is not assigned to a PMS owner, so no Client Survey records are available in your scope.'];
    }
    $types = $owner !== null ? 'i' : '';
    $params = $owner !== null ? [$owner] : [];
    $linkWhere = $owner !== null ? ' WHERE owner_pms_admin_id=?' : '';
    $linkedJoin = $owner !== null
        ? ' INNER JOIN client_survey_invitations i ON i.id=s.invitation_id AND i.owner_pms_admin_id=?'
        : '';
    $unlinkedWhere = $owner !== null
        ? ' AND s.owner_pms_admin_id=?' : '';
    $totalLinks = dbScalarInt($conn, 'SELECT COUNT(*) AS total FROM client_survey_invitations'.$linkWhere, $types, $params);
    $totalLinkedResponses = dbScalarInt($conn,
        'SELECT COUNT(*) AS total FROM clients_survey_form s'.$linkedJoin.' WHERE s.invitation_id IS NOT NULL AND s.deleted_at IS NULL',
        $types, $params);
    $standaloneResponses = dbScalarInt($conn,
        'SELECT COUNT(*) AS total FROM clients_survey_form s WHERE s.invitation_id IS NULL AND s.deleted_at IS NULL'.$unlinkedWhere,
        $types, $params);
    $intro = "Among your accessible Dynabase records, there are {$totalLinks} currently stored client survey link(s), {$totalLinkedResponses} non-deleted linked response(s), and {$standaloneResponses} standalone/historical response(s) without invitations.";
    $wantsSummary = (bool) preg_match('/\b(summari[sz]e|summary|analyse|analyze|strengths|weaknesses|insights|recommendations|average|score)\b/iu', $message);
    if (!$wantsSummary) return ['reply' => $intro, 'source' => 'client_survey_invitations'];
    if (!userHasPermission($conn, $user, 'client_surveys.ai.summarize')) {
        return ['reply' => $intro.' Your account has survey-view access but not the Generate AI survey summaries permission.'];
    }
    if ($totalLinkedResponses === 0) return ['reply' => $intro.' There are no linked responses available to summarize.'];
    $sampleLimit = 25;
    $rows = dbFetchAll($conn,
        'SELECT s.* FROM clients_survey_form s'.$linkedJoin.' WHERE s.invitation_id IS NOT NULL AND s.deleted_at IS NULL ORDER BY s.createdAt DESC, s.id DESC LIMIT 25',
        $types, $params);
    $facts = array_map('surveyAiResponseFacts', $rows);
    // Keep model context bounded without dropping the exact SQL-derived counts.
    // Direct contact fields are removed by surveyAiResponseFacts().
    foreach ($facts as &$fact) {
        if (!empty($fact['feedback']) && is_array($fact['feedback'])) {
            $fact['feedback'] = array_map(
                static fn($v): string => function_exists('mb_substr') ? mb_substr((string)$v, 0, 140) : substr((string)$v, 0, 140),
                array_slice($fact['feedback'], 0, 6, true)
            );
        }
        if (!empty($fact['improvement_other'])) {
            $fact['improvement_other'] = function_exists('mb_substr')
                ? mb_substr((string)$fact['improvement_other'], 0, 180)
                : substr((string)$fact['improvement_other'], 0, 180);
        }
    }
    unset($fact);
    $statistics = surveyAiDatasetStatistics($facts);
    $scored = dbFetchOne($conn,
        'SELECT COUNT(*) AS rated_count, ROUND(AVG(s.overall_score), 2) AS mean_score
         FROM clients_survey_form s'.$linkedJoin.'
         WHERE s.invitation_id IS NOT NULL AND s.deleted_at IS NULL AND (s.survey_version = 1 OR s.survey_rated_count > 0)',
        $types, $params) ?? [];
    return [
        'context' => [
            'source' => 'authenticated_permission_and_ownership_scoped_dynabase_database',
            'checked_at_utc' => gmdate('c'),
            'current_survey_link_count' => $totalLinks,
            'total_non_deleted_linked_responses' => $totalLinkedResponses,
            'total_non_deleted_unlinked_historical_responses' => $standaloneResponses,
            'linked_responses_with_scores' => (int)($scored['rated_count'] ?? 0),
            'mean_of_stored_linked_response_scores' => $scored['mean_score'] === null ? null : (float)$scored['mean_score'],
            'score_note' => 'Scores reflect historical scoring versions and have not been recalculated.',
            'sample_size' => count($rows),
            'sample_limit' => $sampleLimit,
            'sample_is_complete' => $totalLinkedResponses <= count($rows),
            'sampling_method' => 'Most recent non-deleted linked responses',
            'sample_statistics' => $statistics,
            'sample_response_facts_without_direct_contacts' => $facts,
        ],
        'source' => 'client_surveys.view + client_surveys.ai.summarize',
    ];
}

function aiChatReadContext(mysqli $conn, array $user, string $message): ?array
{
    return match (aiChatReadIntent($message)) {
        'gift_list_start' => aiChatReadGiftStart($conn, $user, $message),
        'survey_links_overview' => aiChatReadSurveyOverview($conn, $user, $message),
        default => null,
    };
}
