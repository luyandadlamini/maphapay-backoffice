<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Segments\ValueObjects;

use App\Domain\Segments\ValueObjects\SegmentRuleDSL;
use PHPUnit\Framework\TestCase;

final class SegmentRuleDSLTest extends TestCase
{
    /**
     * Test equality operator with matching values.
     */
    public function test_equality_operator_passes(): void
    {
        $rule = new SegmentRuleDSL('age', '=', 25);
        $context = ['age' => 25];

        $this->assertTrue($rule->evaluate($context));
    }

    /**
     * Test equality operator with non-matching values.
     */
    public function test_equality_operator_fails(): void
    {
        $rule = new SegmentRuleDSL('age', '=', 25);
        $context = ['age' => 26];

        $this->assertFalse($rule->evaluate($context));
    }

    /**
     * Test inequality operator with matching values.
     */
    public function test_inequality_operator_passes(): void
    {
        $rule = new SegmentRuleDSL('status', '!=', 'inactive');
        $context = ['status' => 'active'];

        $this->assertTrue($rule->evaluate($context));
    }

    /**
     * Test inequality operator with non-matching values.
     */
    public function test_inequality_operator_fails(): void
    {
        $rule = new SegmentRuleDSL('status', '!=', 'active');
        $context = ['status' => 'active'];

        $this->assertFalse($rule->evaluate($context));
    }

    /**
     * Test greater than operator passes.
     */
    public function test_greater_than_operator_passes(): void
    {
        $rule = new SegmentRuleDSL('balance', '>', 1000);
        $context = ['balance' => 1500];

        $this->assertTrue($rule->evaluate($context));
    }

    /**
     * Test greater than operator fails when equal.
     */
    public function test_greater_than_operator_fails_when_equal(): void
    {
        $rule = new SegmentRuleDSL('balance', '>', 1000);
        $context = ['balance' => 1000];

        $this->assertFalse($rule->evaluate($context));
    }

    /**
     * Test greater than or equal operator passes with equal value.
     */
    public function test_greater_than_or_equal_operator_passes_with_equal(): void
    {
        $rule = new SegmentRuleDSL('balance', '>=', 1000);
        $context = ['balance' => 1000];

        $this->assertTrue($rule->evaluate($context));
    }

    /**
     * Test greater than or equal operator passes with greater value.
     */
    public function test_greater_than_or_equal_operator_passes_with_greater(): void
    {
        $rule = new SegmentRuleDSL('balance', '>=', 1000);
        $context = ['balance' => 1500];

        $this->assertTrue($rule->evaluate($context));
    }

    /**
     * Test greater than or equal operator fails with less value.
     */
    public function test_greater_than_or_equal_operator_fails(): void
    {
        $rule = new SegmentRuleDSL('balance', '>=', 1000);
        $context = ['balance' => 500];

        $this->assertFalse($rule->evaluate($context));
    }

    /**
     * Test less than operator passes.
     */
    public function test_less_than_operator_passes(): void
    {
        $rule = new SegmentRuleDSL('age', '<', 30);
        $context = ['age' => 25];

        $this->assertTrue($rule->evaluate($context));
    }

    /**
     * Test less than operator fails when equal.
     */
    public function test_less_than_operator_fails_when_equal(): void
    {
        $rule = new SegmentRuleDSL('age', '<', 30);
        $context = ['age' => 30];

        $this->assertFalse($rule->evaluate($context));
    }

    /**
     * Test less than or equal operator passes with equal value.
     */
    public function test_less_than_or_equal_operator_passes_with_equal(): void
    {
        $rule = new SegmentRuleDSL('age', '<=', 30);
        $context = ['age' => 30];

        $this->assertTrue($rule->evaluate($context));
    }

    /**
     * Test less than or equal operator passes with less value.
     */
    public function test_less_than_or_equal_operator_passes_with_less(): void
    {
        $rule = new SegmentRuleDSL('age', '<=', 30);
        $context = ['age' => 25];

        $this->assertTrue($rule->evaluate($context));
    }

    /**
     * Test less than or equal operator fails with greater value.
     */
    public function test_less_than_or_equal_operator_fails(): void
    {
        $rule = new SegmentRuleDSL('age', '<=', 30);
        $context = ['age' => 35];

        $this->assertFalse($rule->evaluate($context));
    }

    /**
     * Test 'in' operator passes when value is in array.
     */
    public function test_in_operator_passes(): void
    {
        $rule = new SegmentRuleDSL('country', 'in', ['SZ', 'ZA', 'BW']);
        $context = ['country' => 'SZ'];

        $this->assertTrue($rule->evaluate($context));
    }

