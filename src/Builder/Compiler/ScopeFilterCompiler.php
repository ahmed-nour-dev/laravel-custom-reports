<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Builder\Compiler;

use AhmedNour\CustomReports\Builder\Definition\ValidatedDefinition;
use AhmedNour\CustomReports\Contracts\ReportVisibility;
use AhmedNour\CustomReports\Enums\ReportScope;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The scope filter (SPEC §14.3): "my records" / "my team's records" on the
 * primary level, ANDed in WHERE on top of visibility (ScopeApplier), so it
 * only ever narrows. DefinitionValidator has already checked the primary
 * source has an owner.
 */
final readonly class ScopeFilterCompiler
{
    public function __construct(private ReportVisibility $visibility) {}

    public function apply(CompiledQuery $compiled, ValidatedDefinition $validated, Authenticatable $viewer): void
    {
        $scope = $validated->definition->scope;

        if ($scope === ReportScope::ALL) {
            return;
        }

        $this->visibility->constrainToOwner(
            $compiled->query,
            $compiled->levels[0]->newModel(),
            $viewer,
            team: $scope === ReportScope::MY_TEAM,
            alias: Alias::level(0),
        );
    }
}
