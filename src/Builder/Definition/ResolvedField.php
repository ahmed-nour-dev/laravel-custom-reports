<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Builder\Definition;

use AhmedNour\CustomReports\Builder\Compiler\FieldRef;
use AhmedNour\CustomReports\Builder\Registry\Field;
use AhmedNour\CustomReports\Builder\Registry\ReportableSource;
use Illuminate\Support\Str;

/**
 * A layout field a definition refers to, resolved against the registry.
 * $source is where the field lives (after any lookup hops); $levelSource is
 * the source of the level the key starts from.
 */
final readonly class ResolvedField
{
    public function __construct(
        public FieldRef $ref,
        public ReportableSource $source,
        public Field $field,
        public bool $isRepeated,
        public ?string $labelEn,
        public ?string $labelAr,
        public ?ReportableSource $levelSource = null,
    ) {}

    public function label(): string
    {
        return $this->labelEn ?? Str::headline($this->field->name);
    }

    /**
     * @return array{key: string, label: string, label_ar: string|null, label_key: string, type: string, repeated: bool, identity: bool}
     */
    public function describe(): array
    {
        return [
            'key' => $this->ref->key,
            'label' => $this->label(),
            'label_ar' => $this->labelAr,
            'label_key' => $this->field->labelKey($this->source->key()),
            'type' => $this->field->type->value,
            'repeated' => $this->isRepeated,
            'identity' => $this->field->isIdentity(),
        ];
    }
}
