<?php

declare(strict_types=1);

it('faker function returns generator', function () {
    $faker = faker();

    expect($faker)->toBeInstanceOf(Faker\Generator::class);
});

it('faker function returns different instances', function () {
    $faker1 = faker();
    $faker2 = faker();

    // They should be different instances but same class
    expect($faker1)->toBeInstanceOf(Faker\Generator::class);
    expect($faker2)->toBeInstanceOf(Faker\Generator::class);
    expect($faker1)->not()->toBe($faker2);
});

it('can generate fake data', function () {
    $faker = faker();

    $name = $faker->name;
    $email = $faker->email;

    expect($name)->toBeString();
    expect($email)->toBeString();
    expect($email)->toContain('@');
});
