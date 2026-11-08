<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Queries;

use AhmedNour\CustomReports\Builder\Registry\Edge;
use AhmedNour\CustomReports\Builder\Registry\Field;
use AhmedNour\CustomReports\Builder\Registry\SourceRegistry;
use AhmedNour\CustomReports\Enums\FieldType;
use BackedEnum;

/**
 * What the wizard may offer, straight from the code registry: every source
 * with its fields, the child links that may follow it and its lookups. The
 * lookups name their target source, so the SPA browses them up to five deep
 * without another request. Labels are translation keys.
 */
final readonly class SourceCatalogue
{
    public function __construct(private SourceRegistry $registry) {}

    /**
     * @return list<array{
     *     key: string,
     *     label_key: string,
     *     fields: list<array{name: string, type: string, label_key: string, identity: bool, options: list<string>|null}>,
     *     children: list<array{link: string, source: string, morph: bool}>,
     *     lookups: list<array{name: string, target: string, label_key: string}>
     * }>
     */
    public function handle(): array
    {
        $catalogue = [];

        foreach ($this->registry->all() as $source) {
            $catalogue[] = [
                'key' => $source->key(),
                'label_key' => "reports.sources.{$source->key()}",
                'fields' => array_values(array_map(
                    static fn (Field $field): array => [
                        'name' => $field->name,
                        'type' => $field->type->value,
                        'label_key' => $field->labelKey($source->key()),
                        'identity' => $field->isIdentity(),
                        'options' => $field->type === FieldType::ENUM && $field->enum !== null
                            ? array_map(static fn (BackedEnum $case): string => (string) $case->value, $field->enum::cases())
                            : null,
                    ],
                    array_filter($source->fields(), static fn (Field $field): bool => ! $field->isInternal()),
                )),
                'children' => array_map(
                    static fn (Edge $edge): array => ['link' => $edge->key, 'source' => $edge->child->key(), 'morph' => $edge->morphType !== null],
                    $this->registry->childrenOf($source->key()),
                ),
                'lookups' => array_map(
                    fn ($lookup): array => [
                        'name' => $lookup->name,
                        'target' => $this->registry->lookup($source, $lookup->name)->parent->key(),
                        'label_key' => "reports.lookups.{$source->key()}.{$lookup->name}",
                    ],
                    $source->lookups(),
                ),
            ];
        }

        return $catalogue;
    }
}
