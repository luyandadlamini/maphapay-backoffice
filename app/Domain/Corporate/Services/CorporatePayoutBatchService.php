<?php

declare(strict_types=1);

namespace App\Domain\Corporate\Services;

use App\Domain\Corporate\Models\CorporatePayoutBatch;
use App\Domain\Corporate\Models\CorporatePayoutBatchItem;
use App\Domain\Corporate\Models\CorporateProfile;
use App\Models\User;
use InvalidArgumentException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CorporatePayoutBatchService
{
    public function __construct(
        private readonly CorporateActionPolicy $actionPolicy,
    ) {
    }

    /**
     * Create a new payout batch in draft state.
     */
    public function createBatch(
        CorporateProfile $profile,
        string $assetCode,
        ?string $label = null,
        ?\DateTimeInterface $cutOffAt = null,
    ): CorporatePayoutBatch {
        /** @var CorporatePayoutBatch $batch */
        $batch = CorporatePayoutBatch::query()->create([
            'public_id'            => 'batch_' . Str::lower(Str::random(20)),
            'corporate_profile_id' => $profile->id,
            'status'               => 'draft',
            'total_amount_minor'   => 0,
            'asset_code'           => $assetCode,
            'label'                => $label,
            'cut_off_at'           => $cutOffAt,
        ]);

        return $batch;
    }

    /**
     * Add a line item to a draft batch.
     *
     * @throws InvalidArgumentException if beneficiary is blank, amount is non-positive,
     *                                  the reference is duplicate within this batch,
     *                                  or the batch is not in draft state.
     */
    public function addItem(
        CorporatePayoutBatch $batch,
        string $beneficiaryIdentifier,
        int $amountMinor,
        string $assetCode,
        string $reference,
    ): CorporatePayoutBatchItem {
        if ($batch->status !== 'draft') {
            throw new InvalidArgumentException('Items can only be added to a batch in draft status.');
        }

        if (trim($beneficiaryIdentifier) === '') {
            throw new InvalidArgumentException('Beneficiary identifier must not be blank.');
        }

        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('Amount must be a positive integer (minor units).');
        }

        $duplicateExists = CorporatePayoutBatchItem::query()
            ->where('batch_id', $batch->id)
            ->where('reference', $reference)
            ->exists();

        if ($duplicateExists) {
            throw new InvalidArgumentException("Duplicate reference '{$reference}' already exists in this batch.");
        }

        /** @var CorporatePayoutBatchItem $item */
        $item = CorporatePayoutBatchItem::query()->create([
            'batch_id'               => $batch->id,
            'beneficiary_identifier' => $beneficiaryIdentifier,
            'amount_minor'           => $amountMinor,
            'asset_code'             => $assetCode,
            'reference'              => $reference,
            'status'                 => 'pending',
        ]);

        // Update batch total
        $batch->forceFill([
            'total_amount_minor' => $batch->total_amount_minor + $amountMinor,
        ])->save();

        return $item;
    }

    /**
     * Submit a draft batch for approval via the corporate action policy.
     *
     * The batch is moved to 'submitted' status and an approval request is persisted.
     * The batch does NOT begin executing until it is explicitly approved.
     *
     * @throws InvalidArgumentException if the batch has no items or is not in draft state.
     */
    public function submitForApproval(
        CorporatePayoutBatch $batch,
        User $submitter,
    ): CorporatePayoutBatch {
        if ($batch->status !== 'draft') {
            throw new InvalidArgumentException('Only draft batches can be submitted for approval.');
        }

        $itemCount = $batch->items()->count();

        if ($itemCount === 0) {
            throw new InvalidArgumentException('Cannot submit a batch with no items.');
        }

        return DB::transaction(function () use ($batch, $submitter, $itemCount): CorporatePayoutBatch {
            $profile = $batch->corporateProfile;

            if (! $profile) {
                throw new InvalidArgumentException('Batch is not linked to a corporate profile.');
            }

            $team = $profile->team;

            if (! $team) {
                throw new InvalidArgumentException('Corporate profile is not linked to a team.');
            }

            $approvalRequest = $this->actionPolicy->submitApprovalRequest(
                requester: $submitter,
                team: $team,
                actionType: 'treasury_affecting',
                targetType: 'payout_batch',
                targetIdentifier: $batch->public_id,
                evidence: [
                    'item_count'          => $itemCount,
                    'total_amount_minor'  => $batch->total_amount_minor,
                    'asset_code'          => $batch->asset_code,
                ],
            );

            $batch->forceFill([
                'status'              => 'submitted',
                'submitted_at'        => now(),
                'submitted_by_id'     => $submitter->id,
                'approval_request_id' => $approvalRequest->id,
            ])->save();

            return $batch;
        });
    }
}
