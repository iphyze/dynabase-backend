<?php
declare(strict_types=1);

/**
 * Versioned scoring configuration for the 16-question Stakeholder Survey.
 *
 * API percent values are strings with at most two decimal places. Database
 * values are integer basis points (0.01%), so validation and future score
 * calculation do not accumulate binary floating-point rounding errors.
 *
 * No public survey API includes this configuration. Batch 2 will bind the
 * active configuration to each newly created survey session.
 */
require_once __DIR__ . '/stakeholderSurveyV2.php';
require_once __DIR__ . '/dbHelpers.php';

const CLIENT_SURVEY_SCORING_SECTION_KEYS = ['performance', 'stakeholder', 'service'];

function surveyScoringExpectedQuestions(): array
{
    return [
        'performance' => array_keys(STAKEHOLDER_SURVEY_V2_PERFORMANCE),
        'stakeholder' => array_map('array_keys', STAKEHOLDER_SURVEY_V2_CATEGORIES),
        'service' => array_keys(STAKEHOLDER_SURVEY_V2_SERVICE_QUESTIONS),
    ];
}

function surveyScoringPercentToBps(mixed $value, string $label): int
{
    if (!is_string($value) && !is_int($value) && !is_float($value)) {
        throw new RuntimeException("{$label} must be a percentage.", 422);
    }
    $text = trim((string) $value);
    if (!preg_match('/^(?:0|[1-9][0-9]{0,2})(?:\.([0-9]{1,2}))?$/D', $text, $matches)) {
        throw new RuntimeException("{$label} must have at most two decimal places.", 422);
    }
    $parts = explode('.', $text, 2);
    $bps = ((int) $parts[0] * 100) + (int) str_pad($parts[1] ?? '', 2, '0');
    if ($bps < 1 || $bps > 10000) {
        throw new RuntimeException("{$label} must be greater than 0% and no more than 100%.", 422);
    }
    return $bps;
}

function surveyScoringBpsToPercent(int $bps): string
{
    return number_format($bps / 100, 2, '.', '');
}

function surveyScoringAssertKeys(array $actual, array $expected, string $label): void
{
    $missing = array_diff($expected, array_keys($actual));
    $extra = array_diff(array_keys($actual), $expected);
    if ($missing !== [] || $extra !== []) {
        throw new RuntimeException("{$label} must contain exactly the required survey items.", 422);
    }
}

/**
 * Normalizes an entire scoring snapshot. An incomplete or nonpositive draft is
 * rejected; drafts with *incorrect totals* are allowed, but activation is not.
 * @return array{sections: array<string,int>, questions: array, errors: list<string>}
 */
function surveyScoringNormalize(array $input): array
{
    $sections = $input['sections'] ?? null;
    $questions = $input['questions'] ?? null;
    if (!is_array($sections) || !is_array($questions)) {
        throw new RuntimeException('Section allocations and question weights are required.', 422);
    }
    surveyScoringAssertKeys($sections, CLIENT_SURVEY_SCORING_SECTION_KEYS, 'Section allocations');
    surveyScoringAssertKeys($questions, CLIENT_SURVEY_SCORING_SECTION_KEYS, 'Question weight groups');

    $normalizedSections = [];
    foreach (CLIENT_SURVEY_SCORING_SECTION_KEYS as $section) {
        $normalizedSections[$section] = surveyScoringPercentToBps($sections[$section], ucfirst($section) . ' allocation');
    }

    $expected = surveyScoringExpectedQuestions();
    $normalizedQuestions = [
        'performance' => [],
        'stakeholder' => [],
        'service' => [],
    ];
    foreach (['performance', 'service'] as $section) {
        if (!is_array($questions[$section])) {
            throw new RuntimeException('Invalid ' . $section . ' question weights.', 422);
        }
        surveyScoringAssertKeys($questions[$section], $expected[$section], ucfirst($section) . ' questions');
        foreach ($expected[$section] as $key) {
            $normalizedQuestions[$section][$key] = surveyScoringPercentToBps(
                $questions[$section][$key], $section . ' / ' . $key
            );
        }
    }
    if (!is_array($questions['stakeholder'])) {
        throw new RuntimeException('Stakeholder category weights are required.', 422);
    }
    surveyScoringAssertKeys($questions['stakeholder'], array_keys($expected['stakeholder']), 'Stakeholder categories');
    foreach ($expected['stakeholder'] as $category => $keys) {
        if (!is_array($questions['stakeholder'][$category])) {
            throw new RuntimeException('Invalid question weights for ' . $category . '.', 422);
        }
        surveyScoringAssertKeys($questions['stakeholder'][$category], $keys, $category . ' questions');
        foreach ($keys as $key) {
            $normalizedQuestions['stakeholder'][$category][$key] = surveyScoringPercentToBps(
                $questions['stakeholder'][$category][$key], $category . ' / ' . $key
            );
        }
    }

    $errors = [];
    $sectionSum = array_sum($normalizedSections);
    if ($sectionSum !== 10000) {
        $errors[] = 'Section allocations total ' . surveyScoringBpsToPercent($sectionSum) . '%; exactly 100.00% is required.';
    }
    foreach (['performance', 'service'] as $section) {
        $actual = array_sum($normalizedQuestions[$section]);
        if ($actual !== $normalizedSections[$section]) {
            $errors[] = ucfirst($section) . ' question weights total ' . surveyScoringBpsToPercent($actual)
                . '%, but the section allocation is ' . surveyScoringBpsToPercent($normalizedSections[$section]) . '%.';
        }
    }
    foreach ($normalizedQuestions['stakeholder'] as $category => $values) {
        $actual = array_sum($values);
        if ($actual !== $normalizedSections['stakeholder']) {
            $errors[] = $category . ' question weights total ' . surveyScoringBpsToPercent($actual)
                . '%, but the stakeholder allocation is ' . surveyScoringBpsToPercent($normalizedSections['stakeholder']) . '%.';
        }
    }

    return ['sections' => $normalizedSections, 'questions' => $normalizedQuestions, 'errors' => $errors];
}

