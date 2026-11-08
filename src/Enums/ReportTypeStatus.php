<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Enums;

enum ReportTypeStatus: string
{
    case DEVELOPMENT = 'development';
    case DEPLOYED = 'deployed';
}
