<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use Tests\TestCase;

final class WalletMocksConfigTest extends TestCase
{
    private const REQUIRED_KEYS = [
        'callback_url',
        'callback_token',
        'hmac_key',
        'callback_delay_seconds',
        'default_seed_balance_minor',
        'currency',
    ];

    private const PROVIDER_IDS = [
        'mtn_momo',
        'emali_eswatini_mobile',
        'fnb_ewallet',
        'standard_unayo',
        'nedbank_send_money',
    ];

    public function test_config_file_loads(): void
    {
        $config = config('wallet_mocks');

        $this->assertIsArray($config);
        $this->assertArrayHasKey('enabled', $config);
        $this->assertArrayHasKey('allow_in_production', $config);
        $this->assertArrayHasKey('providers', $config);
    }

    public function test_all_five_providers_are_defined(): void
    {
        foreach (self::PROVIDER_IDS as $providerId) {
            $this->assertArrayHasKey(
                $providerId,
                config('wallet_mocks.providers'),
                "Missing provider config: {$providerId}",
            );
        }
    }

    public function test_each_provider_has_required_keys(): void
    {
        foreach (self::PROVIDER_IDS as $providerId) {
            $providerConfig = config("wallet_mocks.providers.{$providerId}");

            $this->assertIsArray($providerConfig, "Provider config not array: {$providerId}");

            foreach (self::REQUIRED_KEYS as $key) {
                $this->assertArrayHasKey(
                    $key,
                    $providerConfig,
                    "Provider {$providerId} missing key {$key}",
                );
            }
        }
    }

    public function test_seed_balance_and_delay_are_typed_as_ints(): void
    {
        foreach (self::PROVIDER_IDS as $providerId) {
            $this->assertIsInt(config("wallet_mocks.providers.{$providerId}.default_seed_balance_minor"));
            $this->assertIsInt(config("wallet_mocks.providers.{$providerId}.callback_delay_seconds"));
        }
    }

    public function test_enabled_defaults_to_false(): void
    {
        $this->assertFalse(config('wallet_mocks.enabled'));
        $this->assertFalse(config('wallet_mocks.allow_in_production'));
    }
}
