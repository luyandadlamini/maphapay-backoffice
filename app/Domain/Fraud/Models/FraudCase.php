<?php

declare(strict_types=1);

namespace App\Domain\Fraud\Models;

use App\Domain\Account\Models\Account;
use App\Domain\Shared\Traits\UsesTenantConnection;
use App\Models\User;
use App\Traits\BelongsToTeam;
use Database\Factories\FraudCaseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $uuid
 * @property string $case_number
 * @property string $status
 * @property string $severity
 * @property string $priority Alias for severity in some contexts
 * @property string $type
 * @property string|null $subject_account_uuid
 * @property array $related_transactions
 * @property array $related_accounts
 * @property array $related_entities
 * @property array $related_cases
 * @property float $amount Alias for loss_amount
 * @property float|null $loss_amount
 * @property float|null $recovery_amount
 * @property float|null $recovery_percentage
 * @property string|null $currency
 * @property float $risk_score Alias for total_score
 * @property float $total_score
 * @property string|null $description
 * @property array $detection_rules Alias for triggered_rules
 * @property array $triggered_rules
 * @property array $risk_factors
 * @property array $evidence
 * @property array $investigation_notes
 * @property \Illuminate\Support\Carbon $detected_at
 * @property \Illuminate\Support\Carbon|null $resolved_at
 * @property \Illuminate\Support\Carbon|null $investigation_started_at
 * @property \Illuminate\Support\Carbon|null $status_changed_at
 * @property \Illuminate\Support\Carbon|null $escalated_at
 * @property string|null $assigned_to
 * @property bool $escalated
 * @property string|null $escalation_reason
 * @property array $actions_taken
 * @property string|null $resolution
 * @property string|null $outcome
 * @property array|null $resolution_notes
 * @property array|null $entity_snapshot
 * @property string|null $initial_decision
 * @property array|null $tags
 *
 * @method static \Illuminate\Database\Eloquent\Builder where(string $column, mixed $operator = null, mixed $value = null, string $boolean = 'and')
 * @method static \Illuminate\Database\Eloquent\Builder whereDate(string $column, mixed $operator, string|\DateTimeInterface|null $value = null)
 * @method static \Illuminate\Database\Eloquent\Builder whereMonth(string $column, mixed $operator, string|\DateTimeInterface|null $value = null)
 * @method static \Illuminate\Database\Eloquent\Builder whereYear(string $column, mixed $value)
 * @method static \Illuminate\Database\Eloquent\Builder whereIn(string $column, mixed $values)
 * @method static static updateOrCreate(array $attributes, array $values = [])
 * @method static static firstOrCreate(array $attributes, array $values = [])
 * @method static static|null find(mixed $id, array $columns = ['*'])
 * @method static static|null first(array $columns = ['*'])
 * @method static \Illuminate\Database\Eloquent\Collection get(array $columns = ['*'])
 * @method static \Illuminate\Support\Collection pluck(string $column, string|null $key = null)
 * @method static int count(string $columns = '*')
 * @method static mixed sum(string $column)
 * @method static \Illuminate\Database\Eloquent\Builder orderBy(string $column, string $direction = 'asc')
 * @method static \Illuminate\Database\Eloquent\Builder latest(string $column = null)
 */
class FraudCase extends Model
{
    use UsesTenantConnection;
    use BelongsToTeam;
    use HasFactory;
    use HasUuids;

    protected static function newFactory()
    {
        return FraudCaseFactory::new();
    }

    /**
     * The primary key type.
     *
     * @var string
     */
    protected $keyType = 'int';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = true;

