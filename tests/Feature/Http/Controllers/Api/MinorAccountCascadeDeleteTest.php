<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api;

use App\Domain\Account\Models\Account;
use Tests\TestCase;

class MinorAccountCascadeDeleteTest extends TestCase
{
    public function test_blocks_deletion_of_a_parent_account_that_has_active_minor_children(): void
    {
        $parent = Account::factory()->create([
            'type' => 'personal',
        ]);

        $child = Account::factory()->create([
            'type'              => 'minor',
            'parent_account_id' => $parent->uuid,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $parent->delete();

        $this->assertDatabaseHas('accounts', ['uuid' => $child->uuid]);
    }

    public function test_allows_deletion_of_a_parent_account_with_no_minor_children(): void
    {
        $parent = Account::factory()->create([
            'type' => 'personal',
        ]);

        $parent->delete();

        $this->assertDatabaseMissing('accounts', ['uuid' => $parent->uuid]);
    }
}