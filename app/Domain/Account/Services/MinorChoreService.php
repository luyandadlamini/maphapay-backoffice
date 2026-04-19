<?php
declare(strict_types=1);
namespace App\Domain\Account\Services;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\MinorChore;
use App\Domain\Account\Models\MinorChoreCompletion;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class MinorChoreService
{
    public function __construct(private readonly MinorPointsService $points) {}

    /**
     * Create a new chore for a minor account.
     *
     * @param  Account  $guardianAccount  The guardian/parent account creating the chore
     * @param  Account  $minorAccount     The minor account the chore is assigned to
     * @param  array<string, mixed>  $data  Chore data (title, description, payout_points, due_at)
     * @return MinorChore  The created chore
     */
    public function create(Account $guardianAccount, Account $minorAccount, array $data): MinorChore
    {
        return MinorChore::create([
            'guardian_account_uuid' => $guardianAccount->uuid,
            'minor_account_uuid'    => $minorAccount->uuid,
            'title'                 => $data['title'],
            'description'           => $data['description'] ?? null,
            'payout_type'           => 'points',
            'payout_points'         => (int) ($data['payout_points'] ?? 0),
            'due_at'                => isset($data['due_at']) ? Carbon::parse($data['due_at']) : null,
            'status'                => 'active',
        ]);
    }

    /**
     * Submit completion for a chore.
     *
     * @param  MinorChore  $chore  The chore being completed
     * @param  string|null  $note   Optional submission note from the child
     * @return MinorChoreCompletion  The created completion record
     * @throws ValidationException  If chore is not active or pending completion already exists
     */
    public function submitCompletion(MinorChore $chore, ?string $note = null): MinorChoreCompletion
    {
        // Validate chore is active
        if ($chore->status !== 'active') {
            throw ValidationException::withMessages([
                'chore' => ["This chore is not active and cannot be submitted."],
            ]);
        }

        // Validate no pending_review completion already exists
        $existingPending = MinorChoreCompletion::query()
            ->where('chore_id', $chore->id)
            ->where('status', 'pending_review')
            ->first();

        if ($existingPending) {
            throw ValidationException::withMessages([
                'completion' => ['A completion is already pending review for this chore.'],
            ]);
        }

        return MinorChoreCompletion::create([
            'chore_id'        => $chore->id,
            'submission_note' => $note,
            'status'          => 'pending_review',
        ]);
    }

    /**
     * Approve a chore completion and award points.
     *
     * @param  MinorChoreCompletion  $completion      The completion to approve
     * @param  Account  $guardianAccount  The guardian approving the completion
     * @return void
     * @throws ValidationException  If completion is not pending review
     */
    public function approve(MinorChoreCompletion $completion, Account $guardianAccount): void
    {
        // Validate completion is pending review
        if ($completion->status !== 'pending_review') {
            throw ValidationException::withMessages([
                'completion' => ["This completion has already been reviewed."],
            ]);
        }

        // Load the chore to access payout_points
        $chore = $completion->chore;

        // Update completion status and metadata
        $completion->update([
            'status'                    => 'approved',
            'reviewed_by_account_uuid'  => $guardianAccount->uuid,
            'reviewed_at'               => now(),
            'payout_processed_at'       => now(),
        ]);

        // Award points to the minor account if there are points to award
        if ($chore->payout_points > 0) {
            $this->points->award(
                $chore->minorAccount,
                $chore->payout_points,
                'chore',
                "Chore completed: {$chore->title}",
                $completion->id
            );
        }
    }

    /**
     * Reject a chore completion.
     *
     * @param  MinorChoreCompletion  $completion      The completion to reject
     * @param  Account  $guardianAccount  The guardian rejecting the completion
     * @param  string  $reason              The reason for rejection
     * @return void
     * @throws ValidationException  If completion is not pending review
     */
    public function reject(MinorChoreCompletion $completion, Account $guardianAccount, string $reason): void
    {
        // Validate completion is pending review
        if ($completion->status !== 'pending_review') {
            throw ValidationException::withMessages([
                'completion' => ["This completion has already been reviewed."],
            ]);
        }

        // Update completion status and metadata
        $completion->update([
            'status'                    => 'rejected',
            'reviewed_by_account_uuid'  => $guardianAccount->uuid,
            'reviewed_at'               => now(),
            'rejection_reason'          => $reason,
        ]);

        // Note: Chore status remains 'active' so the child can re-submit
    }
}
