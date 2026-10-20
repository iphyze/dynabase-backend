<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/pmsPortalAuth.php';
require_once __DIR__ . '/../../includes/dbHelpers.php';

requireMethod('GET');
pmsPortalCurrentUser($conn);
$categories = dbFetchAll($conn, 'SELECT category_name AS value, category_name AS label FROM clients_category_table ORDER BY category_name');
header('Cache-Control: no-store');
jsonResponse(['status' => 'Success', 'data' => [
    'categories' => $categories,
    'countries' => [
        ['value' => 'Nigeria', 'label' => 'Nigeria'],
        ['value' => 'Ghana', 'label' => 'Ghana'],
        ['value' => 'Ivory Coast', 'label' => 'Ivory Coast'],
    ],
]]);
