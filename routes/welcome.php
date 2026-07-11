<?php
declare(strict_types=1);

jsonResponse([
    'status' => 'Success',
    'message' => 'Dynabase API is running.',
    'data' => [
        'service' => 'Dynabase API',
        'version' => '1.0.0'
    ]
]);
