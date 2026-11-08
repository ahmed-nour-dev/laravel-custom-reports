<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Builder\Registry;

/**
 * "This source is a child of $parent", declared from the child's side so no
 * inverse relation is ever added to the parent's module (SPEC §3.2). The join
 * keys are read from the child's existing BelongsTo (or MorphTo) relation.
 */
final readonly class ChildLink
{
    /**
     * @param  class-string<ReportableSource>  $parent
     */
    private function __construct(
        public string $parent,
        public string $relation,
        public bool $morph,
    ) {}

    /**
     * @param  class-string<ReportableSource>  $parent
     */
    public static function of(string $parent, string $relation): self
    {
        return new self($parent, $relation, morph: false);
    }

    /**
     * A polymorphic child (Activity::activitable → Account|Lead). The compiler
     * adds the morph-type condition with the parent's getMorphClass().
     *
     * @param  class-string<ReportableSource>  $parent
     */
    public static function morph(string $parent, string $morphName): self
    {
        return new self($parent, $morphName, morph: true);
    }
}
