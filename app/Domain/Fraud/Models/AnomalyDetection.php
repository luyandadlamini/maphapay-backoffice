<?php

declare(strict_types=1);

namespace App\Domain\Fraud\Models;

use App\Domain\Fraud\Enums\AnomalyStatus;
use App\Domain\Fraud\Enums\AnomalyType;
use App\Domain\Fraud\Enums\DetectionMethod;
use App\Domain\Shared\Traits\UsesTenantConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AnomalyDetection extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;
    use UsesTenantConnection;

    public const TRIAGE_STATUS_DETECTED = 'detected';
    public const TRIAGE_STATUS_UNDER_REVIEW = 'under_review';
    public const TRIAGE_STATUS_RESOLVED = 'resolved';
    public const TRIAGE_STATUS_ESCALATED = 'escalated';
    public const TRIAGE_STATUS_DISMISSED = 'dismissed';

    protected $fillable = [
        'entity_id',
        'entity_type',
        'user_id',
        'anomaly_type',
        'detection_method',
        'status',
        'triage_status',
        'assigned_to',
        'resolved_by',
        'resolution_notes',
        'resolution_type',
        'resolved_at',
        'anomaly_score',
        'confidence',
        'severity',
        'features',
        'thresholds',
        'explanation',
        'raw_scores',
        'context_snapshot',
        'baseline_snapshot',
        'model_version',
        'pipeline_run_id',
        'is_real_time',
        'fraud_score_id',
        'fraud_case_id',
        'feedback_outcome',
        'feedback_notes',
        'reviewed_by',
        'reviewed_at',
        'previous_status',
    ];

    protected $casts = [
        'anomaly_type'      => AnomalyType::class,
        'detection_method'  => DetectionMethod::class,
        'status'            => AnomalyStatus::class,
        'anomaly_score'     => 'decimal:2',
        'confidence'        => 'decimal:4',
        'features'          => 'array',
        'thresholds'        => 'array',
        'explanation'       => 'array',
        'raw_scores'        => 'array',
        'context_snapshot'  => 'array',
        'baseline_snapshot' => 'array',
        'is_real_time'      => 'boolean',
        'reviewed_at'       => 'datetime',
        'resolved_at'       => 'datetime',
    ];

    // Relationships

    public function entity(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function fraudScore(): BelongsTo
    {
        return $this->belongsTo(FraudScore::class, 'fraud_score_id');
    }

    public function fraudCase(): BelongsTo
    {
        return $this->belongsTo(FraudCase::class, 'fraud_case_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    protected static function newFactory(): \Database\Factories\AnomalyDetectionFactory
    {
        return \Database\Factories\AnomalyDetectionFactory::new();
    }

    // Helpers

    public function isCritical(): bool
    {
        return $this->severity === 'critical';
    }

    public function isHighSeverity(): bool
    {
        return in_array($this->severity, ['high', 'critical']);
    }

    public function isActive(): bool
    {
        return ! $this->status->isTerminal();
    }

    public static function calculateSeverity(float $score): string
    {
        return match (true) {
            $score >= 80 => 'critical',
            $score >= 60 => 'high',
            $score >= 40 => 'medium',
            default      => 'low',
        };
    }

    /**
     * Record analyst feedback on this anomaly detection.
     */
    public function recordFeedback(string $outcome, ?string $notes = null, ?int $reviewerId = null): void
    {
        $this->update([
            'previous_status'  => $this->status?->value,
            'feedback_outcome' => $outcome,
            'feedback_notes'   => $notes,
            'reviewed_by'      => $reviewerId,
            'reviewed_at'      => now(),
            'status'           => $outcome === 'false_positive'
                ? AnomalyStatus::FalsePositive
                : AnomalyStatus::Resolved,
        ]);
    }
}
