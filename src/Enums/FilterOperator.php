<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Enums;

/**
 * Standard filter operators (SPEC §7.1). Which ones a field offers depends on
 * its type; the relative-date operators take no value except `*_n_days`.
 */
enum FilterOperator: string
{
    case EQUALS = 'equals';
    case NOT_EQUALS = 'not_equals';
    case IN = 'in';
    case NOT_IN = 'not_in';
    case CONTAINS = 'contains';
    case NOT_CONTAINS = 'not_contains';
    case STARTS_WITH = 'starts_with';
    case LT = 'lt';
    case LTE = 'lte';
    case GT = 'gt';
    case GTE = 'gte';
    case BETWEEN = 'between';
    case IS_NULL = 'is_null';
    case IS_NOT_NULL = 'is_not_null';

    case TODAY = 'today';
    case YESTERDAY = 'yesterday';
    case THIS_WEEK = 'this_week';
    case LAST_WEEK = 'last_week';
    case THIS_MONTH = 'this_month';
    case LAST_MONTH = 'last_month';
    case THIS_QUARTER = 'this_quarter';
    case LAST_QUARTER = 'last_quarter';
    case THIS_YEAR = 'this_year';
    case LAST_YEAR = 'last_year';
    case LAST_N_DAYS = 'last_n_days';
    case NEXT_N_DAYS = 'next_n_days';

    /**
     * @return list<self>
     */
    public static function for(FieldType $type): array
    {
        $nulls = [self::IS_NULL, self::IS_NOT_NULL];

        return match (true) {
            $type === FieldType::BOOLEAN => [self::EQUALS, ...$nulls],
            $type === FieldType::ENUM => [self::EQUALS, self::NOT_EQUALS, self::IN, self::NOT_IN, ...$nulls],
            $type->isNumeric() => [self::EQUALS, self::NOT_EQUALS, self::IN, self::NOT_IN, self::LT, self::LTE, self::GT, self::GTE, self::BETWEEN, ...$nulls],
            $type === FieldType::DATE, $type === FieldType::DATETIME => [
                self::EQUALS, self::LT, self::LTE, self::GT, self::GTE, self::BETWEEN, ...$nulls, ...self::relativeDates(),
            ],
            default => [self::EQUALS, self::NOT_EQUALS, self::IN, self::NOT_IN, self::CONTAINS, self::NOT_CONTAINS, self::STARTS_WITH, ...$nulls],
        };
    }

    /**
     * @return list<self>
     */
    public static function relativeDates(): array
    {
        return [
            self::TODAY, self::YESTERDAY, self::THIS_WEEK, self::LAST_WEEK, self::THIS_MONTH, self::LAST_MONTH,
            self::THIS_QUARTER, self::LAST_QUARTER, self::THIS_YEAR, self::LAST_YEAR, self::LAST_N_DAYS, self::NEXT_N_DAYS,
        ];
    }

    public function takesNoValue(): bool
    {
        return in_array($this, [self::IS_NULL, self::IS_NOT_NULL], true)
            || (in_array($this, self::relativeDates(), true) && ! $this->takesDayCount());
    }

    public function takesList(): bool
    {
        return in_array($this, [self::IN, self::NOT_IN], true);
    }

    public function takesDayCount(): bool
    {
        return $this === self::LAST_N_DAYS || $this === self::NEXT_N_DAYS;
    }
}
