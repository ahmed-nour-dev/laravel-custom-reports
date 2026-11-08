<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Builder\Registry;

/**
 * A resolved join between two sources: `child.foreignKey = parent.ownerKey`
 * (+ `child.morphType = morphClass` for a polymorphic child). Child links and
 * lookups both resolve to one; they differ only in which side gets joined.
 */
final readonly class Edge
{
    public function __construct(
        public string $key,
        public ReportableSource $child,
        public ReportableSource $parent,
        public string $foreignKey,
        public string $ownerKey,
        public ?string $morphType = null,
        public ?string $morphClass = null,
    ) {}
}
