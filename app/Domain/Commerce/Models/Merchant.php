<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Models;

use App\Domain\Commerce\Enums\MerchantStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Database\Factories\MerchantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Merchant entity for payment processing.
 *
 * @property string $id
 * @property string $public_id
 * @property string $display_name
 * @property string|null $icon_url
 * @property array<string> $accepted_assets
 * @property array<string> $accepted_networks
 * @property MerchantStatus $status
 * @property string|null $terminal_id
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 */
class Merchant extends Model
{
    /** @use HasFactory<MerchantFactory> */
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $table = 'merchants';

    protected $fillable = [
        'public_id',
        'display_name',
        'icon_url',
        'accepted_assets',
        'accepted_networks',
        'status',
        'terminal_id',
    ];

    protected $casts = [
        'accepted_assets'   => 'array',
        'accepted_networks' => 'array',
        'status'            => MerchantStatus::class,
    ];

    /**
     * @return HasMany<MerchantWalletAddress, $this>
     */
    public function walletAddresses(): HasMany
    {
        return $this->hasMany(MerchantWalletAddress::class);
    }

    public function acceptsAsset(string $asset): bool
    {
        return in_array($asset, $this->accepted_assets ?? [], true);
    }

    public function acceptsNetwork(string $network): bool
    {
        return in_array($network, $this->accepted_networks ?? [], true);
    }

    public function canAcceptPayments(): bool
    {
        return $this->status->canAcceptPayments();
    }

    public function getWalletAddress(string $network): ?string
    {
        $wallet = $this->walletAddresses()
            ->where('network', $network)
            ->where('is_active', true)
            ->first();

        return $wallet?->wallet_address;
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiResponse(): array
    {
        return [
            'merchantId'  => $this->public_id,
            'displayName' => $this->display_name,
            'iconUrl'     => $this->icon_url,
        ];
    }

    protected static function newFactory(): \Database\Factories\MerchantFactory
    {
        return \Database\Factories\MerchantFactory::new();
    }
}
