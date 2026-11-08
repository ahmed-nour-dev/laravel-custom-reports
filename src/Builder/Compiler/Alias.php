<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Builder\Compiler;

/**
 * SQL aliases (SPEC §6.1). Level N is `l{N}`; the lookup path `l{N}@a.b` is
 * `l{N}__a__b`. Every segment comes from the registry, never from input.
 */
final class Alias
{
    public static function level(int $level): string
    {
        return 'l'.$level;
    }

    /** MySQL identifiers stop at 64 characters. */
    private const int MAX_LENGTH = 64;

    public static function lookup(int $level, string $path): string
    {
        $alias = 'l'.$level.'__'.str_replace('.', '__', $path);

        // A long path keeps a readable prefix and a stable hash of the full path.
        return strlen($alias) <= self::MAX_LENGTH ? $alias : substr($alias, 0, 48).'_'.substr(md5($path), 0, 12);
    }

    /** Internal primary-key column for the order tiebreak; never serialized. */
    public static function primaryKey(int $level): string
    {
        return '__pk'.$level;
    }

    public static function isInternal(string $column): bool
    {
        return str_starts_with($column, '__');
    }
}
