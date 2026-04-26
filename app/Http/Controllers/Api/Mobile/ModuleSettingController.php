<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class ModuleSettingController extends Controller
{
    /**
     * Get all module settings for the authenticated user.
     *
     * Returns a flat key-value map of module flags (e.g. donation, airtime, native_tab_bar)
     * used by the mobile app to toggle features on/off via OTA.
     *
     * The mobile app's useModuleSettings() hook calls this endpoint and
     * treats any missing key as true (optimistic default).
     */
    public function index(): JsonResponse
    {
        $settings = Cache::remember(
            'settings.group.module_setting',
            now()->addMinutes(5),
            fn () => Setting::where('group', 'module_setting')
                ->pluck('value', 'key')
                ->toArray(),
        );

        return response()->json([
            'status' => true,
            'data'   => $settings,
        ]);
    }
}