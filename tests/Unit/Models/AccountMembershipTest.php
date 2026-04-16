<?php

declare(strict_types=1);

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use Database\Factories\AccountMembershipFactory;
use Illuminate\Support\Str;

describe('AccountMembership Model', function () {
    describe('Relationships', function () {
        it('belongs to minor account', function () {
            $minorAccount = Account::factory()->create();
            $guardianAccount = Account::factory()->create();

            $membership = AccountMembership::factory()
                ->for($minorAccount, 'minorAccount')
                ->for($guardianAccount, 'guardianAccount')
                ->create();

            expect($membership->minorAccount)
                ->toBeInstanceOf(Account::class)
                ->and($membership->minorAccount->uuid)
                ->toBe($minorAccount->uuid);
        });

        it('belongs to guardian account', function () {
            $minorAccount = Account::factory()->create();
            $guardianAccount = Account::factory()->create();

            $membership = AccountMembership::factory()
                ->for($minorAccount, 'minorAccount')
                ->for($guardianAccount, 'guardianAccount')
                ->create();

            expect($membership->guardianAccount)
                ->toBeInstanceOf(Account::class)
                ->and($membership->guardianAccount->uuid)
                ->toBe($guardianAccount->uuid);
        });

        it('can eager load relationships', function () {
            $minorAccount = Account::factory()->create();
            $guardianAccount = Account::factory()->create();

            AccountMembership::factory()
                ->for($minorAccount, 'minorAccount')
                ->for($guardianAccount, 'guardianAccount')
                ->create();

            $membership = AccountMembership::with(['minorAccount', 'guardianAccount'])->first();

            expect($membership->relationLoaded('minorAccount'))->toBeTrue()
                ->and($membership->relationLoaded('guardianAccount'))->toBeTrue();
        });
    });

    describe('Scopes', function () {
        it('filters memberships for a specific minor account', function () {
            $minorAccount1 = Account::factory()->create();
            $minorAccount2 = Account::factory()->create();
            $guardian = Account::factory()->create();

            AccountMembership::factory()
                ->for($minorAccount1, 'minorAccount')
                ->for($guardian, 'guardianAccount')
                ->create();

            AccountMembership::factory()
                ->for($minorAccount2, 'minorAccount')
                ->for($guardian, 'guardianAccount')
                ->create();

            $result = AccountMembership::forMinorAccount($minorAccount1->uuid)->get();

            expect($result)->toHaveCount(1)
                ->and($result->first()->minor_account_id)->toBe($minorAccount1->uuid);
        });

        it('filters memberships for a specific guardian account', function () {
            $minorAccount = Account::factory()->create();
            $guardian1 = Account::factory()->create();
            $guardian2 = Account::factory()->create();

            AccountMembership::factory()
                ->for($minorAccount, 'minorAccount')
                ->for($guardian1, 'guardianAccount')
                ->create();

            AccountMembership::factory()
                ->for($minorAccount, 'minorAccount')
                ->for($guardian2, 'guardianAccount')
                ->create();

            $result = AccountMembership::forGuardianAccount($guardian1->uuid)->get();

            expect($result)->toHaveCount(1)
                ->and($result->first()->guardian_account_id)->toBe($guardian1->uuid);
        });

        it('filters memberships with primary role (guardian)', function () {
            $minorAccount = Account::factory()->create();
            $guardian1 = Account::factory()->create();
            $guardian2 = Account::factory()->create();

            AccountMembership::factory()
                ->for($minorAccount, 'minorAccount')
                ->for($guardian1, 'guardianAccount')
                ->asGuardian()
                ->create();

            AccountMembership::factory()
                ->for($minorAccount, 'minorAccount')
                ->for($guardian2, 'guardianAccount')
                ->asCoGuardian()
                ->create();

            $result = AccountMembership::primary()->get();

            expect($result)->toHaveCount(1)
                ->and($result->first()->role)->toBe('guardian');
        });

        it('filters memberships with co-guardian role', function () {
            $minorAccount = Account::factory()->create();
            $guardian1 = Account::factory()->create();
            $guardian2 = Account::factory()->create();

            AccountMembership::factory()
                ->for($minorAccount, 'minorAccount')
                ->for($guardian1, 'guardianAccount')
                ->asGuardian()
                ->create();

            AccountMembership::factory()
                ->for($minorAccount, 'minorAccount')
                ->for($guardian2, 'guardianAccount')
                ->asCoGuardian()
                ->create();

            $result = AccountMembership::coGuardians()->get();

            expect($result)->toHaveCount(1)
                ->and($result->first()->role)->toBe('co_guardian');
        });

        it('allows chaining scopes', function () {
            $minorAccount = Account::factory()->create();
            $guardian1 = Account::factory()->create();
            $guardian2 = Account::factory()->create();

            AccountMembership::factory()
                ->for($minorAccount, 'minorAccount')
                ->for($guardian1, 'guardianAccount')
                ->asGuardian()
                ->create();

            AccountMembership::factory()
                ->for($minorAccount, 'minorAccount')
                ->for($guardian2, 'guardianAccount')
                ->asCoGuardian()
                ->create();

            $result = AccountMembership::forMinorAccount($minorAccount->uuid)
                ->primary()
                ->get();

            expect($result)->toHaveCount(1)
                ->and($result->first()->role)->toBe('guardian');
        });
    });

    describe('Casts', function () {
        it('casts id to UUID', function () {
            $membership = AccountMembership::factory()->create();

            expect($membership->id)->toBeString()
                ->and(Str::isUuid($membership->id))->toBeTrue();
        });

        it('casts minor_account_id to UUID', function () {
            $minorAccount = Account::factory()->create();
            $guardianAccount = Account::factory()->create();

            $membership = AccountMembership::factory()
                ->for($minorAccount, 'minorAccount')
                ->for($guardianAccount, 'guardianAccount')
                ->create();

            expect($membership->minor_account_id)->toBeString()
                ->and(Str::isUuid($membership->minor_account_id))->toBeTrue();
        });

        it('casts guardian_account_id to UUID', function () {
            $minorAccount = Account::factory()->create();
            $guardianAccount = Account::factory()->create();

            $membership = AccountMembership::factory()
                ->for($minorAccount, 'minorAccount')
                ->for($guardianAccount, 'guardianAccount')
                ->create();

            expect($membership->guardian_account_id)->toBeString()
                ->and(Str::isUuid($membership->guardian_account_id))->toBeTrue();
        });

        it('casts permissions to array', function () {
            $membership = AccountMembership::factory()
                ->withPermissions(['canApproveSpending' => true, 'canManageChores' => true])
                ->create();

            expect($membership->permissions)->toBeArray()
                ->and($membership->permissions['canApproveSpending'])->toBeTrue()
                ->and($membership->permissions['canManageChores'])->toBeTrue();
        });

        it('handles null permissions', function () {
            $membership = AccountMembership::factory()
                ->withPermissions(null)
                ->create();

            expect($membership->permissions)->toBeNull();
        });

        it('casts created_at and updated_at to datetime', function () {
            $membership = AccountMembership::factory()->create();

            expect($membership->created_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class)
                ->and($membership->updated_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
        });
    });

    describe('Methods', function () {
        it('returns true for isPrimary when role is guardian', function () {
            $membership = AccountMembership::factory()
                ->asGuardian()
                ->create();

            expect($membership->isPrimary())->toBeTrue();
        });

        it('returns false for isPrimary when role is co_guardian', function () {
            $membership = AccountMembership::factory()
                ->asCoGuardian()
                ->create();

            expect($membership->isPrimary())->toBeFalse();
        });

        it('returns true for isCoGuardian when role is co_guardian', function () {
            $membership = AccountMembership::factory()
                ->asCoGuardian()
                ->create();

            expect($membership->isCoGuardian())->toBeTrue();
        });

        it('returns false for isCoGuardian when role is guardian', function () {
            $membership = AccountMembership::factory()
                ->asGuardian()
                ->create();

            expect($membership->isCoGuardian())->toBeFalse();
        });
    });

    describe('Constraints', function () {
        it('prevents duplicate (minor_account_id, guardian_account_id, role) combinations', function () {
            $minorAccount = Account::factory()->create();
            $guardianAccount = Account::factory()->create();

            AccountMembership::factory()
                ->for($minorAccount, 'minorAccount')
                ->for($guardianAccount, 'guardianAccount')
                ->asGuardian()
                ->create();

            // Attempting to create a duplicate should fail
            expect(function () use ($minorAccount, $guardianAccount) {
                AccountMembership::factory()
                    ->for($minorAccount, 'minorAccount')
                    ->for($guardianAccount, 'guardianAccount')
                    ->asGuardian()
                    ->create();
            })->toThrow(Exception::class);
        });

        it('allows same accounts with different roles', function () {
            $minorAccount = Account::factory()->create();
            $guardianAccount = Account::factory()->create();

            $guardian = AccountMembership::factory()
                ->for($minorAccount, 'minorAccount')
                ->for($guardianAccount, 'guardianAccount')
                ->asGuardian()
                ->create();

            $coGuardian = AccountMembership::factory()
                ->for($minorAccount, 'minorAccount')
                ->for($guardianAccount, 'guardianAccount')
                ->asCoGuardian()
                ->create();

            expect($guardian->role)->toBe('guardian')
                ->and($coGuardian->role)->toBe('co_guardian')
                ->and(AccountMembership::count())->toBe(2);
        });
    });

    describe('Factory', function () {
        it('creates a valid AccountMembership instance', function () {
            $membership = AccountMembership::factory()->create();

            expect($membership)->toBeInstanceOf(AccountMembership::class)
                ->and($membership->id)->not()->toBeNull()
                ->and($membership->minor_account_id)->not()->toBeNull()
                ->and($membership->guardian_account_id)->not()->toBeNull()
                ->and($membership->role)->toBe('guardian');
        });

        it('allows overriding fields', function () {
            $minorAccount = Account::factory()->create();
            $guardianAccount = Account::factory()->create();

            $membership = AccountMembership::factory()
                ->for($minorAccount, 'minorAccount')
                ->for($guardianAccount, 'guardianAccount')
                ->asCoGuardian()
                ->create();

            expect($membership->minor_account_id)->toBe($minorAccount->uuid)
                ->and($membership->guardian_account_id)->toBe($guardianAccount->uuid)
                ->and($membership->role)->toBe('co_guardian');
        });

        it('factory can set custom permissions', function () {
            $customPermissions = [
                'canApproveSpending' => true,
                'canManageChores' => true,
                'canViewChildAccounts' => true,
            ];

            $membership = AccountMembership::factory()
                ->withPermissions($customPermissions)
                ->create();

            expect($membership->permissions)->toBe($customPermissions);
        });
    });
});
