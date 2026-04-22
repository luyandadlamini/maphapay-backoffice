<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Account;

use App\Models\MerchantPartner;
use Tests\DomainTestCase;

class MerchantPartnersTest extends DomainTestCase
{
    public function test_merchant_partners_table_exists(): void
    {
        $schema = \Illuminate\Support\Facades\Schema::connection('mysql');
        $this->assertTrue($schema->hasTable('merchant_partners'));
    }

    public function test_merchant_partner_has_required_columns(): void
    {
        $columns = \Illuminate\Support\Facades\DB::connection('mysql')
            ->getSchemaBuilder()
            ->getColumnListing('merchant_partners');

        $this->assertContains('id', $columns);
        $this->assertContains('name', $columns);
        $this->assertContains('category', $columns);
        $this->assertContains('logo_url', $columns);
        $this->assertContains('qr_endpoint', $columns);
        $this->assertContains('api_key', $columns);
        $this->assertContains('commission_rate', $columns);
        $this->assertContains('payout_schedule', $columns);
        $this->assertContains('is_active', $columns);
        $this->assertContains('created_at', $columns);
        $this->assertContains('updated_at', $columns);
    }

    public function test_merchant_partner_can_be_created(): void
    {
        $partner = MerchantPartner::create([
            'name'            => 'MTN Eswatini',
            'category'        => 'telecom',
            'commission_rate' => 30.00,
            'payout_schedule' => 'weekly',
            'is_active'       => true,
        ]);

        $this->assertNotNull($partner->id);
        $this->assertEquals('MTN Eswatini', $partner->name);
    }
}
