<?php
declare(strict_types=1);

require_once __DIR__ . '/dbHelpers.php';
require_once __DIR__ . '/xlsxReport.php';
require_once __DIR__ . '/tenderAnalytics.php';

function dynabaseReportTableExists(mysqli $conn, string $table): bool
{
    $row = dbFetchOne(
        $conn,
        'SELECT COUNT(*) AS total FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
        's',
        [$table]
    );
    return (int) ($row['total'] ?? 0) > 0;
}

function dynabaseReportColumnExists(mysqli $conn, string $table, string $column): bool
{
    $row = dbFetchOne(
        $conn,
        'SELECT COUNT(*) AS total FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        'ss',
        [$table, $column]
    );
    return (int) ($row['total'] ?? 0) > 0;
}

function dynabaseReportCleanLabel(mixed $value, string $fallback = 'Not specified'): string
{
    $label = trim((string) $value);
    return $label !== '' ? $label : $fallback;
}

function dynabaseReportYearFilter(mixed $value): int
{
    $raw = trim((string) $value);
    if ($raw === '' || strtolower($raw) === 'all') {
        return 0;
    }
    $year = (int) $raw;
    if ($year < 2000 || $year > ((int) date('Y') + 1)) {
        throw new RuntimeException('Please choose a valid report year.', 422);
    }
    return $year;
}

function dynabaseReportAvailableGiftYears(mysqli $conn): array
{
    if (!dynabaseReportTableExists($conn, 'gift_lists')) return [];
    $rows = dbFetchAll($conn, 'SELECT DISTINCT gift_year FROM gift_lists ORDER BY gift_year DESC');
    return array_values(array_map(static fn (array $row): int => (int) $row['gift_year'], $rows));
}

function dynabaseReportRateCategory(mixed $rate): string
{
    $rate = strtoupper(trim((string) $rate));
    if ($rate === '') return 'Unrated';
    $letter = substr($rate, 0, 1);
    return in_array($letter, ['A', 'B', 'C', 'D'], true) ? $letter : 'Unrated';
}

function dynabaseReportRateStyle(string $rateOrCategory): int
{
    return match (dynabaseReportRateCategory($rateOrCategory)) {
        'A' => DYNABASE_XLSX_STYLE_RATE_A,
        'B' => DYNABASE_XLSX_STYLE_RATE_B,
        'C' => DYNABASE_XLSX_STYLE_RATE_C,
        'D' => DYNABASE_XLSX_STYLE_RATE_D,
        default => DYNABASE_XLSX_STYLE_CENTER,
    };
}

function dynabaseReportCell(mixed $value, int $style = DYNABASE_XLSX_STYLE_BODY, ?string $type = null): array
{
    $cell = ['value' => $value, 'style' => $style];
    if ($type !== null) $cell['type'] = $type;
    return $cell;
}

function dynabaseReportTitleRows(string $title, string $subtitle, int $columns): array
{
    return [
        [dynabaseReportCell($title, DYNABASE_XLSX_STYLE_TITLE)],
        [dynabaseReportCell($subtitle, DYNABASE_XLSX_STYLE_SUBTITLE)],
        [],
    ];
}

function dynabaseReportHeaderRow(array $headers): array
{
    return array_map(static fn (string $header): array => dynabaseReportCell($header, DYNABASE_XLSX_STYLE_HEADER), $headers);
}

function dynabaseReportGiftRows(mysqli $conn, int $year = 0): array
{
    if (!dynabaseReportTableExists($conn, 'gift_lists') || !dynabaseReportTableExists($conn, 'gift_list_items')) {
        return [];
    }

    $where = " WHERE gli.gift_decision = 'selected' AND gli.gift_rate IS NOT NULL";
    $types = '';
    $params = [];
    if ($year > 0) {
        $where .= ' AND gl.gift_year = ?';
        $types = 'i';
        $params[] = $year;
    }

    return dbFetchAll(
        $conn,
        "SELECT gl.gift_year,
                gl.owner_pms_admin_id,
                TRIM(CONCAT(COALESCE(owner.first_name, ''), ' ', COALESCE(owner.last_name, ''))) AS owner_name,
                owner.email AS owner_email,
                gli.client_id,
                COALESCE(NULLIF(TRIM(c.clients_name), ''), gli.client_name_snapshot) AS client_name,
                COALESCE(NULLIF(TRIM(c.clients_hq_location), ''), 'Not specified') AS client_location,
                COALESCE(NULLIF(TRIM(c.clients_category), ''), 'Not specified') AS client_category,
                gli.keyperson_id,
                COALESCE(NULLIF(TRIM(k.key_person), ''), gli.keyperson_name_snapshot) AS keyperson_name,
                COALESCE(NULLIF(TRIM(k.title), ''), 'Not specified') AS keyperson_title,
                COALESCE(k.key_persons_email, '') AS keyperson_email,
                COALESCE(k.key_persons_tel, '') AS keyperson_phone,
                gli.gift_rate,
                COALESCE(gli.notes, '') AS notes,
                gli.source,
                gli.is_verified,
                gli.updated_at
         FROM gift_list_items gli
         INNER JOIN gift_lists gl ON gl.id = gli.gift_list_id
         INNER JOIN users owner ON owner.id = gl.owner_pms_admin_id
         LEFT JOIN clients_table c ON c.id = gli.client_id
         LEFT JOIN keypersons_table k ON k.id = gli.keyperson_id
         {$where}
         ORDER BY gl.gift_year DESC, client_location ASC, owner_name ASC, client_name ASC, keyperson_name ASC",
        $types,
        $params
    );
}

