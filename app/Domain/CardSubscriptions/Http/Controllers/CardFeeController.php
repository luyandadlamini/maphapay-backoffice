<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Http\Controllers;

use App\Domain\CardSubscriptions\Http\Requests\CardFeePreviewRequest;
use App\Domain\CardSubscriptions\Http\Resources\CardFeePreviewResource;
use App\Domain\CardSubscriptions\Services\CardFeeService;
use App\Domain\CardSubscriptions\ValueObjects\CardFeePreviewInput;
use App\Http\Controllers\Controller;

class CardFeeController extends Controller
{
    public function __construct(
        private readonly CardFeeService $feeService
    ) {}

    public function preview(CardFeePreviewRequest $request): CardFeePreviewResource
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $input = CardFeePreviewInput::transaction(
            amountCents: (int) round(((float) $request->validated('amount')) * 100),
            currency: $request->validated('currency'),
            transactionType: $request->validated('transaction_type')
        );

        $preview = $this->feeService->previewTransaction(
            user: $user,
            input: $input
        );

        return new CardFeePreviewResource($preview);
    }
}
