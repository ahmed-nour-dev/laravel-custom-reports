<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Data;

final readonly class SortOrder
{
    /**
     * @param  'asc'|'desc'  $dir
     */
    public function __construct(
        public string $field,
        public string $dir = 'asc',
    ) {}

    /**
     * @return array{field: string, dir: string}
     */
    public function toArray(): array
    {
        return ['field' => $this->field, 'dir' => $this->dir];
    }
}
