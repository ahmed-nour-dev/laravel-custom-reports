<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Enums;

/**
 * The type of a reportable field (SPEC §4.2). It decides the operators and
 * aggregates the builder offers and how a cell is rendered.
 */
enum FieldType: string
{
    case STRING = 'string';
    case TEXT = 'text';
    case INTEGER = 'integer';
    case DECIMAL = 'decimal';
    case CURRENCY = 'currency';
    case PERCENT = 'percent';
    case DATE = 'date';
    case DATETIME = 'datetime';
    case BOOLEAN = 'boolean';
    case ENUM = 'enum';
    case USER = 'user';

    public function isNumeric(): bool
    {
        return in_array($this, [self::INTEGER, self::DECIMAL, self::CURRENCY, self::PERCENT], true);
    }
}
