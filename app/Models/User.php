<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\Transaction;
use App\Domain\Account\Models\TransactionProjection;
use App\Domain\Banking\Models\BankAccountModel;
use App\Domain\Banking\Models\UserBankPreference;
use App\Domain\CardIssuance\Models\Card;
use App\Domain\Cgo\Models\CgoInvestment;
use App\Domain\Compliance\Models\KycDocument;
use App\Domain\Mobile\Models\Pocket;
use App\Domain\Rewards\Models\RewardProfile;
use App\Domain\User\Values\UserRoles;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Cashier\Billable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Jetstream\HasProfilePhoto;
use Laravel\Jetstream\HasTeams;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property Carbon|null $free_tx_until
 * @property int $sponsored_tx_used
 * @property int $sponsored_tx_limit
 * @property string|null $transaction_pin
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $mobile_verified_at
 * @property string|null $mobile
 * @property string|null $dial_code
 * @property string|null $username
 * @property Carbon|null $kyc_approved_at
 * @property Carbon|null $kyc_submitted_at
 * @property Carbon|null $kyc_rejected_at
 * @property array<string, mixed>|null $mobile_preferences
 * @property Carbon|null $frozen_at
 * @property string|null $frozen_reason
 * @property string|null $frozen_by
 */
class User extends Authenticatable implements FilamentUser
{
    use Billable;
    use HasApiTokens;
    use HasFactory;
    use HasProfilePhoto;
    use HasRoles;
    use HasTeams;
    use HasUuids;
    use Notifiable;
    use TwoFactorAuthenticatable;

    /**
     * Get the columns that should receive a unique identifier.
     *
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'email_verified_at',
        'oauth_provider',
        'oauth_id',
        'avatar',
        'kyc_status',
        'kyc_submitted_at',
        'kyc_approved_at',
        'kyc_rejected_at',
        'kyc_expires_at',
        'kyc_level',
        'kyc_identity_type',
        'kyc_current_step',
        'kyc_steps_completed',
        'pep_status',
        'risk_rating',
        'kyc_data',
        'privacy_policy_accepted_at',
        'terms_accepted_at',
        'marketing_consent_at',
        'data_retention_consent',
        'has_completed_onboarding',
        'onboarding_completed_at',
        'country_code', // Added for testing KYC/AML
        'mobile_preferences',
        'free_tx_until',
        'sponsored_tx_used',
        'sponsored_tx_limit',
        'referral_code',
        'referred_by',
        'transaction_pin',
        'transaction_pin_enabled',
        'mobile',
        'dial_code',
        'mobile_verified_at',
        'username',
        'frozen_at',
        'frozen_reason',
        'frozen_by',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
        'transaction_pin',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'profile_photo_url',
        'transaction_pin_set',
        'transaction_pin_enabled',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'mobile_verified_at' => 'datetime',
            'password' => 'hashed',
            'kyc_submitted_at' => 'datetime',
            'kyc_approved_at' => 'datetime',
            'kyc_expires_at' => 'datetime',
            'pep_status' => 'boolean',
            'kyc_data' => 'encrypted:array',
            'kyc_steps_completed' => 'array',
            'privacy_policy_accepted_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'marketing_consent_at' => 'datetime',
            'data_retention_consent' => 'boolean',
            'has_completed_onboarding' => 'boolean',
            'onboarding_completed_at' => 'datetime',
            'transaction_pin' => 'hashed',
            'transaction_pin_enabled' => 'boolean',
            'mobile_preferences' => 'array',
            'free_tx_until' => 'datetime',
            'sponsored_tx_used' => 'integer',
            'sponsored_tx_limit' => 'integer',
            'frozen_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Get whether the transaction PIN is set.
     */
    public function getTransactionPinSetAttribute(): bool
    {
        return ! empty($this->attributes['transaction_pin']);
    }

    public function getTransactionPinEnabledAttribute(): bool
    {
        if (array_key_exists('transaction_pin_enabled', $this->attributes)) {
            return (bool) $this->attributes['transaction_pin_enabled'];
        }

        return $this->transaction_pin_set;
    }

    /**
     * Determine if the user can access the Filament admin panel.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->hasRole(UserRoles::ADMIN->value);
    }

    /**
     * Get the accounts for the user.
     */
    /**
     * @return HasMany
     */
    public function accounts()
    {
        return $this->hasMany(Account::class, 'user_uuid', 'uuid');
    }

    /**
     * Get the primary account for the user.
     * This returns the first account which is typically the default one created on registration.
     */
    /**
     * @return HasOne
     */
    public function account()
    {
        return $this->hasOne(Account::class, 'user_uuid', 'uuid');
    }