function dynabaseReportGiftAnalytics(array $rows): array
{
    $exactRates = ['A+' => 0, 'A' => 0, 'B+' => 0, 'B' => 0, 'C+' => 0, 'C' => 0, 'D+' => 0, 'D' => 0, 'Unrated' => 0];
    $consolidated = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'Unrated' => 0];
    $locations = [];
    $years = [];
    $clients = [];
    $owners = [];

    foreach ($rows as $row) {
        $rate = strtoupper(trim((string) ($row['gift_rate'] ?? '')));
        if (!array_key_exists($rate, $exactRates)) $rate = 'Unrated';
        $category = dynabaseReportRateCategory($rate);
        $exactRates[$rate]++;
        $consolidated[$category] = ($consolidated[$category] ?? 0) + 1;

        $location = dynabaseReportCleanLabel($row['client_location'] ?? null);
        if (!isset($locations[$location])) {
            $locations[$location] = ['location' => $location, 'A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'Unrated' => 0, 'total' => 0];
        }
        $locations[$location][$category] = ($locations[$location][$category] ?? 0) + 1;
        $locations[$location]['total']++;

        $year = (int) ($row['gift_year'] ?? 0);
        if ($year > 0) {
            if (!isset($years[$year])) $years[$year] = ['year' => $year, 'A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'Unrated' => 0, 'total' => 0];
            $years[$year][$category] = ($years[$year][$category] ?? 0) + 1;
            $years[$year]['total']++;
        }

        $clientId = (int) ($row['client_id'] ?? 0);
        if ($clientId > 0) $clients[$clientId] = true;
        $ownerId = (int) ($row['owner_pms_admin_id'] ?? 0);
        if ($ownerId > 0) $owners[$ownerId] = true;
    }

    uasort($locations, static fn (array $a, array $b): int => $b['total'] <=> $a['total'] ?: strcasecmp($a['location'], $b['location']));
    krsort($years);
    $total = count($rows);

    $locationList = [];
    foreach ($locations as $location) {
        $location['share'] = $total > 0 ? $location['total'] / $total : 0;
        $locationList[] = $location;
    }
    $yearList = [];
    foreach ($years as $year) {
        $year['share'] = $total > 0 ? $year['total'] / $total : 0;
        $yearList[] = $year;
    }

    return [
        'total_recipients' => $total,
        'total_clients' => count($clients),
        'total_locations' => count($locations),
        'total_owners' => count($owners),
        'exact_rates' => $exactRates,
        'consolidated_rates' => $consolidated,
        'locations' => $locationList,
        'years' => $yearList,
    ];
}

function dynabaseReportGiftWorkbook(array $rows, int $year = 0): array
{
    $analytics = dynabaseReportGiftAnalytics($rows);
    $period = $year > 0 ? (string) $year : 'All years';
    $generated = date('d M Y, h:i A');

    $headers = [
        'S/N', 'Gift Year', 'PMS Owner', 'Client', 'Client Location', 'Client Category',
        'Key Person', 'Role / Title', 'Email', 'Phone', 'Exact Gift Rate', 'Category',
        'Notes', 'Source', 'Verified', 'Last Updated'
    ];
    $dataRows = dynabaseReportTitleRows(
        'Annual Gift List Report',
        "Reporting period: {$period}  •  Generated {$generated}  •  Selected recipients only",
        count($headers)
    );
    $dataRows[] = dynabaseReportHeaderRow($headers);

    foreach ($rows as $index => $row) {
        $baseStyle = $index % 2 === 0 ? DYNABASE_XLSX_STYLE_BODY : DYNABASE_XLSX_STYLE_BODY_ALT;
        $category = dynabaseReportRateCategory($row['gift_rate'] ?? '');
        $dataRows[] = [
            dynabaseReportCell($index + 1, DYNABASE_XLSX_STYLE_CENTER, 'number'),
            dynabaseReportCell((int) $row['gift_year'], DYNABASE_XLSX_STYLE_CENTER, 'number'),
            dynabaseReportCell(dynabaseReportCleanLabel($row['owner_name'] ?? null), $baseStyle),
            dynabaseReportCell(dynabaseReportCleanLabel($row['client_name'] ?? null), $baseStyle),
            dynabaseReportCell(dynabaseReportCleanLabel($row['client_location'] ?? null), $baseStyle),
            dynabaseReportCell(dynabaseReportCleanLabel($row['client_category'] ?? null), $baseStyle),
            dynabaseReportCell(dynabaseReportCleanLabel($row['keyperson_name'] ?? null), $baseStyle),
            dynabaseReportCell(dynabaseReportCleanLabel($row['keyperson_title'] ?? null), $baseStyle),
            dynabaseReportCell((string) ($row['keyperson_email'] ?? ''), $baseStyle),
            dynabaseReportCell((string) ($row['keyperson_phone'] ?? ''), $baseStyle),
            dynabaseReportCell((string) ($row['gift_rate'] ?? ''), dynabaseReportRateStyle((string) ($row['gift_rate'] ?? ''))),
            dynabaseReportCell($category, dynabaseReportRateStyle($category)),
            dynabaseReportCell((string) ($row['notes'] ?? ''), DYNABASE_XLSX_STYLE_WRAP),
            dynabaseReportCell(ucwords(str_replace('_', ' ', (string) ($row['source'] ?? 'live'))), $baseStyle),
            dynabaseReportCell((int) ($row['is_verified'] ?? 0) === 1 ? 'Yes' : 'No', DYNABASE_XLSX_STYLE_CENTER),
            dynabaseReportCell($row['updated_at'] ?? null, DYNABASE_XLSX_STYLE_DATE, 'date'),
        ];
    }

    if ($rows === []) {
        $dataRows[] = [dynabaseReportCell('No gift-list recipients were found for the selected period.', DYNABASE_XLSX_STYLE_MUTED)];
    }

    $summaryRows = dynabaseReportTitleRows(
        'Gift Rate Summary',
        "Consolidated A+/A, B+/B, C+/C and D+/D categories for {$period}",
        11
    );
    $summaryRows[] = [
        dynabaseReportCell('Consolidated Category', DYNABASE_XLSX_STYLE_SECTION),
        dynabaseReportCell('Count', DYNABASE_XLSX_STYLE_SECTION),
        dynabaseReportCell('Share', DYNABASE_XLSX_STYLE_SECTION),
        null,
        dynabaseReportCell('Exact Rate', DYNABASE_XLSX_STYLE_SECTION),
        dynabaseReportCell('Count', DYNABASE_XLSX_STYLE_SECTION),
        dynabaseReportCell('Share', DYNABASE_XLSX_STYLE_SECTION),
        null,
        dynabaseReportCell('Year', DYNABASE_XLSX_STYLE_SECTION),
        dynabaseReportCell('Recipients', DYNABASE_XLSX_STYLE_SECTION),
        dynabaseReportCell('Share', DYNABASE_XLSX_STYLE_SECTION),
    ];

    $categoryKeys = ['A', 'B', 'C', 'D', 'Unrated'];
    $exactKeys = ['A+', 'A', 'B+', 'B', 'C+', 'C', 'D+', 'D'];
    $yearRows = $analytics['years'];
    $summaryLines = max(count($exactKeys), count($yearRows), count($categoryKeys));
    for ($index = 0; $index < $summaryLines; $index++) {
        $category = $categoryKeys[$index] ?? null;
        $exact = $exactKeys[$index] ?? null;
        $yearEntry = $yearRows[$index] ?? null;
        $row = [];
        if ($category !== null) {
            $count = (int) ($analytics['consolidated_rates'][$category] ?? 0);
            $row[] = dynabaseReportCell($category, dynabaseReportRateStyle($category));
            $row[] = dynabaseReportCell($count, DYNABASE_XLSX_STYLE_NUMBER, 'number');
            $row[] = dynabaseReportCell($analytics['total_recipients'] > 0 ? $count / $analytics['total_recipients'] : 0, DYNABASE_XLSX_STYLE_PERCENT, 'number');
        } else {
            $row = array_merge($row, [null, null, null]);
        }
        $row[] = null;
        if ($exact !== null) {
            $count = (int) ($analytics['exact_rates'][$exact] ?? 0);
            $row[] = dynabaseReportCell($exact, dynabaseReportRateStyle($exact));
            $row[] = dynabaseReportCell($count, DYNABASE_XLSX_STYLE_NUMBER, 'number');
            $row[] = dynabaseReportCell($analytics['total_recipients'] > 0 ? $count / $analytics['total_recipients'] : 0, DYNABASE_XLSX_STYLE_PERCENT, 'number');
        } else {
            $row = array_merge($row, [null, null, null]);
        }
        $row[] = null;
        if ($yearEntry !== null) {
            $row[] = dynabaseReportCell((int) $yearEntry['year'], DYNABASE_XLSX_STYLE_CENTER, 'number');
            $row[] = dynabaseReportCell((int) $yearEntry['total'], DYNABASE_XLSX_STYLE_NUMBER, 'number');
            $row[] = dynabaseReportCell((float) $yearEntry['share'], DYNABASE_XLSX_STYLE_PERCENT, 'number');
        } else {
            $row = array_merge($row, [null, null, null]);
        }
        $summaryRows[] = $row;
    }
    $summaryRows[] = [];
    $summaryRows[] = [
        dynabaseReportCell('Total recipients', DYNABASE_XLSX_STYLE_KPI_LABEL),
        dynabaseReportCell($analytics['total_recipients'], DYNABASE_XLSX_STYLE_KPI_VALUE, 'number'),
        null,
        dynabaseReportCell('Distinct clients', DYNABASE_XLSX_STYLE_KPI_LABEL),
        dynabaseReportCell($analytics['total_clients'], DYNABASE_XLSX_STYLE_KPI_VALUE, 'number'),
        null,
        dynabaseReportCell('Locations', DYNABASE_XLSX_STYLE_KPI_LABEL),
        dynabaseReportCell($analytics['total_locations'], DYNABASE_XLSX_STYLE_KPI_VALUE, 'number'),
        null,
        dynabaseReportCell('PMS owners', DYNABASE_XLSX_STYLE_KPI_LABEL),
        dynabaseReportCell($analytics['total_owners'], DYNABASE_XLSX_STYLE_KPI_VALUE, 'number'),
    ];

    $locationHeaders = ['Location', 'A', 'B', 'C', 'D', 'Unrated', 'Total', 'Share'];
    $locationRows = dynabaseReportTitleRows(
        'Gift List by Location',
        "Location distribution and detailed recipient register for {$period}",
        10
    );
    $locationRows[] = dynabaseReportHeaderRow($locationHeaders);
    foreach ($analytics['locations'] as $location) {
        $locationRows[] = [
            dynabaseReportCell($location['location'], DYNABASE_XLSX_STYLE_LOCATION),
            dynabaseReportCell((int) $location['A'], DYNABASE_XLSX_STYLE_RATE_A, 'number'),
            dynabaseReportCell((int) $location['B'], DYNABASE_XLSX_STYLE_RATE_B, 'number'),
            dynabaseReportCell((int) $location['C'], DYNABASE_XLSX_STYLE_RATE_C, 'number'),
            dynabaseReportCell((int) $location['D'], DYNABASE_XLSX_STYLE_RATE_D, 'number'),
            dynabaseReportCell((int) $location['Unrated'], DYNABASE_XLSX_STYLE_CENTER, 'number'),
            dynabaseReportCell((int) $location['total'], DYNABASE_XLSX_STYLE_TOTAL, 'number'),
            dynabaseReportCell((float) $location['share'], DYNABASE_XLSX_STYLE_PERCENT, 'number'),
        ];
    }
    $detailHeaderRow = count($locationRows) + 2;
    $locationRows[] = [];
    $locationRows[] = [dynabaseReportCell('Detailed Location Register', DYNABASE_XLSX_STYLE_SECTION)];
    $locationRows[] = dynabaseReportHeaderRow(['S/N', 'Year', 'Location', 'PMS Owner', 'Client', 'Key Person', 'Exact Rate', 'Category', 'Notes']);
    foreach ($rows as $index => $row) {
        $category = dynabaseReportRateCategory($row['gift_rate'] ?? '');
        $locationRows[] = [
            dynabaseReportCell($index + 1, DYNABASE_XLSX_STYLE_CENTER, 'number'),
            dynabaseReportCell((int) $row['gift_year'], DYNABASE_XLSX_STYLE_CENTER, 'number'),
            dynabaseReportCell(dynabaseReportCleanLabel($row['client_location'] ?? null), DYNABASE_XLSX_STYLE_BODY),
            dynabaseReportCell(dynabaseReportCleanLabel($row['owner_name'] ?? null), DYNABASE_XLSX_STYLE_BODY),
            dynabaseReportCell(dynabaseReportCleanLabel($row['client_name'] ?? null), DYNABASE_XLSX_STYLE_BODY),
            dynabaseReportCell(dynabaseReportCleanLabel($row['keyperson_name'] ?? null), DYNABASE_XLSX_STYLE_BODY),
            dynabaseReportCell((string) ($row['gift_rate'] ?? ''), dynabaseReportRateStyle((string) ($row['gift_rate'] ?? ''))),
            dynabaseReportCell($category, dynabaseReportRateStyle($category)),
            dynabaseReportCell((string) ($row['notes'] ?? ''), DYNABASE_XLSX_STYLE_WRAP),
        ];
    }

    return [
        [
            'name' => 'All Gift Data',
            'rows' => $dataRows,
            'merges' => ['A1:P1', 'A2:P2'],
            'widths' => [7, 11, 23, 28, 20, 20, 25, 22, 28, 18, 15, 12, 34, 18, 11, 18],
            'rowHeights' => [1 => 34, 2 => 22, 4 => 34],
            'freezeRows' => 4,
            'autoFilter' => 'A4:P' . max(4, count($dataRows)),
            'landscape' => true,
        ],
        [
            'name' => 'Rate Summary',
            'rows' => $summaryRows,
            'merges' => ['A1:K1', 'A2:K2'],
            'widths' => [24, 13, 13, 3, 16, 13, 13, 3, 13, 15, 13],
            'rowHeights' => [1 => 34, 2 => 22, 4 => 28],
            'freezeRows' => 4,
            'landscape' => true,
        ],
        [
            'name' => 'Location Analysis',
            'rows' => $locationRows,
            'merges' => ['A1:I1', 'A2:I2', 'A' . $detailHeaderRow . ':I' . $detailHeaderRow],
            'widths' => [25, 11, 11, 11, 11, 11, 12, 13, 34],
            'rowHeights' => [1 => 34, 2 => 22, 4 => 28],
            'freezeRows' => 4,
            'landscape' => true,
        ],
    ];
}

function dynabaseReportDataSheet(
    string $title,
    string $subtitle,
    array $headers,
    array $data,
    array $widths,
    array $columnConfig = []
): array {
    $rows = dynabaseReportTitleRows($title, $subtitle, count($headers));
    $rows[] = dynabaseReportHeaderRow($headers);
    foreach ($data as $rowIndex => $record) {
        $baseStyle = $rowIndex % 2 === 0 ? DYNABASE_XLSX_STYLE_BODY : DYNABASE_XLSX_STYLE_BODY_ALT;
        $line = [];
        foreach ($columnConfig as $config) {
            $key = $config['key'];
            $value = is_callable($config['value'] ?? null) ? $config['value']($record) : ($record[$key] ?? '');
            $style = $config['style'] ?? $baseStyle;
            if (is_callable($style)) $style = $style($value, $record);
            $line[] = dynabaseReportCell($value, (int) $style, $config['type'] ?? null);
        }
        $rows[] = $line;
    }
    if ($data === []) $rows[] = [dynabaseReportCell('No records were found for this report.', DYNABASE_XLSX_STYLE_MUTED)];
    $columns = count($headers);
    return [
        'rows' => $rows,
        'merges' => ['A1:' . dynabaseXlsxColumnLetter($columns) . '1', 'A2:' . dynabaseXlsxColumnLetter($columns) . '2'],
        'widths' => $widths,
        'rowHeights' => [1 => 34, 2 => 22, 4 => 32],
        'freezeRows' => 4,
        'autoFilter' => 'A4:' . dynabaseXlsxColumnLetter($columns) . max(4, count($rows)),
        'landscape' => true,
    ];
}

function dynabaseReportSummarySheet(string $title, string $subtitle, array $tables): array
{
    $maxColumns = 8;
    $rows = dynabaseReportTitleRows($title, $subtitle, $maxColumns);
    foreach ($tables as $tableIndex => $table) {
        if ($tableIndex > 0) $rows[] = [];
        $rows[] = [dynabaseReportCell($table['title'], DYNABASE_XLSX_STYLE_SECTION)];
        $rows[] = dynabaseReportHeaderRow($table['headers']);
        foreach ($table['rows'] as $line) {
            $styled = [];
            foreach ($line as $index => $value) {
                $style = $index === 0 ? DYNABASE_XLSX_STYLE_BODY : DYNABASE_XLSX_STYLE_NUMBER;
                $type = is_numeric($value) && $index > 0 ? 'number' : null;
                $styled[] = dynabaseReportCell($value, $style, $type);
            }
            $rows[] = $styled;
        }
    }
    return [
        'rows' => $rows,
        'merges' => ['A1:H1', 'A2:H2'],
        'widths' => [30, 16, 16, 16, 16, 16, 16, 16],
        'rowHeights' => [1 => 34, 2 => 22],
        'freezeRows' => 3,
        'landscape' => true,
    ];
}

function dynabaseReportCountBy(array $rows, callable $selector): array
{
    $counts = [];
    foreach ($rows as $row) {
        $label = dynabaseReportCleanLabel($selector($row));
        $counts[$label] = ($counts[$label] ?? 0) + 1;
    }
    arsort($counts);
    return array_map(static fn (string $label, int $count): array => [$label, $count], array_keys($counts), array_values($counts));
}

function dynabaseReportTenderWorkbook(mysqli $conn, int $year = 0): array
{
    $rows = dbFetchAll(
        $conn,
        "SELECT p.*, TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS owner_name
         FROM project_info_table p
         LEFT JOIN users u ON u.id = p.owner_pms_admin_id
         WHERE p.record_status = 'active'
         ORDER BY p.updated_at DESC, p.project_title ASC"
    );
    $rows = dynabaseTenderAnalyticsFilterRows($rows, $year);
    $period = $year > 0 ? (string) $year : 'All years';
    $headers = ['S/N', 'Tender Code', 'Project Title', 'Client', 'Key Person', 'Division', 'Country', 'City', 'Importance', 'Status', 'Progress', 'Received', 'Due', 'Submitted', 'Currency', 'Amount', 'PMS Owner', 'Last Updated'];
    $columns = [
        ['key' => 'id', 'value' => static fn (array $r): int => 0, 'style' => DYNABASE_XLSX_STYLE_CENTER, 'type' => 'number'],
        ['key' => 'tender_code'], ['key' => 'project_title'], ['key' => 'project_client'], ['key' => 'keyperson'], ['key' => 'division'],
        ['key' => 'project_country'], ['key' => 'project_city'], ['key' => 'project_importance'], ['key' => 'project_status'], ['key' => 'progress'],
        ['key' => 'tender_received_date'], ['key' => 'tender_due'], ['key' => 'tender_submission_date'], ['key' => 'currency'], ['key' => 'tender_amount'],
        ['key' => 'owner_name'], ['key' => 'updated_at', 'style' => DYNABASE_XLSX_STYLE_DATE, 'type' => 'date'],
    ];
    foreach ($rows as $index => $_) $rows[$index]['id'] = $index + 1;
    $dataSheet = dynabaseReportDataSheet(
        'Tender Portfolio Report',
        "Reporting period: {$period} • Complete active tender register • Generated " . date('d M Y, h:i A'),
        $headers,
        $rows,
        [7, 20, 38, 26, 24, 22, 15, 18, 14, 15, 15, 14, 14, 14, 12, 18, 22, 18],
        $columns
    );
    $dataSheet['name'] = 'Tender Register';
    $summary = dynabaseReportSummarySheet('Tender Portfolio Summary', "Status, progress, country and division distribution for {$period}", [
        ['title' => 'By Tender Status', 'headers' => ['Status', 'Count'], 'rows' => dynabaseReportCountBy($rows, static fn (array $r) => $r['project_status'] ?? '')],
        ['title' => 'By Progress', 'headers' => ['Progress', 'Count'], 'rows' => dynabaseReportCountBy($rows, static fn (array $r) => $r['progress'] ?? '')],
        ['title' => 'By Country', 'headers' => ['Country', 'Count'], 'rows' => dynabaseReportCountBy($rows, static fn (array $r) => $r['project_country'] ?? '')],
        ['title' => 'By Division', 'headers' => ['Division', 'Count'], 'rows' => dynabaseReportCountBy($rows, static fn (array $r) => $r['division'] ?? '')],
    ]);
    $summary['name'] = 'Portfolio Summary';
    return [$dataSheet, $summary];
}

function dynabaseReportClientWorkbook(mysqli $conn): array
{
    $rows = dbFetchAll(
        $conn,
        "SELECT c.*, TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS owner_name,
                (SELECT COUNT(*) FROM keypersons_table k WHERE k.clients_id = c.id AND k.status = 'active') AS keyperson_count
         FROM clients_table c
         LEFT JOIN users u ON u.id = c.owner_pms_admin_id
         ORDER BY c.status ASC, c.clients_name ASC"
    );
    foreach ($rows as $index => $_) $rows[$index]['serial'] = $index + 1;
    $headers = ['S/N', 'Client', 'Category', 'HQ Location', 'Email', 'Website', 'Address', 'PMS Owner', 'Key Persons', 'Status', 'Created', 'Last Updated'];
    $columns = [
        ['key' => 'serial', 'style' => DYNABASE_XLSX_STYLE_CENTER, 'type' => 'number'], ['key' => 'clients_name'], ['key' => 'clients_category'], ['key' => 'clients_hq_location'],
        ['key' => 'clients_email'], ['key' => 'clients_website'], ['key' => 'clients_address', 'style' => DYNABASE_XLSX_STYLE_WRAP], ['key' => 'owner_name'],
        ['key' => 'keyperson_count', 'style' => DYNABASE_XLSX_STYLE_NUMBER, 'type' => 'number'], ['key' => 'status'],
        ['key' => 'created_at', 'style' => DYNABASE_XLSX_STYLE_DATE, 'type' => 'date'], ['key' => 'updated_at', 'style' => DYNABASE_XLSX_STYLE_DATE, 'type' => 'date'],
    ];
    $dataSheet = dynabaseReportDataSheet('Client Directory Report', 'Complete client register with ownership and key-person reach', $headers, $rows, [7, 32, 22, 20, 28, 28, 42, 22, 13, 12, 16, 16], $columns);
    $dataSheet['name'] = 'Client Directory';
    $summary = dynabaseReportSummarySheet('Client Portfolio Summary', 'Client distribution across categories, locations and PMS ownership', [
        ['title' => 'By Category', 'headers' => ['Category', 'Count'], 'rows' => dynabaseReportCountBy($rows, static fn (array $r) => $r['clients_category'] ?? '')],
        ['title' => 'By Location', 'headers' => ['Location', 'Count'], 'rows' => dynabaseReportCountBy($rows, static fn (array $r) => $r['clients_hq_location'] ?? '')],
        ['title' => 'By PMS Owner', 'headers' => ['PMS Owner', 'Count'], 'rows' => dynabaseReportCountBy($rows, static fn (array $r) => $r['owner_name'] ?? '')],
        ['title' => 'By Status', 'headers' => ['Status', 'Count'], 'rows' => dynabaseReportCountBy($rows, static fn (array $r) => $r['status'] ?? '')],
    ]);
    $summary['name'] = 'Client Summary';
    return [$dataSheet, $summary];
}

function dynabaseReportKeypersonWorkbook(mysqli $conn): array
{
    $rows = dbFetchAll(
        $conn,
        "SELECT k.*, TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS owner_name,
                (SELECT COUNT(*) FROM gift_list_items gli WHERE gli.keyperson_id = k.id AND gli.gift_decision = 'selected') AS gift_list_count
         FROM keypersons_table k
         LEFT JOIN users u ON u.id = k.owner_pms_admin_id
         ORDER BY k.status ASC, k.clients_name ASC, k.key_person ASC"
    );
    foreach ($rows as $index => $_) $rows[$index]['serial'] = $index + 1;
    $headers = ['S/N', 'Key Person', 'Role / Title', 'Client', 'Client Category', 'Location', 'Email', 'Phone', 'PMS Owner', 'Gift Lists', 'Status', 'Last Updated'];
    $columns = [
        ['key' => 'serial', 'style' => DYNABASE_XLSX_STYLE_CENTER, 'type' => 'number'], ['key' => 'key_person'], ['key' => 'title'], ['key' => 'clients_name'],
        ['key' => 'clients_category'], ['key' => 'clients_hq_location'], ['key' => 'key_persons_email'], ['key' => 'key_persons_tel'], ['key' => 'owner_name'],
        ['key' => 'gift_list_count', 'style' => DYNABASE_XLSX_STYLE_NUMBER, 'type' => 'number'], ['key' => 'status'], ['key' => 'updated_at', 'style' => DYNABASE_XLSX_STYLE_DATE, 'type' => 'date'],
    ];
    $dataSheet = dynabaseReportDataSheet('Key Person Network Report', 'Relationship contact register with client, ownership and gift-list participation', $headers, $rows, [7, 28, 24, 30, 22, 20, 30, 18, 22, 13, 12, 16], $columns);
    $dataSheet['name'] = 'Key Person Directory';
    $summary = dynabaseReportSummarySheet('Key Person Network Summary', 'Relationship contacts grouped by client category, location, role and ownership', [
        ['title' => 'By Client Category', 'headers' => ['Category', 'Count'], 'rows' => dynabaseReportCountBy($rows, static fn (array $r) => $r['clients_category'] ?? '')],
        ['title' => 'By Location', 'headers' => ['Location', 'Count'], 'rows' => dynabaseReportCountBy($rows, static fn (array $r) => $r['clients_hq_location'] ?? '')],
        ['title' => 'By Role / Title', 'headers' => ['Role / Title', 'Count'], 'rows' => dynabaseReportCountBy($rows, static fn (array $r) => $r['title'] ?? '')],
        ['title' => 'By PMS Owner', 'headers' => ['PMS Owner', 'Count'], 'rows' => dynabaseReportCountBy($rows, static fn (array $r) => $r['owner_name'] ?? '')],
    ]);
    $summary['name'] = 'Network Summary';
    return [$dataSheet, $summary];
}

function dynabaseReportOwnershipWorkbook(mysqli $conn): array
{
    $rows = dbFetchAll(
        $conn,
        "SELECT u.id,
                TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS owner_name,
                u.email,
                u.role,
                u.is_pms_admin,
                u.status,
                (SELECT COUNT(*) FROM clients_table c WHERE c.owner_pms_admin_id = u.id AND c.status = 'active') AS active_clients,
                (SELECT COUNT(*) FROM keypersons_table k WHERE k.owner_pms_admin_id = u.id AND k.status = 'active') AS active_keypersons,
                (SELECT COUNT(*) FROM gift_lists gl WHERE gl.owner_pms_admin_id = u.id) AS gift_lists,
                (SELECT COUNT(*) FROM gift_list_items gli INNER JOIN gift_lists gl2 ON gl2.id = gli.gift_list_id WHERE gl2.owner_pms_admin_id = u.id AND gli.gift_decision = 'selected') AS gift_recipients,
                (SELECT COUNT(*) FROM users child WHERE child.parent_pms_admin_id = u.id AND child.status = 'active') AS team_members
         FROM users u
         WHERE u.role = 'pms_admin' OR u.is_pms_admin = 1
         ORDER BY owner_name ASC"
    );
    foreach ($rows as $index => $_) $rows[$index]['serial'] = $index + 1;
    $headers = ['S/N', 'PMS Owner', 'Email', 'Workspace Role', 'PMS Enabled', 'Active Clients', 'Active Key Persons', 'Gift Lists', 'Gift Recipients', 'Team Members', 'Status'];
    $columns = [
        ['key' => 'serial', 'style' => DYNABASE_XLSX_STYLE_CENTER, 'type' => 'number'], ['key' => 'owner_name'], ['key' => 'email'], ['key' => 'role'],
        ['key' => 'is_pms_admin', 'value' => static fn (array $r): string => (int) $r['is_pms_admin'] === 1 ? 'Yes' : 'No', 'style' => DYNABASE_XLSX_STYLE_CENTER],
        ['key' => 'active_clients', 'style' => DYNABASE_XLSX_STYLE_NUMBER, 'type' => 'number'],
        ['key' => 'active_keypersons', 'style' => DYNABASE_XLSX_STYLE_NUMBER, 'type' => 'number'],
        ['key' => 'gift_lists', 'style' => DYNABASE_XLSX_STYLE_NUMBER, 'type' => 'number'],
        ['key' => 'gift_recipients', 'style' => DYNABASE_XLSX_STYLE_NUMBER, 'type' => 'number'],
        ['key' => 'team_members', 'style' => DYNABASE_XLSX_STYLE_NUMBER, 'type' => 'number'], ['key' => 'status'],
    ];
    $dataSheet = dynabaseReportDataSheet('PMS Ownership Report', 'Ownership coverage across clients, key persons, gift lists and assigned users', $headers, $rows, [7, 25, 30, 16, 14, 15, 18, 13, 17, 14, 12], $columns);
    $dataSheet['name'] = 'Ownership Summary';
    return [$dataSheet];
}

function dynabaseReportSurveyWorkbook(mysqli $conn): array
{
    if (!dynabaseReportTableExists($conn, 'clients_survey_form')) return [];
    $hasScore = dynabaseReportColumnExists($conn, 'clients_survey_form', 'overall_score');
    $hasStatus = dynabaseReportColumnExists($conn, 'clients_survey_form', 'response_status');
    $scoreSelect = $hasScore ? 's.overall_score' : '0 AS overall_score';
    $statusSelect = $hasStatus ? 's.response_status' : "'submitted' AS response_status";
    $deletedWhere = dynabaseReportColumnExists($conn, 'clients_survey_form', 'deleted_at') ? ' WHERE s.deleted_at IS NULL' : '';
    $rows = dbFetchAll(
        $conn,
        "SELECT s.id, s.company, s.project_title, s.filled_by, s.position, s.email, s.location,
                s.quality, s.timeline, s.expertise, s.communication, s.resolution, s.cleaniness, s.safety, s.response,
                {$scoreSelect}, {$statusSelect}, s.createdAt, s.updatedAt
         FROM clients_survey_form s {$deletedWhere}
         ORDER BY s.createdAt DESC"
    );
    foreach ($rows as $index => $_) $rows[$index]['serial'] = $index + 1;
    $headers = ['S/N', 'Company', 'Project', 'Respondent', 'Position', 'Email', 'Location', 'Quality', 'Timeline', 'Expertise', 'Communication', 'Resolution', 'Cleanliness', 'Safety', 'Complaint Response', 'Overall Score', 'Status', 'Submitted'];
    $columns = [
        ['key' => 'serial', 'style' => DYNABASE_XLSX_STYLE_CENTER, 'type' => 'number'], ['key' => 'company'], ['key' => 'project_title'], ['key' => 'filled_by'], ['key' => 'position'], ['key' => 'email'], ['key' => 'location'],
        ['key' => 'quality'], ['key' => 'timeline'], ['key' => 'expertise'], ['key' => 'communication'], ['key' => 'resolution'], ['key' => 'cleaniness'], ['key' => 'safety'], ['key' => 'response'],
        ['key' => 'overall_score', 'style' => DYNABASE_XLSX_STYLE_NUMBER, 'type' => 'number'], ['key' => 'response_status'], ['key' => 'createdAt', 'style' => DYNABASE_XLSX_STYLE_DATE, 'type' => 'date'],
    ];
    $dataSheet = dynabaseReportDataSheet('Client Survey Performance Report', 'Client satisfaction responses and delivery performance scores', $headers, $rows, [7, 28, 34, 22, 20, 28, 20, 14, 14, 14, 16, 14, 14, 14, 18, 14, 14, 17], $columns);
    $dataSheet['name'] = 'Survey Responses';
    $summary = dynabaseReportSummarySheet('Survey Performance Summary', 'Responses grouped by company, response status and quality rating', [
        ['title' => 'By Company', 'headers' => ['Company', 'Responses'], 'rows' => dynabaseReportCountBy($rows, static fn (array $r) => $r['company'] ?? '')],
        ['title' => 'By Response Status', 'headers' => ['Status', 'Responses'], 'rows' => dynabaseReportCountBy($rows, static fn (array $r) => $r['response_status'] ?? '')],
        ['title' => 'By Quality Rating', 'headers' => ['Rating', 'Responses'], 'rows' => dynabaseReportCountBy($rows, static fn (array $r) => $r['quality'] ?? '')],
    ]);
    $summary['name'] = 'Survey Summary';
    return [$dataSheet, $summary];
}

function dynabaseReportPrequalificationWorkbook(mysqli $conn): array
{
    if (!dynabaseReportTableExists($conn, 'prequalification_table')) return [];
    $statusWhere = dynabaseReportColumnExists($conn, 'prequalification_table', 'record_status') ? " WHERE p.record_status = 'active'" : '';
    $ownerSelect = dynabaseReportColumnExists($conn, 'prequalification_table', 'owner_pms_admin_id')
        ? "TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS owner_name"
        : "'' AS owner_name";
    $ownerJoin = dynabaseReportColumnExists($conn, 'prequalification_table', 'owner_pms_admin_id') ? ' LEFT JOIN users u ON u.id = p.owner_pms_admin_id' : '';
    $rows = dbFetchAll(
        $conn,
        "SELECT p.*, {$ownerSelect} FROM prequalification_table p {$ownerJoin} {$statusWhere} ORDER BY p.updated_at DESC, p.clients_name ASC"
    );
    foreach ($rows as $index => $_) $rows[$index]['serial'] = $index + 1;
    $headers = ['S/N', 'Company', 'Representative', 'Role / Title', 'Email', 'Phone', 'Website', 'Prospective Project', 'Budget', 'Services Required', 'Next Steps / Remarks', 'PMS Owner', 'Last Updated'];
    $columns = [
        ['key' => 'serial', 'style' => DYNABASE_XLSX_STYLE_CENTER, 'type' => 'number'], ['key' => 'clients_name'], ['key' => 'key_person'], ['key' => 'title'], ['key' => 'clients_email'], ['key' => 'clients_phone'], ['key' => 'clients_website'],
        ['key' => 'prospective_project', 'style' => DYNABASE_XLSX_STYLE_WRAP], ['key' => 'budget'], ['key' => 'services', 'style' => DYNABASE_XLSX_STYLE_WRAP], ['key' => 'remarks', 'style' => DYNABASE_XLSX_STYLE_WRAP], ['key' => 'owner_name'], ['key' => 'updated_at', 'style' => DYNABASE_XLSX_STYLE_DATE, 'type' => 'date'],
    ];
    $dataSheet = dynabaseReportDataSheet('Prequalification Readiness Report', 'Client readiness, prospective projects, budgets and required services', $headers, $rows, [7, 30, 24, 20, 28, 18, 28, 38, 18, 38, 38, 22, 17], $columns);
    $dataSheet['name'] = 'Prequalification Register';
    $summary = dynabaseReportSummarySheet('Prequalification Summary', 'Checklist distribution by PMS ownership and budget availability', [
        ['title' => 'By PMS Owner', 'headers' => ['PMS Owner', 'Checklists'], 'rows' => dynabaseReportCountBy($rows, static fn (array $r) => $r['owner_name'] ?? '')],
        ['title' => 'Budget Availability', 'headers' => ['Budget Status', 'Checklists'], 'rows' => dynabaseReportCountBy($rows, static fn (array $r) => trim((string) ($r['budget'] ?? '')) !== '' ? 'Budget recorded' : 'Budget not recorded')],
    ]);
    $summary['name'] = 'Readiness Summary';
    return [$dataSheet, $summary];
}

function dynabaseReportDocumentWorkbook(mysqli $conn): array
{
    if (!dynabaseReportTableExists($conn, 'document_table')) return [];
    $statusWhere = dynabaseReportColumnExists($conn, 'document_table', 'status') ? " WHERE d.status = 'active'" : '';
    $relationship = dynabaseReportColumnExists($conn, 'document_table', 'relationship_type') ? 'd.relationship_type' : "'general' AS relationship_type";
    $fileSize = dynabaseReportColumnExists($conn, 'document_table', 'file_size') ? 'd.file_size' : '0 AS file_size';
    $version = dynabaseReportColumnExists($conn, 'document_table', 'version_no') ? 'd.version_no' : '1 AS version_no';
    $extension = dynabaseReportColumnExists($conn, 'document_table', 'file_extension') ? 'd.file_extension' : "LOWER(SUBSTRING_INDEX(d.document, '.', -1)) AS file_extension";
    $rows = dbFetchAll(
        $conn,
        "SELECT d.id, d.document_title, d.document_type, d.document_category, d.presentation_code, d.updated_content,
                {$relationship}, {$fileSize}, {$version}, {$extension}, d.created_by, d.updated_by, d.created_at, d.updated_at
         FROM document_table d {$statusWhere}
         ORDER BY d.updated_at DESC, d.document_title ASC"
    );
    foreach ($rows as $index => $_) {
        $rows[$index]['serial'] = $index + 1;
        $rows[$index]['file_size_label'] = (int) $rows[$index]['file_size'] > 0 ? round(((int) $rows[$index]['file_size']) / 1048576, 2) : 0;
    }
    $headers = ['S/N', 'Document', 'Type', 'Category', 'Reference Code', 'Relationship', 'Format', 'Size (MB)', 'Version', 'Update Summary', 'Created By', 'Updated By', 'Created', 'Last Updated'];
    $columns = [
        ['key' => 'serial', 'style' => DYNABASE_XLSX_STYLE_CENTER, 'type' => 'number'], ['key' => 'document_title'], ['key' => 'document_type'], ['key' => 'document_category'], ['key' => 'presentation_code'], ['key' => 'relationship_type'], ['key' => 'file_extension'],
        ['key' => 'file_size_label', 'style' => DYNABASE_XLSX_STYLE_NUMBER, 'type' => 'number'], ['key' => 'version_no', 'style' => DYNABASE_XLSX_STYLE_NUMBER, 'type' => 'number'], ['key' => 'updated_content', 'style' => DYNABASE_XLSX_STYLE_WRAP], ['key' => 'created_by'], ['key' => 'updated_by'], ['key' => 'created_at', 'style' => DYNABASE_XLSX_STYLE_DATE, 'type' => 'date'], ['key' => 'updated_at', 'style' => DYNABASE_XLSX_STYLE_DATE, 'type' => 'date'],
    ];
    $dataSheet = dynabaseReportDataSheet('Document Library Report', 'Document register with category, relationship, format and version metadata', $headers, $rows, [7, 34, 18, 24, 22, 18, 12, 13, 10, 34, 20, 20, 16, 16], $columns);
    $dataSheet['name'] = 'Document Register';
    $summary = dynabaseReportSummarySheet('Document Library Summary', 'Library distribution by document type, category, relationship and format', [
        ['title' => 'By Document Type', 'headers' => ['Type', 'Documents'], 'rows' => dynabaseReportCountBy($rows, static fn (array $r) => $r['document_type'] ?? '')],
        ['title' => 'By Category', 'headers' => ['Category', 'Documents'], 'rows' => dynabaseReportCountBy($rows, static fn (array $r) => $r['document_category'] ?? '')],
        ['title' => 'By Relationship', 'headers' => ['Relationship', 'Documents'], 'rows' => dynabaseReportCountBy($rows, static fn (array $r) => $r['relationship_type'] ?? '')],
        ['title' => 'By Format', 'headers' => ['Format', 'Documents'], 'rows' => dynabaseReportCountBy($rows, static fn (array $r) => $r['file_extension'] ?? '')],
    ]);
    $summary['name'] = 'Document Summary';
    return [$dataSheet, $summary];
}

function dynabaseReportCatalog(mysqli $conn): array
{
    $count = static function (string $sql) use ($conn): int {
        try { return dbScalarInt($conn, $sql); } catch (Throwable) { return 0; }
    };
    $surveyWhere = dynabaseReportColumnExists($conn, 'clients_survey_form', 'deleted_at') ? ' WHERE deleted_at IS NULL' : '';
    $documentWhere = dynabaseReportColumnExists($conn, 'document_table', 'status') ? " WHERE status = 'active'" : '';
    $prequalWhere = dynabaseReportColumnExists($conn, 'prequalification_table', 'record_status') ? " WHERE record_status = 'active'" : '';

    return [
        [
            'key' => 'gift-lists', 'title' => 'Annual Gift List',
            'description' => 'Multi-sheet workbook with recipient data, consolidated A–D counts and location analysis.',
            'record_count' => $count("SELECT COUNT(*) AS total FROM gift_list_items WHERE gift_decision = 'selected'"),
            'year_filter' => true, 'sheets' => 3,
        ],
        [
            'key' => 'tenders', 'title' => 'Tender Portfolio',
            'description' => 'Tender register with status, progress, country and division summaries.',
            'record_count' => $count("SELECT COUNT(*) AS total FROM project_info_table WHERE record_status = 'active'"),
            'year_filter' => true, 'sheets' => 2,
        ],
        [
            'key' => 'clients', 'title' => 'Client Directory',
            'description' => 'Client ownership, location, category, status and key-person reach.',
            'record_count' => $count('SELECT COUNT(*) AS total FROM clients_table'),
            'year_filter' => false, 'sheets' => 2,
        ],
        [
            'key' => 'keypersons', 'title' => 'Key Person Network',
            'description' => 'Relationship contacts grouped by client, location, role and PMS ownership.',
            'record_count' => $count('SELECT COUNT(*) AS total FROM keypersons_table'),
            'year_filter' => false, 'sheets' => 2,
        ],
        [
            'key' => 'pms-ownership', 'title' => 'PMS Ownership',
            'description' => 'Portfolio coverage across PMS owners, teams, clients, contacts and gift recipients.',
            'record_count' => $count("SELECT COUNT(*) AS total FROM users WHERE role = 'pms_admin' OR is_pms_admin = 1"),
            'year_filter' => false, 'sheets' => 1,
        ],
        [
            'key' => 'client-surveys', 'title' => 'Client Survey Performance',
            'description' => 'Survey response register and satisfaction-performance summaries.',
            'record_count' => dynabaseReportTableExists($conn, 'clients_survey_form') ? $count('SELECT COUNT(*) AS total FROM clients_survey_form' . $surveyWhere) : 0,
            'year_filter' => false, 'sheets' => 2,
        ],
        [
            'key' => 'prequalifications', 'title' => 'Prequalification Readiness',
            'description' => 'Client readiness, prospective projects, budgets and required services.',
            'record_count' => dynabaseReportTableExists($conn, 'prequalification_table') ? $count('SELECT COUNT(*) AS total FROM prequalification_table' . $prequalWhere) : 0,
            'year_filter' => false, 'sheets' => 2,
        ],
        [
            'key' => 'documents', 'title' => 'Document Library',
            'description' => 'Document type, category, relationship, file format and version reporting.',
            'record_count' => dynabaseReportTableExists($conn, 'document_table') ? $count('SELECT COUNT(*) AS total FROM document_table' . $documentWhere) : 0,
            'year_filter' => false, 'sheets' => 2,
        ],
    ];
}

function dynabaseReportOverview(mysqli $conn, int $giftYear = 0): array
{
    $giftRows = dynabaseReportGiftRows($conn, $giftYear);
    return [
        'available_years' => dynabaseReportAvailableGiftYears($conn),
        'selected_year' => $giftYear > 0 ? $giftYear : 'all',
        'catalog' => dynabaseReportCatalog($conn),
        'gift_list' => dynabaseReportGiftAnalytics($giftRows),
        'generated_at' => date(DATE_ATOM),
    ];
}

function dynabaseReportWorkbook(mysqli $conn, string $type, int $giftYear = 0): array
{
    return match ($type) {
        'gift-lists' => dynabaseReportGiftWorkbook(dynabaseReportGiftRows($conn, $giftYear), $giftYear),
        'tenders' => dynabaseReportTenderWorkbook($conn, $giftYear),
        'clients' => dynabaseReportClientWorkbook($conn),
        'keypersons' => dynabaseReportKeypersonWorkbook($conn),
        'pms-ownership' => dynabaseReportOwnershipWorkbook($conn),
        'client-surveys' => dynabaseReportSurveyWorkbook($conn),
        'prequalifications' => dynabaseReportPrequalificationWorkbook($conn),
        'documents' => dynabaseReportDocumentWorkbook($conn),
        default => throw new RuntimeException('Unknown report type.', 404),
    };
}

function dynabaseReportFilename(string $type, int $giftYear = 0): string
{
    $date = date('Y-m-d');
    return match ($type) {
        'gift-lists' => 'dynabase-gift-list-report-' . ($giftYear > 0 ? $giftYear : 'all-years') . '-' . $date . '.xlsx',
        'tenders' => 'dynabase-tender-portfolio-' . ($giftYear > 0 ? $giftYear : 'all-years') . '-' . $date . '.xlsx',
        'clients' => 'dynabase-client-directory-' . $date . '.xlsx',
        'keypersons' => 'dynabase-key-person-network-' . $date . '.xlsx',
        'pms-ownership' => 'dynabase-pms-ownership-' . $date . '.xlsx',
        'client-surveys' => 'dynabase-client-survey-performance-' . $date . '.xlsx',
        'prequalifications' => 'dynabase-prequalification-readiness-' . $date . '.xlsx',
        'documents' => 'dynabase-document-library-' . $date . '.xlsx',
        default => 'dynabase-report-' . $date . '.xlsx',
    };
}
