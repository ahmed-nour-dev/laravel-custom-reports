<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Builder\Definition;

/**
 * A node of a parsed filter logic string: a filter number, AND / OR of
 * children, or NOT of one child.
 */
final readonly class LogicNode
{
    public const string REF = 'ref';

    public const string AND = 'and';

    public const string OR = 'or';

    public const string NOT = 'not';

    /**
     * @param  list<LogicNode>  $children
     */
    private function __construct(
        public string $kind,
        public int $number = 0,
        public array $children = [],
    ) {}

    public static function ref(int $number): self
    {
        return new self(self::REF, number: $number);
    }

    /**
     * @param  list<LogicNode>  $children
     */
    public static function all(array $children): self
    {
        return count($children) === 1 ? $children[0] : new self(self::AND, children: $children);
    }

    /**
     * @param  list<LogicNode>  $children
     */
    public static function any(array $children): self
    {
        return count($children) === 1 ? $children[0] : new self(self::OR, children: $children);
    }

    public static function not(LogicNode $child): self
    {
        return new self(self::NOT, children: [$child]);
    }

    /**
     * Every filter number the logic references.
     *
     * @return list<int>
     */
    public function numbers(): array
    {
        if ($this->kind === self::REF) {
            return [$this->number];
        }

        return array_merge(...array_map(static fn (LogicNode $child): array => $child->numbers(), $this->children));
    }

    /**
     * The tree without the given top-level AND terms (outer-scoped filters,
     * which never sit under OR / NOT). Null when nothing is left.
     *
     * @param  list<int>  $numbers
     */
    public function withoutTerms(array $numbers): ?self
    {
        if ($this->kind === self::REF) {
            return in_array($this->number, $numbers, true) ? null : $this;
        }

        if ($this->kind !== self::AND) {
            return $this;
        }

        $kept = array_values(array_filter(
            $this->children,
            static fn (LogicNode $child): bool => $child->kind !== self::REF || ! in_array($child->number, $numbers, true),
        ));

        return $kept === [] ? null : self::all($kept);
    }

    /** True when filter $number sits anywhere under an OR or a NOT. */
    public function isUnderOrOrNot(int $number, bool $inside = false): bool
    {
        if ($this->kind === self::REF) {
            return $inside && $this->number === $number;
        }

        $inside = $inside || $this->kind !== self::AND;

        foreach ($this->children as $child) {
            if ($child->isUnderOrOrNot($number, $inside)) {
                return true;
            }
        }

        return false;
    }
}
