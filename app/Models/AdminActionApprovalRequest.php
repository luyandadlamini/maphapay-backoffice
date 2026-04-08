<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminActionApprovalRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace',
        'action',
        'status',
        'reason',
        'requester_id',
        'reviewer_id',
        'target_type',
        'target_identifier',
        'payload',
        'metadata',
        'requested_at',
        'reviewed_at',
    ];

    protected $casts = [
        'payload'       => 'array',
        'metadata'      => 'array',
        'requested_at'  => 'datetime',
        'reviewed_at'   => 'datetime',
        'created_at'    => 'datetime',
        'updated_at'    => 'datetime',
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
