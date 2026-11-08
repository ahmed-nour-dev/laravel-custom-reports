<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Data;

use AhmedNour\CustomReports\Enums\ChartType;

/**
 * A summary or matrix report's chart (SPEC §14.5): `x` is the first row
 * grouping (`g0`) — or the first column grouping (`x0`) on a matrix with no
 * row grouping — `series` splits `g0` by the second row grouping (`g1`) or
 * the first column grouping (`x0`), and `value` is `record_count` or an
 * aggregate (`a{i}`). A metric or gauge shows the grand total; a gauge
 * needs a `target`.
 */
final readonly class ChartSpec
{
    public function __construct(
        public ChartType $type,
        public string $value = ReportDefinition::RECORD_COUNT,
        public ?string $x = null,
        public ?string $series = null,
        public int|float|null $target = null,
    ) {}

    /**
     * @return array{type: string, value: string, x: string|null, series: string|null, target: int|float|null}
     */
    public function toArray(): array
    {
        return ['type' => $this->type->value, 'value' => $this->value, 'x' => $this->x, 'series' => $this->series, 'target' => $this->target];
    }
}
