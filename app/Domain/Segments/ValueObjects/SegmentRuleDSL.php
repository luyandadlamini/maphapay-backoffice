<?php

declare(strict_types=1);

namespace App\Domain\Segments\ValueObjects;

class SegmentRuleDSL
{
    private const VALID_OPERATORS = ['=', '!=', '>', '>=', '<', '<=', 'in', 'not_in', 'between'];

    public function __construct(
        private readonly string $field,
        private readonly string $operator,
        private readonly mixed $value,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            field: (string) $data['field'],
            operator: (string) $data['operator'],
            value: $data['value'],
        );
    }

    public function evaluate(array $context): bool
    {
        if (! isset($context[$this->field])) {
            return false;
        }

        $fieldValue = $context[$this->field];

        return match ($this->operator) {
            '='       => $fieldValue == $this->value,
            '!='      => $fieldValue != $this->value,
            '>'       => $fieldValue > $this->value,
            '>='      => $fieldValue >= $this->value,
            '<'       => $fieldValue < $this->value,
            '<='      => $fieldValue <= $this->value,
            'in'      => is_array($this->value) && in_array($fieldValue, $this->value, strict: true),
            'not_in'  => is_array($this->value) && ! in_array($fieldValue, $this->value, strict: true),
            'between' => $this->evaluateBetween($fieldValue),
            default   => false,
        };
    }

    private function evaluateBetween(mixed $fieldValue): bool
    {
        if (! is_array($this->value) || count($this->value) !== 2) {
            return false;
        }

        [$min, $max] = $this->value;

        return $fieldValue >= $min && $fieldValue <= $max;
    }

    public function toArray(): array
    {
        return [
            'field'    => $this->field,
            'operator' => $this->operator,
            'value'    => $this->value,
        ];
    }
}
