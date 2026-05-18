<?php

declare(strict_types=1);

it('keeps package assets on central URLs inside tenant backed admin pages', function (): void {
    expect(config('tenancy.filesystem.asset_helper_tenancy'))->toBeFalse();
});
