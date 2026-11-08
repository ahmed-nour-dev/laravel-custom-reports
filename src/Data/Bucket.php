<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Data;

/**
 * A bucket column (SPEC §8.3): one field sorted into named groups — numeric
 * ranges (`from` ≤ value < `to`, either end open) or sets of enum values.
 * The first matching group wins; anything else is `other`. Used in columns
 * and groupings as `bucket:{key}`.
 */
final readonly class Bucket
{
    public const string PREFIX = 'bucket:';

    /**
     * @param  list<array{label: string, from?: int|float|null, to?: int|float|null, values?: list<string>}>  $groups
     */
    public function __construct(
        public string $key,
        public string $field,
        public string $label,
        public array $groups,
        public string $other = 'Other',
    ) {}

    public function columnKey(): string
    {
        return self::PREFIX.$this->key;
    }

    public static function isBucketKey(string $key): bool
    {
        return str_starts_with($key, self::PREFIX);
    }

    /** The label for the index the CASE expression returned (-1 = other). */
    public function labelFor(mixed $index): ?string
    {
        if ($index === null) {
            return null;
        }

        return $this->groups[(int) $index]['label'] ?? $this->other;
    }

    /**
     * @return array{key: string, field: string, label: string, groups: list<array<string, mixed>>, other: string}
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'field' => $this->field, 'label' => $this->label, 'groups' => $this->groups, 'other' => $this->other];
    }
}
