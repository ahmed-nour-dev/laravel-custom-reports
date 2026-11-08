<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Data;

use AhmedNour\CustomReports\Enums\DateGranularity;

/** A summary row grouping; date fields group by a granularity. */
final readonly class Grouping
{
    public function __construct(
        public string $field,
        public ?DateGranularity $granularity = null,
    ) {}

    /**
     * @return array{field: string, granularity: string|null}
     */
    public function toArray(): array
    {
        return ['field' => $this->field, 'granularity' => $this->granularity?->value];
    }
}
