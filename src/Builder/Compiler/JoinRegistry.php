<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Builder\Compiler;

use AhmedNour\CustomReports\Builder\Registry\Edge;
use AhmedNour\CustomReports\Builder\Registry\ReportableSource;
use AhmedNour\CustomReports\Builder\Registry\SourceRegistry;
use AhmedNour\CustomReports\Exceptions\InvalidReportType;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;

/**
 * Lookup joins (SPEC §6.3 rule 3): always LEFT JOIN, keyed by level + path,
 * joined hop by hop so `l0@lead` is shared by `l0@lead.lead_source`. Each hop
 * gets visibility in ON, so a parent the viewer can't see is blank columns,
 * never a dropped row.
 */
final class JoinRegistry
{
    public const int MAX_DEPTH = 5;

    /** @var array<string, array{string, ReportableSource}> */
    private array $joined = [];

    /** @var array<string, Edge> alias => the lookup edge it joins through */
    private array $edges = [];

    /**
     * @param  array<int, ReportableSource>  $levels
     */
    public function __construct(
        private readonly Builder $query,
        private readonly SourceRegistry $registry,
        private readonly ScopeApplier $scopes,
        private readonly Authenticatable $viewer,
        private readonly array $levels,
    ) {}

    /**
     * The alias and source a field at $level (through $path) is read from,
     * joining whatever hops are still missing.
     *
     * @return array{string, ReportableSource}
     */
    public function resolve(int $level, ?string $path): array
    {
        $source = $this->levels[$level] ?? throw InvalidReportType::unknownField("l{$level}");
        $alias = Alias::level($level);

        if ($path === null) {
            return [$alias, $source];
        }

        $hops = explode('.', $path);

        if (count($hops) > self::MAX_DEPTH) {
            throw InvalidReportType::tooDeep("l{$level}@{$path}", self::MAX_DEPTH);
        }

        $walked = [];
        foreach ($hops as $hop) {
            $walked[] = $hop;
            $key = "l{$level}@".implode('.', $walked);

            if (! isset($this->joined[$key])) {
                $edge = $this->registry->lookup($source, $hop);
                $next = Alias::lookup($level, implode('.', $walked));
                $from = $alias;

                $this->query->leftJoin($edge->parent->table().' as '.$next, function (JoinClause $join) use ($edge, $next, $from): void {
                    $join->on($next.'.'.$edge->ownerKey, '=', $from.'.'.$edge->foreignKey);
                    $this->scopes->applyToJoin($join, $edge->parent, $next, $this->viewer);
                });

                $this->joined[$key] = [$next, $edge->parent];
                $this->edges[$next] = $edge;
            }

            [$alias, $source] = $this->joined[$key];
        }

        return [$alias, $source];
    }

    /**
     * The lookup paths joined so far, e.g. `l0@lead`, `l0@lead.lead_source`.
     *
     * @return list<string>
     */
    public function joinedPaths(): array
    {
        return array_keys($this->joined);
    }

    /**
     * @return array<string, Edge> lookup alias => edge
     */
    public function edges(): array
    {
        return $this->edges;
    }
}
