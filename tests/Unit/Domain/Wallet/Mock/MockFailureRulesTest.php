<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Wallet\Mock;

use App\Domain\Wallet\Mock\MockFailureRules;
use Tests\TestCase;

final class MockFailureRulesTest extends TestCase
{
    public function test_sync_outcome_returns_duplicate_for_reserved_amount(): void
    {
        $this->assertSame(
            MockFailureRules::SYNC_DUPLICATE_409,
            MockFailureRules::syncOutcome('26876000001', MockFailureRules::AMOUNT_DUPLICATE_MINOR),
        );
    }

    public function test_sync_outcome_returns_transient_for_reserved_amount(): void
    {
        $this->assertSame(
            MockFailureRules::SYNC_TRANSIENT_500,
            MockFailureRules::syncOutcome('26876000001', MockFailureRules::AMOUNT_TRANSIENT_MINOR),
        );
    }

    public function test_sync_outcome_accepts_arbitrary_amounts(): void
    {
        $this->assertSame(MockFailureRules::SYNC_ACCEPT, MockFailureRules::syncOutcome('26876000001', 100));
        $this->assertSame(MockFailureRules::SYNC_ACCEPT, MockFailureRules::syncOutcome('26876000001', 5000));
    }

    public function test_callback_outcome_returns_successful_for_happy_suffix(): void
    {
        $this->assertSame(
            MockFailureRules::CB_SUCCESSFUL,
            MockFailureRules::callbackOutcome('0001'),
        );
    }

    public function test_callback_outcome_returns_payer_not_found_for_reserved_suffix(): void
    {
        $this->assertSame(
            MockFailureRules::CB_FAILED_PAYER_NOT_FOUND,
            MockFailureRules::callbackOutcome('0002'),
        );
    }

    public function test_callback_outcome_returns_insufficient_funds_for_reserved_suffix(): void
    {
        $this->assertSame(
            MockFailureRules::CB_FAILED_INSUFFICIENT_FUNDS,
            MockFailureRules::callbackOutcome('0003'),
        );
    }

    public function test_callback_outcome_returns_silent_timeout_for_reserved_suffix(): void
    {
        $this->assertSame(
            MockFailureRules::CB_SILENT_TIMEOUT,
            MockFailureRules::callbackOutcome('0004'),
        );
    }

    public function test_callback_outcome_returns_rejected_by_user_for_reserved_suffix(): void
    {
        $this->assertSame(
            MockFailureRules::CB_FAILED_REJECTED_BY_USER,
            MockFailureRules::callbackOutcome('0005'),
        );
    }

    public function test_callback_outcome_defaults_to_successful_for_unreserved_suffix(): void
    {
        $this->assertSame(
            MockFailureRules::CB_SUCCESSFUL,
            MockFailureRules::callbackOutcome('26876009999'),
        );
    }

    public function test_callback_outcome_extracts_last_four_characters_from_longer_msisdns(): void
    {
        $this->assertSame(
            MockFailureRules::CB_SUCCESSFUL,
            MockFailureRules::callbackOutcome('26876000001'),
        );

        $this->assertSame(
            MockFailureRules::CB_FAILED_INSUFFICIENT_FUNDS,
            MockFailureRules::callbackOutcome('  +2687600 0003  '),
        );
    }

    public function test_callback_outcome_defaults_to_successful_for_empty_and_short_strings(): void
    {
        $this->assertSame(MockFailureRules::CB_SUCCESSFUL, MockFailureRules::callbackOutcome(''));
        $this->assertSame(MockFailureRules::CB_SUCCESSFUL, MockFailureRules::callbackOutcome('0'));
        $this->assertSame(MockFailureRules::CB_SUCCESSFUL, MockFailureRules::callbackOutcome('001'));
    }
}
