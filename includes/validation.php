<?php
declare(strict_types=1);

require_once __DIR__ . '/request.php';

function requireStringField(array $payload, string $field, string $label, int $maxLength = 255): string
{
    $value = cleanString($payload[$field] ?? '');
    if ($value === '') {
        throw new RuntimeException("{$label} is required.", 422);
    }

    $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    if ($length > $maxLength) {
        throw new RuntimeException("{$label} must not exceed {$maxLength} characters.", 422);
    }

    return $value;
}

function optionalStringField(array $payload, string $field, int $maxLength = 255): string
{
    $value = cleanString($payload[$field] ?? '');
    $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    if ($length > $maxLength) {
        throw new RuntimeException(str_replace('_', ' ', ucfirst($field)) . " must not exceed {$maxLength} characters.", 422);
    }

    return $value;
}

function optionalEmailField(array $payload, string $field, string $label): string
{
    $email = cleanEmail($payload[$field] ?? '');
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException("{$label} must be a valid email address.", 422);
    }

    return $email;
}

function requiredIntFromRequest(string $field = 'id'): int
{
    $value = (int) ($_GET[$field] ?? 0);
    if ($value <= 0) {
        throw new RuntimeException('A valid record ID is required.', 422);
    }

    return $value;
}

function requiredIntFromPayload(array $payload, string $field, string $label): int
{
    $value = (int) ($payload[$field] ?? 0);
    if ($value <= 0) {
        throw new RuntimeException("{$label} is required.", 422);
    }

    return $value;
}

function composeAddressWithLocation(string $address, string $city = '', string $country = ''): string
{
    $parts = array_values(array_filter(array_map('trim', [$city, $country])));
    if ($parts === []) {
        return $address;
    }

    $location = implode(', ', $parts);
    if ($address === '') {
        return $location;
    }

    if (stripos($address, $location) !== false) {
        return $address;
    }

    return trim($address . ' ' . $location);
}
