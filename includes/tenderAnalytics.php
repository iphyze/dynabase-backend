<?php
declare(strict_types=1);

require_once __DIR__ . '/dbHelpers.php';

function dynabaseTenderAnalyticsYearFilter(mixed $value): int
{
    $raw = trim((string) $value);
    if ($raw === '' || strtolower($raw) === 'all') {
        return 0;
    }

    $year = (int) $raw;
    $maximumYear = (int) date('Y') + 1;
    if ($year < 2000 || $year > $maximumYear) {
        throw new RuntimeException('Please choose a valid tender year.', 422);
    }

    return $year;
}

/**
 * Parse legacy tender dates without asking MySQL to convert malformed values.
 * The old Dynabase database contains a mixture of ISO, slash-separated and
 * textual dates, so analytics are intentionally normalised in PHP.
 */
function dynabaseTenderAnalyticsParseDate(mixed $value): ?DateTimeImmutable
{
    $raw = trim((string) $value);
    if ($raw === '' || in_array(strtolower($raw), ['n/a', 'na', 'none', 'null', 'not available'], true)) {
        return null;
    }

    $raw = preg_replace('/\s+/', ' ', $raw) ?? $raw;
    $formats = [
        '!Y-m-d',
        '!Y-m-d H:i:s',
        '!Y-m-d H:i',
        '!d/m/Y',
        '!d-m-Y',
        '!d.m.Y',
        '!j/n/Y',
        '!j-n-Y',
        '!m/d/Y',
        '!M j, Y',
        '!j M Y',
        '!F j, Y',
        '!j F Y',
    ];

    foreach ($formats as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $raw);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date !== false && ($errors === false || (($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0))) {
            return $date;
        }
    }

    // Only use strtotime after the strict formats above. It is useful for a few
    // readable legacy values, while the validation below rejects impossible dates.
    $timestamp = strtotime($raw);
    if ($timestamp === false) {
        return null;
    }

    return (new DateTimeImmutable())->setTimestamp($timestamp)->setTime(0, 0);
}

function dynabaseTenderAnalyticsRecordDate(array $row): ?DateTimeImmutable
{
    return dynabaseTenderAnalyticsParseDate($row['tender_received_date'] ?? null)
        ?? dynabaseTenderAnalyticsParseDate($row['created_at'] ?? null);
}

function dynabaseTenderAnalyticsRecordYear(array $row): int
{
    $date = dynabaseTenderAnalyticsRecordDate($row);
    return $date ? (int) $date->format('Y') : 0;
}

function dynabaseTenderAnalyticsLoadRows(mysqli $conn): array
{
    static $cache = [];
    $connectionId = spl_object_id($conn);

    if (array_key_exists($connectionId, $cache)) {
        return $cache[$connectionId];
    }

    $rows = dbFetchAll(
        $conn,
        "SELECT id, project_status, progress, project_importance, tender_due,
                tender_received_date, created_at, updated_at
         FROM project_info_table
         WHERE record_status = 'active'"
    );

    foreach ($rows as &$row) {
        $row['id'] = (int) ($row['id'] ?? 0);
        $row['_analytics_year'] = dynabaseTenderAnalyticsRecordYear($row);
    }
    unset($row);

    return $cache[$connectionId] = $rows;
}

function dynabaseTenderAnalyticsFilterRows(array $rows, int $year): array
{
    if ($year <= 0) {
        return array_values($rows);
    }

    return array_values(array_filter(
        $rows,
        static function (array $row) use ($year): bool {
            $rowYear = isset($row['_analytics_year'])
                ? (int) $row['_analytics_year']
                : dynabaseTenderAnalyticsRecordYear($row);
            return $rowYear === $year;
        }
    ));
}

function dynabaseTenderAnalyticsDateSql(string $alias = 'p'): string
{
    // Retained for compatibility with any older code that still imports this helper.
    // New dashboard and report analytics no longer depend on database-side parsing.
    $prefix = $alias !== '' ? '`' . str_replace('`', '', $alias) . '`.' : '';
    return 'DATE(' . $prefix . '`created_at`)';
}

function dynabaseTenderAnalyticsYearSql(string $alias = 'p'): string
{
    return 'YEAR(' . dynabaseTenderAnalyticsDateSql($alias) . ')';
}

function dynabaseTenderAnalyticsAvailableYears(mysqli $conn): array
{
    $years = [];
    foreach (dynabaseTenderAnalyticsLoadRows($conn) as $row) {
        $year = (int) ($row['_analytics_year'] ?? 0);
        if ($year > 0) {
            $years[$year] = true;
        }
    }

    $result = array_map('intval', array_keys($years));
    rsort($result, SORT_NUMERIC);
    return $result;
}

