<?php

use App\Models\TeamInvitation;

it('extends JetstreamTeamInvitation', function () {
    $reflection = new ReflectionClass(TeamInvitation::class);
    expect($reflection->getParentClass()->getName())->toBe('Laravel\Jetstream\TeamInvitation');
});

it('has correct fillable attributes', function () {
    $invitation = new TeamInvitation();

    expect($invitation->getFillable())->toBe([
        'email',
        'role',
    ]);
});

it('has team relationship', function () {
    expect((new ReflectionClass(TeamInvitation::class))->hasMethod('team'))->toBeTrue();
});

it('has team method', function () {
    expect((new ReflectionClass(TeamInvitation::class))->hasMethod('team'))->toBeTrue();
});

it('team method returns BelongsTo type', function () {
    $reflection = new ReflectionMethod(TeamInvitation::class, 'team');
    expect($reflection->getReturnType()?->getName())->toBe('Illuminate\Database\Eloquent\Relations\BelongsTo');
});