    /**
     * Get the primary account for the user.
     * Alias for account() to maintain backward compatibility.
     */
    public function primaryAccount()
    {
        return $this->account()->first();
    }

    /**
     * Get the bank preferences for the user.
     *
     * @return HasMany<UserBankPreference, $this>
     */
    public function bankPreferences()
    {
        return $this->hasMany(UserBankPreference::class, 'user_uuid', 'uuid');
    }

    /**
     * Get the bank accounts for the user.
     */
    /**
     * @return HasMany
     */
    public function bankAccounts()
    {
        return $this->hasMany(BankAccountModel::class, 'user_uuid', 'uuid');
    }

    /**
     * Get active bank preferences for the user.
     *
     * @return HasMany<UserBankPreference, $this>
     */
    public function activeBankPreferences(): HasMany
    {
        return $this->bankPreferences()->where('is_active', true);
    }

    /**
     * Get the KYC documents for the user.
     */
    /**
     * @return HasMany
     */
    public function kycDocuments()
    {
        return $this->hasMany(KycDocument::class, 'user_uuid', 'uuid');
    }

    /**
     * Check if user has completed KYC.
     */
    public function hasCompletedKyc(): bool
    {
        return $this->kyc_status === 'approved' &&
               ($this->kyc_expires_at === null || $this->kyc_expires_at->isFuture());
    }

    /**
     * Check if user needs KYC.
     */
    public function needsKyc(): bool
    {
        return in_array($this->kyc_status, ['not_started', 'rejected', 'expired']) ||
               ($this->kyc_status === 'approved' && $this->kyc_expires_at && $this->kyc_expires_at->isPast());
    }

    /**
     * Check if user has completed onboarding.
     */
    public function hasCompletedOnboarding(): bool
    {
        return $this->has_completed_onboarding === true;
    }

    /**
     * Mark onboarding as completed.
     */
    public function completeOnboarding(): void
    {
        $this->update(
            [
                'has_completed_onboarding' => true,
                'onboarding_completed_at' => now(),
            ]
        );
    }

    /**
     * Get the CGO investments for the user.
     */
    public function cgoInvestments(): HasMany
    {
        return $this->hasMany(CgoInvestment::class);
    }

    /**
     * Get the API keys for the user.
     */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class, 'user_uuid', 'uuid');
    }

    /**
     * Get all transactions for the user through their accounts.
     */
    public function transactions(): HasManyThrough
    {
        return $this->hasManyThrough(
            Transaction::class,
            Account::class,
            'user_uuid', // Foreign key on accounts table
            'aggregate_uuid', // Foreign key on transactions table
            'uuid', // Local key on users table
            'uuid' // Local key on accounts table
        );
    }

    /**
     * Get all transaction projections for the user through their accounts.
     */
    public function transactionProjections(): HasManyThrough
    {
        return $this->hasManyThrough(
            TransactionProjection::class,
            Account::class,
            'user_uuid',
            'account_uuid',
            'uuid',
            'uuid'
        );
    }

    /**
     * Get the user who referred this user.
     *
     * @return BelongsTo<User, $this>
     */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(self::class, 'referred_by');
    }

    /**
     * Get users referred by this user.
     *
     * @return HasMany<Referral, $this>
     */
    public function referrals(): HasMany
    {
        return $this->hasMany(Referral::class, 'referrer_id');
    }

    /**
     * Get the reward profile for this user.
     *
     * @return HasOne<RewardProfile, $this>
     */
    public function rewardProfile(): HasOne
    {
        return $this->hasOne(RewardProfile::class);
    }

    /**
     * Get the cards for this user.
     *
     * @return HasMany<Card, $this>
     */
    public function cards(): HasMany
    {
        return $this->hasMany(Card::class, 'user_id', 'id');
    }

    /**
     * Get the pockets for this user.
     *
     * @return HasMany<Pocket, $this>
     */
    public function pockets(): HasMany
    {
        return $this->hasMany(Pocket::class, 'user_uuid', 'uuid');
    }

    public function isFrozen(): bool
    {
        return $this->frozen_at !== null;
    }

    public function freeze(string $reason, ?string $frozenBy = null): void
    {
        $this->update([
            'frozen_at' => now(),
            'frozen_reason' => $reason,
            'frozen_by' => $frozenBy ?? auth()->user()?->email,
        ]);
    }

    public function unfreeze(): void
    {
        $this->update([
            'frozen_at' => null,
            'frozen_reason' => null,
            'frozen_by' => null,
        ]);
    }
}
