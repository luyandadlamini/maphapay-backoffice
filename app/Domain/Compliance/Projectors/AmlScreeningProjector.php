<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Projectors;

use App\Domain\Compliance\Events\AmlScreeningCompleted;
use App\Domain\Compliance\Events\AmlScreeningMatchStatusUpdated;
use App\Domain\Compliance\Events\AmlScreeningResultsRecorded;
use App\Domain\Compliance\Events\AmlScreeningReviewed;
use App\Domain\Compliance\Events\AmlScreeningStarted;
use App\Domain\Compliance\Models\AmlScreening;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

class AmlScreeningProjector extends Projector implements ShouldQueue
{
    /**
     * Handle when AML screening is started.
     */
    public function onAmlScreeningStarted(AmlScreeningStarted $event): void
    {
        AmlScreening::create([
            'id'                 => $event->aggregateRootUuid(),
            'entity_id'          => $event->entityId,
            'entity_type'        => $event->entityType,
            'screening_number'   => $event->screeningNumber,
            'type'               => $event->type,
            'status'             => AmlScreening::STATUS_IN_PROGRESS,
            'provider'           => $event->provider,
            'provider_reference' => $event->providerReference,
            'search_parameters'  => $event->searchParameters,
            'started_at'         => now(),
        ]);
    }

    /**
     * Handle when AML screening results are recorded.
     */
    public function onAmlScreeningResultsRecorded(AmlScreeningResultsRecorded $event): void
    {
        $screening = AmlScreening::find($event->aggregateRootUuid());

        if ($screening) {
            $screening->update([
                'sanctions_results'     => $event->sanctionsResults,
                'pep_results'           => $event->pepResults,
                'adverse_media_results' => $event->adverseMediaResults,
                'other_results'         => $event->otherResults,
                'total_matches'         => $event->totalMatches,
                'overall_risk'          => $event->overallRisk,
                'lists_checked'         => $event->listsChecked,
                'api_response'          => $event->apiResponse,
                'lists_updated_at'      => isset($event->listsChecked['updated_at'])
                    ? $event->listsChecked['updated_at']
                    : null,
            ]);
        }
    }

    /**
     * Handle when AML screening match status is updated.
     */
    public function onAmlScreeningMatchStatusUpdated(AmlScreeningMatchStatusUpdated $event): void
    {
        $screening = AmlScreening::find($event->aggregateRootUuid());

        if ($screening) {
            switch ($event->action) {
                case 'confirm':
                    $this->confirmMatch($screening, $event->matchId, $event->details);
                    break;

                case 'dismiss':
                    $this->dismissMatch($screening, $event->matchId, $event->reason ?? 'False positive');
                    break;

                case 'potential':
                    $this->markAsPotentialMatch($screening, $event->matchId, $event->details);
                    break;
            }
        }
    }

    /**
     * Handle when AML screening is completed.
     */
    public function onAmlScreeningCompleted(AmlScreeningCompleted $event): void
    {
        $screening = AmlScreening::find($event->aggregateRootUuid());

        if ($screening) {
            $screening->update([
                'status'          => $event->finalStatus,
                'completed_at'    => now(),
                'processing_time' => $event->processingTime,
            ]);
        }
    }

    /**
     * Handle when AML screening is reviewed.
     */
    public function onAmlScreeningReviewed(AmlScreeningReviewed $event): void
    {
        $screening = AmlScreening::find($event->aggregateRootUuid());

        if ($screening) {
            $reviewer = User::where('id', $event->reviewedBy)
                ->orWhere('uuid', $event->reviewedBy)
                ->firstOrFail();

            $screening->addReview($event->decision, $event->notes, $reviewer);
        }
    }

    /**
     * Confirm a match.
     */
    private function confirmMatch(AmlScreening $screening, string $matchId, array $details): void
    {
        $confirmed = $screening->confirmed_matches_detail ?? [];
        $confirmed[$matchId] = array_merge($details, [
            'confirmed_at' => now()->toIso8601String(),
        ]);

        $screening->update([
            'confirmed_matches_detail' => $confirmed,
            'confirmed_matches'        => ($screening->confirmed_matches ?? 0) + 1,
        ]);
    }

    /**
     * Dismiss a match as false positive.
     */
    private function dismissMatch(AmlScreening $screening, string $matchId, string $reason): void
    {
        $dismissed = $screening->dismissed_matches_detail ?? [];
        $dismissed[$matchId] = [
            'dismissed_at' => now()->toIso8601String(),
            'reason'       => $reason,
        ];

        $screening->update([
            'dismissed_matches_detail' => $dismissed,
            'false_positives'          => ($screening->false_positives ?? 0) + 1,
        ]);
    }

    /**
     * Mark as potential match.
     */
    private function markAsPotentialMatch(AmlScreening $screening, string $matchId, array $details): void
    {
        $potential = $screening->potential_matches_detail ?? [];
        $potential[$matchId] = array_merge($details, [
            'marked_at' => now()->toIso8601String(),
        ]);

        $screening->update([
            'potential_matches_detail' => $potential,
        ]);
    }
}
