<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Enums;

/**
 * Standard filter types used by report definitions.
 *
 * These constants document the intent of each filter; the actual
 * application logic lives in the HasFiltering trait.
 */
enum FilterType: string
{
    /** Exact match: WHERE column = value */
    case EXACT = 'exact';

    /** LIKE search: WHERE column LIKE %value% */
    case LIKE = 'like';

    /** Enum match: WHERE column = value (backed enum value) */
    case ENUM = 'enum';

    /** Boolean match: WHERE column = true/false */
    case BOOLEAN = 'boolean';

    /** Relationship existence: whereHas(...) */
    case RELATIONSHIP = 'relationship';

    /** Date range: WHERE column BETWEEN from AND to */
    case DATE_RANGE = 'date_range';
}
