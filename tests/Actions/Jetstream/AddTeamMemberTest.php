<?php

use App\Actions\Jetstream\AddTeamMember;

it('can be instantiated', function () {
    expect(new AddTeamMember())->toBeInstanceOf(AddTeamMember::class);
});

it('implements AddsTeamMembers contract', function () {
    expect(AddTeamMember::class)->toImplement(Laravel\Jetstream\Contracts\AddsTeamMembers::class);
});

it('has validate method', function () {
    expect((new ReflectionClass(AddTeamMember::class))->hasMethod('add'))->toBeTrue();
});

it('has rules method', function () {
    $reflection = new ReflectionClass(AddTeamMember::class);
    expect($reflection->hasMethod('rules'))->toBeTrue();
});
