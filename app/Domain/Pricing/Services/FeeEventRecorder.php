<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Services;

use App\Domain\Pricing\Enums\PricingCategory;
use App\Domain\Pricing\Models\FeeEvent;
use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Pricing\ValueObjects\FeeBreakdown;
use Illuminate\Support\Carbon;
use RuntimeException;

class FeeEventRecorder
{
    public function record(
        string $transactionUuid,
        FeeBreakdown $breakdown,
        PricingRule $rule,
        int $userId,
        ?string $sourceDomain = null,
        ?string $experimentArm = null,
    ): FeeEvent {
        $rule->loadMissing('product');

        $product = $rule->product;

        if ($product === null) {
            throw new RuntimeException("PricingRule {$rule->id} has no associated product.");
        }

        $category = $product->category instanceof PricingCategory
            ? $product->category->value
            : (string) $product->category;

        $key = substr(hash('sha256', "{$transactionUuid}:{$rule->id}"), 0, 120);

        return FeeEvent::firstOrCreate(
            ['idempotency_key' => $key],
            [
                'transaction_uuid' => $transactionUuid,
                'pricing_rule_id'  => $rule->id,
                'product_code'     => $product->code,
                'category'         => $category,
                'user_id'          => $userId,
                'segment_id'       => $rule->segment_id,
                'amount_minor'     => $breakdown->totalMinor(),
                'currency'         => $breakdown->currency(),
                'breakdown'        => $breakdown->toArray(),
                'assessed_at'      => Carbon::now(),
                'source_domain'    => $sourceDomain,
                'experiment_arm'   => $experimentArm,
            ],
        );
    }

    public function recordZero(
        string $transactionUuid,
        string $productCode,
        string $category,
        string $currency,
        int $userId,
        ?int $ruleId = null,
    ): FeeEvent {
        $key = substr(hash('sha256', "{$transactionUuid}:zero:{$productCode}"), 0, 120);

        return FeeEvent::firstOrCreate(
            ['idempotency_key' => $key],
            [
                'transaction_uuid' => $transactionUuid,
                'pricing_rule_id'  => $ruleId,
                'product_code'     => $productCode,
                'category'         => $category,
                'user_id'          => $userId,
                'segment_id'       => null,
                'amount_minor'     => 0,
                'currency'         => $currency,
                'breakdown'        => FeeBreakdown::zero($currency)->toArray(),
                'assessed_at'      => Carbon::now(),
                'source_domain'    => null,
                'experiment_arm'   => null,
            ],
        );
    }
}
