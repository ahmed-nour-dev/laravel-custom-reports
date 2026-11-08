<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Builder\Registry;

/**
 * A parent record reachable from a source: adds columns, never rows. Joined
 * as a LEFT JOIN through a BelongsTo on the source's model, or by a foreign
 * key column when the model has no BelongsTo for it.
 */
final readonly class Lookup
{
    /**
     * @param  class-string<ReportableSource>  $target
     */
    private function __construct(
        public string $name,
        public string $target,
        public ?string $relation,
        public ?string $foreignKey,
    ) {}

    /**
     * @param  class-string<ReportableSource>  $target
     */
    public static function to(string $name, string $target, string $relation): self
    {
        return new self($name, $target, $relation, null);
    }

    /**
     * Joins $foreignKey on this model to the target's primary key.
     *
     * @param  class-string<ReportableSource>  $target
     */
    public static function byKey(string $name, string $target, string $foreignKey): self
    {
        return new self($name, $target, null, $foreignKey);
    }
}
