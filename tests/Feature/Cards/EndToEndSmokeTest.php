<?php

declare(strict_types=1);

/**
 * Full-stack card lifecycle smoke tests (adult + Khula minor) per docs/cards/09-implementation-phases.md §Phase 10.
 *
 * Deferred until all card HTTP + webhook + billing paths are stable on CI MySQL with tenant DBs.
 */
it('adult subscribe → card → webhooks → cancel (smoke)', function () {
    expect(true)->toBeTrue();
})->todo('Wire HTTP + webhook harness for full adult flow on CI tenant DB.');

it('Khula minor guardian approval flow (smoke)', function () {
    expect(true)->toBeTrue();
})->todo('Wire minor request + guardian approval + auth limits on CI tenant DB.');
