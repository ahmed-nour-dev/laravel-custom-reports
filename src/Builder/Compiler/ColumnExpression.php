<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Builder\Compiler;

use AhmedNour\CustomReports\Data\Bucket;
use AhmedNour\CustomReports\Enums\AggregateFunction;
use AhmedNour\CustomReports\Enums\DateGranularity;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;
use InvalidArgumentException;

/**
 * A SQL function over one registry column — a date bucket or an aggregate —
 * rendered by the query grammar: the column and alias are wrapped (quoted)
 * by the grammar, and the function template comes only from the fixed list
 * below, so no input ever reaches SQL as text.
 */
final readonly class ColumnExpression implements Expression
{
    private function __construct(
        private string $template,
        private string $column,
        private ?string $alias,
    ) {}

    /** MySQL / MariaDB date buckets; week = the Monday it starts on. */
    public static function dateBucket(DateGranularity $granularity, string $column, ?string $alias = null): self
    {
        return new self(match ($granularity) {
            DateGranularity::DAY => 'date(%1$s)',
            DateGranularity::WEEK => 'date(date_sub(%1$s, interval weekday(%1$s) day))',
            DateGranularity::MONTH => "date_format(%1\$s, '%%Y-%%m')",
            DateGranularity::QUARTER => "concat(year(%1\$s), '-Q', quarter(%1\$s))",
            DateGranularity::YEAR => 'year(%1$s)',
        }, $column, $alias);
    }

    public static function aggregate(AggregateFunction $function, string $column, ?string $alias = null): self
    {
        return new self(match ($function) {
            AggregateFunction::SUM => 'sum(%1$s)',
            AggregateFunction::AVG => 'avg(%1$s)',
            AggregateFunction::MIN => 'min(%1$s)',
            AggregateFunction::MAX => 'max(%1$s)',
            AggregateFunction::COUNT_DISTINCT => 'count(distinct %1$s)',
        }, $column, $alias);
    }

    /**
     * An aggregate over already-built SQL (the row formula's), from the same
     * fixed list; the SQL comes from RowFormulaCompiler, never from input.
     */
    public static function aggregateSql(AggregateFunction $function, string $sql): string
    {
        return match ($function) {
            AggregateFunction::SUM => 'sum('.$sql.')',
            AggregateFunction::AVG => 'avg('.$sql.')',
            AggregateFunction::MIN => 'min('.$sql.')',
            AggregateFunction::MAX => 'max('.$sql.')',
            AggregateFunction::COUNT_DISTINCT => 'count(distinct '.$sql.')',
        };
    }

    /** COUNT(DISTINCT lN.pk): how many level-N records the group holds. */
    public static function distinctCount(string $column, ?string $alias = null): self
    {
        return new self('count(distinct %1$s)', $column, $alias);
    }

    /**
     * A bucket as `CASE … THEN <group index> … ELSE -1 END` (NULL stays
     * NULL). Labels are mapped in PHP and never reach SQL; the bounds are
     * numbers and enum values DefinitionValidator already checked, and they
     * are re-checked here before they are written.
     */
    public static function bucket(Bucket $bucket, string $column, ?string $alias = null): self
    {
        $cases = ['when %1$s is null then null'];

        foreach ($bucket->groups as $index => $group) {
            if (isset($group['values'])) {
                $values = array_map(static function (string $value): string {
                    if (preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $value) !== 1) {
                        throw new InvalidArgumentException('Unsafe bucket value.');
                    }

                    return "'".$value."'";
                }, $group['values']);
                $cases[] = 'when %1$s in ('.implode(', ', $values).') then '.$index;

                continue;
            }

            $conditions = [];
            if (isset($group['from'])) {
                $conditions[] = '%1$s >= '.self::number($group['from']);
            }
            if (isset($group['to'])) {
                $conditions[] = '%1$s < '.self::number($group['to']);
            }
            $cases[] = 'when '.implode(' and ', $conditions).' then '.$index;
        }

        return new self('case '.implode(' ', $cases).' else -1 end', $column, $alias);
    }

    public static function column(string $column, ?string $alias = null): self
    {
        return new self('%1$s', $column, $alias);
    }

    private static function number(int|float $value): string
    {
        return is_int($value) ? (string) $value : rtrim(rtrim(sprintf('%.8F', $value), '0'), '.');
    }

    public function getValue(Grammar $grammar): string
    {
        $sql = sprintf($this->template, $grammar->wrap($this->column));

        return $this->alias === null ? $sql : $sql.' as '.$grammar->wrap($this->alias);
    }
}
