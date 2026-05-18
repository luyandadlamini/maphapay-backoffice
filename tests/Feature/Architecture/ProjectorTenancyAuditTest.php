<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Domain\Shared\EventSourcing\TenantAwareProjector;
use ReflectionClass;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Architectural audit: every concrete projector under app/Domain must either.
 *
 *   (a) extend TenantAwareProjector (preferred — auto-initializes tenancy from
 *       events implementing CarriesTenantContext), OR
 *   (b) be explicitly listed in the allowlist below with a written justification.
 *
 * The allowlist is the explicit follow-up queue for the projector tenancy
 * migration. Every entry must include a comment explaining WHY it isn't yet
 * migrated (central-only state, no tenant writes, pending migration, etc.).
 * Removing a class from the allowlist without migrating it will fail this test.
 *
 * When migrating an allowlisted projector to TenantAwareProjector:
 *   1. Ensure every event the projector handles implements CarriesTenantContext.
 *   2. Change `extends Projector` to `extends TenantAwareProjector`.
 *   3. Remove the entry from this allowlist.
 *   4. Add or extend a feature test asserting balances/state move correctly.
 */
class ProjectorTenancyAuditTest extends TestCase
{
    /**
     * Projectors NOT YET migrated to TenantAwareProjector.
     *
     * Each line must be justified. Acceptable reasons:
     *   - "pending migration" + ticket reference  (preferred — produces a TODO list)
     *   - "central-only — touches no UsesTenantConnection model"
     *   - "explicitly tenant-agnostic — event sourcing infrastructure"
     *
     * @var list<string>
     */
    private const ALLOWLIST = [
        // Exchange domain — order/liquidity projections may write per-tenant state. PENDING MIGRATION.
        \App\Domain\Exchange\Projectors\OrderProjector::class,
        \App\Domain\Exchange\Projectors\LiquidityPoolProjector::class,
        \App\Domain\Exchange\Projectors\OrderBookProjector::class,

        // Asset domain — ExchangeRate is central reference data; Asset transfer/transaction
        // projections likely tenant-scoped. PENDING MIGRATION (audit each individually).
        \App\Domain\Asset\Projectors\ExchangeRateProjector::class,
        \App\Domain\Asset\Projectors\AssetTransactionProjector::class,
        \App\Domain\Asset\Projectors\AssetTransferProjector::class,

        // Payment domain — touches tenant-scoped account/transaction state. PENDING MIGRATION.
        \App\Domain\Payment\Projectors\PaymentDepositProjector::class,
        \App\Domain\Payment\Projectors\PaymentWithdrawalProjector::class,

        // User profile projections — central (user profiles live in central). VERIFY central-only before migrating.
        \App\Domain\User\Projectors\UserProfileProjector::class,

        // Product catalog — likely central reference data. VERIFY central-only before migrating.
        \App\Domain\Product\Projectors\ProductProjector::class,

        // Compliance — AML and monitoring may aggregate per-tenant. PENDING AUDIT.
        \App\Domain\Compliance\Projectors\TransactionMonitoringProjector::class,
        \App\Domain\Compliance\Projectors\ComplianceAlertProjector::class,
        \App\Domain\Compliance\Projectors\AmlScreeningProjector::class,

        // Lending — tenant-scoped credit history. PENDING MIGRATION.
        \App\Domain\Lending\Projectors\LoanApplicationProjector::class,
        \App\Domain\Lending\Projectors\LoanProjector::class,

        // CGO — refund flows write to tenant-scoped account state. PENDING MIGRATION.
        \App\Domain\Cgo\Projectors\RefundProjector::class,

        // Batch processing — orchestration; verify whether it writes tenant state. PENDING AUDIT.
        \App\Domain\Batch\Projectors\BatchProjector::class,

        // Wallet/blockchain — multisig wallet state. PENDING AUDIT.
        \App\Domain\Wallet\Projectors\BlockchainWalletProjector::class,

        // Account domain — these touch tenant-scoped state and SHOULD be migrated next.
        // PENDING MIGRATION (high priority — same risk profile as AssetBalanceProjector).
        \App\Domain\Account\Projectors\MinorPointsProjector::class,
        \App\Domain\Account\Projectors\AccountProjector::class,
        \App\Domain\Account\Projectors\MinorRedemptionProjector::class,
        \App\Domain\Account\Projectors\TurnoverProjector::class,
        \App\Domain\Account\Projectors\TransactionProjector::class,

        // Stablecoin — reserves likely central, positions per-tenant. PENDING AUDIT.
        \App\Domain\Stablecoin\Projectors\StablecoinProjector::class,
        \App\Domain\Stablecoin\Projectors\StablecoinReserveProjector::class,
        \App\Domain\Stablecoin\Projectors\CollateralPositionProjector::class,
    ];

    public function test_every_concrete_projector_is_tenant_aware_or_allowlisted(): void
    {
        $finder = (new Finder())
            ->files()
            ->in(base_path('app/Domain'))
            ->path('Projectors')
            ->name('*.php');

        $violations = [];

        foreach ($finder as $file) {
            $class = $this->classFromFile($file->getRealPath());

            if ($class === null || ! class_exists($class)) {
                continue;
            }

            $ref = new ReflectionClass($class);

            if ($ref->isAbstract()) {
                continue;
            }

            if (! $ref->isSubclassOf(Projector::class)) {
                continue;
            }

            if ($ref->isSubclassOf(TenantAwareProjector::class) || $ref->getName() === TenantAwareProjector::class) {
                continue;
            }

            if (in_array($class, self::ALLOWLIST, true)) {
                continue;
            }

            $violations[] = $class;
        }

        $this->assertSame(
            [],
            $violations,
            'The following projectors extend Projector but neither extend TenantAwareProjector '
            . "nor appear in the allowlist:\n  - "
            . implode("\n  - ", $violations)
            . "\n\nEither migrate them to TenantAwareProjector (preferred — ensures events that "
            . 'carry CarriesTenantContext trigger tenancy initialization) or add them to '
            . self::class . '::ALLOWLIST with a written justification.',
        );
    }

    public function test_allowlist_only_contains_existing_classes(): void
    {
        $missing = [];
        foreach (self::ALLOWLIST as $class) {
            if (! class_exists($class)) {
                $missing[] = $class;
            }
        }

        $this->assertSame(
            [],
            $missing,
            'The following classes in the allowlist do not exist. Remove them if they were deleted, '
            . "or fix the FQCN:\n  - " . implode("\n  - ", $missing),
        );
    }

    private function classFromFile(string $path): ?string
    {
        $src = file_get_contents($path);
        if ($src === false) {
            return null;
        }
        if (! preg_match('/namespace\s+([^;]+);/', $src, $ns)) {
            return null;
        }
        if (! preg_match('/class\s+([A-Za-z0-9_]+)/', $src, $cn)) {
            return null;
        }

        return trim($ns[1]) . '\\' . $cn[1];
    }
}
