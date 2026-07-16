<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/mailer.php';

requireMethod('GET');
$authUser = authenticateUser();

$catalog = array_values(dynabaseEmailTemplateCatalog());
$categoryCounts = [];
foreach ($catalog as $template) {
    $category = (string) ($template['category'] ?? 'Other');
    $categoryCounts[$category] = ($categoryCounts[$category] ?? 0) + 1;
}

jsonResponse([
    'status' => 'Success',
    'message' => 'Email templates retrieved successfully.',
    'data' => [
        'items' => $catalog,
        'summary' => [
            'total' => count($catalog),
            'categories' => count($categoryCounts),
            'survey_templates' => $categoryCounts['Client surveys'] ?? 0,
            'mail_enabled' => envBool('MAIL_ENABLED', false),
            'transport' => dynabaseMailTransport(),
        ],
    ],
]);
