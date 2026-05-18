<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Http\Controllers;

use App\Domain\CardIssuance\Models\Card;
use App\Domain\CardSubscriptions\Http\Concerns\RespondsWithCardApiEnvelope;
use App\Domain\CardSubscriptions\Http\Requests\CardFreezeRequest;
use App\Domain\CardSubscriptions\Http\Requests\CardReplaceRequest;
use App\Domain\CardSubscriptions\Http\Requests\CreateVirtualCardRequest;
use App\Domain\CardSubscriptions\Http\Requests\UpdateCardControlsRequest;
use App\Domain\CardSubscriptions\Http\Resources\CardResource;
use App\Domain\CardSubscriptions\Services\CardAuditService;
use App\Domain\CardSubscriptions\Services\CardProductAuthorizationCoordinator;
use App\Domain\CardSubscriptions\Services\CardRevealService;
use App\Domain\CardSubscriptions\Services\CardSubscriptionService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CardController extends Controller
{
    use RespondsWithCardApiEnvelope;

    public function __construct(
        private readonly CardProductAuthorizationCoordinator $cardProductAuthorization,
        private readonly CardRevealService $revealService,
        private readonly CardSubscriptionService $subscriptionService,
        private readonly CardAuditService $auditService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $cards = Card::where('user_id', $user->id)->latest()->get();

        return $this->cardSuccess('cards', [
            'cards' => CardResource::collection($cards)->resolve($request),
        ]);
    }

    public function storeVirtual(CreateVirtualCardRequest $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $subscription = $this->subscriptionService->getCurrent($user);

        if (! $subscription) {
            abort(403, 'Active subscription required.');
        }

        $idempotencyKey = (string) $request->header('Idempotency-Key', '');

        return $this->cardProductAuthorization->begin($user, 'create_virtual', [
            'subscription_id' => (string) $subscription->id,
            'create_virtual'  => $request->validated(),
        ], $idempotencyKey);
    }

    public function show(Request $request, string $cardId): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $card = Card::where('id', $cardId)->where('user_id', $user->id)->firstOrFail();

        return $this->cardSuccess('card', [
            'card' => (new CardResource($card))->resolve($request),
        ]);
    }

    /**
     * Legacy single-step reveal (trust header). Prefer {@see beginRevealChallenge} + PIN verify for mobile.
     */
    public function reveal(Request $request, string $cardId): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $card = Card::where('id', $cardId)->where('user_id', $user->id)->firstOrFail();

        if (! $request->hasHeader('X-Mobile-Trust')) {
            abort(403, 'Step-up authentication required.');
        }

        $result = $this->revealService->mintRevealUrl($user, $card);

        $this->auditService->recordCardEvent('reveal_requested', $card, null, [
            'expires_at' => $result->expiresAt->format('c'),
        ]);

        return $this->cardSuccess('card_reveal', [
            'reveal_url'  => $result->url,
            'expires_at'  => $result->expiresAt->format('c'),
            'ttl_seconds' => max(0, now()->diffInSeconds($result->expiresAt, false)),
        ]);
    }

    public function beginRevealChallenge(Request $request, string $cardId): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        Card::where('id', $cardId)->where('user_id', $user->id)->firstOrFail();

        $idempotencyKey = (string) $request->header('Idempotency-Key', '');

        return $this->cardProductAuthorization->begin($user, 'reveal_mint', [
            'card_id' => $cardId,
        ], $idempotencyKey);
    }

    public function updateControls(UpdateCardControlsRequest $request, string $cardId): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        Card::where('id', $cardId)->where('user_id', $user->id)->firstOrFail();

        $idempotencyKey = (string) $request->header('Idempotency-Key', '');

        return $this->cardProductAuthorization->begin($user, 'update_controls', [
            'card_id'        => $cardId,
            'controls_patch' => [
                'per_transaction_limit' => $request->validated('per_transaction_limit'),
                'daily_limit'           => $request->validated('daily_limit'),
                'monthly_limit'         => $request->validated('monthly_limit'),
                'online_enabled'        => $request->validated('online_enabled'),
                'international_enabled' => $request->validated('international_enabled'),
                'atm_enabled'           => $request->validated('atm_enabled'),
                'contactless_enabled'   => $request->validated('contactless_enabled'),
                'blocked_mcc_groups'    => $request->validated('blocked_mcc_groups'),
            ],
        ], $idempotencyKey);
    }

    public function freeze(CardFreezeRequest $request, string $cardId): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        Card::where('id', $cardId)->where('user_id', $user->id)->firstOrFail();

        $idempotencyKey = (string) $request->header('Idempotency-Key', '');

        return $this->cardProductAuthorization->begin($user, 'freeze', [
            'card_id' => $cardId,
            'reason'  => $request->validated('reason'),
        ], $idempotencyKey);
    }

    public function unfreeze(Request $request, string $cardId): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        Card::where('id', $cardId)->where('user_id', $user->id)->firstOrFail();

        $idempotencyKey = (string) $request->header('Idempotency-Key', '');

        return $this->cardProductAuthorization->begin($user, 'unfreeze', [
            'card_id' => $cardId,
        ], $idempotencyKey);
    }

    public function cancel(Request $request, string $cardId): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        Card::where('id', $cardId)->where('user_id', $user->id)->firstOrFail();

        $idempotencyKey = (string) $request->header('Idempotency-Key', '');

        return $this->cardProductAuthorization->begin($user, 'cancel', [
            'card_id' => $cardId,
        ], $idempotencyKey);
    }

    public function replace(CardReplaceRequest $request, string $cardId): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        Card::where('id', $cardId)->where('user_id', $user->id)->firstOrFail();

        $idempotencyKey = (string) $request->header('Idempotency-Key', '');

        return $this->cardProductAuthorization->begin($user, 'replace', [
            'card_id' => $cardId,
            'reason'  => $request->validated('reason'),
        ], $idempotencyKey);
    }
}
