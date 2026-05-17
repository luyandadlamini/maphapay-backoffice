<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Segments\ValueObjects;

use App\Domain\Segments\ValueObjects\SegmentRuleDSL;
use App\Domain\Segments\ValueObjects\SegmentRuleGroup;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SegmentRuleGroupTest extends TestCase
{
    /**
     * Test 'all' (AND) logic with all children passing.
     */
    public function test_all_logic_passes_when_all_children_pass(): void
    {
        $children = [
            new SegmentRuleDSL('age', '>=', 18),
            new SegmentRuleDSL('country', '=', 'SZ'),
        ];

        $group = new SegmentRuleGroup('all', $children);
        $context = ['age' => 25, 'country' => 'SZ'];

        $this->assertTrue($group->evaluate($context));
    }

    /**
     * Test 'all' (AND) logic fails when one child fails.
     */
    public function test_all_logic_fails_when_one_child_fails(): void
    {
        $children = [
            new SegmentRuleDSL('age', '>=', 18),
            new SegmentRuleDSL('country', '=', 'SZ'),
        ];

        $group = new SegmentRuleGroup('all', $children);
        $context = ['age' => 25, 'country' => 'ZA'];

        $this->assertFalse($group->evaluate($context));
    }

    /**
     * Test 'all' (AND) logic fails when multiple children fail.
     */
    public function test_all_logic_fails_when_multiple_children_fail(): void
    {
        $children = [
            new SegmentRuleDSL('age', '>=', 18),
            new SegmentRuleDSL('country', '=', 'SZ'),
            new SegmentRuleDSL('status', '=', 'active'),
        ];

        $group = new SegmentRuleGroup('all', $children);
        $context = ['age' => 15, 'country' => 'ZA', 'status' => 'inactive'];

        $this->assertFalse($group->evaluate($context));
    }

    /**
     * Test 'any' (OR) logic passes when at least one child passes.
     */
    public function test_any_logic_passes_when_one_child_passes(): void
    {
        $children = [
            new SegmentRuleDSL('country', '=', 'SZ'),
            new SegmentRuleDSL('country', '=', 'ZA'),
        ];

        $group = new SegmentRuleGroup('any', $children);
        $context = ['country' => 'ZA'];

        $this->assertTrue($group->evaluate($context));
    }

    /**
     * Test 'any' (OR) logic passes when all children pass.
     */
    public function test_any_logic_passes_when_all_children_pass(): void
    {
        $children = [
            new SegmentRuleDSL('country', 'in', ['SZ', 'ZA']),
            new SegmentRuleDSL('status', '=', 'active'),
        ];

        $group = new SegmentRuleGroup('any', $children);
        $context = ['country' => 'SZ', 'status' => 'active'];

        $this->assertTrue($group->evaluate($context));
    }

    /**
     * Test 'any' (OR) logic fails when all children fail.
     */
    public function test_any_logic_fails_when_all_children_fail(): void
    {
        $children = [
            new SegmentRuleDSL('country', '=', 'SZ'),
            new SegmentRuleDSL('country', '=', 'ZA'),
        ];

        $group = new SegmentRuleGroup('any', $children);
        $context = ['country' => 'BW'];

        $this->assertFalse($group->evaluate($context));
    }

    /**
     * Test 'any' (OR) logic with three conditions, one passing.
     */
    public function test_any_logic_with_three_conditions_one_passing(): void
    {
        $children = [
            new SegmentRuleDSL('age', '<', 18),
            new SegmentRuleDSL('age', '>', 65),
            new SegmentRuleDSL('status', '=', 'vip'),
        ];

        $group = new SegmentRuleGroup('any', $children);
        $context = ['age' => 30, 'status' => 'vip'];

        $this->assertTrue($group->evaluate($context));
    }

    /**
     * Test nested 'all' within 'any'.
     */
    public function test_nested_all_within_any(): void
    {
        $innerGroup = new SegmentRuleGroup('all', [
            new SegmentRuleDSL('age', '>=', 18),
            new SegmentRuleDSL('age', '<=', 65),
        ]);

        $outerGroup = new SegmentRuleGroup('any', [
            $innerGroup,
            new SegmentRuleDSL('status', '=', 'vip'),
        ]);

        $context1 = ['age' => 30, 'status' => 'regular'];
        $this->assertTrue($outerGroup->evaluate($context1));

        $context2 = ['age' => 70, 'status' => 'vip'];
        $this->assertTrue($outerGroup->evaluate($context2));

        $context3 = ['age' => 70, 'status' => 'regular'];
        $this->assertFalse($outerGroup->evaluate($context3));
    }

    /**
     * Test nested 'any' within 'all'.
     */
    public function test_nested_any_within_all(): void
    {
        $innerGroup = new SegmentRuleGroup('any', [
            new SegmentRuleDSL('country', '=', 'SZ'),
            new SegmentRuleDSL('country', '=', 'ZA'),
        ]);

        $outerGroup = new SegmentRuleGroup('all', [
            $innerGroup,
            new SegmentRuleDSL('balance', '>', 1000),
        ]);

        $context1 = ['country' => 'SZ', 'balance' => 2000];
        $this->assertTrue($outerGroup->evaluate($context1));

        $context2 = ['country' => 'BW', 'balance' => 2000];
        $this->assertFalse($outerGroup->evaluate($context2));

        $context3 = ['country' => 'SZ', 'balance' => 500];
        $this->assertFalse($outerGroup->evaluate($context3));
    }

    /**
     * Test deeply nested groups.
     */
    public function test_deeply_nested_groups(): void
    {
        $level3 = new SegmentRuleGroup('all', [
            new SegmentRuleDSL('age', '>=', 18),
            new SegmentRuleDSL('age', '<=', 65),
        ]);

        $level2 = new SegmentRuleGroup('any', [
            $level3,
            new SegmentRuleDSL('status', '=', 'vip'),
        ]);

        $level1 = new SegmentRuleGroup('all', [
            $level2,
            new SegmentRuleDSL('country', 'in', ['SZ', 'ZA']),
        ]);

        $context = ['age' => 30, 'country' => 'SZ', 'status' => 'regular'];
        $this->assertTrue($level1->evaluate($context));
    }

    /**
     * Test empty children array for 'all' returns true.
     */
    public function test_empty_children_all_returns_true(): void
    {
        $group = new SegmentRuleGroup('all', []);

        $this->assertTrue($group->evaluate([]));
    }

    /**
     * Test empty children array for 'any' returns true.
     */
    public function test_empty_children_any_returns_true(): void
    {
        $group = new SegmentRuleGroup('any', []);

        $this->assertTrue($group->evaluate([]));
    }

    /**
     * Test fromArray with simple 'all' group.
     */
    public function test_fromArray_with_all_group(): void
    {
        $data = [
            'all' => [
                ['field' => 'age', 'operator' => '>=', 'value' => 18],
                ['field' => 'country', 'operator' => '=', 'value' => 'SZ'],
            ],
        ];

        $group = SegmentRuleGroup::fromArray($data);
        $context = ['age' => 25, 'country' => 'SZ'];

        $this->assertTrue($group->evaluate($context));
    }

    /**
     * Test fromArray with simple 'any' group.
     */
    public function test_fromArray_with_any_group(): void
    {
        $data = [
            'any' => [
                ['field' => 'country', 'operator' => '=', 'value' => 'SZ'],
                ['field' => 'country', 'operator' => '=', 'value' => 'ZA'],
            ],
        ];

        $group = SegmentRuleGroup::fromArray($data);
        $context = ['country' => 'BW'];

        $this->assertFalse($group->evaluate($context));
    }

    /**
     * Test fromArray with nested groups.
     */
    public function test_fromArray_with_nested_groups(): void
    {
        $data = [
            'all' => [
                [
                    'any' => [
                        ['field' => 'country', 'operator' => '=', 'value' => 'SZ'],
                        ['field' => 'country', 'operator' => '=', 'value' => 'ZA'],
                    ],
                ],
                ['field' => 'balance', 'operator' => '>', 'value' => 1000],
            ],
        ];

        $group = SegmentRuleGroup::fromArray($data);

        $context1 = ['country' => 'SZ', 'balance' => 2000];
        $this->assertTrue($group->evaluate($context1));

        $context2 = ['country' => 'BW', 'balance' => 2000];
        $this->assertFalse($group->evaluate($context2));
    }

    /**
     * Test fromArray throws exception without 'all' or 'any'.
     */
    public function test_fromArray_throws_exception_without_logic_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Rule group must have "all" or "any" key');

        SegmentRuleGroup::fromArray([
            'field'    => 'age',
            'operator' => '>=',
            'value'    => 18,
        ]);
    }

    /**
     * Test toArray with simple 'all' group.
     */
    public function test_toArray_with_all_group(): void
    {
        $children = [
            new SegmentRuleDSL('age', '>=', 18),
            new SegmentRuleDSL('country', '=', 'SZ'),
        ];

        $group = new SegmentRuleGroup('all', $children);
        $result = $group->toArray();

        $this->assertSame([
            'all' => [
                ['field' => 'age', 'operator' => '>=', 'value' => 18],
                ['field' => 'country', 'operator' => '=', 'value' => 'SZ'],
            ],
        ], $result);
    }

    /**
     * Test toArray with simple 'any' group.
     */
    public function test_toArray_with_any_group(): void
    {
        $children = [
            new SegmentRuleDSL('status', '=', 'active'),
            new SegmentRuleDSL('status', '=', 'pending'),
        ];

        $group = new SegmentRuleGroup('any', $children);
        $result = $group->toArray();

        $this->assertSame([
            'any' => [
                ['field' => 'status', 'operator' => '=', 'value' => 'active'],
                ['field' => 'status', 'operator' => '=', 'value' => 'pending'],
            ],
        ], $result);
    }

    /**
     * Test toArray with nested groups.
     */
    public function test_toArray_with_nested_groups(): void
    {
        $innerGroup = new SegmentRuleGroup('any', [
            new SegmentRuleDSL('country', '=', 'SZ'),
            new SegmentRuleDSL('country', '=', 'ZA'),
        ]);

        $outerGroup = new SegmentRuleGroup('all', [
            $innerGroup,
            new SegmentRuleDSL('balance', '>', 1000),
        ]);

        $result = $outerGroup->toArray();

        $this->assertSame([
            'all' => [
                [
                    'any' => [
                        ['field' => 'country', 'operator' => '=', 'value' => 'SZ'],
                        ['field' => 'country', 'operator' => '=', 'value' => 'ZA'],
                    ],
                ],
                ['field' => 'balance', 'operator' => '>', 'value' => 1000],
            ],
        ], $result);
    }

    /**
     * Test round-trip: fromArray -> toArray.
     */
    public function test_round_trip_fromArray_toArray(): void
    {
        $original = [
            'all' => [
                [
                    'any' => [
                        ['field' => 'country', 'operator' => '=', 'value' => 'SZ'],
                        ['field' => 'country', 'operator' => '=', 'value' => 'ZA'],
                    ],
                ],
                ['field' => 'balance', 'operator' => '>', 'value' => 1000],
            ],
        ];

        $group = SegmentRuleGroup::fromArray($original);
        $result = $group->toArray();

        $this->assertSame($original, $result);
    }

    /**
     * Test complex real-world scenario.
     */
    public function test_complex_real_world_scenario(): void
    {
        $data = [
            'all' => [
                ['field' => 'active', 'operator' => '=', 'value' => true],
                [
                    'any' => [
                        ['field' => 'plan', 'operator' => '=', 'value' => 'premium'],
                        ['field' => 'plan', 'operator' => '=', 'value' => 'enterprise'],
                    ],
                ],
                ['field' => 'monthly_spend', 'operator' => '>', 'value' => 5000],
                [
                    'any' => [
                        ['field' => 'country', 'operator' => 'in', 'value' => ['SZ', 'ZA']],
                        ['field' => 'vip_status', 'operator' => '=', 'value' => true],
                    ],
                ],
            ],
        ];

        $group = SegmentRuleGroup::fromArray($data);

        // Should match: all conditions met
        $context1 = [
            'active'        => true,
            'plan'          => 'premium',
            'monthly_spend' => 6000,
            'country'       => 'SZ',
            'vip_status'    => false,
        ];
        $this->assertTrue($group->evaluate($context1));

        // Should not match: plan doesn't match
        $context2 = [
            'active'        => true,
            'plan'          => 'free',
            'monthly_spend' => 6000,
            'country'       => 'SZ',
            'vip_status'    => false,
        ];
        $this->assertFalse($group->evaluate($context2));

        // Should match: vip_status makes it pass
        $context3 = [
            'active'        => true,
            'plan'          => 'enterprise',
            'monthly_spend' => 6000,
            'country'       => 'BW',
            'vip_status'    => true,
        ];
        $this->assertTrue($group->evaluate($context3));
    }
}
