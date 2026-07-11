<?php
declare(strict_types=1);

function paginationParams(): array
{
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $limit = (int) ($_GET['limit'] ?? 20);
    $limit = max(5, min($limit, 100));
    $offset = ($page - 1) * $limit;

    return [$page, $limit, $offset];
}

function paginationMeta(int $page, int $limit, int $total): array
{
    return [
        'page' => $page,
        'limit' => $limit,
        'total' => $total,
        'total_pages' => $limit > 0 ? (int) ceil($total / $limit) : 0,
        'has_next_page' => ($page * $limit) < $total,
        'has_previous_page' => $page > 1,
    ];
}
