<?php

declare(strict_types=1);

namespace App\Domain\Account\Services;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\MinorChore;
use App\Domain\Account\Models\MinorChoreCompletion;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MinorChoreService
{
    public function __construct(
        private readonly MinorPointsService $points,
        private readonly MinorNotificationService $notifications,
    ) {
    }

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
        /** @var MinorChore $chore */
        $chore = DB::transaction(function () use ($guardianAccount, $minorAccount, $data): MinorChore {
            $chore = MinorChore::query()->create([
                'guardian_account_uuid' => $guardianAccount->uuid,
                'minor_account_uuid'    => $minorAccount->uuid,
                'title'                 => $data['title'],
                'description'           => $data['description'] ?? null,
                'payout_type'           => 'points',
                'payout_points'         => (int) ($data['payout_points'] ?? 0),
                'due_at'                => isset($data['due_at']) ? Carbon::parse($data['due_at']) : null,
                'status'                => 'active',
            ]);

            $this->notifications->notify(
                $minorAccount->uuid,
                MinorNotificationService::TYPE_CHORE_ASSIGNED,
                [
                    'chore_id'      => $chore->id,
                    'title'         => $chore->title,
                    'payout_points' => $chore->payout_points,
                ],
                $guardianAccount->user_uuid,
                'minor_chore',
                (string) $chore->id,
            );

            return $chore;
        });

        return $chore;
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
                'chore' => ['This chore is not active and cannot be submitted.'],
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
        DB::transaction(function () use ($completion, $guardianAccount): void {
            /** @var MinorChoreCompletion $lockedCompletion */
            $lockedCompletion = MinorChoreCompletion::query()
                ->with('chore')
                ->lockForUpdate()
                ->findOrFail($completion->id);

            if ($lockedCompletion->status !== 'pending_review') {
                throw ValidationException::withMessages([
                    'completion' => ['This completion has already been reviewed.'],
                ]);
            }

            $chore = $lockedCompletion->chore;

            $lockedCompletion->update([
                'status'                   => 'approved',
                'reviewed_by_account_uuid' => $guardianAccount->uuid,
                'reviewed_at'              => now(),
                'payout_processed_at'      => now(),
            ]);

            if ($chore->payout_points > 0) {
                $minorAccount = Account::query()
                    ->where('uuid', $chore->minor_account_uuid)
                    ->first();

                if ($minorAccount !== null) {
                    $this->points->award(
                        $minorAccount,
                        $chore->payout_points,
                        'chore',
                        "Chore completed: {$chore->title}",
                        (string) $lockedCompletion->id,
                        true,
                    );
                }
            }

            $this->notifications->notify(
                $chore->minor_account_uuid,
                MinorNotificationService::TYPE_CHORE_APPROVED,
                [
                    'chore_id'      => $chore->id,
                    'title'         => $chore->title,
                    'payout_points' => $chore->payout_points,
                    'completion_id' => (string) $lockedCompletion->id,
                ],
                $guardianAccount->user_uuid,
                'minor_chore_completion',
                (string) $lockedCompletion->id,
            );

            $completion->forceFill($lockedCompletion->getAttributes());
            $completion->syncOriginal();
        });
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
        DB::transaction(function () use ($completion, $guardianAccount, $reason): void {
            /** @var MinorChoreCompletion $lockedCompletion */
            $lockedCompletion = MinorChoreCompletion::query()
                ->with('chore')
                ->lockForUpdate()
                ->findOrFail($completion->id);

            if ($lockedCompletion->status !== 'pending_review') {
                throw ValidationException::withMessages([
                    'completion' => ['This completion has already been reviewed.'],
                ]);
            }

            $lockedCompletion->update([
                'status'                   => 'rejected',
                'reviewed_by_account_uuid' => $guardianAccount->uuid,
                'reviewed_at'              => now(),
                'rejection_reason'         => $reason,
            ]);

            $this->notifications->notify(
                $lockedCompletion->chore->minor_account_uuid,
                MinorNotificationService::TYPE_CHORE_REJECTED,
                [
                    'chore_id'      => $lockedCompletion->chore_id,
                    'reason'        => $reason,
                    'completion_id' => (string) $lockedCompletion->id,
                ],
                $guardianAccount->user_uuid,
                'minor_chore_completion',
                (string) $lockedCompletion->id,
            );

            $completion->forceFill($lockedCompletion->getAttributes());
            $completion->syncOriginal();
        });
    }
}
