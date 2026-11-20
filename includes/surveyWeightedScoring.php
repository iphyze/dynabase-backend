<?php
declare(strict_types=1);

require_once __DIR__ . '/surveyScoringConfigurations.php';

/** Bind one immutable scoring configuration to a newly issued public survey session. */
function surveyActiveScoringConfigurationId(mysqli $conn): int
{
    $row = dbFetchOne($conn,
        "SELECT s.active_configuration_id FROM client_survey_scoring_state s
         INNER JOIN client_survey_scoring_configurations c ON c.id = s.active_configuration_id
         WHERE s.id = 1 AND c.status = 'active' LIMIT 1");
    if (!$row || (int) $row['active_configuration_id'] < 1) {
        throw new RuntimeException('Survey scoring is temporarily unavailable. Please try again later.', 503);
    }
    return (int) $row['active_configuration_id'];
}

/** Computes a reproducible private scoring snapshot; never return to the public form. */
function surveyCalculateWeightedScore(mysqli $conn, int $configurationId, array $data): array
{
    return surveyCalculateWeightedScoreFromSnapshot(surveyScoringLoad($conn, $configurationId), $data);
}

/** Pure calculation for deterministic regression tests against stored versions. */
function surveyCalculateWeightedScoreFromSnapshot(array $configuration, array $data): array
{
    $configurationId = (int) $configuration['id'];
    if (!in_array($configuration['status'], ['active', 'retired'], true)) {
        throw new RuntimeException('The assigned survey scoring version is not valid.', 409);
    }
    $answers = json_decode((string) $data['survey_answers'], true, 512, JSON_THROW_ON_ERROR);
    $category = $data['stakeholder_category'];
    $weights = $configuration['questions'];
    $groups = [
        'performance' => ['ratings' => $answers['performance_ratings'], 'weights' => $weights['performance']],
        'stakeholder' => ['ratings' => $answers['stakeholder_ratings'], 'weights' => $weights['stakeholder'][$category] ?? []],
        'service' => ['ratings' => $answers['service_ratings'], 'weights' => $weights['service']],
    ];
    $ratingFactors = ['A' => 100, 'B' => 75, 'C' => 50, 'D' => 25];
    $weightedHundredths = 0;
    $weightTotal = 0;
    $breakdown = [];
    foreach ($groups as $section => $group) {
        if (array_keys($group['ratings']) !== array_keys($group['weights'])) {
            // Order does not matter, but no missing or additional scored questions are permitted.
            if (array_diff_key($group['ratings'], $group['weights']) || array_diff_key($group['weights'], $group['ratings'])) {
                throw new RuntimeException('Survey scoring configuration does not match the selected questions.', 409);
            }
        }
        $sectionBps = 0;
        $sectionContributions = 0;
        $questions = [];
        foreach ($group['weights'] as $key => $percent) {
            $rating = $group['ratings'][$key] ?? null;
            if (!is_string($rating) || !array_key_exists($rating, $ratingFactors)) {
                throw new RuntimeException('All assessment questions must have a valid A–D rating.', 422);
            }
            $bps = surveyScoringPercentToBps($percent, "Weight of {$key}");
            $numerator = $bps * $ratingFactors[$rating]; // score in 1/100 of one percent after division by 100
            $sectionBps += $bps;
            $sectionContributions += $numerator;
            $weightedHundredths += $numerator;
            $weightTotal += $bps;
            $questions[$key] = [
                'rating' => $rating,
                'rating_factor_percent' => $ratingFactors[$rating],
                'weight_percent' => surveyScoringBpsToPercent($bps),
                'contribution_percent' => number_format($numerator / 10000, 4, '.', ''),
            ];
        }
        $expectedBps = surveyScoringPercentToBps($configuration['sections'][$section], "{$section} allocation");
        if ($sectionBps !== $expectedBps) {
            throw new RuntimeException('Assigned scoring configuration is not balanced.', 409);
        }
        $breakdown[$section] = [
            'allocation_percent' => surveyScoringBpsToPercent($sectionBps),
            'score_percent' => number_format($sectionContributions / 10000, 4, '.', ''),
            'questions' => $questions,
        ];
    }
    if ($weightTotal !== 10000 || count($breakdown['performance']['questions']) !== 12
        || count($breakdown['stakeholder']['questions']) !== 2 || count($breakdown['service']['questions']) !== 2) {
        throw new RuntimeException('The assigned survey scoring configuration must total exactly 100%.', 409);
    }
    return [
        'score' => round($weightedHundredths / 10000, 2),
        'json' => json_encode([
            'method' => 'weighted_v1',
            'configuration_id' => $configurationId,
            'version_number' => $configuration['version_number'],
            'stakeholder_category' => $category,
            'total_weight_percent' => '100.00',
            'overall_score_percent' => number_format($weightedHundredths / 10000, 4, '.', ''),
            'sections' => $breakdown,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ];
}
