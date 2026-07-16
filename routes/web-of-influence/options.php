<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/webOfInfluence.php';

requireMethod('GET');
$authUser = authenticateUser();

jsonResponse([
    'status' => 'Success',
    'message' => 'Web of Influence options retrieved successfully.',
    'data' => [
        'authorities' => WOI_AUTHORITIES,
        'influence_levels' => WOI_LEVELS,
        'company_recommendations' => WOI_RECOMMENDATIONS,
        'personal_win_levels' => WOI_LEVELS,
    ],
]);
