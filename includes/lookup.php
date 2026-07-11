<?php
declare(strict_types=1);

require_once __DIR__ . '/dbHelpers.php';
require_once __DIR__ . '/request.php';

function lookupLimit(int $default = 20, int $max = 50): int
{
    $limit = (int) ($_GET['limit'] ?? $default);
    return max(5, min($limit, $max));
}

function lookupOffset(): int
{
    $offset = (int) ($_GET['offset'] ?? 0);
    return max(0, $offset);
}

function lookupSearchTerm(string $key = 'q'): string
{
    $q = cleanString($_GET[$key] ?? '');
    $maxLength = 100;
    if ((function_exists('mb_strlen') ? mb_strlen($q) : strlen($q)) > $maxLength) {
        throw new RuntimeException('Search term is too long.', 422);
    }

    return $q;
}

function likeTerm(string $q): string
{
    return '%' . $q . '%';
}

function optionRow(mixed $value, string $label, array $meta = []): array
{
    return [
        'value' => $value,
        'label' => $label,
        'meta' => $meta,
    ];
}

function lookupResponse(string $message, array $data, int $limit, int $offset, ?int $total = null): never
{
    jsonResponse([
        'status' => 'Success',
        'message' => $message,
        'data' => $data,
        'meta' => [
            'limit' => $limit,
            'offset' => $offset,
            'returned' => count($data),
            'total' => $total,
            'has_more' => $total !== null ? ($offset + count($data)) < $total : count($data) === $limit,
        ],
    ]);
}

function normalizeCountryName(string $country): string
{
    $country = strtolower(trim($country));
    $country = str_replace(['_', '-'], ' ', $country);
    $country = preg_replace('/\s+/', ' ', $country) ?: $country;

    return match ($country) {
        'nigeria', 'ng', 'nga' => 'Nigeria',
        'ghana', 'gh', 'gha' => 'Ghana',
        'ivory coast', 'cote d ivoire', "cote d'ivoire", 'civ', 'ci' => 'Ivory Coast',
        default => '',
    };
}

function cityTableForCountry(string $country): ?string
{
    return match (normalizeCountryName($country)) {
        'Nigeria' => 'ngn_cities',
        'Ghana' => 'gha_cities',
        'Ivory Coast' => 'civ_cities',
        default => null,
    };
}
