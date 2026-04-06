<?php

declare(strict_types=1);

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

trait BelongsToTeam
{
    /**
     * Boot the trait.
     */
    protected static function bootBelongsToTeam()
    {
        // Automatically set team_id when creating models
        static::creating(
            function ($model) {
                // Skip auth check in test environment or when app is not booted
                if (app()->runningInConsole() && app()->environment('testing')) {
                    return;
                }

                if (Auth::check() && Auth::user()->currentTeam) {
                    $model->team_id = Auth::user()->currentTeam->id;
                }
            }
        );

        // Global scope to filter by team
        static::addGlobalScope(
            'team',
            function (Builder $builder) {
                // Skip auth check in test environment or when app is not booted
                if (app()->runningInConsole() && app()->environment('testing')) {
                    return;
                }

                if (Auth::check() && Auth::user()->currentTeam) {
                    $builder->where('team_id', Auth::user()->currentTeam->id);
                }
            }
        );
    }

    /**
     * Scope to include all teams (bypass team isolation).
     */
    public function scopeAllTeams($query)
    {
        return $query->withoutGlobalScope('team');
    }

    /**
     * Scope to filter by specific team.
     */
    public function scopeForTeam($query, $teamId)
    {
        return $query->withoutGlobalScope('team')->where('team_id', $teamId);
    }

    /**
     * Get the team relationship.
     */
    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function team()
    {
        return $this->belongsTo(\App\Models\Team::class);
    }
}
