<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Tests\Unit\Definition;

use AhmedNour\CustomReports\Data\JoinedDefinition;
use AhmedNour\CustomReports\Exceptions\InvalidReportDefinition;
use PHPUnit\Framework\TestCase;

/** Phase 9c: a joined report's shape (SPEC §14.7). */
final class JoinedDefinitionTest extends TestCase
{
    public function test_it_round_trips_and_names_unnamed_blocks(): void
    {
        $definition = JoinedDefinition::fromArray(['v' => 1, 'blocks' => [
            ['report_type' => 'RTY-0001', 'label' => ' Accounts ', 'definition' => ['v' => 1, 'groupings' => [['field' => 'l0.region']]]],
            ['report_type' => 'RTY-0002', 'definition' => ['v' => 1, 'groupings' => [['field' => 'l0@account.region']]]],
        ]]);

        $this->assertSame(['RTY-0001', 'RTY-0002'], $definition->reportTypes());
        $this->assertSame(['Accounts', 'Block 2'], array_map(static fn ($block): string => $block->label, $definition->blocks));
        $this->assertEquals($definition, JoinedDefinition::fromArray($definition->toArray()));
    }

    public function test_bad_shapes_are_refused(): void
    {
        $block = ['report_type' => 'RTY-0001', 'definition' => ['v' => 1]];
        $bad = [
            ['v' => 2, 'blocks' => [$block]],
            ['v' => 1],
            ['v' => 1, 'blocks' => []],
            ['v' => 1, 'blocks' => array_fill(0, 6, $block)],
            ['v' => 1, 'blocks' => ['RTY-0001']],
            ['v' => 1, 'blocks' => [['definition' => ['v' => 1]]]],
            ['v' => 1, 'blocks' => [['report_type' => 'RTY-0001']]],
        ];

        foreach ($bad as $data) {
            try {
                JoinedDefinition::fromArray($data);
                $this->fail('accepted '.json_encode($data));
            } catch (InvalidReportDefinition) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
