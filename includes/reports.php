<?php
declare(strict_types=1);

require_once __DIR__ . '/dbHelpers.php';
require_once __DIR__ . '/xlsxReport.php';
require_once __DIR__ . '/tenderAnalytics.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/ownership.php';

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

function dynabaseReportTableHasColumns(mysqli $conn, string $table, array $columns): bool
{
    if (!dynabaseReportTableExists($conn, $table)) return false;
    foreach ($columns as $column) {
        if (!dynabaseReportColumnExists($conn, $table, (string) $column)) return false;
    }
    return true;
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

function dynabaseReportAccessRules(): array
{
    return [
        'gift-lists' => ['view' => 'gift_lists.view', 'export' => 'gift_lists.export'],
        'tenders' => ['view' => 'tenders.view', 'export' => 'tenders.export'],
        'clients' => ['view' => 'clients.view', 'export' => 'clients.export'],
        'keypersons' => ['view' => 'keypersons.view', 'export' => 'keypersons.export'],
        'pms-ownership' => ['view' => 'users.view', 'export' => 'users.export'],
        'client-surveys' => ['view' => 'client_surveys.view', 'export' => 'client_surveys.export'],
        'prequalifications' => ['view' => 'prequalifications.view', 'export' => 'prequalifications.export'],
        'submission-register' => ['view' => 'submission_register.view', 'export' => 'submission_register.export'],
        'documents' => ['view' => 'documents.view', 'export' => 'documents.export'],
        'agreement-register' => ['view' => 'agreement_register.view', 'export' => 'agreement_register.export'],
    ];
}

function dynabaseReportKnownTypes(): array
{
    return array_keys(dynabaseReportAccessRules());
}

function dynabaseReportAccessForUser(mysqli $conn, array $authUser, string $type, ?array $effectivePermissions = null): array
{
    $rules = dynabaseReportAccessRules();
    if (!isset($rules[$type])) {
        return ['can_view' => false, 'can_export' => false, 'view_permission' => null, 'export_permission' => null];
    }

    $effectivePermissions ??= userEffectivePermissions($conn, $authUser);
    $viewPermission = $rules[$type]['view'];
    $exportPermission = $rules[$type]['export'];
    $canViewReports = in_array('reports.view', $effectivePermissions, true);
    $canExportReports = in_array('reports.export', $effectivePermissions, true);

    return [
        'can_view' => $canViewReports && in_array($viewPermission, $effectivePermissions, true),
        'can_export' => $canExportReports && in_array($exportPermission, $effectivePermissions, true),
        'view_permission' => $viewPermission,
        'export_permission' => $exportPermission,
    ];
}

function dynabaseReportRequireExportAccess(mysqli $conn, array $authUser, string $type): void
{
    $access = dynabaseReportAccessForUser($conn, $authUser, $type);
    if (!$access['can_view']) {
        throw new RuntimeException('You do not have access to the records used by this report.', 403);
    }
    if (!$access['can_export']) {
        throw new RuntimeException('You do not have permission to export this report.', 403);
    }
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

function dynabaseReportSubmissionWorkbook(mysqli $conn, array $authUser): array
{
    if (!dynabaseReportTableHasColumns($conn, 'submission_registers', [
        'id', 'submission_reference', 'project_tender_code', 'project_company_name', 'client_name',
        'category', 'date_received', 'date_submitted', 'mode_of_submission', 'status',
        'owner_pms_admin_id', 'record_status', 'created_by', 'created_at', 'updated_by', 'updated_at',
    ])) return [];

    $scopeSql = '';
    $types = '';
    $params = [];
    if ($authUser !== []) {
        [$scopeSql, $types, $params] = appendScopedWhere($authUser, 'sr');
    }

    $hasUpdates = dynabaseReportTableHasColumns($conn, 'submission_register_updates', [
        'id', 'submission_id', 'message', 'created_by', 'created_by_id', 'created_at', 'updated_at', 'deleted_at',
    ]);
    $updateCountSql = $hasUpdates
        ? "(SELECT COUNT(*) FROM submission_register_updates su WHERE su.submission_id = sr.id AND su.deleted_at IS NULL)"
        : '0';
    $latestUpdateSql = $hasUpdates
        ? "(SELECT su.message FROM submission_register_updates su WHERE su.submission_id = sr.id AND su.deleted_at IS NULL ORDER BY su.created_at DESC, su.id DESC LIMIT 1)"
        : "''";

    $rows = dbFetchAll(
        $conn,
        "SELECT sr.id, sr.submission_reference, sr.project_tender_code, sr.project_company_name,
                sr.client_name, sr.category, sr.date_received, sr.date_submitted,
                DATEDIFF(sr.date_submitted, sr.date_received) AS turnaround_days,
                sr.mode_of_submission, sr.hard_copy_contact_name, sr.hard_copy_contact_email,
                sr.hard_copy_contact_phone, sr.hard_copy_contact_address, sr.email_recipient,
                sr.status, sr.owner_pms_admin_id,
                TRIM(CONCAT(COALESCE(owner.first_name, ''), ' ', COALESCE(owner.last_name, ''))) AS owner_name,
                {$updateCountSql} AS progress_update_count,
                {$latestUpdateSql} AS latest_progress_update,
                sr.created_by, sr.created_at, sr.updated_by, sr.updated_at
         FROM submission_registers sr
         LEFT JOIN users owner ON owner.id = sr.owner_pms_admin_id
         WHERE sr.record_status = 'active'{$scopeSql}
         ORDER BY sr.date_submitted DESC, sr.updated_at DESC, sr.project_company_name ASC",
        $types,
        $params
    );

    foreach ($rows as $index => $_) {
        $rows[$index]['serial'] = $index + 1;
        $rows[$index]['owner_name'] = dynabaseReportCleanLabel($rows[$index]['owner_name'] ?? null, 'Unassigned');
        $rows[$index]['delivery_contact'] = trim(implode(' | ', array_filter([
            trim((string) ($rows[$index]['hard_copy_contact_name'] ?? '')),
            trim((string) ($rows[$index]['hard_copy_contact_email'] ?? '')),
            trim((string) ($rows[$index]['hard_copy_contact_phone'] ?? '')),
        ])));
    }

    $headers = [
        'S/N', 'Reference', 'Tender Code', 'Project / Company', 'Client', 'Category',
        'Date Received', 'Date Submitted', 'Turnaround (Days)', 'Submission Mode',
        'Email Recipient', 'Hard-copy Contact', 'Hard-copy Address', 'Status',
        'Progress Updates', 'Latest Progress Update', 'PMS Owner', 'Created By',
        'Created', 'Updated By', 'Last Updated'
    ];
    $columns = [
        ['key' => 'serial', 'style' => DYNABASE_XLSX_STYLE_CENTER, 'type' => 'number'],
        ['key' => 'submission_reference'], ['key' => 'project_tender_code'], ['key' => 'project_company_name'],
        ['key' => 'client_name'], ['key' => 'category'],
        ['key' => 'date_received', 'style' => DYNABASE_XLSX_STYLE_DATE, 'type' => 'date'],
        ['key' => 'date_submitted', 'style' => DYNABASE_XLSX_STYLE_DATE, 'type' => 'date'],
        ['key' => 'turnaround_days', 'style' => DYNABASE_XLSX_STYLE_NUMBER, 'type' => 'number'],
        ['key' => 'mode_of_submission'], ['key' => 'email_recipient'], ['key' => 'delivery_contact'],
        ['key' => 'hard_copy_contact_address', 'style' => DYNABASE_XLSX_STYLE_WRAP], ['key' => 'status'],
        ['key' => 'progress_update_count', 'style' => DYNABASE_XLSX_STYLE_NUMBER, 'type' => 'number'],
        ['key' => 'latest_progress_update', 'style' => DYNABASE_XLSX_STYLE_WRAP], ['key' => 'owner_name'],
        ['key' => 'created_by'], ['key' => 'created_at', 'style' => DYNABASE_XLSX_STYLE_DATE, 'type' => 'date'],
        ['key' => 'updated_by'], ['key' => 'updated_at', 'style' => DYNABASE_XLSX_STYLE_DATE, 'type' => 'date'],
    ];
    $dataSheet = dynabaseReportDataSheet(
        'Submission Register Report',
        'Active prequalification, technical and registration submissions with delivery and progress information',
        $headers,
        $rows,
        [7, 20, 22, 34, 28, 18, 15, 15, 16, 18, 26, 30, 38, 14, 15, 42, 24, 22, 17, 22, 17],
        $columns
    );
    $dataSheet['name'] = 'Submission Register';

    $summary = dynabaseReportSummarySheet(
        'Submission Register Summary',
        'Submission distribution by status, category, delivery mode and PMS ownership',
        [
            ['title' => 'By Status', 'headers' => ['Status', 'Submissions'], 'rows' => dynabaseReportCountBy($rows, static fn (array $r) => $r['status'] ?? '')],
            ['title' => 'By Category', 'headers' => ['Category', 'Submissions'], 'rows' => dynabaseReportCountBy($rows, static fn (array $r) => $r['category'] ?? '')],
            ['title' => 'By Submission Mode', 'headers' => ['Mode', 'Submissions'], 'rows' => dynabaseReportCountBy($rows, static fn (array $r) => $r['mode_of_submission'] ?? '')],
            ['title' => 'By PMS Owner', 'headers' => ['PMS Owner', 'Submissions'], 'rows' => dynabaseReportCountBy($rows, static fn (array $r) => $r['owner_name'] ?? '')],
        ]
    );
    $summary['name'] = 'Submission Summary';

    $sheets = [$dataSheet, $summary];
    if ($hasUpdates) {
        $updateRows = dbFetchAll(
            $conn,
            "SELECT su.id, sr.submission_reference, sr.project_company_name, sr.client_name,
                    sr.category, sr.status, su.message,
                    COALESCE(NULLIF(TRIM(CONCAT(COALESCE(author.first_name, ''), ' ', COALESCE(author.last_name, ''))), ''), su.created_by) AS update_author,
                    su.created_at, su.updated_at
             FROM submission_register_updates su
             INNER JOIN submission_registers sr ON sr.id = su.submission_id
             LEFT JOIN users author ON author.id = su.created_by_id
             WHERE su.deleted_at IS NULL AND sr.record_status = 'active'{$scopeSql}
             ORDER BY su.created_at DESC, su.id DESC",
            $types,
            $params
        );
        foreach ($updateRows as $index => $_) $updateRows[$index]['serial'] = $index + 1;
        $updateSheet = dynabaseReportDataSheet(
            'Submission Progress Updates',
            'Chronological progress and communication history for active submission records',
            ['S/N', 'Reference', 'Project / Company', 'Client', 'Category', 'Current Status', 'Progress Update', 'Updated By', 'Created', 'Edited'],
            $updateRows,
            [7, 20, 34, 27, 18, 15, 55, 24, 18, 18],
            [
                ['key' => 'serial', 'style' => DYNABASE_XLSX_STYLE_CENTER, 'type' => 'number'],
                ['key' => 'submission_reference'], ['key' => 'project_company_name'], ['key' => 'client_name'],
                ['key' => 'category'], ['key' => 'status'], ['key' => 'message', 'style' => DYNABASE_XLSX_STYLE_WRAP],
                ['key' => 'update_author'], ['key' => 'created_at', 'style' => DYNABASE_XLSX_STYLE_DATE, 'type' => 'date'],
                ['key' => 'updated_at', 'style' => DYNABASE_XLSX_STYLE_DATE, 'type' => 'date'],
            ]
        );
        $updateSheet['name'] = 'Progress Updates';
        $sheets[] = $updateSheet;
    }

    return $sheets;
}

function dynabaseReportDocumentWorkbook(mysqli $conn, array $authUser): array
{
    if (!dynabaseReportTableExists($conn, 'document_table')) return [];

    $effectivePermissions = userEffectivePermissions($conn, $authUser);
    $canViewShareAnalytics = in_array('documents.share', $effectivePermissions, true);
    $hasRevisions = dynabaseReportTableHasColumns($conn, 'document_revisions', [
        'document_id', 'revision_code', 'revision_no', 'revision_notes', 'original_name',
        'file_extension', 'file_size', 'is_current', 'record_status', 'uploaded_by', 'uploaded_at',
    ]);
    $hasShares = $canViewShareAnalytics && dynabaseReportTableHasColumns($conn, 'document_share_links', [
        'document_id', 'revision_id', 'link_name', 'access_mode', 'allow_download', 'expires_at',
        'status', 'access_count', 'download_count', 'created_by', 'created_at', 'last_accessed_at', 'revoked_at',
    ]);
    $statusWhere = dynabaseReportColumnExists($conn, 'document_table', 'status') ? " WHERE d.status = 'active'" : '';
    $relationship = dynabaseReportColumnExists($conn, 'document_table', 'relationship_type') ? 'd.relationship_type' : "'general' AS relationship_type";
    $fileSize = dynabaseReportColumnExists($conn, 'document_table', 'file_size') ? 'd.file_size' : '0 AS file_size';
    $extension = dynabaseReportColumnExists($conn, 'document_table', 'file_extension') ? 'd.file_extension' : "LOWER(SUBSTRING_INDEX(d.document, '.', -1)) AS file_extension";
    $revisionSelect = $hasRevisions
        ? "(SELECT COUNT(*) FROM document_revisions r WHERE r.document_id = d.id AND r.record_status <> 'deleted') AS revision_count,
           (SELECT r.revision_code FROM document_revisions r WHERE r.document_id = d.id AND r.record_status = 'active' AND r.is_current = 1 ORDER BY r.id DESC LIMIT 1) AS current_revision_code"
        : "1 AS revision_count, CONCAT('Rev', LPAD(GREATEST(COALESCE(d.version_no, 1), 1), 3, '0')) AS current_revision_code";
    $shareSelect = $hasShares
        ? "(SELECT COUNT(*) FROM document_share_links sl WHERE sl.document_id = d.id) AS share_link_count,
           (SELECT COUNT(*) FROM document_share_links sl WHERE sl.document_id = d.id AND sl.status = 'active' AND sl.expires_at > NOW()) AS active_share_link_count,
           (SELECT COUNT(*) FROM document_share_links sl WHERE sl.document_id = d.id AND sl.status = 'active' AND sl.expires_at <= NOW()) AS expired_share_link_count,
           (SELECT COALESCE(SUM(sl.access_count), 0) FROM document_share_links sl WHERE sl.document_id = d.id) AS share_access_count,
           (SELECT COALESCE(SUM(sl.download_count), 0) FROM document_share_links sl WHERE sl.document_id = d.id) AS share_download_count"
        : '0 AS share_link_count, 0 AS active_share_link_count, 0 AS expired_share_link_count, 0 AS share_access_count, 0 AS share_download_count';

    $rows = dbFetchAll(
        $conn,
        "SELECT d.id, d.document_title, d.document_type, d.document_category, d.presentation_code, d.updated_content,
                {$relationship}, {$fileSize}, {$extension}, {$revisionSelect}, {$shareSelect},
                d.created_by, d.updated_by, d.created_at, d.updated_at
         FROM document_table d {$statusWhere}
         ORDER BY d.updated_at DESC, d.document_title ASC"
    );
    foreach ($rows as $index => $_) {
        $rows[$index]['serial'] = $index + 1;
        $rows[$index]['file_size_label'] = (int) $rows[$index]['file_size'] > 0 ? round(((int) $rows[$index]['file_size']) / 1048576, 2) : 0;
        $revisionCount = (int) ($rows[$index]['revision_count'] ?? 0);
        $rows[$index]['revision_coverage'] = $revisionCount <= 1 ? 'Single revision' : 'Multiple revisions';
        $rows[$index]['sharing_state'] = (int) ($rows[$index]['active_share_link_count'] ?? 0) > 0 ? 'Actively shared' : 'Not actively shared';
    }

    $headers = ['S/N', 'Document', 'Type', 'Category', 'Reference Code', 'Relationship', 'Format', 'Size (MB)', 'Current Revision', 'Revision Count'];
    $columns = [
        ['key' => 'serial', 'style' => DYNABASE_XLSX_STYLE_CENTER, 'type' => 'number'], ['key' => 'document_title'],
        ['key' => 'document_type'], ['key' => 'document_category'], ['key' => 'presentation_code'], ['key' => 'relationship_type'],
        ['key' => 'file_extension'], ['key' => 'file_size_label', 'style' => DYNABASE_XLSX_STYLE_NUMBER, 'type' => 'number'],
        ['key' => 'current_revision_code'], ['key' => 'revision_count', 'style' => DYNABASE_XLSX_STYLE_NUMBER, 'type' => 'number'],
    ];
    $widths = [7, 34, 18, 24, 22, 18, 11, 13, 16, 14];

    if ($hasShares) {
        array_push($headers, 'Active Share Links', 'Expired Links', 'Total Link Accesses', 'Shared Downloads');
        array_push(
            $columns,
            ['key' => 'active_share_link_count', 'style' => DYNABASE_XLSX_STYLE_NUMBER, 'type' => 'number'],
            ['key' => 'expired_share_link_count', 'style' => DYNABASE_XLSX_STYLE_NUMBER, 'type' => 'number'],
            ['key' => 'share_access_count', 'style' => DYNABASE_XLSX_STYLE_NUMBER, 'type' => 'number'],
            ['key' => 'share_download_count', 'style' => DYNABASE_XLSX_STYLE_NUMBER, 'type' => 'number']
        );
        array_push($widths, 16, 14, 16, 16);
    }

    array_push($headers, 'Update Summary', 'Created By', 'Updated By', 'Created', 'Last Updated');
    array_push(
        $columns,
        ['key' => 'updated_content', 'style' => DYNABASE_XLSX_STYLE_WRAP], ['key' => 'created_by'], ['key' => 'updated_by'],
        ['key' => 'created_at', 'style' => DYNABASE_XLSX_STYLE_DATE, 'type' => 'date'],
        ['key' => 'updated_at', 'style' => DYNABASE_XLSX_STYLE_DATE, 'type' => 'date']
    );
    array_push($widths, 34, 20, 20, 16, 16);

    $dataSheet = dynabaseReportDataSheet(
        'Document Library Report',
        $hasShares
            ? 'Active document register with revision coverage, secure-sharing activity and file metadata'
            : 'Active document register with revision coverage and file metadata',
        $headers,
        $rows,
        $widths,
        $columns
    );
    $dataSheet['name'] = 'Document Register';

    $summaryTables = [
        ['title' => 'By Document Type', 'headers' => ['Type', 'Documents'], 'rows' => dynabaseReportCountBy($rows, static fn (array $r) => $r['document_type'] ?? '')],
        ['title' => 'By Category', 'headers' => ['Category', 'Documents'], 'rows' => dynabaseReportCountBy($rows, static fn (array $r) => $r['document_category'] ?? '')],
        ['title' => 'By Format', 'headers' => ['Format', 'Documents'], 'rows' => dynabaseReportCountBy($rows, static fn (array $r) => $r['file_extension'] ?? '')],
        ['title' => 'Revision Coverage', 'headers' => ['Revision State', 'Documents'], 'rows' => dynabaseReportCountBy($rows, static fn (array $r) => $r['revision_coverage'] ?? '')],
    ];
    if ($hasShares) {
        $summaryTables[] = ['title' => 'Sharing State', 'headers' => ['Sharing State', 'Documents'], 'rows' => dynabaseReportCountBy($rows, static fn (array $r) => $r['sharing_state'] ?? '')];
    }
    $summary = dynabaseReportSummarySheet('Document Library Summary', 'Library distribution, revision coverage and permitted sharing intelligence', $summaryTables);
    $summary['name'] = 'Document Summary';
    $sheets = [$dataSheet, $summary];

    if ($hasRevisions) {
        $revisionRows = dbFetchAll(
            $conn,
            "SELECT r.id, d.document_title, d.presentation_code, r.revision_code, r.revision_no,
                    r.revision_notes, r.original_name, r.file_extension, r.file_size,
                    r.is_current, r.record_status, r.replaces_revision_id, r.uploaded_by, r.uploaded_at, r.replaced_at
             FROM document_revisions r
             INNER JOIN document_table d ON d.id = r.document_id
             WHERE d.status = 'active' AND r.record_status <> 'deleted'
             ORDER BY d.document_title ASC, r.revision_no DESC, r.id DESC"
        );
        foreach ($revisionRows as $index => $_) {
            $revisionRows[$index]['serial'] = $index + 1;
            $revisionRows[$index]['file_size_label'] = (int) $revisionRows[$index]['file_size'] > 0 ? round(((int) $revisionRows[$index]['file_size']) / 1048576, 2) : 0;
            $revisionRows[$index]['current_label'] = (int) ($revisionRows[$index]['is_current'] ?? 0) === 1 ? 'Yes' : 'No';
            $revisionRows[$index]['status_label'] = ucwords(str_replace('_', ' ', (string) ($revisionRows[$index]['record_status'] ?? 'active')));
        }
        $revisionSheet = dynabaseReportDataSheet(
            'Document Revision History',
            'Current and replaced revision uploads retained for document traceability',
            ['S/N', 'Document', 'Reference Code', 'Revision', 'Sequence', 'Current', 'Revision Status', 'Filename', 'Format', 'Size (MB)', 'Revision Notes', 'Uploaded By', 'Uploaded', 'Replaced'],
            $revisionRows,
            [7, 34, 22, 14, 10, 10, 16, 38, 11, 13, 40, 22, 18, 18],
            [
                ['key' => 'serial', 'style' => DYNABASE_XLSX_STYLE_CENTER, 'type' => 'number'], ['key' => 'document_title'],
                ['key' => 'presentation_code'], ['key' => 'revision_code'], ['key' => 'revision_no', 'style' => DYNABASE_XLSX_STYLE_NUMBER, 'type' => 'number'],
                ['key' => 'current_label', 'style' => DYNABASE_XLSX_STYLE_CENTER], ['key' => 'status_label'], ['key' => 'original_name'],
                ['key' => 'file_extension'], ['key' => 'file_size_label', 'style' => DYNABASE_XLSX_STYLE_NUMBER, 'type' => 'number'],
                ['key' => 'revision_notes', 'style' => DYNABASE_XLSX_STYLE_WRAP], ['key' => 'uploaded_by'],
                ['key' => 'uploaded_at', 'style' => DYNABASE_XLSX_STYLE_DATE, 'type' => 'date'],
                ['key' => 'replaced_at', 'style' => DYNABASE_XLSX_STYLE_DATE, 'type' => 'date'],
            ]
        );
        $revisionSheet['name'] = 'Revision History';
        $sheets[] = $revisionSheet;
    }

    if ($hasShares) {
        $shareRows = dbFetchAll(
            $conn,
            "SELECT sl.id, d.document_title, d.presentation_code,
                    COALESCE(r.revision_code, 'Current revision') AS shared_revision,
                    sl.link_name, sl.access_mode, sl.allow_download,
                    CASE
                        WHEN sl.status = 'revoked' THEN 'Revoked'
                        WHEN sl.expires_at <= NOW() THEN 'Expired'
                        ELSE 'Active'
                    END AS link_status,
                    sl.expires_at, sl.access_count, sl.download_count,
                    sl.created_by, sl.created_at, sl.last_accessed_at, sl.revoked_at
             FROM document_share_links sl
             INNER JOIN document_table d ON d.id = sl.document_id
             LEFT JOIN document_revisions r ON r.id = sl.revision_id
             WHERE d.status = 'active'
             ORDER BY sl.created_at DESC, sl.id DESC"
        );
        foreach ($shareRows as $index => $_) {
            $shareRows[$index]['serial'] = $index + 1;
            $shareRows[$index]['access_mode_label'] = ucfirst((string) ($shareRows[$index]['access_mode'] ?? 'open'));
            $shareRows[$index]['download_label'] = (int) ($shareRows[$index]['allow_download'] ?? 0) === 1 ? 'Allowed' : 'Disabled';
        }
        $shareSheet = dynabaseReportDataSheet(
            'Secure Document Sharing Report',
            'Link controls and usage metrics without exposing passwords or secure tokens',
            ['S/N', 'Document', 'Reference Code', 'Shared Revision', 'Link Name', 'Access Type', 'Downloads', 'Link Status', 'Expires', 'Accesses', 'Downloads Made', 'Created By', 'Created', 'Last Accessed', 'Revoked'],
            $shareRows,
            [7, 34, 22, 16, 28, 14, 13, 13, 18, 12, 16, 22, 18, 18, 18],
            [
                ['key' => 'serial', 'style' => DYNABASE_XLSX_STYLE_CENTER, 'type' => 'number'], ['key' => 'document_title'],
                ['key' => 'presentation_code'], ['key' => 'shared_revision'], ['key' => 'link_name'], ['key' => 'access_mode_label'],
                ['key' => 'download_label'], ['key' => 'link_status'], ['key' => 'expires_at', 'style' => DYNABASE_XLSX_STYLE_DATE, 'type' => 'date'],
                ['key' => 'access_count', 'style' => DYNABASE_XLSX_STYLE_NUMBER, 'type' => 'number'],
                ['key' => 'download_count', 'style' => DYNABASE_XLSX_STYLE_NUMBER, 'type' => 'number'], ['key' => 'created_by'],
                ['key' => 'created_at', 'style' => DYNABASE_XLSX_STYLE_DATE, 'type' => 'date'],
                ['key' => 'last_accessed_at', 'style' => DYNABASE_XLSX_STYLE_DATE, 'type' => 'date'],
                ['key' => 'revoked_at', 'style' => DYNABASE_XLSX_STYLE_DATE, 'type' => 'date'],
            ]
        );
        $shareSheet['name'] = 'Secure Sharing';
        $sheets[] = $shareSheet;
    }

    return $sheets;
}

function dynabaseReportAgreementFilters(array $source): array
{
    $yearRaw = cleanString($source['agreement_year'] ?? $source['year'] ?? 'all');
    $year = 0;
    if ($yearRaw !== '' && strtolower($yearRaw) !== 'all') {
        $year = (int) $yearRaw;
        if ($year < 2000 || $year > ((int) date('Y') + 1)) {
            throw new RuntimeException('Please choose a valid agreement year.', 422);
        }
    }

    $type = strtoupper(cleanString($source['document_ref_type'] ?? ''));
    if ($type !== '' && !in_array($type, ['NDA', 'MOU'], true)) {
        throw new RuntimeException('Please choose a valid agreement type.', 422);
    }

    $status = cleanString($source['status'] ?? '');
    $allowedStatuses = [
        'Draft', 'Under Review', 'Sent', 'Awaiting Client Signature', 'Awaiting Lambert Signature',
        'Fully Executed', 'Active', 'Expiring Soon', 'Renewed', 'Expired', 'Terminated', 'Archived',
    ];
    if ($status !== '' && !in_array($status, $allowedStatuses, true)) {
        throw new RuntimeException('Please choose a valid agreement status.', 422);
    }

    $renewal = cleanString($source['renewal'] ?? '');
    if ($renewal !== '' && !in_array($renewal, ['Yes', 'No'], true)) {
        throw new RuntimeException('Please choose a valid renewal option.', 422);
    }

    return [
        'year' => $year,
        'document_ref_type' => $type,
        'status' => $status,
        'renewal' => $renewal,
        'department' => cleanString($source['department'] ?? ''),
        'q' => cleanString($source['q'] ?? $source['search'] ?? ''),
    ];
}

function dynabaseReportAgreementWhere(array $authUser, array $filters): array
{
    $where = " WHERE a.record_status = 'active'";
    $types = '';
    $params = [];

    if (($filters['year'] ?? 0) > 0) {
        $where .= ' AND a.ref_year = ?';
        $types .= 'i';
        $params[] = (int) $filters['year'];
    }
    if (($filters['document_ref_type'] ?? '') !== '') {
        $where .= ' AND a.document_ref_type = ?';
        $types .= 's';
        $params[] = $filters['document_ref_type'];
    }
    if (($filters['status'] ?? '') !== '') {
        $where .= ' AND a.status = ?';
        $types .= 's';
        $params[] = $filters['status'];
    }
    if (($filters['renewal'] ?? '') !== '') {
        $where .= ' AND a.renewal = ?';
        $types .= 's';
        $params[] = $filters['renewal'];
    }
    if (($filters['department'] ?? '') !== '') {
        $where .= ' AND a.department LIKE ?';
        $types .= 's';
        $params[] = '%' . $filters['department'] . '%';
    }
    if (($filters['q'] ?? '') !== '') {
        $like = '%' . $filters['q'] . '%';
        $where .= ' AND (a.document_ref_no LIKE ? OR a.project_subject LIKE ? OR a.client_company LIKE ? OR a.counterparty_contact_person LIKE ? OR a.purpose LIKE ? OR a.department LIKE ?)';
        $types .= 'ssssss';
        for ($index = 0; $index < 6; $index++) $params[] = $like;
    }

    [$scopeSql, $scopeTypes, $scopeParams] = appendScopedWhere($authUser, 'a');
    $where .= $scopeSql;
    $types .= $scopeTypes;
    $params = array_merge($params, $scopeParams);
    return [$where, $types, $params];
}

function dynabaseReportAgreementRows(mysqli $conn, array $authUser, array $filters = []): array
{
    if (!dynabaseReportTableExists($conn, 'agreement_registers')) return [];
    [$where, $types, $params] = dynabaseReportAgreementWhere($authUser, $filters);
    return dbFetchAll(
        $conn,
        "SELECT a.id, a.document_ref_type, a.document_ref_no, a.ref_year, a.project_subject,
                a.client_company, a.counterparty_contact_person, a.issued_by, a.date_issued, a.date_sent,
                a.date_received, a.lambert_signatory, a.client_signatory, a.effective_date, a.expiry_date,
                a.duration, a.renewal, a.status, a.purpose, a.department, a.reminder_date,
                a.reminder_sent_at, a.reminder_last_status, a.remark, a.linked_document_id,
                a.renewed_from_id, a.renewed_to_id, a.owner_pms_admin_id, a.created_at, a.updated_at,
                CASE WHEN a.reminder_sent_at IS NULL AND a.reminder_date <= CURRENT_DATE() THEN 1 ELSE 0 END AS reminder_due,
                CASE WHEN a.expiry_date IS NULL THEN NULL ELSE DATEDIFF(a.expiry_date, CURRENT_DATE()) END AS days_to_expiry,
                CASE WHEN a.date_sent IS NULL THEN NULL ELSE DATEDIFF(CURRENT_DATE(), a.date_sent) END AS days_since_sent,
                NULLIF(TRIM(CONCAT(COALESCE(owner.first_name, ''), ' ', COALESCE(owner.last_name, ''))), '') AS owner_name,
                d.document_title AS linked_document_title
         FROM agreement_registers a
         LEFT JOIN users owner ON owner.id = a.owner_pms_admin_id
         LEFT JOIN document_table d ON d.id = a.linked_document_id
         {$where}
         ORDER BY a.ref_year DESC, a.document_ref_type ASC, a.ref_sequence DESC, a.id DESC",
        $types,
        $params
    );
}

function dynabaseReportAgreementAvailableYears(mysqli $conn, array $authUser): array
{
    if (!dynabaseReportTableExists($conn, 'agreement_registers')) return [];
    [$scopeSql, $types, $params] = appendScopedWhere($authUser, 'a');
    $rows = dbFetchAll(
        $conn,
        "SELECT DISTINCT a.ref_year FROM agreement_registers a WHERE a.record_status = 'active'{$scopeSql} ORDER BY a.ref_year DESC",
        $types,
        $params
    );
    return array_values(array_map(static fn (array $row): int => (int) $row['ref_year'], $rows));
}

function dynabaseReportAgreementAnalytics(array $rows): array
{
    $status = [];
    $types = ['NDA' => 0, 'MOU' => 0];
    $departments = [];
    $clients = [];
    $issuedBy = ['Lambert' => 0, 'Client' => 0];
    $total = count($rows);
    $metrics = [
        'active' => 0,
        'awaiting_signature' => 0,
        'expiring_soon' => 0,
        'expired' => 0,
        'reminders_due' => 0,
        'renewal_candidates' => 0,
        'pending_signature_over_30_days' => 0,
        'without_document' => 0,
        'without_expiry_date' => 0,
    ];
    $attention = [];

    foreach ($rows as $row) {
        $statusName = dynabaseReportCleanLabel($row['status'] ?? null);
        $status[$statusName] = ($status[$statusName] ?? 0) + 1;
        $type = strtoupper(trim((string) ($row['document_ref_type'] ?? '')));
        if (isset($types[$type])) $types[$type]++;
        $department = dynabaseReportCleanLabel($row['department'] ?? null);
        $departments[$department] = ($departments[$department] ?? 0) + 1;
        $client = dynabaseReportCleanLabel($row['client_company'] ?? null);
        $clients[$client] = ($clients[$client] ?? 0) + 1;
        $issuer = dynabaseReportCleanLabel($row['issued_by'] ?? null);
        if (isset($issuedBy[$issuer])) $issuedBy[$issuer]++;

        if ($statusName === 'Active') $metrics['active']++;
        if (in_array($statusName, ['Awaiting Client Signature', 'Awaiting Lambert Signature'], true)) $metrics['awaiting_signature']++;
        if ($statusName === 'Expiring Soon') $metrics['expiring_soon']++;
        if ($statusName === 'Expired') $metrics['expired']++;
        if ((int) ($row['reminder_due'] ?? 0) === 1) $metrics['reminders_due']++;
        if (($row['renewal'] ?? '') === 'Yes' && in_array($statusName, ['Active', 'Expiring Soon', 'Expired'], true)) $metrics['renewal_candidates']++;
        if (in_array($statusName, ['Awaiting Client Signature', 'Awaiting Lambert Signature'], true) && (int) ($row['days_since_sent'] ?? 0) > 30) $metrics['pending_signature_over_30_days']++;
        if (empty($row['linked_document_id'])) $metrics['without_document']++;
        if (empty($row['expiry_date'])) $metrics['without_expiry_date']++;

        $reasons = [];
        if ((int) ($row['reminder_due'] ?? 0) === 1) $reasons[] = 'Reminder due';
        if ($statusName === 'Expiring Soon') $reasons[] = 'Expiring soon';
        if ($statusName === 'Expired') $reasons[] = 'Expired';
        if (in_array($statusName, ['Awaiting Client Signature', 'Awaiting Lambert Signature'], true)) {
            $days = $row['days_since_sent'] !== null ? (int) $row['days_since_sent'] : null;
            $reasons[] = $days !== null ? "Signature pending {$days} days" : 'Signature pending';
        }
        if (($row['renewal'] ?? '') === 'Yes' && in_array($statusName, ['Expiring Soon', 'Expired'], true)) $reasons[] = 'Renewal decision';
        if ($reasons !== []) {
            $copy = $row;
            $copy['attention_reason'] = implode(' · ', array_values(array_unique($reasons)));
            $attention[] = $copy;
        }
    }

    arsort($status);
    arsort($departments);
    arsort($clients);
    usort($attention, static function (array $a, array $b): int {
        $aDue = (int) ($a['reminder_due'] ?? 0);
        $bDue = (int) ($b['reminder_due'] ?? 0);
        if ($aDue !== $bDue) return $bDue <=> $aDue;
        $aExpiry = $a['days_to_expiry'] === null ? PHP_INT_MAX : (int) $a['days_to_expiry'];
        $bExpiry = $b['days_to_expiry'] === null ? PHP_INT_MAX : (int) $b['days_to_expiry'];
        return $aExpiry <=> $bExpiry;
    });

    $toNamed = static fn (array $counts): array => array_map(
        static fn (string $label, int $count): array => ['label' => $label, 'count' => $count],
        array_keys($counts),
        array_values($counts)
    );

    return [
        'total' => $total,
        'metrics' => $metrics,
        'by_status' => $toNamed($status),
        'by_type' => $toNamed($types),
        'by_department' => array_slice($toNamed($departments), 0, 12),
        'top_clients' => array_slice($toNamed($clients), 0, 12),
        'by_issued_by' => $toNamed($issuedBy),
        'attention_queue' => array_slice($attention, 0, 25),
    ];
}

function dynabaseReportAgreementOverview(mysqli $conn, array $authUser, array $filters = []): array
{
    $access = dynabaseReportAccessForUser($conn, $authUser, 'agreement-register');
    if (!$access['can_view']) {
        throw new RuntimeException('You do not have access to Agreement Register reporting.', 403);
    }
    $rows = dynabaseReportAgreementRows($conn, $authUser, $filters);
    $analytics = dynabaseReportAgreementAnalytics($rows);
    $analytics['filters'] = $filters;
    $analytics['available_years'] = dynabaseReportAgreementAvailableYears($conn, $authUser);
    $analytics['can_export'] = $access['can_export'];
    $analytics['generated_at'] = date(DATE_ATOM);
    return $analytics;
}

function dynabaseReportAgreementWorkbook(mysqli $conn, array $authUser, array $filters = []): array
{
    $rows = dynabaseReportAgreementRows($conn, $authUser, $filters);
    $analytics = dynabaseReportAgreementAnalytics($rows);
    $generated = date('d M Y, h:i A');
    $filterLabel = [];
    if (($filters['year'] ?? 0) > 0) $filterLabel[] = 'Year ' . $filters['year'];
    if (($filters['document_ref_type'] ?? '') !== '') $filterLabel[] = $filters['document_ref_type'];
    if (($filters['status'] ?? '') !== '') $filterLabel[] = $filters['status'];
    if (($filters['renewal'] ?? '') !== '') $filterLabel[] = 'Renewal ' . $filters['renewal'];
    if (($filters['department'] ?? '') !== '') $filterLabel[] = 'Department: ' . $filters['department'];
    $scope = $filterLabel !== [] ? implode(' • ', $filterLabel) : 'All accessible agreements';

    foreach ($rows as $index => &$row) {
        $row['serial'] = $index + 1;
        $row['reminder_due_label'] = (int) ($row['reminder_due'] ?? 0) === 1 ? 'Due' : (($row['reminder_sent_at'] ?? null) ? 'Sent' : 'Pending');
        $row['document_label'] = !empty($row['linked_document_id']) ? 'Linked' : 'Not attached';
        $row['days_to_expiry_label'] = $row['days_to_expiry'] === null ? '' : (int) $row['days_to_expiry'];
    }
    unset($row);

    $register = dynabaseReportDataSheet(
        'Agreement Register',
        "{$scope}  •  Generated {$generated}",
        ['S/N', 'Reference', 'Type', 'Project / Subject', 'Client / Company', 'Counterparty Contact', 'Issued By', 'Date Issued', 'Date Sent', 'Date Received', 'Lambert Signatory', 'Client Signatory', 'Effective Date', 'Expiry Date', 'Days to Expiry', 'Duration', 'Renewal', 'Status', 'Purpose', 'Department', 'Reminder Date', 'Reminder State', 'Document', 'PMS Owner', 'Remark', 'Last Updated'],
        $rows,
        [8, 19, 9, 28, 28, 25, 12, 15, 15, 15, 22, 22, 15, 15, 14, 18, 11, 24, 38, 20, 15, 15, 16, 22, 34, 18],
        [
            ['key' => 'serial', 'style' => DYNABASE_XLSX_STYLE_CENTER, 'type' => 'number'],
            ['key' => 'document_ref_no'], ['key' => 'document_ref_type', 'style' => DYNABASE_XLSX_STYLE_CENTER],
            ['key' => 'project_subject'], ['key' => 'client_company'], ['key' => 'counterparty_contact_person'],
            ['key' => 'issued_by', 'style' => DYNABASE_XLSX_STYLE_CENTER],
            ['key' => 'date_issued', 'style' => DYNABASE_XLSX_STYLE_DATE, 'type' => 'date'],
            ['key' => 'date_sent', 'style' => DYNABASE_XLSX_STYLE_DATE, 'type' => 'date'],
            ['key' => 'date_received', 'style' => DYNABASE_XLSX_STYLE_DATE, 'type' => 'date'],
            ['key' => 'lambert_signatory'], ['key' => 'client_signatory'],
            ['key' => 'effective_date', 'style' => DYNABASE_XLSX_STYLE_DATE, 'type' => 'date'],
            ['key' => 'expiry_date', 'style' => DYNABASE_XLSX_STYLE_DATE, 'type' => 'date'],
            ['key' => 'days_to_expiry_label', 'style' => DYNABASE_XLSX_STYLE_NUMBER, 'type' => 'number'],
            ['key' => 'duration'], ['key' => 'renewal', 'style' => DYNABASE_XLSX_STYLE_CENTER], ['key' => 'status'],
            ['key' => 'purpose', 'style' => DYNABASE_XLSX_STYLE_WRAP], ['key' => 'department'],
            ['key' => 'reminder_date', 'style' => DYNABASE_XLSX_STYLE_DATE, 'type' => 'date'], ['key' => 'reminder_due_label'],
            ['key' => 'document_label'], ['key' => 'owner_name'], ['key' => 'remark', 'style' => DYNABASE_XLSX_STYLE_WRAP],
            ['key' => 'updated_at', 'style' => DYNABASE_XLSX_STYLE_DATE, 'type' => 'date'],
        ]
    );
    $register['name'] = 'Agreement Register';

    $metricRows = [
        ['Total agreements', $analytics['total']],
        ['Active', $analytics['metrics']['active']],
        ['Awaiting signature', $analytics['metrics']['awaiting_signature']],
        ['Signature pending > 30 days', $analytics['metrics']['pending_signature_over_30_days']],
        ['Expiring soon', $analytics['metrics']['expiring_soon']],
        ['Expired', $analytics['metrics']['expired']],
        ['Reminders due', $analytics['metrics']['reminders_due']],
        ['Renewal candidates', $analytics['metrics']['renewal_candidates']],
        ['Without attached document', $analytics['metrics']['without_document']],
        ['Without expiry date', $analytics['metrics']['without_expiry_date']],
    ];
    $summaryTables = [
        ['title' => 'Management KPIs', 'headers' => ['Metric', 'Count'], 'rows' => $metricRows],
        ['title' => 'Status Distribution', 'headers' => ['Status', 'Count'], 'rows' => array_map(static fn (array $item): array => [$item['label'], $item['count']], $analytics['by_status'])],
        ['title' => 'Agreement Type', 'headers' => ['Type', 'Count'], 'rows' => array_map(static fn (array $item): array => [$item['label'], $item['count']], $analytics['by_type'])],
        ['title' => 'Top Departments', 'headers' => ['Department', 'Count'], 'rows' => array_map(static fn (array $item): array => [$item['label'], $item['count']], $analytics['by_department'])],
        ['title' => 'Top Clients / Companies', 'headers' => ['Client / Company', 'Count'], 'rows' => array_map(static fn (array $item): array => [$item['label'], $item['count']], $analytics['top_clients'])],
    ];
    $summary = dynabaseReportSummarySheet('Agreement Management Summary', "{$scope}  •  Generated {$generated}", $summaryTables);
    $summary['name'] = 'Management Summary';

    $attentionRows = $analytics['attention_queue'];
    foreach ($attentionRows as $index => &$row) $row['serial'] = $index + 1;
    unset($row);
    $attention = dynabaseReportDataSheet(
        'Agreement Attention Queue',
        'Items requiring signatures, reminders, expiry action or renewal decisions',
        ['S/N', 'Reference', 'Client / Company', 'Status', 'Attention', 'Date Sent', 'Days Since Sent', 'Expiry Date', 'Days to Expiry', 'Renewal', 'Reminder Date', 'Department', 'PMS Owner'],
        $attentionRows,
        [8, 20, 28, 24, 34, 15, 16, 15, 15, 11, 15, 20, 22],
        [
            ['key' => 'serial', 'style' => DYNABASE_XLSX_STYLE_CENTER, 'type' => 'number'], ['key' => 'document_ref_no'],
            ['key' => 'client_company'], ['key' => 'status'], ['key' => 'attention_reason', 'style' => DYNABASE_XLSX_STYLE_WRAP],
            ['key' => 'date_sent', 'style' => DYNABASE_XLSX_STYLE_DATE, 'type' => 'date'],
            ['key' => 'days_since_sent', 'style' => DYNABASE_XLSX_STYLE_NUMBER, 'type' => 'number'],
            ['key' => 'expiry_date', 'style' => DYNABASE_XLSX_STYLE_DATE, 'type' => 'date'],
            ['key' => 'days_to_expiry', 'style' => DYNABASE_XLSX_STYLE_NUMBER, 'type' => 'number'],
            ['key' => 'renewal', 'style' => DYNABASE_XLSX_STYLE_CENTER],
            ['key' => 'reminder_date', 'style' => DYNABASE_XLSX_STYLE_DATE, 'type' => 'date'], ['key' => 'department'], ['key' => 'owner_name'],
        ]
    );
    $attention['name'] = 'Attention Queue';

    $sheets = [$register, $summary, $attention];

    if (dynabaseReportTableExists($conn, 'agreement_reminder_deliveries')) {
        [$where, $whereTypes, $whereParams] = dynabaseReportAgreementWhere($authUser, $filters);
        $deliveryRows = dbFetchAll(
            $conn,
            "SELECT ard.id, ard.agreement_id, a.document_ref_no, a.client_company, ard.reminder_date,
                    ard.recipient_email, ard.delivery_mode, ard.satisfies_schedule, ard.status,
                    ard.error_message, ard.triggered_by, ard.created_at
             FROM agreement_reminder_deliveries ard
             INNER JOIN agreement_registers a ON a.id = ard.agreement_id
             {$where}
             ORDER BY ard.created_at DESC, ard.id DESC",
            $whereTypes,
            $whereParams
        );
        foreach ($deliveryRows as $index => &$delivery) {
            $delivery['serial'] = $index + 1;
            $delivery['scheduled_label'] = (int) ($delivery['satisfies_schedule'] ?? 0) === 1 ? 'Yes' : 'No';
        }
        unset($delivery);
        $deliverySheet = dynabaseReportDataSheet(
            'Agreement Reminder Delivery',
            'Automatic and manual reminder delivery history for the selected agreement scope',
            ['S/N', 'Reference', 'Client / Company', 'Reminder Date', 'Recipient', 'Mode', 'Scheduled Reminder', 'Status', 'Error', 'Triggered By', 'Sent / Attempted'],
            $deliveryRows,
            [8, 20, 28, 15, 30, 12, 17, 12, 36, 28, 19],
            [
                ['key' => 'serial', 'style' => DYNABASE_XLSX_STYLE_CENTER, 'type' => 'number'], ['key' => 'document_ref_no'], ['key' => 'client_company'],
                ['key' => 'reminder_date', 'style' => DYNABASE_XLSX_STYLE_DATE, 'type' => 'date'], ['key' => 'recipient_email'], ['key' => 'delivery_mode'],
                ['key' => 'scheduled_label', 'style' => DYNABASE_XLSX_STYLE_CENTER], ['key' => 'status'], ['key' => 'error_message', 'style' => DYNABASE_XLSX_STYLE_WRAP],
                ['key' => 'triggered_by'], ['key' => 'created_at', 'style' => DYNABASE_XLSX_STYLE_DATE, 'type' => 'date'],
            ]
        );
        $deliverySheet['name'] = 'Reminder Delivery';
        $sheets[] = $deliverySheet;
    }

    return $sheets;
}


function dynabaseReportCatalog(mysqli $conn, array $authUser): array
{
    $count = static function (string $sql, string $types = '', array $params = []) use ($conn): int {
        try { return dbScalarInt($conn, $sql, $types, $params); } catch (Throwable) { return 0; }
    };
    $surveyWhere = dynabaseReportColumnExists($conn, 'clients_survey_form', 'deleted_at') ? ' WHERE deleted_at IS NULL' : '';
    $documentWhere = dynabaseReportColumnExists($conn, 'document_table', 'status') ? " WHERE status = 'active'" : '';
    $prequalWhere = dynabaseReportColumnExists($conn, 'prequalification_table', 'record_status') ? " WHERE record_status = 'active'" : '';
    $effectivePermissions = userEffectivePermissions($conn, $authUser);
    $documentSheets = 2
        + (dynabaseReportTableHasColumns($conn, 'document_revisions', ['document_id', 'revision_code', 'record_status']) ? 1 : 0)
        + (in_array('documents.share', $effectivePermissions, true)
            && dynabaseReportTableHasColumns($conn, 'document_share_links', ['document_id', 'status', 'expires_at']) ? 1 : 0);

    $submissionCount = 0;
    if (dynabaseReportTableExists($conn, 'submission_registers')) {
        [$submissionScope, $submissionTypes, $submissionParams] = appendScopedWhere($authUser, 'sr');
        $submissionCount = $count(
            "SELECT COUNT(*) AS total FROM submission_registers sr WHERE sr.record_status = 'active'{$submissionScope}",
            $submissionTypes,
            $submissionParams
        );
    }

    $definitions = [
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
            'key' => 'submission-register', 'title' => 'Submission Register',
            'description' => 'Submission status, category, delivery mode, ownership and progress-update reporting.',
            'record_count' => $submissionCount,
            'year_filter' => false,
            'sheets' => dynabaseReportTableHasColumns($conn, 'submission_register_updates', ['submission_id', 'message', 'deleted_at']) ? 3 : 2,
        ],
        [
            'key' => 'documents', 'title' => 'Document Library',
            'description' => in_array('documents.share', $effectivePermissions, true)
                ? 'Document metadata, revision history and secure-sharing activity reporting.'
                : 'Document metadata, formats and revision-history reporting.',
            'record_count' => dynabaseReportTableExists($conn, 'document_table') ? $count('SELECT COUNT(*) AS total FROM document_table' . $documentWhere) : 0,
            'year_filter' => false, 'sheets' => $documentSheets,
        ],
        [
            'key' => 'agreement-register', 'title' => 'Agreement Register',
            'description' => 'NDA/MOU lifecycle, signature bottlenecks, expiry exposure, renewals and reminder delivery reporting.',
            'record_count' => dynabaseReportTableExists($conn, 'agreement_registers')
                ? (function () use ($conn, $authUser): int {
                    [$scopeSql, $scopeTypes, $scopeParams] = appendScopedWhere($authUser, 'a');
                    return dbScalarInt($conn, "SELECT COUNT(*) AS total FROM agreement_registers a WHERE a.record_status = 'active'{$scopeSql}", $scopeTypes, $scopeParams);
                })()
                : 0,
            'year_filter' => true,
            'sheets' => dynabaseReportTableExists($conn, 'agreement_reminder_deliveries') ? 4 : 3,
        ],
    ];

    $catalog = [];
    foreach ($definitions as $definition) {
        $access = dynabaseReportAccessForUser($conn, $authUser, $definition['key'], $effectivePermissions);
        if (!$access['can_view']) continue;
        $definition['can_export'] = $access['can_export'];
        $definition['required_view_permission'] = $access['view_permission'];
        $definition['required_export_permission'] = $access['export_permission'];
        $catalog[] = $definition;
    }

    return $catalog;
}

function dynabaseReportOverview(mysqli $conn, array $authUser, int $giftYear = 0): array
{
    $giftAccess = dynabaseReportAccessForUser($conn, $authUser, 'gift-lists');
    $giftRows = $giftAccess['can_view'] ? dynabaseReportGiftRows($conn, $giftYear) : [];
    $giftAnalytics = dynabaseReportGiftAnalytics($giftRows);
    $giftAnalytics['can_view'] = $giftAccess['can_view'];
    $giftAnalytics['can_export'] = $giftAccess['can_export'];

    return [
        'available_years' => $giftAccess['can_view'] ? dynabaseReportAvailableGiftYears($conn) : [],
        'selected_year' => $giftYear > 0 ? $giftYear : 'all',
        'catalog' => dynabaseReportCatalog($conn, $authUser),
        'gift_list' => $giftAnalytics,
        'generated_at' => date(DATE_ATOM),
    ];
}

function dynabaseReportWorkbook(mysqli $conn, string $type, int $giftYear = 0, array $authUser = [], array $filters = []): array
{
    return match ($type) {
        'gift-lists' => dynabaseReportGiftWorkbook(dynabaseReportGiftRows($conn, $giftYear), $giftYear),
        'tenders' => dynabaseReportTenderWorkbook($conn, $giftYear),
        'clients' => dynabaseReportClientWorkbook($conn),
        'keypersons' => dynabaseReportKeypersonWorkbook($conn),
        'pms-ownership' => dynabaseReportOwnershipWorkbook($conn),
        'client-surveys' => dynabaseReportSurveyWorkbook($conn),
        'prequalifications' => dynabaseReportPrequalificationWorkbook($conn),
        'submission-register' => dynabaseReportSubmissionWorkbook($conn, $authUser),
        'documents' => dynabaseReportDocumentWorkbook($conn, $authUser),
        'agreement-register' => dynabaseReportAgreementWorkbook($conn, $authUser, $filters),
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
        'submission-register' => 'dynabase-submission-register-' . $date . '.xlsx',
        'documents' => 'dynabase-document-library-' . $date . '.xlsx',
        'agreement-register' => 'dynabase-agreement-register-' . $date . '.xlsx',
        default => 'dynabase-report-' . $date . '.xlsx',
    };
}

