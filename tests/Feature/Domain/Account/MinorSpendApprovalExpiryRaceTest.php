<?php

declare(strict_types=1);

use App\Domain\Account\Models\MinorSpendApproval;
use Illuminate\Support\Facades\DB;

it('expiry command cancels only past-expiry approvals', function (): void {
    $expired = MinorSpendApproval::factory()->create([
        'status'     => 'pending',
        'expires_at' => now()->subHour(),
    ]);

    $valid = MinorSpendApproval::factory()->create([
        'status'     => 'pending',
        'expires_at' => now()->addHour(),
    ]);

    $this->artisan('minor-accounts:expire-approvals')->assertSuccessful();

    expect($expired->fresh()->status)->toBe('cancelled')
        ->and($expired->fresh()->decided_at)->not->toBeNull()
        ->and($valid->fresh()->status)->toBe('pending');
});

it('does not cancel approvals that are no longer pending', function (): void {
    $approval = MinorSpendApproval::factory()->create([
        'status'     => 'pending',
        'expires_at' => now()->subMinutes(5),
    ]);

    DB::transaction(function () use ($approval): void {
        $locked = MinorSpendApproval::query()
            ->where('id', $approval->id)
            ->lockForUpdate()
            ->first();

        if ($locked !== null && $locked->status === 'pending') {
            $locked->forceFill(['status' => 'approved', 'decided_at' => now()])->save();
        }
    });

    $this->artisan('minor-accounts:expire-approvals')->assertSuccessful();

    expect($approval->fresh()->status)->toBe('approved');
});
