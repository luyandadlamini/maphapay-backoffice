<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Pricing\Services;

use App\Domain\Pricing\Services\ElasticityModel;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ElasticityModelTest extends TestCase
{
    #[Test]
    public function zero_elasticity_returns_base_volume(): void
    {
        $this->assertSame(100, ElasticityModel::apply(100, 0.5, 0));
    }

    #[Test]
    public function zero_fee_change_returns_base_volume(): void
    {
        $this->assertSame(100, ElasticityModel::apply(100, 0.0, 5_000));
    }

    #[Test]
    public function negative_elasticity_reduces_volume_when_price_rises(): void
    {
        // base=100, feePctChange=+1.0 (100% increase), elasticity=-5000bps (=-0.5)
        // result = max(0, round(100 * (1 + -5000/10000 * 1.0))) = round(100 * 0.5) = 50
        $this->assertSame(50, ElasticityModel::apply(100, 1.0, -5_000));
    }

    #[Test]
    public function positive_elasticity_increases_volume_when_price_drops(): void
    {
        // base=100, feePctChange=-0.5 (price -50%), elasticity=-5000bps
        // result = round(100 * (1 + -5000/10000 * -0.5)) = round(100 * 1.25) = 125
        $this->assertSame(125, ElasticityModel::apply(100, -0.5, -5_000));
    }

    #[Test]
    public function result_is_clamped_to_zero(): void
    {
        // Wild swing that would mathematically go negative.
        $this->assertSame(0, ElasticityModel::apply(100, 5.0, -5_000));
    }
}
