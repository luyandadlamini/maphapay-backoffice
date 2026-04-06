<?php

declare(strict_types=1);

namespace App\Domain\Support\Models;

use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Models\User;
use Database\Factories\SupportCaseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SupportCase extends Model
{
    /** @use HasFactory<SupportCaseFactory> */
    use HasFactory;
    use HasUuids;

    protected static function newFactory(): SupportCaseFactory
    {
        return SupportCaseFactory::new();
    }

    protected $fillable = [
        'user_id',
        'transaction_id',
        'assigned_to',
        'subject',
        'description',
        'status',
        'priority',
        'linked_subject_type',
        'linked_subject_id',
        'transaction_reference',
        'reported_by',
    ];

    /** @return BelongsTo<User, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return BelongsTo<AuthorizedTransaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(AuthorizedTransaction::class, 'transaction_id');
    }

    /** @return MorphTo<Model, $this> */
    public function linkedSubject(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return HasMany<SupportCaseNote, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(SupportCaseNote::class)->orderBy('created_at', 'desc');
    }
}
