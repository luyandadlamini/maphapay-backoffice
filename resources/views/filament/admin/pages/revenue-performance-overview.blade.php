<x-filament-panels::page>
    @php
        $activity = $this->getActivityResult();
        $overview = $activity->overview;
    @endphp

    <div class="mx-auto max-w-6xl space-y-8">
        <p class="text-sm leading-relaxed text-gray-600 dark:text-gray-400">
            {{ __('Figures below are wallet activity from projections (minor units per asset). They are not recognized revenue until finance publishes rules (ADR-006).') }}
        </p>

        {{ $this->form }}

        <x-filament::section>
            <x-slot name="heading">
                {{ __('Activity snapshot') }}
            </x-slot>
            <x-slot name="description">
                {{ __('Completed rows in transaction_projections where type is transfer or withdrawal.') }}
            </x-slot>

            @if (config('maphapay.revenue_activity_stub_reader') && ! app()->isProduction())
                <div class="mb-5">
                    <x-filament::badge color="warning" size="lg">
                        {{ __('STUB reader — not your ledger') }}
                    </x-filament::badge>
                </div>
            @endif

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @include('filament.admin.partials.revenue-kpi-tile', [
                    'label' => __('Reporting currency'),
                    'value' => $this->reportingCurrencyDisplay(),
                    'sub'   => __('Display label only. Per-asset sums stay separate below.'),
                ])
                @include('filament.admin.partials.revenue-kpi-tile', [
                    'label' => __('Mapped rows'),
                    'value' => number_format($overview->transactionCount),
                    'sub'   => __('Transfer + withdrawal, status completed, in range.'),
                ])
                @include('filament.admin.partials.revenue-kpi-tile', [
                    'label' => __('Last activity (UTC)'),
                    'value' => $overview->lastActivityAtIso ?? '—',
                    'sub'   => $overview->lastActivityAtIso ? __('Latest projection row in this window.') : null,
                ])
            </div>

            <div class="mt-8">
                <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ __('Per-asset volume (minor units)') }}
                </p>
                @if ($overview->volumesByAsset === [])
                    <div class="rounded-xl bg-gray-50 px-6 py-10 text-center dark:bg-white/5">
                        <p class="text-sm text-gray-600 dark:text-gray-400">
                            {{ __('No completed transfer or withdrawal rows in this range for any asset.') }}
                        </p>
                    </div>
                @else
                    <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 text-left dark:divide-white/10">
                                <thead>
                                    <tr class="bg-gray-50/90 dark:bg-white/5">
                                        <th class="px-4 py-3 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 sm:px-6">
                                            {{ __('Asset') }}
                                        </th>
                                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 sm:px-6">
                                            {{ __('Sum (minor)') }}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                                    @foreach ($overview->volumesByAsset as $asset => $minor)
                                        <tr class="hover:bg-gray-50/80 dark:hover:bg-white/5">
                                            <td class="px-4 py-3.5 sm:px-6">
                                                <x-filament::badge color="gray">
                                                    <span class="font-mono">{{ $asset }}</span>
                                                </x-filament::badge>
                                            </td>
                                            <td class="px-4 py-3.5 text-right text-sm tabular-nums text-gray-900 dark:text-gray-100 sm:px-6">
                                                {{ number_format($minor) }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">
                {{ __('Trend') }}
            </x-slot>
            <x-slot name="description">
                {{ __('Reserved for a finance-approved time series after mart grain is fixed.') }}
            </x-slot>
            <div class="rounded-xl bg-gray-50 px-6 py-14 text-center text-sm leading-relaxed text-gray-600 dark:bg-white/5 dark:text-gray-400">
                {{ __('Chart placeholder — connect post-mart aggregates when ready.') }}
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">
                {{ __('Targets needing review') }}
            </x-slot>
            <x-slot name="description">
                {{ __('Rows with amount ≤ 0. Open a target to fix in Targets & forecasts.') }}
            </x-slot>

            @if ($activity->anomalousTargets === [])
                <div class="rounded-xl bg-gray-50 px-6 py-10 text-center dark:bg-white/5">
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        {{ __('No non-positive targets in this tenant.') }}
                    </p>
                </div>
            @else
                <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-left dark:divide-white/10">
                            <thead>
                                <tr class="bg-gray-50/90 dark:bg-white/5">
                                    <th class="px-4 py-3 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 sm:px-6">
                                        {{ __('Month') }}
                                    </th>
                                    <th class="px-4 py-3 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 sm:px-6">
                                        {{ __('Stream') }}
                                    </th>
                                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 sm:px-6">
                                        {{ __('Amount') }}
                                    </th>
                                    <th class="px-4 py-3 sm:px-6"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                                @foreach ($activity->anomalousTargets as $row)
                                    <tr class="hover:bg-gray-50/80 dark:hover:bg-white/5">
                                        <td class="px-4 py-3.5 text-sm tabular-nums text-gray-900 dark:text-gray-100 sm:px-6">
                                            {{ $row->periodMonth }}
                                        </td>
                                        <td class="px-4 py-3.5 sm:px-6">
                                            <x-filament::badge color="gray">
                                                <span class="font-mono">{{ $row->streamCode }}</span>
                                            </x-filament::badge>
                                        </td>
                                        <td class="px-4 py-3.5 text-right sm:px-6">
                                            <x-filament::badge color="warning">
                                                {{ $row->amount }} {{ $row->currency }}
                                            </x-filament::badge>
                                        </td>
                                        <td class="px-4 py-3.5 text-right sm:px-6">
                                            <x-filament::button
                                                tag="a"
                                                size="sm"
                                                outlined
                                                :href="\App\Filament\Admin\Resources\RevenueTargetResource::getUrl('edit', ['record' => $row->id])"
                                            >
                                                {{ __('Open') }}
                                            </x-filament::button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
