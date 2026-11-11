<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Safe list-query helpers. Dynamic sort fields are resolved against
 * config-defined ALLOWLISTS — user input is never interpolated into SQL.
 */
class ListQuery
{
    public static function applySort(Builder $query, Request $request, string $sortableKey, string $defaultColumn, string $defaultDir = 'desc'): Builder
    {
        $allowed  = config("pos.sortable.{$sortableKey}", []);
        $column   = (string) $request->query('sort', $defaultColumn);
        $dir      = strtolower((string) $request->query('dir', $defaultDir)) === 'asc' ? 'asc' : 'desc';

        if (! in_array($column, $allowed, true)) {
            $column = $defaultColumn;
        }

        return $query->orderBy($column, $dir);
    }

    public static function perPage(Request $request, int $default = 15, int $max = 100): int
    {
        $perPage = (int) $request->query('per_page', $default);

        return max(1, min($perPage, $max));
    }

    /** Escape LIKE wildcards so search terms are matched literally. */
    public static function like(string $term): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($term)).'%';
    }
}