/** Loads an immutable active/retired configuration or an editable draft. */
function surveyScoringLoad(mysqli $conn, int $id): array
{
    $record = dbFetchOne(
        $conn,
        "SELECT c.id, c.version_number, c.title, c.status, c.created_by, c.modified_by, c.activated_by,
                c.created_at, c.updated_at, c.activated_at,
                NULLIF(TRIM(CONCAT(COALESCE(editor.first_name, ''), ' ', COALESCE(editor.last_name, ''))), '') AS modified_by_name,
                NULLIF(TRIM(CONCAT(COALESCE(activator.first_name, ''), ' ', COALESCE(activator.last_name, ''))), '') AS activated_by_name
         FROM client_survey_scoring_configurations c
         LEFT JOIN users editor ON editor.id = c.modified_by
         LEFT JOIN users activator ON activator.id = c.activated_by
         WHERE c.id = ?",
        'i', [$id]
    );
    if (!$record) {
        throw new RuntimeException('Survey scoring configuration not found.', 404);
    }
    $sections = [];
    $questions = ['performance' => [], 'stakeholder' => [], 'service' => []];
    foreach (dbFetchAll($conn, 'SELECT section_code, allocation_bps FROM client_survey_scoring_sections WHERE configuration_id = ?', 'i', [$id]) as $row) {
        $sections[$row['section_code']] = surveyScoringBpsToPercent((int) $row['allocation_bps']);
    }
    foreach (dbFetchAll($conn, 'SELECT section_code, stakeholder_category, question_key, weight_bps FROM client_survey_scoring_questions WHERE configuration_id = ?', 'i', [$id]) as $row) {
        $section = (string) $row['section_code'];
        $percent = surveyScoringBpsToPercent((int) $row['weight_bps']);
        if ($section === 'stakeholder') {
            $questions[$section][$row['stakeholder_category']][$row['question_key']] = $percent;
        } elseif ($section === 'performance' || $section === 'service') {
            $questions[$section][$row['question_key']] = $percent;
        }
    }
    return array_merge($record, [
        'id' => (int) $record['id'],
        'version_number' => (int) $record['version_number'],
        'sections' => $sections,
        'questions' => $questions,
    ]);
}

/** Replaces the question and section rows of an existing *draft* within a transaction. */
function surveyScoringWriteWeights(mysqli $conn, int $id, array $normalized): void
{
    $stmt = dbExecute($conn, 'DELETE FROM client_survey_scoring_questions WHERE configuration_id = ?', 'i', [$id]);
    $stmt->close();
    $stmt = dbExecute($conn, 'DELETE FROM client_survey_scoring_sections WHERE configuration_id = ?', 'i', [$id]);
    $stmt->close();

    foreach ($normalized['sections'] as $key => $bps) {
        $stmt = dbExecute(
            $conn, 'INSERT INTO client_survey_scoring_sections (configuration_id, section_code, allocation_bps) VALUES (?, ?, ?)',
            'isi', [$id, $key, $bps]
        );
        $stmt->close();
    }
    foreach (['performance', 'service'] as $section) {
        foreach ($normalized['questions'][$section] as $key => $bps) {
            $stmt = dbExecute(
                $conn, 'INSERT INTO client_survey_scoring_questions (configuration_id, section_code, stakeholder_category, question_key, weight_bps) VALUES (?, ?, ?, ?, ?)',
                'isssi', [$id, $section, '', $key, $bps]
            );
            $stmt->close();
        }
    }
    foreach ($normalized['questions']['stakeholder'] as $category => $weights) {
        foreach ($weights as $key => $bps) {
            $stmt = dbExecute(
                $conn, 'INSERT INTO client_survey_scoring_questions (configuration_id, section_code, stakeholder_category, question_key, weight_bps) VALUES (?, ?, ?, ?, ?)',
                'isssi', [$id, 'stakeholder', $category, $key, $bps]
            );
            $stmt->close();
        }
    }
}

