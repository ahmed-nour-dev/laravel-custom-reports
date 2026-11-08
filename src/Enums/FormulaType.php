<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Enums;

/** How a summary formula's value is shown (SPEC §14.6). A percent of 0.25 shows as 25%. */
enum FormulaType: string
{
    case NUMBER = 'number';
    case CURRENCY = 'currency';
    case PERCENT = 'percent';
}
