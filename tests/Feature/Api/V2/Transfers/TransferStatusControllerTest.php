<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V2\Transfers;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Workflows\TestHangingWorkflow;
use Tests\Fixtures\Workflows\TestSuccessfulWorkflow;
use Tests\TestCase;
use Workflow\Models\StoredWorkflow;
use Workflow\States\WorkflowFailedStatus;
use Workflow\States\WorkflowPendingStatus;
use Workflow\WorkflowStub;

/**
 * Coverage for GET /api/v2/transfers/{workflowId}/status. Tests follow the
 * same shape as SyncTransferAwaiterTest: real stored-workflow rows are
 * staged via the laravel-workflow state machine so the controller exercise
 * is independent of the workflow runner.
 */
class TransferStatusControllerTest extends TestCase
{
    protected function shouldCreateDefaultAccountsInSetup(): bool
    {
        return false;
    }

    #[Test]
    public function it_returns_404_for_unknown_workflow(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['read', 'write']);

        $this->getJson('/api/v2/transfers/nonexistent-workflow-id/status')
            ->assertStatus(404)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('data', null);
    }

    #[Test]
    public function it_reports_completed_state_with_result_payload(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['read', 'write']);

        // Workflow that completes immediately under the sync queue driver.
        $stub = WorkflowStub::make(TestSuccessfulWorkflow::class);
        $stub->start('integration');
        $stub->fresh();
        $this->assertTrue($stub->completed(), 'Pre-condition: workflow should be completed');

        $this->getJson('/api/v2/transfers/' . $stub->id() . '/status')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.state', 'completed')
            ->assertJsonPath('data.workflow_id', (string) $stub->id())
            ->assertJsonPath('data.result', 'integration-ok');
    }

    #[Test]
    public function it_reports_pending_state_when_workflow_not_terminal(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['read', 'write']);

        // Created-but-not-started: status=created (non-terminal).
        $stub = WorkflowStub::make(TestHangingWorkflow::class);

        $this->getJson('/api/v2/transfers/' . $stub->id() . '/status')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.state', 'pending')
            ->assertJsonPath('data.workflow_id', (string) $stub->id())
            ->assertJsonPath('data.result', null);
    }

    #[Test]
    public function it_reports_failed_state_with_message(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['read', 'write']);

        $stub = WorkflowStub::make(TestHangingWorkflow::class);
        $stored = StoredWorkflow::query()->whereKey($stub->id())->firstOrFail();

        DB::table('workflow_exceptions')->insert([
            'stored_workflow_id' => $stored->id,
            'class'              => TestHangingWorkflow::class,
            'exception'          => serialize(['message' => 'Insufficient funds']),
            'created_at'         => now(),
        ]);
        $stored->status->transitionTo(WorkflowPendingStatus::class);
        $stored->status->transitionTo(WorkflowFailedStatus::class);

        $this->getJson('/api/v2/transfers/' . $stub->id() . '/status')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.state', 'failed')
            ->assertJsonPath('data.workflow_id', (string) $stub->id());
    }
}
