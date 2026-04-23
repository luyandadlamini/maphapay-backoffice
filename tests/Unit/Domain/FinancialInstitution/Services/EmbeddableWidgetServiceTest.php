<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\FinancialInstitution\Services;

use App\Domain\FinancialInstitution\Models\FinancialInstitutionPartner;
use App\Domain\FinancialInstitution\Models\PartnerBranding;
use App\Domain\FinancialInstitution\Services\EmbeddableWidgetService;
use App\Domain\FinancialInstitution\Services\PartnerTierService;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class EmbeddableWidgetServiceTest extends TestCase
{
    private EmbeddableWidgetService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('baas.widgets.enabled', true);
        Config::set('baas.widgets.sandbox_domain', 'sandbox.finaegis.com');
        Config::set('baas.widgets.production_domain', 'api.finaegis.com');
        Config::set('baas.widgets.types', [
            'payment' => [
                'name'        => 'Payment Form',
                'description' => 'Embeddable payment form widget',
                'js_file'     => 'finaegis-payment.js',
            ],
            'checkout' => [
                'name'        => 'Checkout Experience',
                'description' => 'Full checkout flow widget',
                'js_file'     => 'finaegis-checkout.js',
            ],
            'balance' => [
                'name'        => 'Balance Display',
                'description' => 'Account balance display widget',
                'js_file'     => 'finaegis-balance.js',
            ],
            'transfer' => [
                'name'        => 'Transfer Form',
                'description' => 'Money transfer widget',
                'js_file'     => 'finaegis-transfer.js',
            ],
            'account' => [
                'name'        => 'Account Summary',
                'description' => 'Account summary widget',
                'js_file'     => 'finaegis-account.js',
            ],
        ]);
        $this->service = new EmbeddableWidgetService(new PartnerTierService());
    }

    private function createMockPartner(array $attributes = []): FinancialInstitutionPartner
    {
        $defaults = [
            'id'               => fake()->uuid(),
            'partner_code'     => 'TST-12345',
            'institution_name' => 'Test Partner',
            'tier'             => 'growth',
            'api_client_id'    => 'test_client_abc',
            'sandbox_enabled'  => true,
        ];

        $merged = array_merge($defaults, $attributes);
        $branding = array_key_exists('branding', $merged) ? $merged['branding'] : null;
        unset($merged['branding']);

        $partner = new FinancialInstitutionPartner();
        $partner->forceFill($merged);
        $partner->setRelation('branding', $branding);

        return $partner;
    }

    private function createMockBranding(): PartnerBranding
    {
        $branding = new PartnerBranding();
        $branding->forceFill([
            'primary_color'    => '#1a73e8',
            'secondary_color'  => '#5f6368',
            'accent_color'     => '#1a73e8',
            'text_color'       => '#202124',
            'background_color' => '#ffffff',
            'company_name'     => 'Test Partner Co',
            'tagline'          => 'Testing made easy',
            'logo_url'         => 'https://example.com/logo.png',
            'logo_dark_url'    => 'https://example.com/logo-dark.png',
            'support_email'    => 'support@test.com',
            'widget_config'    => null,
        ]);

        return $branding;
    }

    public function test_generate_embed_code_for_growth_tier(): void
    {
        $partner = $this->createMockPartner(['tier' => 'growth', 'branding' => $this->createMockBranding()]);

        $result = $this->service->generateEmbedCode($partner, 'payment');

        $this->assertTrue($result['success']);
        $this->assertEquals('payment', $result['widget_type']);
        $this->assertNotNull($result['html']);
        $this->assertStringContainsString('finaegis-payment.js', $result['html']);
        $this->assertStringContainsString('test_client_abc', $result['html']);
    }

    public function test_generate_embed_code_denied_for_starter_tier(): void
    {
        $partner = $this->createMockPartner(['tier' => 'starter']);

        $result = $this->service->generateEmbedCode($partner, 'payment');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Growth or Enterprise', $result['message']);
        $this->assertNull($result['html']);
    }

    public function test_generate_embed_code_invalid_widget_type(): void
    {
        $partner = $this->createMockPartner(['tier' => 'growth']);

        $result = $this->service->generateEmbedCode($partner, 'nonexistent');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Unknown widget type', $result['message']);
    }

    public function test_generate_embed_code_with_custom_options(): void
    {
        $partner = $this->createMockPartner(['tier' => 'growth', 'branding' => $this->createMockBranding()]);

        $result = $this->service->generateEmbedCode($partner, 'checkout', [
            'container_id' => 'my-checkout',
            'width'        => '500px',
            'height'       => '600px',
        ]);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('my-checkout', $result['html']);
        $this->assertStringContainsString('500px', $result['html']);
        $this->assertStringContainsString('600px', $result['html']);
    }

    public function test_generate_embed_code_uses_sandbox_domain(): void
    {
        $partner = $this->createMockPartner([
            'tier'            => 'growth',
            'sandbox_enabled' => true,
            'branding'        => $this->createMockBranding(),
        ]);

        $result = $this->service->generateEmbedCode($partner, 'payment');

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('sandbox.finaegis.com', $result['html']);
    }

    public function test_get_available_widgets(): void
    {
        $widgets = $this->service->getAvailableWidgets();

        $this->assertIsArray($widgets);
        $this->assertArrayHasKey('payment', $widgets);
        $this->assertArrayHasKey('checkout', $widgets);
        $this->assertArrayHasKey('balance', $widgets);
        $this->assertArrayHasKey('transfer', $widgets);
        $this->assertArrayHasKey('account', $widgets);
    }

    public function test_generate_widget_script(): void
    {
        $partner = $this->createMockPartner(['tier' => 'growth', 'branding' => $this->createMockBranding()]);

        $result = $this->service->generateWidgetScript($partner, 'payment');

        $this->assertTrue($result['success']);
        $this->assertNotNull($result['content']);
        $this->assertStringContainsString('FinAegis', $result['content']);
        $this->assertStringContainsString('Payment Form', $result['content']);
        $this->assertStringContainsString('Test Partner', $result['content']);
    }

    public function test_generate_widget_script_denied_for_starter(): void
    {
        $partner = $this->createMockPartner(['tier' => 'starter']);

        $result = $this->service->generateWidgetScript($partner, 'payment');

        $this->assertFalse($result['success']);
        $this->assertNull($result['content']);
    }

    public function test_preview_widget(): void
    {
        $partner = $this->createMockPartner(['tier' => 'enterprise', 'branding' => $this->createMockBranding()]);

        $result = $this->service->previewWidget($partner, 'balance');

        $this->assertTrue($result['success']);
        $this->assertNotNull($result['html']);
        $this->assertStringContainsString('<!DOCTYPE html>', $result['html']);
        $this->assertStringContainsString('Test Partner Co', $result['html']);
        $this->assertStringContainsString('Preview', $result['html']);
        $this->assertStringContainsString('finaegis-balance.js', $result['html']);
    }

    public function test_validate_widget_access_growth_allowed(): void
    {
        $partner = $this->createMockPartner(['tier' => 'growth']);

        $result = $this->service->validateWidgetAccess($partner, 'payment');

        $this->assertTrue($result['allowed']);
        $this->assertNull($result['reason']);
    }

    public function test_validate_widget_access_starter_denied(): void
    {
        $partner = $this->createMockPartner(['tier' => 'starter']);

        $result = $this->service->validateWidgetAccess($partner, 'payment');

        $this->assertFalse($result['allowed']);
        $this->assertStringContainsString('Growth or Enterprise', $result['reason']);
    }

    public function test_generate_embed_code_without_branding(): void
    {
        $partner = $this->createMockPartner(['tier' => 'growth', 'branding' => null]);

        $result = $this->service->generateEmbedCode($partner, 'payment');

        $this->assertTrue($result['success']);
        $this->assertNotNull($result['html']);
        $this->assertStringContainsString('finaegis-payment.js', $result['html']);
    }
}
