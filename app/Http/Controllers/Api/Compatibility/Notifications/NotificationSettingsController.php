<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Compatibility\Notifications;

use App\Domain\Mobile\Models\MobileNotificationPreference;
use App\Domain\Mobile\Services\NotificationPreferenceService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationSettingsController extends Controller
{
    public function __construct(
        private readonly NotificationPreferenceService $preferenceService,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'status' => 'success',
            'remark' => 'notification_settings',
            'data'   => $this->compactSettings($this->preferenceService->getUserPreferences($user)),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'transactions' => ['required', 'boolean'],
            'promotions'   => ['required', 'boolean'],
            'security'     => ['required', 'boolean'],
            'social'       => ['required', 'boolean'],
        ]);

        $this->preferenceService->updatePreferences($user, $this->expandedSettings($validated));

        return response()->json([
            'status' => 'success',
            'remark' => 'notification_settings_updated',
            'data'   => $this->compactSettings($this->preferenceService->getUserPreferences($user)),
        ]);
    }

    /**
     * @param array<string, array{type: string, description: string, push_enabled: bool, email_enabled: bool}> $preferences
     * @return array<string, bool>
     */
    private function compactSettings(array $preferences): array
    {
        $allEnabled = static fn (array $types): bool => collect($types)
            ->every(fn (string $type): bool => (bool) ($preferences[$type]['push_enabled'] ?? false));

        return [
            'transactions' => $allEnabled([
                MobileNotificationPreference::TYPE_TRANSACTION_RECEIVED,
                MobileNotificationPreference::TYPE_TRANSACTION_SENT,
            ]),
            'promotions' => (bool) ($preferences[MobileNotificationPreference::TYPE_MARKETING]['push_enabled'] ?? false),
            'security'   => $allEnabled([
                MobileNotificationPreference::TYPE_LOW_BALANCE,
                MobileNotificationPreference::TYPE_SECURITY_LOGIN,
                MobileNotificationPreference::TYPE_SECURITY_DEVICE,
            ]),
            'social' => (bool) ($preferences[MobileNotificationPreference::TYPE_SYSTEM_UPDATE]['push_enabled'] ?? true),
        ];
    }

    /**
     * @param array{transactions: bool, promotions: bool, security: bool, social: bool} $validated
     * @return array<string, array{push_enabled: bool, email_enabled: bool}>
     */
    private function expandedSettings(array $validated): array
    {
        return [
            MobileNotificationPreference::TYPE_TRANSACTION_RECEIVED => [
                'push_enabled'  => $validated['transactions'],
                'email_enabled' => false,
            ],
            MobileNotificationPreference::TYPE_TRANSACTION_SENT => [
                'push_enabled'  => $validated['transactions'],
                'email_enabled' => false,
            ],
            MobileNotificationPreference::TYPE_MARKETING => [
                'push_enabled'  => $validated['promotions'],
                'email_enabled' => false,
            ],
            MobileNotificationPreference::TYPE_LOW_BALANCE => [
                'push_enabled'  => $validated['security'],
                'email_enabled' => $validated['security'],
            ],
            MobileNotificationPreference::TYPE_SECURITY_LOGIN => [
                'push_enabled'  => $validated['security'],
                'email_enabled' => $validated['security'],
            ],
            MobileNotificationPreference::TYPE_SECURITY_DEVICE => [
                'push_enabled'  => $validated['security'],
                'email_enabled' => $validated['security'],
            ],
            MobileNotificationPreference::TYPE_SYSTEM_UPDATE => [
                'push_enabled'  => $validated['social'],
                'email_enabled' => false,
            ],
        ];
    }
}
