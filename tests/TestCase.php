<?php

declare(strict_types=1);

namespace Tests;

use App\Domain\Account\Aggregates\LedgerAggregate;
use App\Domain\Account\Models\Account;
use App\Domain\Account\Repositories\AccountRepository;
use App\Domain\Account\Values\DefaultAccountNames;
use App\Domain\User\Values\UserRoles;
use App\Models\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use ReflectionClass;
use Tests\Concerns\LazilyRefreshExistingMySqlSchema;
use Throwable;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;
    use LazilyRefreshExistingMySqlSchema;

    private static int $testCounter = 0;

    protected User $user;

    protected User $business_user;

    protected Account $account;

    protected function tearDown(): void
    {
        parent::tearDown();

        Mockery::close();

        // Run GC every 50 tests instead of every test to avoid
        // accumulating into process-level max_execution_time
        if (++self::$testCounter % 50 === 0) {
            gc_collect_cycles();
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Set up parallel testing tokens for isolated Redis and cache prefixes
        $this->setUpParallelTesting();

        $this->createRoles();

        // Only create default users and accounts if the test needs them
        if ($this->shouldCreateDefaultAccountsInSetup()) {
            $this->user = User::factory()->create();
            $this->business_user = User::factory()->withBusinessRole()->create();
            $this->account = $this->createAccount($this->business_user);
        }

        // Set up Filament panel if we're in a Filament test directory
        $testFile = (new ReflectionClass($this))->getFileName();
        if (str_contains($testFile, '/Filament/') || str_contains($testFile, '\\Filament\\')) {
            $this->setUpFilament();
        }
    }

    /**
     * Determine if this test should create default accounts in setUp.
     * Override in child classes to disable.
     */
    protected function shouldCreateDefaultAccountsInSetup(): bool
    {
        // Don't create accounts for Security tests by default to avoid transaction conflicts
        $testFile = (new ReflectionClass($this))->getFileName();
        if (str_contains($testFile, '/Security/') || str_contains($testFile, '\\Security\\')) {
            return false;
        }

        return true;
    }

    /**
     * Create default users and accounts if they don't exist.
     */
    protected function createDefaultAccounts(): void
    {
        if (! isset($this->user)) {
            $this->user = User::factory()->create();
        }

        if (! isset($this->business_user)) {
            $this->business_user = User::factory()->withBusinessRole()->create();
        }

        if (! isset($this->account)) {
            $this->account = $this->createAccount($this->business_user);
        }
    }

    /**
     * @throws Throwable
     */
    protected function assertExceptionThrown(callable $callable, string $expectedExceptionClass): void
    {
        try {
            $callable();

            $this->fail(
                "Expected exception `{$expectedExceptionClass}` was not thrown."
            );
        } catch (Throwable $exception) {
            if (! $exception instanceof $expectedExceptionClass) {
                throw $exception;
            }
            $this->assertInstanceOf($expectedExceptionClass, $exception);
        }
    }

    protected function createAccount(User $user): Account
    {
        $uuid = (string) Str::uuid();

        app(LedgerAggregate::class)->retrieve($uuid)
            ->createAccount(
                hydrate(
                    class: \App\Domain\Account\DataObjects\Account::class,
                    properties: [
                        'name' => DefaultAccountNames::default(
                        ),
                        'user_uuid' => $user->uuid,
                    ]
                )
            )
            ->persist();

        return app(AccountRepository::class)->findByUuid($uuid);
    }

    protected function createRoles(): void
    {
        // Check if roles already exist in the database
        $existingRoles = Role::whereIn('name', array_column(UserRoles::cases(), 'value'))->count();

        if ($existingRoles >= count(UserRoles::cases())) {
            return;
        }

        // Create roles without transaction to avoid nesting issues
        collect(UserRoles::cases())->each(function ($role) {
            Role::firstOrCreate(
                ['name' => $role->value],
                ['guard_name' => 'web']
            );
        });
    }

    protected function ensureMoneyMovementTestSchemaBaseline(): void
    {
        if (! Schema::hasTable('roles')) {
            Schema::create('roles', function ($table): void {
                $table->bigIncrements('id');
                $table->string('name');
                $table->string('guard_name');
                $table->timestamps();
                $table->unique(['name', 'guard_name']);
            });
        }

        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function ($table): void {
            if (! Schema::hasColumn('users', 'kyc_status')) {
                $table->string('kyc_status')->default('not_started')->after('current_team_id');
            }

            if (! Schema::hasColumn('users', 'kyc_level')) {
                $table->string('kyc_level')->default('basic')->after('kyc_status');
            }

            if (! Schema::hasColumn('users', 'pep_status')) {
                $table->boolean('pep_status')->default(false)->after('kyc_level');
            }

            if (! Schema::hasColumn('users', 'risk_rating')) {
                $table->string('risk_rating')->nullable()->after('pep_status');
            }

            if (! Schema::hasColumn('users', 'data_retention_consent')) {
                $table->boolean('data_retention_consent')->default(false)->after('pep_status');
            }

            if (! Schema::hasColumn('users', 'transaction_pin')) {
                $table->string('transaction_pin')->nullable()->after('data_retention_consent');
            }

            if (! Schema::hasColumn('users', 'transaction_pin_enabled')) {
                $table->boolean('transaction_pin_enabled')->default(false)->after('transaction_pin');
            }

            if (! Schema::hasColumn('users', 'send_money_step_up_threshold_override')) {
                $table->decimal('send_money_step_up_threshold_override', 20, 2)->nullable()->after('transaction_pin_enabled');
            }

            if (! Schema::hasColumn('users', 'send_money_step_up_threshold_override_reason')) {
                $table->text('send_money_step_up_threshold_override_reason')->nullable()->after('send_money_step_up_threshold_override');
            }

            if (! Schema::hasColumn('users', 'send_money_step_up_threshold_override_updated_at')) {
                $table->timestamp('send_money_step_up_threshold_override_updated_at')->nullable()->after('send_money_step_up_threshold_override_reason');
            }

            if (! Schema::hasColumn('users', 'send_money_step_up_threshold_override_updated_by')) {
                $table->string('send_money_step_up_threshold_override_updated_by')->nullable()->after('send_money_step_up_threshold_override_updated_at');
            }
        });

        if (! Schema::hasTable('settings')) {
            Schema::create('settings', function ($table): void {
                $table->bigIncrements('id');
                $table->string('group', 100)->default('general')->index();
                $table->string('key', 255)->unique();
                $table->json('value');
                $table->string('type', 50)->default('string');
                $table->string('label', 255);
                $table->text('description')->nullable();
                $table->boolean('is_public')->default(false)->index();
                $table->boolean('is_encrypted')->default(false);
                $table->json('validation_rules')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index(['group', 'key']);
            });
        }

        if (! Schema::hasTable('setting_audits')) {
            Schema::create('setting_audits', function ($table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('setting_id');
                $table->string('key', 255);
                $table->json('old_value')->nullable();
                $table->json('new_value');
                $table->string('changed_by')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->string('user_agent')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index(['key', 'created_at']);
                $table->index('changed_by');
            });
        }

        if (Schema::hasTable('accounts') && ! Schema::hasColumn('accounts', 'account_number')) {
            Schema::table('accounts', function ($table): void {
                $table->string('account_number')->nullable()->after('name');
            });
        }

        if (Schema::hasTable('accounts') && ! Schema::hasColumn('accounts', 'frozen')) {
            Schema::table('accounts', function ($table): void {
                $table->boolean('frozen')->default(false)->after('balance');
            });
        }

        if (! Schema::hasTable('authorized_transactions')) {
            Schema::create('authorized_transactions', function ($table): void {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('user_id');
                $table->string('remark');
                $table->string('trx')->unique();
                $table->json('payload');
                $table->string('status');
                $table->json('result')->nullable();
                $table->string('verification_type')->nullable();
                $table->text('otp_hash')->nullable();
                $table->timestamp('otp_sent_at')->nullable();
                $table->timestamp('otp_expires_at')->nullable();
                $table->unsignedInteger('verification_failures')->default(0);
                $table->timestamp('verification_confirmed_at')->nullable();
                $table->string('failure_reason')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'trx']);
            });
        }

        if (! Schema::hasTable('assets')) {
            Schema::create('assets', function ($table): void {
                $table->string('code')->primary();
                $table->string('name');
                $table->string('type');
                $table->unsignedInteger('precision')->default(2);
                $table->boolean('is_active')->default(true);
                $table->boolean('is_basket')->default(false);
                $table->boolean('is_tradeable')->default(false);
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('account_balances')) {
            Schema::create('account_balances', function ($table): void {
                $table->bigIncrements('id');
                $table->uuid('account_uuid');
                $table->string('asset_code');
                $table->bigInteger('balance')->default(0);
                $table->timestamps();
                $table->unique(['account_uuid', 'asset_code']);
            });
        }

        if (! Schema::hasTable('money_requests')) {
            Schema::create('money_requests', function ($table): void {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('requester_user_id');
                $table->unsignedBigInteger('recipient_user_id');
                $table->decimal('amount', 20, 2);
                $table->string('asset_code');
                $table->text('note')->nullable();
                $table->string('status');
                $table->string('trx')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('asset_transfers')) {
            Schema::create('asset_transfers', function ($table): void {
                $table->bigIncrements('id');
                $table->uuid('uuid')->nullable();
                $table->string('reference')->nullable();
                $table->string('transfer_id')->nullable();
                $table->string('hash')->nullable();
                $table->uuid('from_account_uuid')->nullable();
                $table->uuid('to_account_uuid')->nullable();
                $table->string('from_asset_code')->nullable();
                $table->string('to_asset_code')->nullable();
                $table->bigInteger('from_amount')->default(0);
                $table->bigInteger('to_amount')->default(0);
                $table->decimal('exchange_rate', 20, 10)->nullable();
                $table->string('status');
                $table->text('description')->nullable();
                $table->string('failure_reason')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('initiated_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('failed_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('operation_records')) {
            Schema::create('operation_records', function ($table): void {
                $table->ulid('id')->primary();
                $table->unsignedBigInteger('user_id');
                $table->string('operation_type');
                $table->string('idempotency_key', 255);
                $table->string('payload_hash', 64);
                $table->enum('status', ['pending', 'completed', 'failed'])->default('pending');
                $table->json('result_payload')->nullable();
                $table->timestamps();
                $table->unique(
                    ['user_id', 'operation_type', 'idempotency_key'],
                    'op_records_user_type_key_unique',
                );
            });
        }
    }

    /**
     * Set up parallel testing isolation for Redis and cache.
     */
    protected function setUpParallelTesting(): void
    {
        $token = ParallelTesting::token();

        if ($token) {
            // Prefix Redis connections for isolation
            config([
                'database.redis.options.prefix' => 'test_' . $token . ':',
                'cache.prefix'                  => 'test_' . $token,
                'horizon.prefix'                => 'test_' . $token . '_horizon:',
            ]);

            // Ensure event sourcing uses isolated storage
            config([
                'event-sourcing.storage_prefix' => 'test_' . $token,
            ]);

            // Use separate database for each parallel process when using MySQL
            if (config('database.default') === 'mysql') {
                $database = config('database.connections.mysql.database');
                config([
                    'database.connections.mysql.database' => $database . '_test_' . $token,
                ]);
            }

            // Ensure unique constraint violations don't affect parallel tests
            config([
                'database.connections.sqlite.foreign_key_constraints' => false,
            ]);
        }
    }

    /**
     * Set up Filament for testing.
     */
    protected function setUpFilament(): void
    {
        // Register and set the admin panel as current
        $panel = Filament::getPanel('admin');

        if ($panel) {
            Filament::setCurrentPanel($panel);
            Filament::setServingStatus(true);
            $panel->boot();
        }
    }

    /**
     * Authenticate a user with API scopes for testing.
     *
     * This method wraps Sanctum::actingAs and provides default scopes
     * needed for API endpoint testing after security hardening.
     *
     * @param  User  $user  The user to authenticate
     * @param  array<string>  $scopes  The scopes to grant (defaults to read, write, delete)
     * @return void
     */
    protected function actingAsWithScopes(User $user, array $scopes = ['read', 'write', 'delete']): void
    {
        Sanctum::actingAs($user, $scopes);
    }
}
