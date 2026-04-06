<?php

declare(strict_types=1);

namespace App\Domain\Account\Support;

use App\Domain\Account\Models\TransactionProjection;
use App\Domain\Mobile\Models\BudgetCategory;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class TransactionClassification
{
    /**
     * @param  array<string, mixed>  $metadata
     * @return array{
     *   direction: 'in'|'out'|'none',
     *   analytics_bucket: 'income'|'expense'|'transfer'|'savings',
     *   budget_eligible: bool,
     *   source_domain: string,
     *   system_category_slug: string,
     *   category_label: string,
     *   editable_category: bool
     * }
     */
    public static function defaults(string $type, ?string $subtype = null, array $metadata = []): array
    {
        $direction = match ($type) {
            'deposit', 'transfer_in' => 'in',
            'withdrawal', 'transfer_out' => 'out',
            default => 'none',
        };

        $normalizedSubtype = (string) ($subtype ?? '');
        $source = (string) ($metadata['source'] ?? '');

        if ($source === 'pocket_transfer' || Str::startsWith($normalizedSubtype, 'pocket_')) {
            return [
                'direction'            => $direction,
                'analytics_bucket'     => 'savings',
                'budget_eligible'      => false,
                'source_domain'        => 'savings',
                'system_category_slug' => 'savings_transfer',
                'category_label'       => 'Savings transfer',
                'editable_category'    => false,
            ];
        }

        if (in_array($normalizedSubtype, ['send_money', 'request_money_accept'], true) || in_array($type, ['transfer_in', 'transfer_out'], true)) {
            return [
                'direction'            => $direction,
                'analytics_bucket'     => $direction === 'out' ? 'expense' : 'income',
                'budget_eligible'      => $direction === 'out',
                'source_domain'        => 'p2p',
                'system_category_slug' => 'peer_transfer',
                'category_label'       => 'Peer transfer',
                'editable_category'    => $direction === 'out',
            ];
        }

        if (str_contains($normalizedSubtype, 'cash_out')) {
            return [
                'direction'            => $direction,
                'analytics_bucket'     => 'expense',
                'budget_eligible'      => false,
                'source_domain'        => 'cash_out',
                'system_category_slug' => 'cash_out',
                'category_label'       => 'Cash out',
                'editable_category'    => false,
            ];
        }

        if (str_contains($normalizedSubtype, 'merchant') || str_contains($normalizedSubtype, 'pay')) {
            return [
                'direction'            => $direction,
                'analytics_bucket'     => $direction === 'out' ? 'expense' : 'income',
                'budget_eligible'      => $direction === 'out',
                'source_domain'        => 'merchant_payment',
                'system_category_slug' => $direction === 'out' ? 'bills' : 'income',
                'category_label'       => $direction === 'out' ? 'Bills' : 'Income',
                'editable_category'    => $direction === 'out',
            ];
        }

        $systemCategory = $direction === 'out' ? 'other' : 'income';

        return [
            'direction'            => $direction,
            'analytics_bucket'     => $direction === 'out' ? 'expense' : 'income',
            'budget_eligible'      => $direction === 'out',
            'source_domain'        => $source !== '' ? $source : 'wallet',
            'system_category_slug' => $systemCategory,
            'category_label'       => self::labelForCategorySlug($systemCategory),
            'editable_category'    => $direction === 'out',
        ];
    }

    /**
     * @return array{
     *   direction: 'in'|'out'|'none',
     *   analytics_bucket: string,
     *   budget_eligible: bool,
     *   source_domain: string,
     *   category_slug: string,
     *   category_label: string,
     *   category_source: 'system'|'user',
     *   editable_category: bool
     * }
     */
    public static function forProjection(TransactionProjection $transaction): array
    {
        $metadata = is_array($transaction->metadata) ? $transaction->metadata : [];
        $defaults = self::defaults(
            type: (string) $transaction->type,
            subtype: $transaction->subtype,
            metadata: $metadata,
        );

        $effectiveSlug = (string) ($transaction->effective_category_slug ?: $transaction->system_category_slug ?: $defaults['system_category_slug']);
        $source = $transaction->user_category_slug ? 'user' : (string) ($transaction->categorization_source ?: 'system');

        return [
            'direction'         => $defaults['direction'],
            'analytics_bucket'  => $transaction->analytics_bucket ?: $defaults['analytics_bucket'],
            'budget_eligible'   => $transaction->budget_eligible ?? $defaults['budget_eligible'],
            'source_domain'     => $transaction->source_domain ?: $defaults['source_domain'],
            'category_slug'     => $effectiveSlug,
            'category_label'    => self::labelForCategorySlug($effectiveSlug, $transaction->account?->user_uuid),
            'category_source'   => $source === 'user' ? 'user' : 'system',
            'editable_category' => $defaults['editable_category'],
        ];
    }

    public static function labelForCategorySlug(string $slug, ?string $userUuid = null): string
    {
        if ($slug === '') {
            return 'Other';
        }

        if ($userUuid !== null && class_exists(BudgetCategory::class) && Schema::hasTable('budget_categories')) {
            $category = BudgetCategory::query()
                ->where('user_uuid', $userUuid)
                ->where('slug', $slug)
                ->first();

            if ($category instanceof BudgetCategory) {
                return $category->name;
            }
        }

        return match ($slug) {
            'peer_transfer'    => 'Peer transfer',
            'savings_transfer' => 'Savings transfer',
            'cash_out'         => 'Cash out',
            'bills'            => 'Bills',
            'food'             => 'Food',
            'transport'        => 'Transport',
            'shopping'         => 'Shopping',
            'entertainment'    => 'Entertainment',
            'health'           => 'Health',
            'education'        => 'Education',
            'income'           => 'Income',
            default            => Str::of($slug)->replace('_', ' ')->headline()->toString(),
        };
    }
}