/** All mutations lock the singleton row first, serializing drafts and activation. */
function surveyScoringLockState(mysqli $conn): array
{
    $state = dbFetchOne($conn, 'SELECT * FROM client_survey_scoring_state WHERE id = 1 FOR UPDATE');
    if (!$state) {
        throw new RuntimeException('Survey scoring has not been initialized. Apply the Batch 1 migration.', 503);
    }
    return $state;
}

function surveyScoringSaveDraft(mysqli $conn, array $actor, array $payload): array
{
    $normalized = surveyScoringNormalize($payload); // Check complete, positive entries before opening transaction.
    $id = (int) ($payload['configuration_id'] ?? 0);
    if (isset($payload['title']) && !is_string($payload['title'])) {
        throw new RuntimeException('Configuration title must be text.', 422);
    }
    if (isset($payload['configuration_id'])
        && filter_var($payload['configuration_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
        throw new RuntimeException('Invalid configuration ID.', 422);
    }
    $title = trim($payload['title'] ?? '');
    if (strlen($title) > 160) {
        throw new RuntimeException('Configuration title must not exceed 160 characters.', 422);
    }
    $actorId = (int) $actor['id'];
    $conn->begin_transaction();
    try {
        $state = surveyScoringLockState($conn);
        if ($id > 0) {
            $row = dbFetchOne($conn, 'SELECT status FROM client_survey_scoring_configurations WHERE id = ? FOR UPDATE', 'i', [$id]);
            if (!$row) throw new RuntimeException('Scoring configuration not found.', 404);
            if ($row['status'] !== 'draft') throw new RuntimeException('Activated or retired configurations cannot be edited. Create a new draft instead.', 409);
            $stmt = dbExecute($conn, 'UPDATE client_survey_scoring_configurations SET title = ?, modified_by = ? WHERE id = ?', 'sii', [$title !== '' ? $title : 'Scoring draft', $actorId, $id]);
            $stmt->close();
        } else {
            $version = (int) $state['next_version_number'];
            $stmt = dbExecute($conn, 'INSERT INTO client_survey_scoring_configurations (version_number, title, status, created_by, modified_by) VALUES (?, ?, \'draft\', ?, ?)', 'isii', [$version, $title !== '' ? $title : 'Scoring draft', $actorId, $actorId]);
            $id = (int) $stmt->insert_id;
            $stmt->close();
            $stmt = dbExecute($conn, 'UPDATE client_survey_scoring_state SET next_version_number = ? WHERE id = 1', 'i', [$version + 1]);
            $stmt->close();
        }
        surveyScoringWriteWeights($conn, $id, $normalized);
        $conn->commit();
        return ['configuration' => surveyScoringLoad($conn, $id), 'validation' => [
            'valid' => $normalized['errors'] === [], 'errors' => $normalized['errors'],
        ]];
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

function surveyScoringActivate(mysqli $conn, array $actor, int $id): array
{
    if ($id <= 0) throw new RuntimeException('A draft configuration is required.', 422);
    $conn->begin_transaction();
    try {
        $state = surveyScoringLockState($conn);
        $row = dbFetchOne($conn, 'SELECT id, status, version_number FROM client_survey_scoring_configurations WHERE id = ? FOR UPDATE', 'i', [$id]);
        if (!$row) throw new RuntimeException('Scoring configuration not found.', 404);
        if ($row['status'] !== 'draft') throw new RuntimeException('Only a draft can be activated.', 409);
        $active = dbFetchOne($conn, 'SELECT version_number FROM client_survey_scoring_configurations WHERE id = ?', 'i', [(int) $state['active_configuration_id']]);
        if (!$active || (int) $row['version_number'] <= (int) $active['version_number']) {
            throw new RuntimeException('Only a newer scoring version can replace the active configuration.', 409);
        }

        $snapshot = surveyScoringLoad($conn, $id);
        $validated = surveyScoringNormalize($snapshot);
        if ($validated['errors'] !== []) {
            throw new RuntimeException('Cannot activate: ' . implode(' ', $validated['errors']), 422);
        }
        $previousId = (int) $state['active_configuration_id'];
        $stmt = dbExecute($conn, "UPDATE client_survey_scoring_configurations SET status = 'retired' WHERE id = ? AND status = 'active'", 'i', [$previousId]);
        $stmt->close();
        $stmt = dbExecute($conn, "UPDATE client_survey_scoring_configurations SET status = 'active', activated_at = CURRENT_TIMESTAMP, activated_by = ?, modified_by = ? WHERE id = ? AND status = 'draft'", 'iii', [(int) $actor['id'], (int) $actor['id'], $id]);
        $stmt->close();
        $stmt = dbExecute($conn, 'UPDATE client_survey_scoring_state SET active_configuration_id = ? WHERE id = 1', 'i', [$id]);
        $stmt->close();
        $conn->commit();
        return ['configuration' => surveyScoringLoad($conn, $id), 'previous_configuration_id' => $previousId];
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}
