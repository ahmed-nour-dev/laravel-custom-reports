<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Builder\Registry;

use AhmedNour\CustomReports\Enums\FieldType;
use BackedEnum;

/**
 * One column a source lets admins put on a report type. The name is the
 * column and the stable key saved reports refer to, so it never changes once
 * released (SPEC §4.3).
 */
final class Field
{
    private bool $identity = false;

    private bool $internal = false;

    /**
     * @param  class-string<BackedEnum>|null  $enum
     */
    private function __construct(
        public readonly string $name,
        public readonly FieldType $type,
        public readonly ?string $enum = null,
        public readonly ?string $currencyColumn = null,
    ) {}

    public static function string(string $name): self
    {
        return new self($name, FieldType::STRING);
    }

    public static function text(string $name): self
    {
        return new self($name, FieldType::TEXT);
    }

    public static function integer(string $name): self
    {
        return new self($name, FieldType::INTEGER);
    }

    public static function decimal(string $name): self
    {
        return new self($name, FieldType::DECIMAL);
    }

    /** A money amount; $currencyColumn names the column holding its currency, if any. */
    public static function currency(string $name, ?string $currencyColumn = null): self
    {
        return new self($name, FieldType::CURRENCY, currencyColumn: $currencyColumn);
    }

    public static function percent(string $name): self
    {
        return new self($name, FieldType::PERCENT);
    }

    public static function date(string $name): self
    {
        return new self($name, FieldType::DATE);
    }

    public static function datetime(string $name): self
    {
        return new self($name, FieldType::DATETIME);
    }

    public static function boolean(string $name): self
    {
        return new self($name, FieldType::BOOLEAN);
    }

    /**
     * @param  class-string<BackedEnum>  $enum
     */
    public static function enum(string $name, string $enum): self
    {
        return new self($name, FieldType::ENUM, enum: $enum);
    }

    /** The code users see for this record (CLAUDE.md: code, never id). */
    public function identity(): self
    {
        $this->identity = true;

        return $this;
    }

    /** Readable by the compiler, never offered to admins or serialized. */
    public function internal(): self
    {
        $this->internal = true;

        return $this;
    }

    public function isIdentity(): bool
    {
        return $this->identity;
    }

    public function isInternal(): bool
    {
        return $this->internal;
    }

    /** Translation key of the default label, overridable per report type. */
    public function labelKey(string $sourceKey): string
    {
        return "reports.fields.{$sourceKey}.{$this->name}";
    }
}