    /**
     * Get the columns that should receive a unique identifier.
     *
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected $fillable = [
        'uuid',
        'team_id',
        'case_number',
        'status',
        'severity',
        'priority',
        'type',
        'subject_account_uuid',
        'related_transactions',
        'related_accounts',
        'related_entities',
        'related_cases',
        'amount',
        'loss_amount',
        'recovery_amount',
        'recovery_percentage',
        'currency',
        'risk_score',
        'total_score',
        'description',
        'detection_rules',
        'triggered_rules',
        'risk_factors',
        'evidence',
        'investigation_notes',
        'detected_at',
        'resolved_at',
        'investigation_started_at',
        'status_changed_at',
        'escalated_at',
        'assigned_to',
        'escalated',
        'escalation_reason',
        'actions_taken',
        'resolution',
        'outcome',
        'resolution_notes',
        'entity_snapshot',
        'initial_decision',
        'tags',
        'fraud_score_id',
        'entity_id',
        'entity_type',
    ];

    protected $casts = [
        'related_transactions'     => 'array',
        'related_accounts'         => 'array',
        'related_entities'         => 'array',
        'related_cases'            => 'array',
        'detection_rules'          => 'array',
        'triggered_rules'          => 'array',
        'risk_factors'             => 'array',
        'evidence'                 => 'array',
        'investigation_notes'      => 'array',
        'actions_taken'            => 'array',
        'resolution_notes'         => 'array',
        'entity_snapshot'          => 'array',
        'tags'                     => 'array',
        'amount'                   => 'decimal:8',
        'loss_amount'              => 'decimal:8',
        'recovery_amount'          => 'decimal:8',
        'recovery_percentage'      => 'decimal:2',
        'risk_score'               => 'decimal:2',
        'total_score'              => 'decimal:2',
        'escalated'                => 'boolean',
        'detected_at'              => 'datetime',
        'resolved_at'              => 'datetime',
        'investigation_started_at' => 'datetime',
        'status_changed_at'        => 'datetime',
        'escalated_at'             => 'datetime',
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_INVESTIGATING = 'investigating';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_FALSE_POSITIVE = 'false_positive';

    public const STATUS_RESOLVED = 'resolved';

    public const SEVERITY_LOW = 'low';

    public const SEVERITY_MEDIUM = 'medium';

    public const SEVERITY_HIGH = 'high';

    public const SEVERITY_CRITICAL = 'critical';

    public const TYPE_ACCOUNT_TAKEOVER = 'account_takeover';

    public const TYPE_IDENTITY_THEFT = 'identity_theft';

    public const TYPE_TRANSACTION_FRAUD = 'transaction_fraud';

    public const TYPE_CARD_FRAUD = 'card_fraud';

    public const TYPE_PHISHING = 'phishing';

    public const TYPE_MONEY_LAUNDERING = 'money_laundering';

    public const TYPE_OTHER = 'other';

    public const STATUS_OPEN = 'open';

    public const PRIORITY_LOW = 'low';

    public const PRIORITY_MEDIUM = 'medium';

    public const PRIORITY_HIGH = 'high';

    public const PRIORITY_CRITICAL = 'critical';

    public const OUTCOME_FRAUD = 'fraud';

    public const OUTCOME_LEGITIMATE = 'legitimate';

    public const OUTCOME_UNKNOWN = 'unknown';

    public const DETECTION_METHOD_RULE_BASED = 'rule_based';

    public const DETECTION_METHOD_ML_MODEL = 'ml_model';

    public const DETECTION_METHOD_MANUAL_REPORT = 'manual_report';

    public const DETECTION_METHOD_EXTERNAL_REPORT = 'external_report';

    public const FRAUD_TYPES = [
        self::TYPE_ACCOUNT_TAKEOVER  => 'Account Takeover',
        self::TYPE_IDENTITY_THEFT    => 'Identity Theft',
        self::TYPE_TRANSACTION_FRAUD => 'Transaction Fraud',
        self::TYPE_CARD_FRAUD        => 'Card Fraud',
        self::TYPE_PHISHING          => 'Phishing',
        self::TYPE_MONEY_LAUNDERING  => 'Money Laundering',
        self::TYPE_OTHER             => 'Other',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(
            function ($case) {
                if (! $case->case_number) {
                    $case->case_number = static::generateCaseNumber();
                }
                if (! $case->detected_at) {
                    $case->detected_at = now();
                }
            }
        );
    }

    public static function generateCaseNumber(): string
    {
        $year = date('Y');
        $lastCase = static::whereYear('created_at', $year)
            ->orderBy('case_number', 'desc')
            ->first();

        if ($lastCase) {
            $lastNumber = intval(substr($lastCase->case_number, -5));
            $newNumber = str_pad((string) ($lastNumber + 1), 5, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '00001';
        }

        return "FC-{$year}-{$newNumber}";
    }

    // Relationships
    public function subjectAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'subject_account_uuid', 'uuid');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to', 'uuid');
    }

    public function fraudScore(): BelongsTo
    {
        return $this->belongsTo(FraudScore::class, 'fraud_score_id');
    }

    public function entity(): \Illuminate\Database\Eloquent\Relations\MorphTo
    {
        return $this->morphTo();
    }

    // Helper methods
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isInvestigating(): bool
    {
        return $this->status === self::STATUS_INVESTIGATING;
    }

    public function isResolved(): bool
    {
        return $this->status === self::STATUS_RESOLVED;
    }

    public function isConfirmed(): bool
    {
        return $this->status === self::STATUS_CONFIRMED;
    }

    public function isHighPriority(): bool
    {
        return in_array($this->severity, [self::SEVERITY_HIGH, self::SEVERITY_CRITICAL]);
    }

    public function wasFalsePositive(): bool
    {
        return $this->status === self::STATUS_FALSE_POSITIVE;
    }

    public function getDurationInDays(): int
    {
        // Since we don't have fraud_start_date and fraud_end_date columns,
        // we'll calculate based on detected_at and resolved_at
        if (! $this->detected_at || ! $this->resolved_at) {
            return 0;
        }

        return (int) $this->detected_at->diffInDays($this->resolved_at);
    }

    public function assign(User $investigator): void
    {
        $this->update(
            [
                'assigned_to' => $investigator->uuid,
                'status'      => self::STATUS_INVESTIGATING,
            ]
        );
    }

    public function addEvidence(array $evidence): void
    {
        $currentEvidence = $this->evidence ?? [];
        $currentEvidence[] = array_merge(
            $evidence,
            [
                'added_at' => now()->toIso8601String(),
            ]
        );

        $this->update(['evidence' => $currentEvidence]);
    }

    public function addInvestigationNote(string $note, string $author, string $type = 'investigation'): void
    {
        $notes = $this->investigation_notes ?? [];
        $notes[] = [
            'timestamp' => now()->toIso8601String(),
            'type'      => $type,
            'note'      => $note,
            'author'    => $author,
        ];

        $this->update(['investigation_notes' => $notes]);
    }

    public function recordAction(string $action, array $details = []): void
    {
        $actions = $this->actions_taken ?? [];
        $actions[] = array_merge(
            [
                'action'    => $action,
                'timestamp' => now()->toIso8601String(),
            ],
            $details
        );

        $this->update(['actions_taken' => $actions]);
    }

    public function resolve(string $notes): void
    {
        $this->update(
            [
                'resolution_notes' => $notes,
                'resolved_at'      => now(),
                'status'           => self::STATUS_RESOLVED,
            ]
        );
    }

    public function getCaseSummary(): array
    {
        return [
            'case_number'       => $this->case_number,
            'type'              => $this->type,
            'status'            => $this->status,
            'severity'          => $this->severity,
            'total_loss'        => $this->amount,
            'duration_days'     => $this->getDurationInDays(),
            'accounts_affected' => count($this->related_accounts ?? []),
        ];
    }

    /**
     * Scope to get cases for all teams.
     */
    public function scopeAllTeams($query)
    {
        return $query;
    }

    public function load($relations)
    {
        return $this->loadMissing($relations);
    }

    /**
     * Get the activity logs for this model.
     */
    /**
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function logs()
    {
        return $this->morphMany(\App\Domain\Activity\Models\Activity::class, 'subject');
    }
}
