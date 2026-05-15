<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Wallet;

use App\Domain\Wallet\Models\WalletLinking;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WalletLinking>
 */
final class WalletLinkingFactory extends Factory
{
    protected $model = WalletLinking::class;

    public function definition(): array
    {
        return [
            'user_id'     => User::factory(),
            'provider'    => WalletLinking::PROVIDER_MTN_MOMO,
            'account_ref' => (string) fake()->numerify('4673#######'),
            'currency'    => 'SZL',
            'link_status' => WalletLinking::STATUS_ACTIVE,
            'linked_at'   => now(),
            'metadata'    => null,
        ];
    }
}