function dynabaseTenderAnalyticsScope(int $year, string $alias = 'p'): array
{
    // Compatibility helper. Year filtering is now performed in PHP by
    // dynabaseTenderAnalyticsFilterRows() to support mixed legacy date formats.
    $prefix = $alias !== '' ? '`' . str_replace('`', '', $alias) . '`.' : '';
    return ["{$prefix}`record_status` = 'active'", '', []];
}

function dynabaseTenderAnalyticsCanonicalLabel(string $value, string $fallback): string
{
    $clean = trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    if ($clean === '') {
        return $fallback;
    }

    $key = strtolower($clean);
    $known = [
        'approved' => 'Approved',
        'awarded' => 'Awarded',
        'on hold' => 'On Hold',
        'onhold' => 'On Hold',
        'declined' => 'Declined',
        'abortive' => 'Abortive',
        'pending' => 'Pending',
        'in progress' => 'In Progress',
        'inprogress' => 'In Progress',
        'awaiting' => 'Awaiting',
        'submitted' => 'Submitted',
        'lost' => 'Lost',
    ];

    return $known[$key] ?? ucwords($clean);
}

function dynabaseTenderAnalyticsOrderDistribution(array $rows, array $preferredOrder): array
{
    $normalised = [];
    $total = 0;

    foreach ($rows as $row) {
        $label = trim((string) ($row['label'] ?? ''));
        if ($label === '') {
            $label = 'Not specified';
        }
        $count = (int) ($row['total'] ?? 0);
        $total += $count;
        $normalised[] = [
            'label' => $label,
            'total' => $count,
        ];
    }

    $rank = [];
    foreach ($preferredOrder as $index => $label) {
        $rank[strtolower($label)] = $index;
    }

    usort($normalised, static function (array $left, array $right) use ($rank): int {
        $leftRank = $rank[strtolower($left['label'])] ?? 999;
        $rightRank = $rank[strtolower($right['label'])] ?? 999;
        if ($leftRank !== $rightRank) {
            return $leftRank <=> $rightRank;
        }
        if ($left['total'] !== $right['total']) {
            return $right['total'] <=> $left['total'];
        }
        return strcasecmp($left['label'], $right['label']);
    });

    foreach ($normalised as &$item) {
        $item['percentage'] = $total > 0 ? round(($item['total'] / $total) * 100, 1) : 0;
    }
    unset($item);

    return [
        'total' => $total,
        'items' => $normalised,
    ];
}

function dynabaseTenderAnalyticsDistributionFromRows(
    array $rows,
    string $column,
    string $fallback,
    array $preferredOrder
): array {
    if (!in_array($column, ['project_status', 'progress'], true)) {
        throw new InvalidArgumentException('Unsupported tender distribution column.');
    }

    $counts = [];
    foreach ($rows as $row) {
        $label = dynabaseTenderAnalyticsCanonicalLabel((string) ($row[$column] ?? ''), $fallback);
        $counts[$label] = ($counts[$label] ?? 0) + 1;
    }

    $distributionRows = [];
    foreach ($counts as $label => $total) {
        $distributionRows[] = ['label' => $label, 'total' => $total];
    }

    return dynabaseTenderAnalyticsOrderDistribution($distributionRows, $preferredOrder);
}

function dynabaseTenderAnalyticsDistribution(mysqli $conn, int $year, string $column, string $fallback, array $preferredOrder): array
{
    $rows = dynabaseTenderAnalyticsFilterRows(dynabaseTenderAnalyticsLoadRows($conn), $year);
    return dynabaseTenderAnalyticsDistributionFromRows($rows, $column, $fallback, $preferredOrder);
}

function dynabaseTenderAnalyticsSummaryFromRows(array $rows): array
{
    $summary = [
        'total' => count($rows),
        'open_tenders' => 0,
        'awarded' => 0,
        'on_hold' => 0,
        'declined' => 0,
        'abortive' => 0,
        'high_priority' => 0,
        'due_soon' => 0,
    ];

    $today = new DateTimeImmutable('today');
    $dueLimit = $today->modify('+30 days');

    foreach ($rows as $row) {
        $progress = strtolower(trim((string) ($row['progress'] ?? '')));
        $status = strtolower(trim((string) ($row['project_status'] ?? '')));
        $importance = strtolower(trim((string) ($row['project_importance'] ?? '')));

        if (!in_array($progress, ['declined', 'awarded', 'lost'], true)
            && !in_array($status, ['declined', 'abortive'], true)) {
            $summary['open_tenders']++;
        }
        if ($progress === 'awarded' || in_array($status, ['approved', 'awarded'], true)) {
            $summary['awarded']++;
        }
        if (in_array($status, ['on hold', 'onhold'], true)) {
            $summary['on_hold']++;
        }
        if (in_array($progress, ['declined', 'lost'], true) || $status === 'declined') {
            $summary['declined']++;
        }
        if ($status === 'abortive') {
            $summary['abortive']++;
        }
        if ($importance === 'high') {
            $summary['high_priority']++;
        }

        $dueDate = dynabaseTenderAnalyticsParseDate($row['tender_due'] ?? null);
        if ($dueDate && $dueDate >= $today && $dueDate <= $dueLimit) {
            $summary['due_soon']++;
        }
    }

    return $summary;
}

