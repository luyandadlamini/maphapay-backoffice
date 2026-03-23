<?php

declare(strict_types=1);

describe('Machine Payments Feature Page', function (): void {
    it('renders the machine payments feature page', function (): void {
        $response = $this->get('/features/machine-payments');

        $response->assertOk();
        $response->assertSee('Machine Payments Protocol');
        $response->assertSee('Stripe');
        $response->assertSee('Tempo');
        $response->assertSee('Lightning');
    });
});
