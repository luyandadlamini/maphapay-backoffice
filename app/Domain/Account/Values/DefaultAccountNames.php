<?php

declare(strict_types=1);

namespace App\Domain\Account\Values;

enum DefaultAccountNames: string
{
    case MAIN = 'Main';
    case SAVINGS = 'Savings';
    case LOAN = 'Loan';

    public static function default(): self
    {
        return self::MAIN;
    }

    /**
     * Get the translation for the account name.
     */
    public function label(): string
    {
        return __(sprintf('accounts.names.%s', strtolower($this->value)));
    }
}
