<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Builder\Compiler;

use AhmedNour\CustomReports\Exceptions\InvalidReportType;

/**
 * A parsed field key: `l{level}[@{lookup.path}].{field}`, e.g. `l1.total`,
 * `l1@account_manager.name`, `l0@lead.lead_source.name`.
 */
final readonly class FieldRef
{
    private const string PATTERN = '/^l([0-3])(?:@([a-z_]+(?:\.[a-z_]+)*))?\.([a-z_]+)$/';

    private function __construct(
        public string $key,
        public int $level,
        public ?string $path,
        public string $field,
    ) {}

    public static function parse(string $key): self
    {
        if (preg_match(self::PATTERN, $key, $m) !== 1) {
            throw InvalidReportType::unknownField($key);
        }

        return new self($key, (int) $m[1], $m[2] === '' ? null : $m[2], $m[3]);
    }

    public static function make(int $level, ?string $path, string $field): self
    {
        return self::parse('l'.$level.($path === null ? '' : '@'.$path).'.'.$field);
    }
}
