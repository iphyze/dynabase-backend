<?php
declare(strict_types=1);

/** Internal reporting only. Never recalculate a submitted score using today's weights. */
function surveyReportingSnapshot(array $record): ?array
{
    if ((int) ($record['survey_version'] ?? 1) !== 2 || (int) ($record['scoring_configuration_id'] ?? 0) < 1) {
        return null;
    }
    $raw = $record['survey_scoring_breakdown'] ?? null;
    $snapshot = is_array($raw) ? $raw : (is_string($raw) ? json_decode($raw, true) : null);
    if (!is_array($snapshot) || ($snapshot['method'] ?? '') !== 'weighted_v1'
        || (int) ($snapshot['configuration_id'] ?? 0) !== (int) $record['scoring_configuration_id']
        || !isset($snapshot['sections']) || !is_array($snapshot['sections'])
        || (int) ($snapshot['version_number'] ?? 0) < 1) {
        return null;
    }
    foreach (['performance', 'stakeholder', 'service'] as $section) {
        if (!isset($snapshot['sections'][$section]['questions']) || !is_array($snapshot['sections'][$section]['questions'])) {
            return null;
        }
    }
    return $snapshot;
}

function surveyReportingMethod(array $record): string
{
    if ((int) ($record['survey_version'] ?? 1) === 1) return 'Legacy average (8 criteria)';
    if ((int) ($record['scoring_configuration_id'] ?? 0) > 0) return 'Weighted';
    if ((int) ($record['survey_rated_count'] ?? 0) === 0) return 'Historical (not rated)';
    return 'Historical average (N/A excluded)';
}

/** Builds one additional XLSX sheet without changing historical export columns. */
function surveyReportingTableSheet(string $name, array $headers, array $rows): array
{
    $headerCells = array_map(static fn ($label) => ['value' => (string) $label, 'style' => DYNABASE_XLSX_STYLE_HEADER], $headers);
    $sheetRows = [$headerCells];
    $widths = array_map(static fn ($value) => max(10, min(40, mb_strlen((string) $value) + 4)), $headers);
    foreach ($rows as $i => $row) {
        $style = $i % 2 === 0 ? DYNABASE_XLSX_STYLE_BODY : DYNABASE_XLSX_STYLE_BODY_ALT;
        $cells = [];
        foreach (array_values($row) as $j => $value) {
            $cells[] = ['value' => $value ?? '', 'style' => $name === 'Scoring Details' && $j >= 15 ? DYNABASE_XLSX_STYLE_WRAP : $style,
                'type' => is_int($value) || is_float($value) ? 'number' : 'string'];
            if ($i < 200 && is_string($value)) $widths[$j] = max($widths[$j] ?? 10, min(45, mb_strlen($value) + 2));
        }
        $sheetRows[] = $cells;
    }
    return [
        'name' => $name, 'rows' => $sheetRows, 'widths' => $widths,
        'freezeRows' => 1, 'rowHeights' => [1 => 42], 'autoFilter' => 'A1:' . dynabaseXlsxColumnLetter(count($headers)) . count($sheetRows),
        'landscape' => true, 'showGridLines' => true,
    ];
}

/** One row per response (bounded memory, even for 10,000-response exports).
 * Each question cell carries its original rating, weight and contribution.
 * The stakeholder question title is included because it varies by category.
 */
function surveyReportingQuestionRow(array $record, ?array $snapshot): array
{
    $category = (string) ($record['stakeholder_category'] ?? '');
    $row = [
        (string) ($record['submission_reference'] ?? ''),
        (string) ($record['company'] ?? ''),
        (string) ($record['project_title'] ?? ''),
        (string) ($record['filled_by'] ?? ''),
        $category, surveyReportingMethod($record),
        $snapshot['version_number'] ?? '',
        $record['scoring_configuration_id'] ?? '',
    ];
    foreach (['performance', 'stakeholder', 'service'] as $section) {
        $row[] = $snapshot['sections'][$section]['allocation_percent'] ?? '';
        $row[] = $snapshot['sections'][$section]['score_percent'] ?? '';
    }
    $row[] = $snapshot['overall_score_percent'] ?? '';
    $definitions = [
        'performance' => STAKEHOLDER_SURVEY_V2_PERFORMANCE,
        'stakeholder' => STAKEHOLDER_SURVEY_V2_CATEGORIES[$category] ?? [],
        'service' => STAKEHOLDER_SURVEY_V2_SERVICE_QUESTIONS,
    ];
    foreach ($definitions['performance'] as $key => $definition) {
        $row[] = surveyReportingQuestionCell($snapshot['sections']['performance']['questions'][$key] ?? null);
    }
    // Exactly two columns for the stakeholder category selected on submission.
    $stakeholderQuestions = array_slice($definitions['stakeholder'], 0, 2, true);
    $keys = array_keys($stakeholderQuestions);
    for ($i = 0; $i < 2; $i++) {
        $key = $keys[$i] ?? null;
        $row[] = $key === null ? '' : $stakeholderQuestions[$key]['title'] . ' — '
            . surveyReportingQuestionCell($snapshot['sections']['stakeholder']['questions'][$key] ?? null);
    }
    foreach ($definitions['service'] as $key => $definition) {
        $row[] = surveyReportingQuestionCell($snapshot['sections']['service']['questions'][$key] ?? null);
    }
    $row[] = $snapshot === null ? 'Historical response or missing stored breakdown: no contributions inferred.' : '';
    return $row;
}

function surveyReportingQuestionCell(?array $question): string
{
    if (!$question) return '';
    return (string) ($question['rating'] ?? '—')
        . ' | factor ' . (string) ($question['rating_factor_percent'] ?? '—') . '%'
        . ' | weight ' . (string) ($question['weight_percent'] ?? '—') . '%'
        . ' | contribution ' . (string) ($question['contribution_percent'] ?? '—') . ' pp';
}
