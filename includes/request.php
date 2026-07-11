<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function requestMethod(): string
{
    return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
}

function requireMethod(string $method): void
{
    if (requestMethod() !== strtoupper($method)) {
        jsonResponse([
            'status' => 'Failed',
            'message' => 'Method not allowed.'
        ], 405);
    }
}

function readJsonBody(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);

    if (!is_array($data)) {
        throw new RuntimeException('Invalid request payload.', 400);
    }

    return $data;
}

function cleanString(mixed $value): string
{
    return trim((string) $value);
}

function cleanEmail(mixed $value): string
{
    return strtolower(trim((string) $value));
}