    /**
     * Test 'in' operator fails when value is not in array.
     */
    public function test_in_operator_fails(): void
    {
        $rule = new SegmentRuleDSL('country', 'in', ['SZ', 'ZA', 'BW']);
        $context = ['country' => 'NA'];

        $this->assertFalse($rule->evaluate($context));
    }

    /**
     * Test 'not_in' operator passes when value is not in array.
     */
    public function test_not_in_operator_passes(): void
    {
        $rule = new SegmentRuleDSL('country', 'not_in', ['SZ', 'ZA', 'BW']);
        $context = ['country' => 'NA'];

        $this->assertTrue($rule->evaluate($context));
    }

    /**
     * Test 'not_in' operator fails when value is in array.
     */
    public function test_not_in_operator_fails(): void
    {
        $rule = new SegmentRuleDSL('country', 'not_in', ['SZ', 'ZA', 'BW']);
        $context = ['country' => 'SZ'];

        $this->assertFalse($rule->evaluate($context));
    }

    /**
     * Test 'between' operator passes when value is within range.
     */
    public function test_between_operator_passes(): void
    {
        $rule = new SegmentRuleDSL('age', 'between', [18, 65]);
        $context = ['age' => 30];

        $this->assertTrue($rule->evaluate($context));
    }

    /**
     * Test 'between' operator passes at lower bound.
     */
    public function test_between_operator_passes_at_lower_bound(): void
    {
        $rule = new SegmentRuleDSL('age', 'between', [18, 65]);
        $context = ['age' => 18];

        $this->assertTrue($rule->evaluate($context));
    }

    /**
     * Test 'between' operator passes at upper bound.
     */
    public function test_between_operator_passes_at_upper_bound(): void
    {
        $rule = new SegmentRuleDSL('age', 'between', [18, 65]);
        $context = ['age' => 65];

        $this->assertTrue($rule->evaluate($context));
    }

    /**
     * Test 'between' operator fails when value is below range.
     */
    public function test_between_operator_fails_below_range(): void
    {
        $rule = new SegmentRuleDSL('age', 'between', [18, 65]);
        $context = ['age' => 17];

        $this->assertFalse($rule->evaluate($context));
    }

    /**
     * Test 'between' operator fails when value is above range.
     */
    public function test_between_operator_fails_above_range(): void
    {
        $rule = new SegmentRuleDSL('age', 'between', [18, 65]);
        $context = ['age' => 66];

        $this->assertFalse($rule->evaluate($context));
    }

    /**
     * Test 'between' operator fails with invalid value array.
     */
    public function test_between_operator_fails_with_invalid_value(): void
    {
        $rule = new SegmentRuleDSL('age', 'between', [18]);
        $context = ['age' => 30];

        $this->assertFalse($rule->evaluate($context));
    }

    /**
     * Test unknown operator returns false.
     */
    public function test_unknown_operator_fails(): void
    {
        $rule = new SegmentRuleDSL('age', 'unknown_operator', 30);
        $context = ['age' => 30];

        $this->assertFalse($rule->evaluate($context));
    }

    /**
     * Test missing field in context returns false.
     */
    public function test_missing_field_in_context(): void
    {
        $rule = new SegmentRuleDSL('age', '=', 30);
        $context = ['name' => 'John'];

        $this->assertFalse($rule->evaluate($context));
    }

    /**
     * Test fromArray creates rule correctly.
     */
    public function test_fromArray_creates_rule(): void
    {
        $data = [
            'field'    => 'age',
            'operator' => '>=',
            'value'    => 18,
        ];

        $rule = SegmentRuleDSL::fromArray($data);
        $context = ['age' => 25];

        $this->assertTrue($rule->evaluate($context));
    }

    /**
     * Test toArray returns correct structure.
     */
    public function test_toArray_returns_correct_structure(): void
    {
        $rule = new SegmentRuleDSL('age', '>=', 18);
        $result = $rule->toArray();

        $this->assertSame([
            'field'    => 'age',
            'operator' => '>=',
            'value'    => 18,
        ], $result);
    }

    /**
     * Test round-trip: fromArray -> toArray.
     */
    public function test_round_trip_fromArray_toArray(): void
    {
        $original = [
            'field'    => 'country',
            'operator' => 'in',
            'value'    => ['SZ', 'ZA'],
        ];

        $rule = SegmentRuleDSL::fromArray($original);
        $result = $rule->toArray();

        $this->assertSame($original, $result);
    }
}
