<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Models;

use App\Models\User;
use Database\Factories\Domain\Wallet\WalletLinkingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $user_id
 * @property string $provider
 * @property string $account_ref
 * @property string $currency
 * @property string $link_status
 * @property \Illuminate\Support\Carbon $linked_at
 * @property \Illuminate\Support\Carbon|null $last_used_at
 * @property \Illuminate\Support\Carbon|null $disabled_at
 * @property int|null $disabled_by_user_id
 * @property array<string, mixed>|null $metadata
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
final class WalletLinking extends Model
{
    /** @use HasFactory<WalletLinkingFactory> */
    use HasFactory;

    use SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PENDING = 'pending';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DISABLED = 'disabled';

    public const PROVIDER_MTN_MOMO = 'mtn_momo';

    public const PROVIDER_EMALI = 'emali_eswatini_mobile';

    public const PROVIDER_FNB = 'fnb_ewallet';

    public const PROVIDER_STANDARD_UNAYO = 'standard_unayo';

    public const PROVIDER_NEDBANK = 'nedbank_send_money';

    public const PROVIDERS = [
        self::PROVIDER_MTN_MOMO,
        self::PROVIDER_EMALI,
        self::PROVIDER_FNB,
        self::PROVIDER_STANDARD_UNAYO,
        self::PROVIDER_NEDBANK,
    ];

    protected $table = 'wallet_linkings';

    protected $fillable = [
        'user_id',
        'provider',
        'account_ref',
        'currency',
        'link_status',
        'linked_at',
        'last_used_at',
        'disabled_at',
        'disabled_by_user_id',
        'metadata',
    ];

    protected $casts = [
        'linked_at'    => 'datetime',
        'last_used_at' => 'datetime',
        'disabled_at'  => 'datetime',
        'metadata'     => 'array',
    ];

    protected static function newFactory(): WalletLinkingFactory
    {
        return WalletLinkingFactory::new();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
