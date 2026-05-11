<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Http\Controllers;

use App\Domain\CardIssuance\Models\Card;
use App\Domain\CardSubscriptions\Http\Requests\CardFreezeRequest;
use App\Domain\CardSubscriptions\Http\Requests\CardReplaceRequest;
use App\Domain\CardSubscriptions\Http\Requests\CreateVirtualCardRequest;
use App\Domain\CardSubscriptions\Http\Requests\UpdateCardControlsRequest;
use App\Domain\CardSubscriptions\Http\Resources\CardResource;
use App\Domain\CardSubscriptions\Services\CardAuditService;
use App\Domain\CardSubscriptions\Services\CardLifecycleService;
use App\Domain\CardSubscriptions\Services\CardRevealService;
use App\Domain\CardSubscriptions\Services\CardSubscriptionService;
use App\Domain\CardSubscriptions\ValueObjects\CardControlsInput;
use App\Domain\CardSubscriptions\ValueObjects\CreateVirtualCardInput;
use App\Domain\CardSubscriptions\ValueObjects\ReplacementReason;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CardController extends Controller
{
    public function __construct(
        private readonly CardLifecycleService $lifecycleService,
        private readonly CardRevealService $revealService,
        private readonly CardSubscriptionService $subscriptionService,
        private readonly CardAuditService $auditService
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $cards = Card::where('user_id', $user->id)->latest()->get();
        return CardResource::collection($cards);
    }

    public function storeVirtual(CreateVirtualCardRequest $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $subscription = $this->subscriptionService->getCurrent($user);

        if (!$subscription) {
            abort(403, 'Active subscription required.');
        }

        $input = new CreateVirtualCardInput(
            controls: CardControlsInput::fromArray([
                'limits' => [
                    'per_transaction_cents' => (int) ($request->validated('controls.per_transaction_limit') * 100),
                    'daily_cents' => (int) ($request->validated('controls.daily_limit') * 100),
                    'monthly_cents' => (int) ($request->validated('controls.monthly_limit') * 100),
                ],
                'online_enabled' => $request->validated('controls.online_enabled'),
                'international_enabled' => $request->validated('controls.international_enabled'),
            ]),
            label: $request->validated('nickname')
        );

        $card = $this->lifecycleService->createVirtualCard($user, $subscription, $input);

        return (new CardResource($card))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, string $cardId): CardResource
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $card = Card::where('id', $cardId)->where('user_id', $user->id)->firstOrFail();

        return new CardResource($card);
    }

    public function reveal(Request $request, string $cardId): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $card = Card::where('id', $cardId)->where('user_id', $user->id)->firstOrFail();

        if (!$request->hasHeader('X-Mobile-Trust')) {
            abort(403, 'Step-up authentication required.');
        }

        $result = $this->revealService->mintRevealUrl($user, $card);

        $this->auditService->recordCardEvent('reveal_requested', $card, null, [
            'expires_at' => $result->expiresAt->format('c')
        ]);

        return response()->json(['url' => $result->url, 'expires_at' => $result->expiresAt->format('c')]);
    }

    public function updateControls(UpdateCardControlsRequest $request, string $cardId): CardResource
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $card = Card::where('id', $cardId)->where('user_id', $user->id)->firstOrFail();

        $input = CardControlsInput::fromArray([
            'limits' => [
                'per_transaction_cents' => $request->validated('controls.per_transaction_limit'),
                'daily_cents' => $request->validated('controls.daily_limit'),
                'monthly_cents' => $request->validated('controls.monthly_limit'),
            ],
            'online_enabled' => $request->validated('controls.online_enabled'),
            'international_enabled' => $request->validated('controls.international_enabled'),
            'atm_enabled' => $request->validated('controls.atm_enabled'),
            'contactless_enabled' => $request->validated('controls.contactless_enabled'),
            'blocked_mcc_groups' => $request->validated('controls.blocked_mcc_groups')
        ]);

        $card = $this->lifecycleService->updateControls($user, $card, $input);

        return new CardResource($card);
    }

    public function freeze(CardFreezeRequest $request, string $cardId): CardResource
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $card = Card::where('id', $cardId)->where('user_id', $user->id)->firstOrFail();

        $card = $this->lifecycleService->freezeCard($user, $card, $request->validated('reason', 'user_requested'));

        return new CardResource($card);
    }

    public function unfreeze(Request $request, string $cardId): CardResource
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $card = Card::where('id', $cardId)->where('user_id', $user->id)->firstOrFail();

        $card = $this->lifecycleService->unfreezeCard($user, $card);

        return new CardResource($card);
    }

    public function cancel(Request $request, string $cardId): CardResource
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $card = Card::where('id', $cardId)->where('user_id', $user->id)->firstOrFail();

        $card = $this->lifecycleService->cancelCard($user, $card, 'user_requested');

        return new CardResource($card);
    }

    public function replace(CardReplaceRequest $request, string $cardId): CardResource
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $card = Card::where('id', $cardId)->where('user_id', $user->id)->firstOrFail();

        $reasonEnum = ReplacementReason::tryFrom($request->validated('reason')) ?? ReplacementReason::DAMAGED;

        $newCard = $this->lifecycleService->replaceCard($user, $card, $reasonEnum);

        return new CardResource($newCard);
    }
}
