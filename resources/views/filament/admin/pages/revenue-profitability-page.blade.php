<x-filament-panels::page>
    @php
        $cor = $this->getCorMarginBridgeState();
    @endphp

    <div class="mx-auto max-w-6xl space-y-8">
        <x-filament::section>
            <x-slot name="heading">
                {{ __('Margin bridge') }}
            </x-slot>
            <x-slot name="description">
                {{ __('Reserved cells bind to CorMarginBridgeDataPort. Values are null until a mart or finance feed supplies them — no synthetic margins.') }}
            </x-slot>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                @foreach ($cor->slots as $slot)
                    <div
                        class="overflow-hidden rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 sm:p-6"
                        data-cor-slot="{{ $slot->id }}"
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

        @if (! $cor->featureEnabled)
            <x-filament::section>
                <x-slot name="heading">
                    {{ __('Status') }}
                </x-slot>
                <x-slot name="description">
                    {{ __('COR live tier is off. The grid above still shows slot labels for engineering handoff.') }}
                </x-slot>

                <div class="space-y-4">
                    <div>
                        <x-filament::badge color="warning" size="lg">
                            {{ __('Blocked — COR inputs not connected') }}
                        </x-filament::badge>
                    </div>
                    <p class="text-sm leading-relaxed text-gray-700 dark:text-gray-300">
                        {{ __('Nothing in the grid is calculated in v1.') }}
                    </p>
                    <ul class="list-disc space-y-2 pl-5 text-sm leading-relaxed text-gray-700 dark:text-gray-300">
                        <li>
                            {{ __('Authoritative COR line items per wallet stream (mapped to') }}
                            <code class="rounded bg-white/80 px-1 py-0.5 font-mono text-xs dark:bg-black/30">WalletRevenueStream</code>{{ __(').') }}
                        </li>
                        <li>{{ __('Allocation rules for shared infrastructure, MoMo rails, and card scheme costs.') }}</li>
                        <li>{{ __('Time-aligned revenue recognition vs cash movement (where they diverge).') }}</li>
                        <li>{{ __('Signed-off bridge: gross → pass-through → COR → contribution margin.') }}</li>
                    </ul>
                    <p class="text-sm leading-relaxed text-gray-600 dark:text-gray-400">
                        {{ __('When finance publishes COR sources and you bind CorMarginBridgeDataPort, enable the env flag and values fill the cells above.') }}
                    </p>
                    <p class="text-xs text-gray-400 dark:text-gray-500">
                        {{ __('Local wiring: MAPHAPAY_REVENUE_COR_BRIDGE_ENABLED + MAPHAPAY_REVENUE_COR_BRIDGE_STUB_READER (non-production).') }}
                    </p>
                </div>
            </x-filament::section>
        @else
            <x-filament::section>
                <x-slot name="heading">
                    {{ __('COR live tier') }}
                </x-slot>

                @if (! $cor->dataAvailable)
                    <x-slot name="description">
                        {{ __('Port is enabled but returned no snapshot for this tenant yet.') }}
                    </x-slot>
                    <div class="rounded-xl bg-gray-50 px-6 py-10 text-center dark:bg-white/5">
                        <p class="text-base font-semibold text-gray-900 dark:text-white">
                            {{ __('Awaiting first COR snapshot') }}
                        </p>
                        <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                            {{ __('The grid above will populate when CorMarginBridgeDataPort returns values.') }}
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
