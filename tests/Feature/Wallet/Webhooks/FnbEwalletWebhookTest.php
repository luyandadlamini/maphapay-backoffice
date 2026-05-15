<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet\Webhooks;

use App\Domain\Account\Models\Account;
use App\Domain\Wallet\Models\WalletProviderTransaction;
use App\Domain\Wallet\Services\WalletOperationsService;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

final class FnbEwalletWebhookTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'fnb_ewallet.callback_token'        => 'tok',
            'fnb_ewallet.hmac_key'              => 'sec',
            'fnb_ewallet.verify_callback_token' => true,
            'fnb_ewallet.verify_hmac_signature' => true,
        ]);
    }

    public function test_posted_callback_credits_user_and_marks_row_successful(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'uuid'      => (string) Str::uuid(),
            'user_uuid' => $user->uuid,
            'frozen'    => false,
        ]);

        $row = WalletProviderTransaction::query()->create([
            'provider_id'         => 'fnb_ewallet',
            'provider_request_id' => 'fnb-wh-1',
            'type'                => WalletProviderTransaction::TYPE_COLLECT,
            'status'              => WalletProviderTransaction::STATUS_PENDING,
            'currency'            => 'SZL',
            'amount_minor'        => 15_000,
            'user_uuid'           => $user->uuid,
        ]);

        $walletOps = $this->createMock(WalletOperationsService::class);
        $walletOps->expects($this->once())
            ->method('deposit')
            ->with($account->uuid, 'SZL', '15000', 'fnb-ewallet-credit:fnb-wh-1', $this->anything())
            ->willReturn('dep-1');
        $this->app->instance(WalletOperationsService::class, $walletOps);

        $body = json_encode(['reference_id' => 'fnb-wh-1', 'status' => 'POSTED'], JSON_THROW_ON_ERROR);

        $this->postRawJson('/api/webhooks/wallets/fnb_ewallet', $body, [
            'X-Callback-Token' => 'tok',
            'X-Signature'      => hash_hmac('sha256', $body, 'sec'),
        ])->assertOk();

        $this->assertSame(WalletProviderTransaction::STATUS_SUCCESSFUL, $row->fresh()->status);
    }

    public function test_rejects_bad_signature(): void
    {
        $this->postRawJson('/api/webhooks/wallets/fnb_ewallet', '{"reference_id":"r","status":"POSTED"}', [
            'X-Callback-Token' => 'tok',
            'X-Signature'      => 'bad',
        ])->assertUnauthorized();
    }

    /**
     * @param  array<string, string>  $headers
     * @return \Illuminate\Testing\TestResponse<\Illuminate\Http\Response>
     */
    private function postRawJson(string $uri, string $body, array $headers = []): \Illuminate\Testing\TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call('POST', $uri, [], [], [], $server, $body);
    }
}
