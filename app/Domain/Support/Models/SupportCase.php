<?php

namespace App\Domain\Support\Models;

use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportCase extends Model
{
    use HasFactory;
    use HasUuids;

    protected static function newFactory()
    {
        return \Database\Factories\SupportCaseFactory::new();
    }

    protected $fillable = [
        'user_id',
        'transaction_id',
        'assigned_to',
        'subject',
        'description',
        'status',
        'priority',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(AuthorizedTransaction::class, 'transaction_id');
    }

    public function notes(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SupportCaseNote::class)->orderBy('created_at', 'desc');
    }
}
