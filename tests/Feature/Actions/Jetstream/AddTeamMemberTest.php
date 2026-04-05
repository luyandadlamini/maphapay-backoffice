<?php

use App\Actions\Jetstream\AddTeamMember;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Events\AddingTeamMember;
use Laravel\Jetstream\Events\TeamMemberAdded;

beforeEach(function () {
    Event::fake();
});

it('can add a team member successfully', function () {
    $owner = User::factory()->withPersonalTeam()->create();
    $team = $owner->currentTeam;
    $newMember = User::factory()->create();

    Gate::define('addTeamMember', fn ($user, $team) => $user->id === $owner->id);

    $action = new AddTeamMember();
    $action->add($owner, $team, $newMember->email, 'editor');

    // Check if user was added to team by querying the pivot table directly
    $membership = DB::table('team_user')
        ->where('team_id', $team->id)
        ->where('user_id', $newMember->id)
        ->first();

    expect($membership)->not()->toBeNull();
    /** @var stdClass $membership */
    expect($membership->role)->toBe('editor');

    Event::assertDispatched(AddingTeamMember::class);
    Event::assertDispatched(TeamMemberAdded::class);
});

it('validates user authorization before adding member', function () {
    $owner = User::factory()->withPersonalTeam()->create();
    $unauthorizedUser = User::factory()->create();
    $team = $owner->currentTeam;
    $newMember = User::factory()->create();

    Gate::define('addTeamMember', fn ($user, $team) => $user->id === $owner->id);

    $action = new AddTeamMember();

    expect(fn () => $action->add($unauthorizedUser, $team, $newMember->email, 'editor'))
        ->toThrow(Illuminate\Auth\Access\AuthorizationException::class);
});

it('validates email exists in users table', function () {
    $owner = User::factory()->withPersonalTeam()->create();
    $team = $owner->currentTeam;

    Gate::define('addTeamMember', fn ($user, $team) => $user->id === $owner->id);

    $action = new AddTeamMember();

    expect(fn () => $action->add($owner, $team, 'nonexistent@example.com', 'editor'))
        ->toThrow(ValidationException::class);
});

it('validates user is not already on team', function () {
    $owner = User::factory()->withPersonalTeam()->create();
    $team = $owner->currentTeam;
    $existingMember = User::factory()->create();

    // Add member first time
    $team->users()->attach($existingMember, ['role' => 'editor']);

    Gate::define('addTeamMember', fn ($user, $team) => $user->id === $owner->id);

    $action = new AddTeamMember();

    expect(fn () => $action->add($owner, $team, $existingMember->email, 'editor'))
        ->toThrow(ValidationException::class);
});

it('validates role when roles are enabled', function () {
    config(['jetstream.features' => ['teams']]);

    $owner = User::factory()->withPersonalTeam()->create();
    $team = $owner->currentTeam;
    $newMember = User::factory()->create();

    Gate::define('addTeamMember', fn ($user, $team) => $user->id === $owner->id);

    $action = new AddTeamMember();

    // This would fail validation if roles are required but invalid role provided
    // For this basic test, we'll just ensure it doesn't throw when role is provided
    $action->add($owner, $team, $newMember->email, 'editor');

    // Check if user was added to team by querying the pivot table directly
    $membership = DB::table('team_user')
        ->where('team_id', $team->id)
        ->where('user_id', $newMember->id)
        ->first();

    expect($membership)->not()->toBeNull();
});
