<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Data;

use AhmedNour\CustomReports\Enums\AggregateFunction;

final readonly class AggregateSpec
{
    public function __construct(
        public string $field,
        public AggregateFunction $fn,
    ) {}

    /**
     * @return array{field: string, fn: string}
     */
    public function toArray(): array
    {
        return ['field' => $this->field, 'fn' => $this->fn->value];
    }
}
