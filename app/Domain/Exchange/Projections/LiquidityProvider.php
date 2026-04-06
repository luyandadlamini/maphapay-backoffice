<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Projections;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
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
class LiquidityProvider extends Model
{
    use HasFactory;

    protected $table = 'liquidity_providers';

    protected $fillable = [
        'pool_id',
        'provider_id',
        'shares',
        'initial_base_amount',
        'initial_quote_amount',
        'pending_rewards',
        'total_rewards_claimed',
        'metadata',
    ];

    protected $casts = [
        'pending_rewards' => 'array',
        'metadata'        => 'array',
    ];

    public function pool(): BelongsTo
    {
        return $this->belongsTo(LiquidityPool::class, 'pool_id', 'pool_id');
    }

    public function getSharePercentageAttribute(): string
    {
        $pool = $this->pool;
        if (! $pool || $pool->total_shares == 0) {
            return '0';
        }

        return \Brick\Math\BigDecimal::of($this->shares)
            ->dividedBy($pool->total_shares, 18, \Brick\Math\RoundingMode::DOWN)
            ->multipliedBy(100)
            ->toScale(6, \Brick\Math\RoundingMode::DOWN)
            ->__toString();
    }

    public function getCurrentValueAttribute(): array
    {
        $pool = $this->pool;
        if (! $pool || $pool->total_shares == 0) {
            return [
                'base_amount'  => '0',
                'quote_amount' => '0',
            ];
        }

        $shareRatio = \Brick\Math\BigDecimal::of($this->shares)
            ->dividedBy($pool->total_shares, 18);

        return [
            'base_amount' => \Brick\Math\BigDecimal::of($pool->base_reserve)
                ->multipliedBy($shareRatio)
                ->__toString(),
            'quote_amount' => \Brick\Math\BigDecimal::of($pool->quote_reserve)
                ->multipliedBy($shareRatio)
                ->__toString(),
        ];
    }
}
