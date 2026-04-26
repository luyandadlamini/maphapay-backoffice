<x-filament-panels::page>
    @php
        $unit = $this->getUnitEconomicsState();
    @endphp

    <div class="mx-auto max-w-6xl space-y-8">
        <x-filament::section>
            <x-slot name="heading">
                {{ __('Unit economics') }}
            </x-slot>
            <x-slot name="description">
                {{ __('Reserved cells bind to UnitEconomicsDataPort. Values are null until marketing + finance feeds exist — no demo CAC/LTV.') }}
            </x-slot>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($unit->slots as $slot)
                    <div
                        class="overflow-hidden rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 sm:p-6"
                        data-unit-slot="{{ $slot->id }}"
                    >
                        <p class="text-sm font-medium leading-6 text-gray-600 dark:text-gray-400">
                            {{ $slot->label }}
                        </p>
                        <p class="mt-2 text-2xl font-semibold tracking-tight tabular-nums text-gray-900 dark:text-white sm:text-3xl">
                            {{ $slot->value ?? '—' }}
                        </p>
                        @if ($slot->helper)
                            <p class="mt-3 text-sm leading-relaxed text-gray-500 dark:text-gray-400">
                                {{ $slot->helper }}
                            </p>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        @if (! $unit->featureEnabled)
            <x-filament::section>
                <x-slot name="heading">
                    {{ __('Status') }}
                </x-slot>
                <x-slot name="description">
                    {{ __('Live tier is off. Slots above stay visible for future cohort readers.') }}
                </x-slot>

                <div class="space-y-4">
                    <div>
                        <x-filament::badge color="gray" size="lg">
                            {{ __('Not connected') }}
                        </x-filament::badge>
                    </div>
                    <p class="text-sm leading-relaxed text-gray-600 dark:text-gray-400">
                        {{ __('No acquisition or lifecycle value feeds are configured for this tenant.') }}
                    </p>
                    <p class="text-sm leading-relaxed text-gray-700 dark:text-gray-300">
                        {{ __('Expected inputs (examples—none are active in v1): marketing spend by cohort, attributed sign-ups, wallet revenue attributed to cohort, churn / dormancy signals agreed with finance.') }}
                    </p>
                    <p class="text-sm font-medium text-gray-900 dark:text-white">
                        {{ __('We intentionally do not display sample CAC, LTV, or ratio charts here.') }}
                    </p>
                    <p class="text-sm leading-relaxed text-gray-600 dark:text-gray-400">
                        {{ __('Bind UnitEconomicsDataPort and enable the env flag; the cells above accept real numbers.') }}
                    </p>
                    <p class="text-xs text-gray-400 dark:text-gray-500">
                        {{ __('Local wiring: MAPHAPAY_REVENUE_UNIT_ECONOMICS_ENABLED + MAPHAPAY_REVENUE_UNIT_ECONOMICS_STUB_READER (non-production).') }}
                    </p>
                </div>
            </x-filament::section>
        @else
            <x-filament::section>
                <x-slot name="heading">
                    {{ __('Unit economics live tier') }}
                </x-slot>

                @if (! $unit->dataAvailable)
                    <x-slot name="description">
                        {{ __('Port is enabled but returned no snapshot for this tenant yet.') }}
                    </x-slot>
                    <div class="rounded-xl bg-gray-50 px-6 py-10 text-center dark:bg-white/5">
                        <p class="text-base font-semibold text-gray-900 dark:text-white">
                            {{ __('Awaiting first CAC/LTV snapshot') }}
                        </p>
                        <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                            {{ __('Implement UnitEconomicsDataPort::hasRenderableData() when your cohort mart is ready.') }}
                        </p>
                    </div>
                @else
                    <x-slot name="description">
                        {{ __('Data port reports renderable values for this context.') }}
                    </x-slot>
                    <x-filament::badge color="success" size="lg">
                        {{ __('Live data') }}
                    </x-filament::badge>
                @endif
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
