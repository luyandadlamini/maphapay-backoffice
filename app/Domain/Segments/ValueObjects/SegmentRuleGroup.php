<?php

declare(strict_types=1);

namespace App\Domain\Segments\ValueObjects;

use InvalidArgumentException;

class SegmentRuleGroup
{
    private const VALID_LOGIC = ['all', 'any'];

    /**
     * @param  string  $logic  'all' for AND, 'any' for OR
     * @param  array<SegmentRuleDSL|SegmentRuleGroup>  $children
     */
    public function __construct(
        private readonly string $logic,
        private readonly array $children,
    ) {
    }

    public static function fromArray(array $data): self
    {
        if (isset($data['all'])) {
            $logic = 'all';
            $childrenData = $data['all'];
        } elseif (isset($data['any'])) {
            $logic = 'any';
            $childrenData = $data['any'];
        } else {
            throw new InvalidArgumentException('Rule group must have "all" or "any" key');
        }

        $children = array_map(function (array $childData): SegmentRuleDSL|SegmentRuleGroup {
            if (isset($childData['all']) || isset($childData['any'])) {
                return self::fromArray($childData);
            }

            return SegmentRuleDSL::fromArray($childData);
        }, $childrenData);

        return new self(logic: $logic, children: $children);
    }

    public function evaluate(array $context): bool
    {
        if (empty($this->children)) {
            return true;
        }

        if ($this->logic === 'all') {
            foreach ($this->children as $child) {
                if (! $this->evaluateChild($child, $context)) {
                    return false;
                }
            }

            return true;
        }

        foreach ($this->children as $child) {
            if ($this->evaluateChild($child, $context)) {
                return true;
            }
        }

        return false;
    }

    private function evaluateChild(SegmentRuleDSL|self $child, array $context): bool
    {
        return $child->evaluate($context);
    }

    public function toArray(): array
    {
        $key = $this->logic;
        $children = array_map(static function (SegmentRuleDSL|SegmentRuleGroup $child): array {
            return $child->toArray();
        }, $this->children);

        return [
            $key => $children,
        ];
    }
}
