<?php

declare(strict_types=1);

namespace App\Domain\Account\Models;

use App\Domain\Shared\Traits\UsesTenantConnection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $minor_account_id
 * @property string $guardian_account_id
 * @property string $role
 * @property array|null $permissions
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder forMinorAccount(string $accountId)
 * @method static \Illuminate\Database\Eloquent\Builder forGuardianAccount(string $accountId)
 * @method static \Illuminate\Database\Eloquent\Builder primary()
 * @method static \Illuminate\Database\Eloquent\Builder coGuardians()
 */
class AccountMembership extends Model
{
    use UsesTenantConnection;
    use HasFactory;
    use HasUuids;

    protected $table = 'account_memberships';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'minor_account_id',
        'guardian_account_id',
        'role',
        'permissions',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'id' => 'string',
        'minor_account_id' => 'string',
        'guardian_account_id' => 'string',
        'permissions' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the columns that should receive a unique identifier.
     *
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['id'];
    }

    /**
     * The minor account this membership belongs to.
     */
    public function minorAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'minor_account_id', 'uuid');
    }

    /**
     * The guardian account this membership belongs to.
     */
    public function guardianAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'guardian_account_id', 'uuid');
    }

    /**
     * Scope: Filter memberships for a specific minor account.
     */
    public function scopeForMinorAccount($query, string $accountId)
    {
        return $query->where('minor_account_id', $accountId);
    }

    /**
     * Scope: Filter memberships for a specific guardian account.
     */
    public function scopeForGuardianAccount($query, string $accountId)
    {
        return $query->where('guardian_account_id', $accountId);
    }

    /**
     * Scope: Filter to primary guardians only (role='guardian').
     */
    public function scopePrimary($query)
    {
        return $query->where('role', 'guardian');
    }

    /**
     * Scope: Filter to co-guardians only (role='co_guardian').
     */
    public function scopeCoGuardians($query)
    {
        return $query->where('role', 'co_guardian');
    }

    /**
     * Check if this membership is a primary guardian.
     */
    public function isPrimary(): bool
    {
        return $this->role === 'guardian';
    }

    /**
     * Check if this membership is a co-guardian.
     */
    public function isCoGuardian(): bool
    {
        return $this->role === 'co_guardian';
    }
}
