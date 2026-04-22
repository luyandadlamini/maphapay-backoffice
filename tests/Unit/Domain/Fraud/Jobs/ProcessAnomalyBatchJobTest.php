<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Fraud\Jobs;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\Transaction;
use App\Domain\Fraud\Jobs\ProcessAnomalyBatchJob;
use App\Domain\Fraud\Services\AnomalyDetectionOrchestrator;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class ProcessAnomalyBatchJobTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('fraud.anomaly_detection.enabled', true);
    }

    #[Test]
    public function job_dispatches_to_correct_queue(): void
    {
        Queue::fake();

        ProcessAnomalyBatchJob::dispatch([1, 2, 3], 'test-pipeline');

        Queue::assertPushedOn('fraud-batch', ProcessAnomalyBatchJob::class);
    }

    #[Test]
    public function job_processes_transactions_through_orchestrator(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $transaction = Transaction::factory()->create([
            'account_id' => $account->id,
            'amount'     => 5000,
        ]);

        $orchestrator = Mockery::mock(AnomalyDetectionOrchestrator::class);
        $orchestrator->shouldReceive('detectAnomalies')
            ->once()
            ->andReturn([
                'anomalies'     => [],
                'highest_score' => 0.0,
                'has_critical'  => false,
                'persisted'     => 0,
            ]);

        $job = new ProcessAnomalyBatchJob([$transaction->id], 'test-pipeline');
        $job->handle($orchestrator);

        // Mockery verifies the expectation was met in tearDown
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function job_handles_missing_transactions_gracefully(): void
    {
        $orchestrator = Mockery::mock(AnomalyDetectionOrchestrator::class);
        // Should not be called since no transactions found
        $orchestrator->shouldNotReceive('detectAnomalies');

        $job = new ProcessAnomalyBatchJob([999999], 'test-pipeline-missing');
        $job->handle($orchestrator);

        // Mockery verifies shouldNotReceive in tearDown
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function job_continues_after_individual_transaction_failure(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $tx1 = Transaction::factory()->create(['account_id' => $account->id, 'amount' => 100]);
        $tx2 = Transaction::factory()->create(['account_id' => $account->id, 'amount' => 200]);

        $callCount = 0;
        $orchestrator = Mockery::mock(AnomalyDetectionOrchestrator::class);
        $orchestrator->shouldReceive('detectAnomalies')
            ->twice()
            ->andReturnUsing(function ($context) use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    throw new RuntimeException('Test failure');
                }

                return [
                    'anomalies'     => [],
                    'highest_score' => 0.0,
                    'has_critical'  => false,
                    'persisted'     => 0,
                ];
            });

        $job = new ProcessAnomalyBatchJob([$tx1->id, $tx2->id], 'test-pipeline-fail');
        $job->handle($orchestrator);

        // Both transactions were attempted despite the first one failing
        $this->assertEquals(2, $callCount);
    }

    #[Test]
    public function command_dispatches_batch_jobs(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        Transaction::factory()->count(3)->create([
            'account_id' => $account->id,
            'created_at' => now()->subHours(1),
        ]);

        $this->artisan('fraud:scan-anomalies', ['--hours' => 24, '--chunk' => 2])
            ->assertExitCode(0);

        Queue::assertPushed(ProcessAnomalyBatchJob::class);
    }

    #[Test]
    public function command_reports_zero_transactions(): void
    {
        DB::table('transactions')->update([
            'created_at' => now()->subDays(2),
        ]);

        $this->artisan('fraud:scan-anomalies', ['--hours' => 1])
            ->expectsOutput('No transactions found in the specified time range.')
            ->assertExitCode(0);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
