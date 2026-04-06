<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CryptoDepositTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Create test user with team
        $this->user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($this->user);
    }

    #[Test]
    public function test_crypto_deposit_page_loads_successfully()
    {
        $response = $this->get('/wallet/deposit/crypto');

        $response->assertStatus(200);
        $response->assertSee('Cryptocurrency Deposit');

        // Check all crypto options are present
        $response->assertSee('Bitcoin (BTC)');
        $response->assertSee('Ethereum (ETH)');
        $response->assertSee('Tether (USDT)');

        // Check network information
        $response->assertSee('Network: Bitcoin');
        $response->assertSee('Network: ERC-20');
        $response->assertSee('Network: TRC-20');
    }

    #[Test]
    public function test_crypto_deposit_page_includes_qr_code_functionality()
    {
        $response = $this->get('/wallet/deposit/crypto');

        $response->assertStatus(200);

        // Should include QR code library
        $response->assertSee('qrcode@1.5.3', false);

        // Should have QR code generation function
        $response->assertSee('generateQRCode', false);
        $response->assertSee('QRCode.toCanvas', false);

        // Should not have placeholder text
        $response->assertDontSee('QR Code Placeholder');
    }

    #[Test]
    public function test_crypto_deposit_page_has_copy_functionality()
    {
        $response = $this->get('/wallet/deposit/crypto');

        $response->assertStatus(200);

        // Check copy functionality exists
        $response->assertSee('copyAddress()', false);
        $response->assertSee('navigator.clipboard', false);
        $response->assertSee('Copy');

        // Check feedback mechanism
        $response->assertSee('Copied!');
        $response->assertSee('showCopyFeedback', false);
    }

    #[Test]
    public function test_crypto_deposit_page_uses_config_driven_addresses(): void
    {
        config(['cgo.deposit_addresses' => [
            'btc'  => 'tb1qtest_btc_address',
            'eth'  => '0xtest_eth_address',
            'usdt' => 'Ttest_usdt_address',
        ]]);

        $response = $this->get('/wallet/deposit/crypto');

        $response->assertStatus(200);

        // Verify config addresses are rendered in the JS (via @json)
        $response->assertSee('tb1qtest_btc_address', false);
        $response->assertSee('0xtest_eth_address', false);
        $response->assertSee('Ttest_usdt_address', false);

        // Verify no hardcoded third-party addresses
        $response->assertDontSee('1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa', false);
    }

    #[Test]
    public function test_crypto_deposit_page_handles_empty_addresses(): void
    {
        config(['cgo.deposit_addresses' => [
            'btc'  => '',
            'eth'  => '',
            'usdt' => '',
        ]]);

        $response = $this->get('/wallet/deposit/crypto');

        $response->assertStatus(200);

        // Should show "Not configured" fallback in JS logic
        $response->assertSee('Not configured', false);
    }

    #[Test]
    public function test_crypto_deposit_page_shows_important_notices()
    {
        $response = $this->get('/wallet/deposit/crypto');

        $response->assertStatus(200);

        // Check important notice section
        $response->assertSee('Important Notice');
        $response->assertSee('Send only');
        $response->assertSee('Minimum deposit:');
        $response->assertSee('network confirmations');
        $response->assertSee('Processing time: 10-60 minutes');
    }

    #[Test]
    public function test_crypto_deposit_page_has_back_button()
    {
        $response = $this->get('/wallet/deposit/crypto');

        $response->assertStatus(200);

        // Check back button exists
        $response->assertSee('Back to Deposit Options');
        $response->assertSee(route('wallet.deposit'));
    }
}
