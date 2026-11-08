<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Builder;

use AhmedNour\CustomReports\Builder\Compiler\BaseQueryCompiler;
use AhmedNour\CustomReports\Builder\Compiler\FieldRef;
use AhmedNour\CustomReports\Builder\Compiler\JoinRegistry;
use AhmedNour\CustomReports\Builder\Registry\Field;
use AhmedNour\CustomReports\Builder\Registry\ReportableSource;
use AhmedNour\CustomReports\Builder\Registry\SourceRegistry;
use AhmedNour\CustomReports\Enums\JoinType;
use AhmedNour\CustomReports\Exceptions\InvalidReportType;
use AhmedNour\CustomReports\Models\ReportType;
use Throwable;

/**
 * The save-time checks of SPEC §11, shared by the chain, layout and deploy
 * Actions. Everything is checked against the code registry: an admin can
 * never name a source, link, lookup or field a developer did not allow.
 */
final readonly class ReportTypeValidator
{
    public function __construct(private SourceRegistry $registry) {}

    /**
     * Rules 1 + registry checks for a chain; returns the source per level.
     *
     * @param  list<array{source: string, parent_link: string, join_type: JoinType}>  $objects
     * @return array<int, ReportableSource>
     */
    public function chain(string $primarySource, array $objects): array
    {
        $levels = [0 => $this->registry->source($primarySource)];

        if (count($objects) > BaseQueryCompiler::MAX_CHILD_LEVELS) {
            throw InvalidReportType::tooDeep('The chain', BaseQueryCompiler::MAX_CHILD_LEVELS);
        }

        $outer = false;
        foreach ($objects as $index => $object) {
            $level = $index + 1;
            $edge = $this->registry->link($object['parent_link']);

            if ($edge->child->key() !== $object['source'] || $edge->parent->key() !== $levels[$level - 1]->key()) {
                throw InvalidReportType::invalidChain($level, "[{$object['parent_link']}] does not link [{$object['source']}] to [{$levels[$level - 1]->key()}].");
            }

            if ($outer && $object['join_type'] === JoinType::INNER) {
                throw InvalidReportType::innerAfterOuter($level);
            }

            $outer = $outer || $object['join_type'] === JoinType::LEFT;
            $levels[$level] = $edge->child;
        }

        return $levels;
    }

    /**
     * @return array<int, ReportableSource>
     */
    public function levelsOf(ReportType $type): array
    {
        $objects = [];
        foreach ($type->objects->sortBy('level') as $object) {
            $objects[] = ['source' => $object->source, 'parent_link' => $object->parent_link, 'join_type' => $object->join_type];
        }

        return $this->chain($type->primary_source, $objects);
    }

    /**
     * The join each child level compiles to, after the rule 1 cascade.
     *
     * @return array<int, JoinType>
     */
    public function joinTypesOf(ReportType $type): array
    {
        $types = [];
        $outer = false;
        foreach ($type->objects->sortBy('level')->values() as $index => $object) {
            $outer = $outer || $object->join_type === JoinType::LEFT;
            $types[$index + 1] = $outer ? JoinType::LEFT : JoinType::INNER;
        }

        return $types;
    }

    /**
     * Resolve a field key against the chain's levels: the level exists, every
     * lookup hop is registered (≤ 5), and the field is allowed on the source.
     *
     * @param  array<int, ReportableSource>  $levels
     * @return array{FieldRef, ReportableSource, Field}
     */
    public function field(string $key, array $levels): array
    {
        $ref = FieldRef::parse($key);
        $source = $levels[$ref->level] ?? throw InvalidReportType::unknownField($key);

        if ($ref->path !== null) {
            $hops = explode('.', $ref->path);

            if (count($hops) > JoinRegistry::MAX_DEPTH) {
                throw InvalidReportType::tooDeep($key, JoinRegistry::MAX_DEPTH);
            }

            foreach ($hops as $hop) {
                $source = $this->registry->lookup($source, $hop)->parent;
            }
        }

        $field = $source->field($ref->field) ?? throw InvalidReportType::unknownField($key);

        return [$ref, $source, $field];
    }

    /** SPEC §7.4: a field above the deepest level repeats once per deeper row. */
    public function isRepeated(FieldRef $ref, int $deepestLevel): bool
    {
        return $ref->level < $deepestLevel;
    }

    /**
     * Everything that stops a type from being deployed (SPEC §11). Empty = deployable.
     *
     * @return list<string>
     */
    public function deployProblems(ReportType $type): array
    {
        try {
            $levels = $this->levelsOf($type);
        } catch (InvalidReportType $e) {
            return [$e->getMessage()];
        }

        $problems = [];
        $fields = $type->fields()->get();

        if ($fields->isEmpty()) {
            $problems[] = 'The layout has no fields.';
        }

        if ($fields->isNotEmpty() && ! $fields->contains('is_default', true)) {
            $problems[] = 'At least one field must be a default column.';
        }

        $max = (int) config('custom-reports.max_fields', 1000);
        if ($fields->count() > $max) {
            $problems[] = "The layout has {$fields->count()} fields; the limit is {$max}.";
        }

        foreach ($fields as $field) {
            try {
                $this->field($field->key, $levels);
            } catch (Throwable $e) {
                $problems[] = $e instanceof InvalidReportType ? $e->getMessage() : "Field \"{$field->key}\" cannot be resolved.";
            }
        }

        return $problems;
    }
}
