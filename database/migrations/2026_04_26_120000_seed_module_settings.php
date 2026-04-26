<?php

declare(strict_types=1);

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

return new class extends Migration
{
    public function up(): void
    {
        $modules = [
            'donation'         => true,
            'education_fee'   => true,
            'microfinance'    => false,
            'airtime'          => true,
            'utility_bill'    => true,
            'bank_transfer'   => true,
            'mobile_recharge' => true,
            'virtual_card'    => true,
            'savings'          => true,
            'rewards'          => true,
            'social_money'    => true,
            'native_tab_bar'  => false,
        ];

        foreach ($modules as $key => $default) {
            Setting::firstOrCreate(
                ['key' => $key, 'group' => 'module_setting'],
                [
                    'value'       => $default,
                    'type'        => 'boolean',
                    'label'       => ucwords(str_replace('_', ' ', $key)),
                    'description' => "Feature toggle for {$key}",
                    'is_public'   => true,
                    'is_encrypted' => false,
                ],
            );
        }

        // Belt-and-suspenders cache clear: Setting::saved already clears
        // per-key and per-group caches, but some edge cases (e.g. firstOrCreate
        // on a fresh DB where no model events fire because the model was new)
        // may not trigger the saved event in all Laravel versions.
        Cache::forget('settings.group.module_setting');
    }

    public function down(): void
    {
        Setting::where('group', 'module_setting')->delete();
        Cache::forget('settings.group.module_setting');
    }
};