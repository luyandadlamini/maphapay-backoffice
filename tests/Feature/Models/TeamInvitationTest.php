<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\TeamInvitation;

it('can create a team invitation', function () {
    $team = Team::factory()->create();

    $invitation = TeamInvitation::create([
        'team_id' => $team->id,
        'email'   => 'test@example.com',
        'role'    => 'editor',
    ]);

    expect($invitation)->toBeInstanceOf(TeamInvitation::class);
    expect($invitation->email)->toBe('test@example.com');
    expect($invitation->role)->toBe('editor');
    expect($invitation->team_id)->toBe($team->id);
});

it('has fillable attributes', function () {
    $invitation = new TeamInvitation();

    expect($invitation->getFillable())->toContain('email');
    expect($invitation->getFillable())->toContain('role');
});

it('belongs to a team', function () {
    $team = Team::factory()->create();
    $invitation = TeamInvitation::create([
        'team_id' => $team->id,
        'email'   => 'test@example.com',
        'role'    => 'editor',
    ]);

    expect($invitation->team)->toBeInstanceOf(Team::class);
    expect($invitation->team->id)->toBe($team->id);
});
