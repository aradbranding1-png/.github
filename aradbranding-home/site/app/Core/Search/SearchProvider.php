<?php

declare(strict_types=1);

namespace App\Core\Search;

/**
 * Search abstraction (doc §99): MySQL Full-Text today, OpenSearch later — callers never change.
 */
interface SearchProvider
{
    /**
     * @param 'traders'|'pages'|'proposals' $type
     * @param array{country?: ?int} $filters
     * @return array{rows: list<array<string, mixed>>, more: bool}
     */
    public function search(string $type, string $query, array $filters, int $page): array;
}
