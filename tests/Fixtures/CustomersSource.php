<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Tests\Fixtures;

use AhmedNour\CustomReports\Builder\Registry\Field;
use AhmedNour\CustomReports\Builder\Registry\ReportableSource;

final class CustomersSource extends ReportableSource
{
    public function key(): string
    {
        return 'customers';
    }

    public function model(): string
    {
        return Customer::class;
    }

    public function fields(): array
    {
        return [
            Field::string('code')->identity(),
            Field::string('name'),
            Field::string('region'),
            Field::datetime('created_at'),
        ];
    }
}
