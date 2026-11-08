<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Builder\Registry;

use AhmedNour\CustomReports\Exceptions\InvalidReportType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * Every registered source by key, and the parent → children graph built by
 * inverting each source's parents(). Built once per request.
 */
final class SourceRegistry
{
    /** @var array<string, ReportableSource> */
    private array $sources = [];

    /** @var array<class-string<ReportableSource>, string> */
    private array $keysByClass = [];

    /** @var array<string, Edge>|null */
    private ?array $links = null;

    /** @var array<string, Edge> */
    private array $lookupEdges = [];

    /**
     * @param  iterable<ReportableSource>  $sources
     */
    public function __construct(iterable $sources)
    {
        foreach ($sources as $source) {
            if (isset($this->sources[$source->key()])) {
                throw new LogicException("Report source key [{$source->key()}] is registered twice.");
            }

            $this->sources[$source->key()] = $source;
            $this->keysByClass[$source::class] = $source->key();
        }
    }

    /**
     * @return array<string, ReportableSource>
     */
    public function all(): array
    {
        return $this->sources;
    }

    public function has(string $key): bool
    {
        return isset($this->sources[$key]);
    }

    public function source(string $key): ReportableSource
    {
        return $this->sources[$key] ?? throw InvalidReportType::unknownSource($key);
    }

    /**
     * @param  class-string<ReportableSource>  $class
     */
    public function sourceOf(string $class): ReportableSource
    {
        $key = $this->keysByClass[$class] ?? throw new LogicException("Report source [{$class}] is not registered.");

        return $this->sources[$key];
    }

    /**
     * Every child link, keyed by its stable link key.
     *
     * @return array<string, Edge>
     */
    public function links(): array
    {
        if ($this->links === null) {
            $this->links = [];
            foreach ($this->sources as $child) {
                foreach ($child->parents() as $link) {
                    $edge = $this->resolveChildLink($child, $link);
                    $this->links[$edge->key] = $edge;
                }
            }
        }

        return $this->links;
    }

    public function link(string $key): Edge
    {
        return $this->links()[$key] ?? throw InvalidReportType::unknownSource($key);
    }

    /**
     * The links that may follow $parentKey as the next level of a chain.
     *
     * @return list<Edge>
     */
    public function childrenOf(string $parentKey): array
    {
        return array_values(array_filter(
            $this->links(),
            static fn (Edge $edge): bool => $edge->parent->key() === $parentKey,
        ));
    }

    /** The join for lookup $name on $holder; the parent side is the lookup target. */
    public function lookup(ReportableSource $holder, string $name): Edge
    {
        $key = $holder->key().'@'.$name;

        if (isset($this->lookupEdges[$key])) {
            return $this->lookupEdges[$key];
        }

        $lookup = $holder->lookup($name) ?? throw InvalidReportType::unknownSource($key);
        $target = $this->sourceOf($lookup->target);

        if ($lookup->relation === null) {
            $foreignKey = $lookup->foreignKey ?? throw new LogicException("Lookup [{$key}] has neither a relation nor a key.");
            $ownerKey = $target->keyName();
        } else {
            $relation = $this->relation($holder, $lookup->relation);

            if (! $relation instanceof BelongsTo || $relation instanceof MorphTo) {
                throw new LogicException("Lookup [{$key}] must use a BelongsTo relation.");
            }

            $this->assertRelatedTo($relation, $target, $key);
            $foreignKey = $relation->getForeignKeyName();
            $ownerKey = $relation->getOwnerKeyName();
        }

        return $this->lookupEdges[$key] = new Edge($key, $holder, $target, $foreignKey, $ownerKey);
    }

    private function resolveChildLink(ReportableSource $child, ChildLink $link): Edge
    {
        $parent = $this->sourceOf($link->parent);
        $relation = $this->relation($child, $link->relation);

        if ($link->morph) {
            if (! $relation instanceof MorphTo) {
                throw new LogicException("Child link [{$child->key()}.{$link->relation}] must use a MorphTo relation.");
            }

            return new Edge(
                key: "{$child->key()}.{$link->relation}.{$parent->key()}",
                child: $child,
                parent: $parent,
                foreignKey: $relation->getForeignKeyName(),
                ownerKey: $parent->keyName(),
                morphType: $relation->getMorphType(),
                morphClass: $parent->newModel()->getMorphClass(),
            );
        }

        $key = "{$child->key()}.{$link->relation}";

        if (! $relation instanceof BelongsTo || $relation instanceof MorphTo) {
            throw new LogicException("Child link [{$key}] must use a BelongsTo relation.");
        }

        $this->assertRelatedTo($relation, $parent, $key);

        return new Edge($key, $child, $parent, $relation->getForeignKeyName(), $relation->getOwnerKeyName());
    }

    private function relation(ReportableSource $source, string $name): mixed
    {
        $model = $source->newModel();

        if (! method_exists($model, $name)) {
            throw new LogicException("Relation [{$name}] does not exist on [{$source->model()}].");
        }

        return $model->{$name}();
    }

    /**
     * @param  BelongsTo<*, *>  $relation
     */
    private function assertRelatedTo(BelongsTo $relation, ReportableSource $target, string $key): void
    {
        if (! $relation->getRelated() instanceof ($target->model())) {
            throw new LogicException("[{$key}] points at [".$relation->getRelated()::class."], not the source's [{$target->model()}].");
        }
    }
}
