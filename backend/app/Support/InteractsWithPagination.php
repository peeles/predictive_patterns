<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

trait InteractsWithPagination
{
    protected function resolvePerPage(Request $request, int $default = 15, int $max = 100): int
    {
        $perPage = (int) $request->integer('per_page', $default);

        return max(1, min($perPage, $max));
    }

    /**
     * @param Request $request
     * @param array<string, string|array{column: string, direction?: string}> $allowed
     * @param string $defaultColumn
     * @param 'asc'|'desc' $defaultDirection
     *
     * @return array{0: string, 1: 'asc'|'desc'}
     */
    protected function resolveSort(Request $request, array $allowed, string $defaultColumn, string $defaultDirection = 'asc'): array
    {
        $raw = $request->query('sort');

        if (is_array($raw)) {
            return [$defaultColumn, $defaultDirection];
        }

        $sort = (string) $raw;

        if ($sort === '') {
            return [$defaultColumn, $defaultDirection];
        }

        $direction = Str::startsWith($sort, '-') ? 'desc' : 'asc';
        $key = ltrim($sort, '-');

        if (!array_key_exists($key, $allowed)) {
            return [$defaultColumn, $defaultDirection];
        }

        $column = $allowed[$key];

        if (is_array($column)) {
            $direction = $column['direction'] ?? $direction;
            $column = $column['column'];
        }

        return [$column, $direction === 'desc' ? 'desc' : 'asc'];
    }

    /**
     * @template TItem
     * @param LengthAwarePaginator<TItem> $paginator
     * @param callable(TItem): array $transformer
     */
    protected function formatPaginatedResponse(LengthAwarePaginator $paginator, callable $transformer): array
    {
        $data = $paginator
            ->getCollection()
            ->map(fn ($item) => $transformer($item))
            ->all();

        return [
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
        ];
    }
}

