<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Data;

/**
 * Conditional highlighting (SPEC §14.6, 9b), for display only: a total
 * (`record_count`, `a{i}`) or summary formula (`f{i}`) coloured in three
 * bands — below `low`, from `low` up to `high`, and `high` or more. A band's
 * colour may be null (no colour).
 */
final readonly class Highlight
{
    public const int MAX = 3;

    /** CoreUI table colours a band may use. */
    public const array COLORS = ['danger', 'warning', 'success', 'info', 'primary', 'secondary'];

    public function __construct(
        public string $target,
        public int|float $low,
        public int|float $high,
        public ?string $lowColor = 'danger',
        public ?string $midColor = 'warning',
        public ?string $highColor = 'success',
    ) {}

    /**
     * @return array{target: string, low: int|float, high: int|float, colors: array{string|null, string|null, string|null}}
     */
    public function toArray(): array
    {
        return ['target' => $this->target, 'low' => $this->low, 'high' => $this->high, 'colors' => [$this->lowColor, $this->midColor, $this->highColor]];
    }
}
