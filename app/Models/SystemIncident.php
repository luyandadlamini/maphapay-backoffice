<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @method static \Illuminate\Database\Eloquent\Builder where(string $column, mixed $operator = null, mixed $value = null, string $boolean = 'and')
 * @method static \Illuminate\Database\Eloquent\Builder whereIn(string $column, mixed $values, string $boolean = 'and', bool $not = false)
 * @method static \Illuminate\Database\Eloquent\Builder orderBy(string $column, string $direction = 'asc')
 * @method static \Illuminate\Database\Eloquent\Collection get(array $columns = ['*'])
 * @method static static|null find(mixed $id, array $columns = ['*'])
 * @method static static|null first(array $columns = ['*'])
 * @method static static firstOrFail(array $columns = ['*'])
 * @method static int count(string $columns = '*')
 * @method static bool exists()
 * @method static static create(array $attributes = [])
 * @method static static updateOrCreate(array $attributes, array $values = [])
 */
class SystemIncident extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'description',
        'service',
        'impact',
        'status',
        'started_at',
        'resolved_at',
        'affected_services',
    ];

    protected $casts = [
        'started_at'        => 'datetime',
        'resolved_at'       => 'datetime',
        'affected_services' => 'array',
    ];

    /**
     * Get all updates for this incident.
     */
    public function updates(): HasMany
    {
        return $this->hasMany(SystemIncidentUpdate::class)->orderBy('created_at', 'desc');
    }

    /**
     * Get only active incidents.
     */
    public function scopeActive($query)
    {
        return $query->whereIn('status', ['identified', 'in_progress']);
    }

    /**
     * Get only resolved incidents.
     */
    public function scopeResolved($query)
    {
        return $query->where('status', 'resolved');
    }

    /**
     * Get incidents within a time range.
     */
    public function scopeInTimeRange($query, $start, $end)
    {
        return $query->whereBetween('started_at', [$start, $end]);
    }

    /**
     * Add an update to the incident.
     */
    public function addUpdate(string $status, string $message)
    {
        $update = $this->updates()->create(
            [
            'status'  => $status,
            'message' => $message,
            ]
        );

        // Update the incident status
        $this->update(['status' => $status]);

        // If resolved, set the resolved_at timestamp
        if ($status === 'resolved' && ! $this->resolved_at) {
            $this->update(['resolved_at' => now()]);
        }

        return $update;
    }

    /**
     * Calculate the duration of the incident.
     */
    public function getDurationAttribute()
    {
        $endTime = $this->resolved_at ?? now();

        return $this->started_at->diffForHumans($endTime, true);
    }

    /**
     * Get impact color for UI.
     */
    public function getImpactColorAttribute()
    {
        return match ($this->impact) {
            'minor'    => 'yellow',
            'major'    => 'orange',
            'critical' => 'red',
            default    => 'gray',
        };
    }

    /**
     * Get status color for UI.
     */
    public function getStatusColorAttribute()
    {
        return match ($this->status) {
            'resolved'    => 'green',
            'in_progress' => 'yellow',
            'identified'  => 'red',
            default       => 'gray',
        };
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
