<?php

declare(strict_types=1);

namespace App\Domain\Shared\Money;

final readonly class Money
{
    public function __construct(
        public string $amount,
        public string $currency = 'SZL',
    ) {
    }

    public static function zero(string $currency = 'SZL'): self
    {
        return new self('0.00', $currency);
    }

    public static function fromMajorString(string $amount, string $currency = 'SZL'): self
    {
        return new self($amount, $currency);
    }

    public function add(self $other): self
    {
        if ($this->currency !== $other->currency) {
            throw new \InvalidArgumentException('Cannot add money values with different currencies.');
        }

        return new self(bcadd($this->amount, $other->amount, 2), $this->currency);
    }

    public function multiplyBps(int $basisPoints): self
    {
        $amount = bcdiv(bcmul($this->amount, (string) $basisPoints, 6), '10000', 2);

        return new self($amount, $this->currency);
    }
}
