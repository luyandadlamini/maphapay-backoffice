<?php
declare(strict_types=1);
namespace Tests\Feature\Http\Controllers\Api;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Domain\Account\Models\MinorChore;
use App\Domain\Account\Models\MinorChoreCompletion;
use App\Domain\Account\Services\MinorChoreService;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\ControllerTestCase;

class MinorChoreTest extends ControllerTestCase
{
    protected function connectionsToTransact(): array
    {
        return ['mysql', 'central'];
    }

    private MinorChoreService $service;
    private User $guardianUser;
    private User $childUser;
    private Account $guardianAccount;
    private Account $minorAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(MinorChoreService::class);

        // Create users
        $this->guardianUser = User::factory()->create();
        $this->childUser = User::factory()->create();

        // Create accounts
        $this->guardianAccount = Account::factory()->create([
            'user_uuid' => $this->guardianUser->uuid,
            'type'      => 'personal',
        ]);

        $this->minorAccount = Account::factory()->create([
            'user_uuid' => $this->childUser->uuid,
            'type'      => 'minor',
            'tier'      => 'grow',
            'parent_account_id' => $this->guardianAccount->id,
        ]);

        // Create account membership linking the guardian to the minor account
        AccountMembership::create([
            'user_uuid'    => $this->guardianUser->uuid,
            'account_uuid' => $this->minorAccount->uuid,
            'status'       => 'active',
        ]);
    }

    // ========== SERVICE-LEVEL TESTS ==========

    #[Test]
    public function guardian_can_create_chore_for_minor(): void
    {
        $data = [
            'title'          => 'Clean bedroom',
            'description'    => 'Tidy up the room and make the bed',
            'payout_points'  => 30,
            'due_at'         => Carbon::now()->addDays(3),
        ];

        $chore = $this->service->create($this->guardianAccount, $this->minorAccount, $data);

        $this->assertSame('Clean bedroom', $chore->title);
        $this->assertSame('Tidy up the room and make the bed', $chore->description);
        $this->assertSame(30, $chore->payout_points);
        $this->assertSame('active', $chore->status);
        $this->assertSame('points', $chore->payout_type);
        $this->assertSame($this->guardianAccount->uuid, $chore->guardian_account_uuid);
        $this->assertSame($this->minorAccount->uuid, $chore->minor_account_uuid);
        $this->assertDatabaseHas('minor_chores', [
            'id'                    => $chore->id,
            'title'                 => 'Clean bedroom',
            'status'                => 'active',
            'payout_points'         => 30,
        ]);
    }

    #[Test]
    public function child_can_submit_completion_and_status_becomes_pending_review(): void
    {
        $chore = MinorChore::create([
            'guardian_account_uuid' => $this->guardianAccount->uuid,
            'minor_account_uuid'    => $this->minorAccount->uuid,
            'title'                 => 'Do homework',
            'payout_points'         => 20,
            'status'                => 'active',
            'payout_type'           => 'points',
        ]);

        $completion = $this->service->submitCompletion($chore, 'I finished all homework!');

        $this->assertSame('pending_review', $completion->status);
        $this->assertSame('I finished all homework!', $completion->submission_note);
        $this->assertSame($chore->id, $completion->chore_id);
        $this->assertDatabaseHas('minor_chore_completions', [
            'chore_id'       => $chore->id,
            'status'         => 'pending_review',
            'submission_note' => 'I finished all homework!',
        ]);
    }

    #[Test]
    public function approving_completion_awards_points_and_marks_payout_processed(): void
    {
        $chore = MinorChore::create([
            'guardian_account_uuid' => $this->guardianAccount->uuid,
            'minor_account_uuid'    => $this->minorAccount->uuid,
            'title'                 => 'Mow the lawn',
            'payout_points'         => 30,
            'status'                => 'active',
            'payout_type'           => 'points',
        ]);

        $completion = MinorChoreCompletion::create([
            'chore_id' => $chore->id,
            'status'   => 'pending_review',
        ]);

        $this->service->approve($completion, $this->guardianAccount);

        $completion->refresh();

        $this->assertSame('approved', $completion->status);
        $this->assertNotNull($completion->reviewed_at);
        $this->assertNotNull($completion->payout_processed_at);
        $this->assertSame($this->guardianAccount->uuid, $completion->reviewed_by_account_uuid);

        // Verify points were awarded
        $this->assertDatabaseHas('minor_points_ledger', [
            'minor_account_uuid' => $this->minorAccount->uuid,
            'points'             => 30,
            'source'             => 'chore',
            'reference_id'       => $completion->id,
        ]);
    }

    #[Test]
    public function rejecting_completion_sets_reason_and_chore_stays_active(): void
    {
        $chore = MinorChore::create([
            'guardian_account_uuid' => $this->guardianAccount->uuid,
            'minor_account_uuid'    => $this->minorAccount->uuid,
            'title'                 => 'Wash dishes',
            'payout_points'         => 15,
            'status'                => 'active',
            'payout_type'           => 'points',
        ]);

        $completion = MinorChoreCompletion::create([
            'chore_id' => $chore->id,
            'status'   => 'pending_review',
        ]);

        $this->service->reject($completion, $this->guardianAccount, 'Not done properly');

        $completion->refresh();
        $chore->refresh();

        $this->assertSame('rejected', $completion->status);
        $this->assertSame('Not done properly', $completion->rejection_reason);
        $this->assertNotNull($completion->reviewed_at);
        $this->assertSame($this->guardianAccount->uuid, $completion->reviewed_by_account_uuid);

        // Verify chore stays active for re-submission
        $this->assertSame('active', $chore->status);

        // Verify no points were awarded
        $this->assertDatabaseMissing('minor_points_ledger', [
            'reference_id' => $completion->id,
        ]);
    }

    // ========== HTTP API TESTS ==========

    #[Test]
    public function guardian_creates_chore_via_api(): void
    {
        Sanctum::actingAs($this->guardianUser, ['read', 'write', 'delete']);

        $payload = [
            'title'          => 'Organize bookshelf',
            'payout_points'  => 25,
            'description'    => 'Arrange books by color',
            'due_at'         => Carbon::now()->addDays(5)->toDateTimeString(),
        ];

        $response = $this->postJson(
            "/api/accounts/minor/{$this->minorAccount->uuid}/chores",
            $payload
        );

        $response->assertCreated();
        $this->assertDatabaseHas('minor_chores', [
            'title'         => 'Organize bookshelf',
            'payout_points' => 25,
            'status'        => 'active',
        ]);
    }

    #[Test]
    public function child_lists_own_chores_via_api(): void
    {
        // Create a test chore
        MinorChore::create([
            'guardian_account_uuid' => $this->guardianAccount->uuid,
            'minor_account_uuid'    => $this->minorAccount->uuid,
            'title'                 => 'Water plants',
            'payout_points'         => 10,
            'status'                => 'active',
            'payout_type'           => 'points',
        ]);

        Sanctum::actingAs($this->childUser, ['read', 'write', 'delete']);

        $response = $this->getJson(
            "/api/accounts/minor/{$this->minorAccount->uuid}/chores"
        );

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'title', 'payout_points', 'status'],
                ],
            ]);

        $chores = $response->json('data');
        $this->assertCount(1, $chores);
        $this->assertSame('Water plants', $chores[0]['title']);
        $this->assertSame(10, $chores[0]['payout_points']);
        $this->assertSame('active', $chores[0]['status']);
    }

    #[Test]
    public function child_marks_chore_complete_via_api(): void
    {
        $chore = MinorChore::create([
            'guardian_account_uuid' => $this->guardianAccount->uuid,
            'minor_account_uuid'    => $this->minorAccount->uuid,
            'title'                 => 'Practice piano',
            'payout_points'         => 20,
            'status'                => 'active',
            'payout_type'           => 'points',
        ]);

        Sanctum::actingAs($this->childUser, ['read', 'write', 'delete']);

        $payload = [
            'submission_note' => 'Practiced for 30 minutes',
        ];

        $response = $this->postJson(
            "/api/accounts/minor/{$this->minorAccount->uuid}/chores/{$chore->id}/complete",
            $payload
        );

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => ['id', 'status', 'submission_note'],
            ]);

        $data = $response->json('data');
        $this->assertSame('pending_review', $data['status']);
        $this->assertSame('Practiced for 30 minutes', $data['submission_note']);
    }

    #[Test]
    public function guardian_approves_chore_via_api(): void
    {
        $chore = MinorChore::create([
            'guardian_account_uuid' => $this->guardianAccount->uuid,
            'minor_account_uuid'    => $this->minorAccount->uuid,
            'title'                 => 'Take out trash',
            'payout_points'         => 15,
            'status'                => 'active',
            'payout_type'           => 'points',
        ]);

        $completion = MinorChoreCompletion::create([
            'chore_id' => $chore->id,
            'status'   => 'pending_review',
        ]);

        Sanctum::actingAs($this->guardianUser, ['read', 'write', 'delete']);

        $response = $this->postJson(
            "/api/accounts/minor/{$this->minorAccount->uuid}/chores/{$chore->id}/approve/{$completion->id}"
        );

        $response->assertOk()
            ->assertJsonStructure([
                'data' => ['id', 'status', 'reviewed_at', 'payout_processed_at'],
            ]);

        $data = $response->json('data');
        $this->assertSame('approved', $data['status']);
        $this->assertNotNull($data['reviewed_at']);
        $this->assertNotNull($data['payout_processed_at']);

        // Verify points were awarded
        $this->assertDatabaseHas('minor_points_ledger', [
            'minor_account_uuid' => $this->minorAccount->uuid,
            'points'             => 15,
            'source'             => 'chore',
        ]);
    }

    #[Test]
    public function guardian_rejects_chore_with_reason_via_api(): void
    {
        $chore = MinorChore::create([
            'guardian_account_uuid' => $this->guardianAccount->uuid,
            'minor_account_uuid'    => $this->minorAccount->uuid,
            'title'                 => 'Clean bathroom',
            'payout_points'         => 40,
            'status'                => 'active',
            'payout_type'           => 'points',
        ]);

        $completion = MinorChoreCompletion::create([
            'chore_id' => $chore->id,
            'status'   => 'pending_review',
        ]);

        Sanctum::actingAs($this->guardianUser, ['read', 'write', 'delete']);

        $payload = [
            'reason' => 'The floor is still dirty',
        ];

        $response = $this->postJson(
            "/api/accounts/minor/{$this->minorAccount->uuid}/chores/{$chore->id}/reject/{$completion->id}",
            $payload
        );

        $response->assertOk()
            ->assertJsonStructure([
                'data' => ['id', 'status', 'rejection_reason'],
            ]);

        $data = $response->json('data');
        $this->assertSame('rejected', $data['status']);
        $this->assertSame('The floor is still dirty', $data['rejection_reason']);
    }
}
