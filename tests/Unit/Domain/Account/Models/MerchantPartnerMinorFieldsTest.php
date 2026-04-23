<?php

namespace Tests\Unit\Domain\Account\Models;

use App\Models\MerchantPartner;
use Tests\TestCase;

class MerchantPartnerMinorFieldsTest extends TestCase
{
    public function test_merchant_partner_has_minor_bonus_fields_in_fillable(): void
    {
        $partner = new MerchantPartner();
        $fillable = $partner->getFillable();
        
        $this->assertContains('bonus_multiplier', $fillable);
        $this->assertContains('min_age_allowance', $fillable);
        $this->assertContains('category_slugs', $fillable);
        $this->assertContains('is_active_for_minors', $fillable);
        $this->assertContains('bonus_terms', $fillable);
        $this->assertContains('tenant_id', $fillable);
    }
}