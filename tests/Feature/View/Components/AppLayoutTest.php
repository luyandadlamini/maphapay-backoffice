<?php

declare(strict_types=1);

use App\View\Components\AppLayout;
use Illuminate\View\View;

it('can render app layout component', function () {
    $component = new AppLayout();
    $view = $component->render();

    expect($view)->toBeInstanceOf(View::class);
    expect($view->getName())->toBe('layouts.app');
});

it('returns the correct view name', function () {
    $component = new AppLayout();
    $view = $component->render();

    expect($view->getName())->toBe('layouts.app');
});
