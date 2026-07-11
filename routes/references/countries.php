<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/authorization.php';

requireMethod('GET');
authenticateUser();

$countries = [
    ['value' => 'Nigeria', 'label' => 'Nigeria', 'code' => 'NGN', 'city_source' => 'ngn_cities'],
    ['value' => 'Ghana', 'label' => 'Ghana', 'code' => 'GHA', 'city_source' => 'gha_cities'],
    ['value' => 'Ivory Coast', 'label' => 'Ivory Coast', 'code' => 'CIV', 'city_source' => 'civ_cities'],
];

jsonResponse([
    'status' => 'Success',
    'message' => 'Countries retrieved successfully.',
    'data' => $countries,
]);
