<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use Database\Seeders\DatabaseSeeder;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

class DatabaseSeederCardPlanTest extends TestCase
{
    #[Test]
    public function database_seeder_includes_card_plans_for_deploy_bootstrap(): void
    {
        $source = file_get_contents((new ReflectionClass(DatabaseSeeder::class))->getFileName());

        $this->assertIsString($source);
        $this->assertStringContainsString('CardPlanSeeder::class', $source);
    }
}
