<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/request.php';

requireMethod('GET');

$rotate = in_array((string) ($_GET['rotate'] ?? ''), ['1', 'true', 'yes'], true);
$csrfToken = issueCsrfCookie($rotate);

header('Cache-Control: no-store');
jsonResponse([
    'status' => 'Success',
    'message' => 'CSRF protection initialised.',
    'data' => [
        'csrfToken' => $csrfToken
    ]
]);
