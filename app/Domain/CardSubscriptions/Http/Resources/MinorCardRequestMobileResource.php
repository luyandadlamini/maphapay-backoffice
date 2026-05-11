<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Http\Resources;

use App\Domain\Account\Constants\MinorCardConstants;
use App\Domain\Account\Models\MinorCardRequest;

/**
 * Maps a persisted {@see MinorCardRequest} into the mobile contract
 * (`maphapayrn` `MinorCardRequest` in `cardSubscriptionTypes.ts`).
 */
final class MinorCardRequestMobileResource
{
    private const KHULA_TYPES = [
        'subscribe',
        'change_plan',
        'create_card',
        'replace_card',
        'unfreeze_card',
    ];

    /** @return array<string, mixed> */
    public static function toArray(MinorCardRequest $request): array
    {
        $intent = $request->intent_payload;
        $payload = is_array($intent) && isset($intent['payload']) && is_array($intent['payload'])
            ? $intent['payload']
            : [];

        return [
            'id'                => (string) $request->id,
            'minor_user_id'     => (string) ($request->minorAccount?->user_uuid ?? ''),
            'guardian_user_id'  => '',
            'request_type'      => self::resolveKhulaRequestType($intent),
            'status'            => self::mapStatus($request->status),
            'payload'           => $payload,
            'approval_note'     => null,
            'denial_reason'     => $request->denial_reason,
            'created_at'        => $request->created_at?->toIso8601String() ?? '',
            'resolved_at'       => self::resolveResolvedAt($request),
        ];
    }

    private static function resolveKhulaRequestType(mixed $intent): string
    {
        if (! is_array($intent)) {
            return 'create_card';
        }

        $type = $intent['request_type'] ?? null;
        if (is_string($type) && in_array($type, self::KHULA_TYPES, true)) {
            return $type;
        }

        return 'create_card';
    }

    private static function mapStatus(string $status): string
    {
        return match ($status) {
            MinorCardConstants::STATUS_PENDING_APPROVAL => 'pending',
            MinorCardConstants::STATUS_APPROVED         => 'approved',
            MinorCardConstants::STATUS_DENIED           => 'denied',
            MinorCardConstants::STATUS_EXPIRED          => 'expired',
            default                                     => 'pending',
        };
    }

    private static function resolveResolvedAt(MinorCardRequest $request): ?string
    {
        if ($request->status === MinorCardConstants::STATUS_APPROVED && $request->approved_at !== null) {
            return $request->approved_at->toIso8601String();
        }

        if ($request->status === MinorCardConstants::STATUS_DENIED && $request->updated_at !== null) {
            return $request->updated_at->toIso8601String();
        }

        return null;
    }
}
