<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Data;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Data object wrapping a paginated report result and its aggregates.
 *
 * @phpstan-type AggregateMap array<string, mixed>
 */
final readonly class ReportResult implements Responsable
{
    /**
     * @param  LengthAwarePaginator<int, Model>  $rows
     * @param  AggregateMap  $aggregates
     * @param  array<string, mixed>  $appliedFilters
     */
    public function __construct(
        public LengthAwarePaginator $rows,
        public array $aggregates,
        public array $appliedFilters,
    ) {}

    /**
     * Convert the result to a JSON response suitable for the API.
     */
    public function toResponse($request): JsonResponse
    {
        return response()->json([
            'data' => $this->rows->items(),
            'aggregates' => $this->aggregates,
            'applied_filters' => $this->appliedFilters,
            'meta' => [
                'current_page' => $this->rows->currentPage(),
                'last_page' => $this->rows->lastPage(),
                'per_page' => $this->rows->perPage(),
                'total' => $this->rows->total(),
            ],
        ]);
    }
}
