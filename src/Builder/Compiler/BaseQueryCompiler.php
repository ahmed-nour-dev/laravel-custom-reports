<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Builder\Compiler;

use AhmedNour\CustomReports\Builder\Registry\SourceRegistry;
use AhmedNour\CustomReports\Enums\JoinType;
use AhmedNour\CustomReports\Exceptions\InvalidReportType;
use AhmedNour\CustomReports\Models\ReportType;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Query\JoinClause;

/**
 * The FROM/JOIN skeleton of a report type (SPEC §6.2): the primary source as
 * `l0`, each child level as `l{N}`, at the grain of the deepest level.
 */
final readonly class BaseQueryCompiler
{
    public const int MAX_CHILD_LEVELS = 3;

    public function __construct(
        private SourceRegistry $registry,
        private ScopeApplier $scopes,
    ) {}

    /**
     * $onJoin(JoinClause, level) adds to a child level's ON — rule 5a, the
     * filters that narrow an outer level without dropping its parent.
     *
     * @param  (Closure(JoinClause, int): void)|null  $onJoin
     */
    public function base(ReportType $type, Authenticatable $viewer, ?Closure $onJoin = null): CompiledQuery
    {
        $primary = $this->registry->source($type->primary_source);
        $model = $primary->newModel();

        $query = $model->getConnection()->table($primary->table().' as '.Alias::level(0));
        $this->scopes->applyToWhere($query, $primary, Alias::level(0), $viewer);

        $levels = [0 => $primary];
        $joinTypes = [];
        $edges = [];
        $outer = false;

        foreach ($type->objects->sortBy('level')->values() as $index => $object) {
            $level = $index + 1;

            if ($object->level !== $level) {
                throw InvalidReportType::invalidChain($object->level, "expected level {$level}.");
            }

            if ($level > self::MAX_CHILD_LEVELS) {
                throw InvalidReportType::tooDeep('The chain', self::MAX_CHILD_LEVELS);
            }

            $edge = $this->registry->link($object->parent_link);

            if ($edge->child->key() !== $object->source || $edge->parent->key() !== $levels[$level - 1]->key()) {
                throw InvalidReportType::invalidChain($level, "[{$object->parent_link}] does not link [{$object->source}] to [{$levels[$level - 1]->key()}].");
            }

            // RULE 1: once a level is outer, every deeper level is outer too.
            $outer = $outer || $object->join_type === JoinType::LEFT;
            $joinTypes[$level] = $outer ? JoinType::LEFT : JoinType::INNER;

            $alias = Alias::level($level);
            $parentAlias = Alias::level($level - 1);

            $query->join(
                $edge->child->table().' as '.$alias,
                function (JoinClause $join) use ($edge, $alias, $parentAlias, $viewer, $onJoin, $level): void {
                    $join->on($alias.'.'.$edge->foreignKey, '=', $parentAlias.'.'.$edge->ownerKey);

                    if ($edge->morphType !== null) {
                        $join->where($alias.'.'.$edge->morphType, '=', $edge->morphClass);
                    }

                    // RULE 2: the child's visibility goes in ON, never WHERE.
                    $this->scopes->applyToJoin($join, $edge->child, $alias, $viewer);

                    if ($onJoin instanceof Closure) {
                        $onJoin($join, $level);
                    }
                },
                type: $outer ? 'left' : 'inner',
            );

            $levels[$level] = $edge->child;
            $edges[$level] = $edge;
        }

        return new CompiledQuery(
            query: $query,
            levels: $levels,
            joinTypes: $joinTypes,
            edges: $edges,
            joins: new JoinRegistry($query, $this->registry, $this->scopes, $viewer, $levels),
        );
    }
}
