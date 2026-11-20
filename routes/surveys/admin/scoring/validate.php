<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../includes/request.php';
require_once __DIR__ . '/../../../../includes/surveyScoringConfigurations.php';

requireMethod('POST');
$authUser = authenticateUser();
$payload = readJsonBody();
$validated = surveyScoringNormalize($payload);
jsonResponse(['status' => 'Success', 'data' => [
    'valid' => $validated['errors'] === [],
    'errors' => $validated['errors'],
    'total_percent' => surveyScoringBpsToPercent(array_sum($validated['sections'])),
]]);
