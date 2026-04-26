<x-filament-panels::page>
    @php
        $bundle = $this->getStreamsActivity();
    @endphp

    <div class="mx-auto max-w-6xl space-y-8">
        <div class="flex flex-wrap items-center gap-3">
            <p class="text-sm text-gray-600 dark:text-gray-400">
                {{ __('Each card is one stream. P2P and cash-out show projection activity when mapped; other streams stay pending until finance signs rules.') }}
                <span class="text-gray-400 dark:text-gray-500">·</span>
                {{ __('Window: last :days days.', ['days' => (int) config('maphapay.revenue_streams_default_activity_window_days', 30)]) }}
            </p>
            @if (config('maphapay.revenue_activity_stub_reader') && ! app()->isProduction())
                <x-filament::badge color="warning" size="lg">
                    {{ __('STUB reader — not your ledger') }}
                </x-filament::badge>
            @endif
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2 xl:grid-cols-3">
            @foreach (\App\Domain\Analytics\WalletRevenueStream::cases() as $stream)
                @php
                    $evidenceUrl = \App\Filament\Admin\Support\WalletRevenueStreamEvidence::adminUrl($stream);
                    $metrics = $bundle->streamMetrics[$stream->value] ?? \App\Domain\Analytics\DTO\StreamActivityMetricsDto::pending();
                @endphp
                <div
                    class="flex flex-col rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
                    data-stream-card="{{ $stream->value }}"
                >
                    <div class="flex flex-wrap items-start justify-between gap-2 border-b border-gray-100 pb-4 dark:border-white/10">
                        <div>
                            <h3 class="text-base font-semibold tracking-tight text-gray-900 dark:text-white">
                                {{ $stream->label() }}
                            </h3>
                            <p class="mt-1 text-sm leading-relaxed text-gray-600 dark:text-gray-400">
                                {{ $stream->description() }}
                            </p>
                        </div>
                        <x-filament::badge color="gray">
                            <span class="font-mono text-xs">{{ $stream->value }}</span>
                        </x-filament::badge>
                    </div>

                    <div class="mt-5 flex flex-1 flex-col gap-4">
                        @if ($metrics->isMapped())
                            <div class="rounded-lg bg-gray-50/80 p-4 dark:bg-white/5" data-mapped="1">
                                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    {{ __('Projection activity') }}
                                </p>
                                <p class="mt-1 text-3xl font-semibold tracking-tight tabular-nums text-gray-900 dark:text-white">
                                    {{ number_format($metrics->transactionCount) }}
                                </p>
                                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                    {{ __('projection rows in window') }}
                                </p>
                                @if ($metrics->mappingNote)
                                    <p class="mt-3 text-sm leading-relaxed text-gray-600 dark:text-gray-400">
                                        {{ $metrics->mappingNote }}
                                    </p>
                                @endif
                                @if ($metrics->lastActivityAtIso)
                                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                        {{ __('Last activity (UTC): :ts', ['ts' => $metrics->lastActivityAtIso]) }}
                                    </p>
                                @endif
                                @if ($metrics->volumesByAsset !== [])
                                    <div class="mt-4 border-t border-gray-200/80 pt-4 dark:border-white/10">
                                        <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                            {{ __('Per-asset (minor units)') }}
                                        </p>
                                        <ul class="space-y-1.5 text-sm text-gray-800 dark:text-gray-200">
                                            @foreach ($metrics->volumesByAsset as $asset => $minor)
                                                <li class="flex justify-between gap-4 tabular-nums">
                                                    <span class="font-mono text-gray-600 dark:text-gray-400">{{ $asset }}</span>
                                                    <span>{{ number_format($minor) }}</span>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endif
                            </div>
                        @else
                            <div class="flex flex-1 flex-col justify-center rounded-lg bg-amber-50/60 px-4 py-6 dark:bg-amber-950/20" data-mapped="0">
                                <x-filament::badge color="warning" class="w-fit">
                                    {{ __('Pending finance mapping') }}
                                </x-filament::badge>
                                <p class="mt-3 text-sm leading-relaxed text-gray-700 dark:text-gray-300">
                                    {{ __('No v1 projection mapping for this stream yet.') }}
                                </p>
                            </div>
                        @endif

                        <div class="mt-auto pt-2">
                            <x-filament::button tag="a" href="{{ $evidenceUrl }}" size="sm" outlined>
                                {{ __('View evidence') }}
                            </x-filament::button>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</x-filament-panels::page>
