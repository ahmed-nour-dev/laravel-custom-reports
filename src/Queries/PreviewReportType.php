<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Queries;

use AhmedNour\CustomReports\Builder\Compiler\Alias;
use AhmedNour\CustomReports\Builder\Compiler\BaseQueryCompiler;
use AhmedNour\CustomReports\Builder\Compiler\SelectCompiler;
use AhmedNour\CustomReports\Builder\Compiler\SelectedColumn;
use AhmedNour\CustomReports\Builder\DefaultColumns;
use AhmedNour\CustomReports\Models\ReportType;
use AhmedNour\CustomReports\Models\ReportTypeField;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;

/**
 * The first rows of a report type with its default columns, as the viewer
 * sees them — the wizard's preview step. Visibility is applied by the
 * compiler (ScopeApplier → the app's ReportVisibility) on every level and lookup.
 */
final readonly class PreviewReportType
{
    public const int ROWS = 20;

    public function __construct(
        private BaseQueryCompiler $compiler,
        private SelectCompiler $select,
        private DefaultColumns $defaults,
    ) {}

    /**
     * @return array{
     *     columns: list<array{key: string, column: string, label: string, type: string}>,
     *     rows: list<array<string, mixed>>
     * }
     */
    public function handle(ReportType $type, Authenticatable $viewer, int $limit = self::ROWS): array
    {
        $compiled = $this->compiler->base($type, $viewer);

        $layout = $this->defaults->layout($type);
        $columns = $this->select->select($compiled, $this->defaults->keys($type, $compiled));
        $this->select->tiebreak($compiled);

        $rows = [];
        foreach ($compiled->query->limit($limit)->get() as $row) {
            $cells = [];
            foreach ((array) $row as $column => $value) {
                if (! Alias::isInternal((string) $column)) {
                    $cells[(string) $column] = $value;
                }
            }
            $rows[] = $cells;
        }

        return [
            'columns' => array_map(fn (SelectedColumn $column): array => [
                'key' => $column->ref->key,
                'column' => $column->alias,
                'label' => $this->label($column, $layout->get($column->ref->key)),
                'type' => $column->field->type->value,
            ], $columns),
            'rows' => $rows,
        ];
    }

    private function label(SelectedColumn $column, mixed $layoutField): string
    {
        if ($layoutField instanceof ReportTypeField && $layoutField->label_en !== null) {
            return $layoutField->label_en;
        }

        return Str::headline($column->field->name);
    }
}
