<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Tests\Fixtures;

use AhmedNour\CustomReports\Builder\Registry\ChildLink;
use AhmedNour\CustomReports\Builder\Registry\Field;
use AhmedNour\CustomReports\Builder\Registry\Lookup;
use AhmedNour\CustomReports\Builder\Registry\ReportableSource;

final class OrdersSource extends ReportableSource
{
    public function key(): string
    {
        return 'orders';
    }

    public function model(): string
    {
        return Order::class;
    }

    public function fields(): array
    {
        return [
            Field::string('code')->identity(),
            Field::currency('total'),
            Field::string('status'),
            Field::date('ordered_at'),
        ];
    }

    public function parents(): array
    {
        return [ChildLink::of(CustomersSource::class, relation: 'customer')];
    }

    public function lookups(): array
    {
        return [Lookup::to('customer', CustomersSource::class, relation: 'customer')];
    }
}
