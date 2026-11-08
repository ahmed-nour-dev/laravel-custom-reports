<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Builder\Registry;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;

/**
 * One object admins may report on (SPEC §4). Developers decide here which
 * fields, parents and lookups exist; admins can never get past this list.
 */
abstract class ReportableSource
{
    /** @var array<string, Field>|null */
    private ?array $fieldIndex = null;

    private ?Model $instance = null;

    /** Stable key stored in report types, e.g. `accounts`. */
    abstract public function key(): string;

    /**
     * @return class-string<Model>
     */
    abstract public function model(): string;

    /**
     * @return list<Field>
     */
    abstract public function fields(): array;

    /**
     * Parents this source can be a child level of.
     *
     * @return list<ChildLink>
     */
    public function parents(): array
    {
        return [];
    }

    /**
     * @return list<Lookup>
     */
    public function lookups(): array
    {
        return [];
    }

    /**
     * Any constraint beyond visibility and soft deletes this source needs on
     * every read, in WHERE (primary) or in ON (joined). None by default, on
     * purpose: nothing applies silently (SPEC §6.3 rule 2).
     */
    public function constrain(Builder $query, string $alias): void {}

    /**
     * Where the SPA opens one record, by code (SPEC §10): the path with a
     * `{code}` placeholder and the column whose value fills it. Null: the
     * identity field renders as plain text.
     *
     * @return array{string, string}|null
     */
    public function route(): ?array
    {
        return null;
    }

    public function newModel(): Model
    {
        return $this->instance ??= new ($this->model());
    }

    public function table(): string
    {
        return $this->newModel()->getTable();
    }

    public function keyName(): string
    {
        return $this->newModel()->getKeyName();
    }

    /** A field admins may use (internal fields are excluded). */
    public function field(string $name): ?Field
    {
        $field = $this->fieldIndex()[$name] ?? null;

        return $field instanceof Field && ! $field->isInternal() ? $field : null;
    }

    public function identityField(): ?Field
    {
        foreach ($this->fieldIndex() as $field) {
            if ($field->isIdentity()) {
                return $field;
            }
        }

        return null;
    }

    public function lookup(string $name): ?Lookup
    {
        foreach ($this->lookups() as $lookup) {
            if ($lookup->name === $name) {
                return $lookup;
            }
        }

        return null;
    }

    /**
     * @return array<string, Field>
     */
    private function fieldIndex(): array
    {
        if ($this->fieldIndex === null) {
            $this->fieldIndex = [];
            foreach ($this->fields() as $field) {
                $this->fieldIndex[$field->name] = $field;
            }
        }

        return $this->fieldIndex;
    }
}