function dynabaseTenderAnalyticsSummary(mysqli $conn, int $year): array
{
    return dynabaseTenderAnalyticsSummaryFromRows(
        dynabaseTenderAnalyticsFilterRows(dynabaseTenderAnalyticsLoadRows($conn), $year)
    );
}

function dynabaseTenderAnalyticsTrendFromRows(array $rows, int $year, array $availableYears = []): array
{
    if ($year > 0) {
        $counts = array_fill(1, 12, 0);
        foreach ($rows as $row) {
            $date = dynabaseTenderAnalyticsRecordDate($row);
            if ($date) {
                $month = (int) $date->format('n');
                $counts[$month] = ($counts[$month] ?? 0) + 1;
            }
        }

        $trend = [];
        for ($month = 1; $month <= 12; $month++) {
            $date = DateTimeImmutable::createFromFormat('!m', (string) $month);
            $trend[] = [
                'period' => $month,
                'label' => $date ? $date->format('M') : (string) $month,
                'total' => $counts[$month] ?? 0,
            ];
        }
        return $trend;
    }

    $counts = [];
    foreach ($rows as $row) {
        $recordYear = isset($row['_analytics_year'])
            ? (int) $row['_analytics_year']
            : dynabaseTenderAnalyticsRecordYear($row);
        if ($recordYear > 0) {
            $counts[$recordYear] = ($counts[$recordYear] ?? 0) + 1;
        }
    }

    $years = $availableYears;
    sort($years, SORT_NUMERIC);
    return array_map(static fn (int $item): array => [
        'period' => $item,
        'label' => (string) $item,
        'total' => $counts[$item] ?? 0,
    ], $years);
}

function dynabaseTenderAnalyticsTrend(mysqli $conn, int $year, array $availableYears = []): array
{
    $allRows = dynabaseTenderAnalyticsLoadRows($conn);
    $rows = dynabaseTenderAnalyticsFilterRows($allRows, $year);
    return dynabaseTenderAnalyticsTrendFromRows($rows, $year, $availableYears);
}

function dynabaseTenderAnalyticsEmptyOverview(int $year = 0): array
{
    return [
        'selected_year' => $year > 0 ? $year : 'all',
        'period_label' => $year > 0 ? (string) $year : 'All years',
        'available_years' => [],
        'summary' => [
            'total' => 0,
            'open_tenders' => 0,
            'awarded' => 0,
            'on_hold' => 0,
            'declined' => 0,
            'abortive' => 0,
            'high_priority' => 0,
            'due_soon' => 0,
        ],
        'statuses' => [],
        'progress' => [],
        'trend' => [],
    ];
}

function dynabaseTenderAnalyticsOverview(mysqli $conn, int $year): array
{
    $allRows = dynabaseTenderAnalyticsLoadRows($conn);
    $availableYears = [];
    foreach ($allRows as $row) {
        $rowYear = (int) ($row['_analytics_year'] ?? 0);
        if ($rowYear > 0) {
            $availableYears[$rowYear] = true;
        }
    }
    $availableYears = array_map('intval', array_keys($availableYears));
    rsort($availableYears, SORT_NUMERIC);

    $rows = dynabaseTenderAnalyticsFilterRows($allRows, $year);
    $status = dynabaseTenderAnalyticsDistributionFromRows(
        $rows,
        'project_status',
        'Not specified',
        ['Approved', 'Awarded', 'On Hold', 'Declined', 'Abortive', 'Not specified']
    );
    $progress = dynabaseTenderAnalyticsDistributionFromRows(
        $rows,
        'progress',
        'Pending',
        ['Pending', 'In Progress', 'Awaiting', 'Submitted', 'Awarded', 'Declined', 'Lost']
    );

    return [
        'selected_year' => $year > 0 ? $year : 'all',
        'period_label' => $year > 0 ? (string) $year : 'All years',
        'available_years' => $availableYears,
        'summary' => dynabaseTenderAnalyticsSummaryFromRows($rows),
        'statuses' => $status['items'],
        'progress' => $progress['items'],
        'trend' => dynabaseTenderAnalyticsTrendFromRows($rows, $year, $availableYears),
    ];
}
