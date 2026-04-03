<x-filament-panels::page>
    <div class="space-y-6">
        <form wire:submit="inspect" class="space-y-4">
            {{ $this->form }}

            <div class="flex justify-end">
                <x-filament::button type="submit" color="primary">
                    Inspect Transaction
                </x-filament::button>
            </div>
        </form>

        @if ($inspection !== null)
            <div class="grid gap-4 lg:grid-cols-2">
                <x-filament::section>
                    <x-slot name="heading">
                        Lookup
                    </x-slot>

                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between gap-4">
                            <dt class="text-gray-500">TRX</dt>
                            <dd class="font-mono text-right text-gray-950 dark:text-white">{{ $inspection['lookup']['trx'] ?? 'n/a' }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-gray-500">Reference</dt>
                            <dd class="font-mono text-right text-gray-950 dark:text-white">{{ $inspection['lookup']['reference'] ?? 'n/a' }}</dd>
                        </div>
                    </dl>
                </x-filament::section>

                <x-filament::section>
                    <x-slot name="heading">
                        Authorization
                    </x-slot>

                    @php($authorization = $inspection['authorized_transaction'] ?? null)
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between gap-4">
                            <dt class="text-gray-500">Remark</dt>
                            <dd class="text-right text-gray-950 dark:text-white">{{ $authorization['remark'] ?? 'n/a' }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-gray-500">Status</dt>
                            <dd class="text-right text-gray-950 dark:text-white">{{ $authorization['status'] ?? 'n/a' }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-gray-500">Verification</dt>
                            <dd class="text-right text-gray-950 dark:text-white">{{ $authorization['verification_type'] ?? 'n/a' }}</dd>
                        </div>
                    </dl>
                </x-filament::section>
            </div>

            <x-filament::section>
                <x-slot name="heading">
                    Timeline
                </x-slot>

                <div class="space-y-3">
                    @forelse ($inspection['timeline'] ?? [] as $event)
                        <div class="rounded-lg border border-gray-200 px-4 py-3 dark:border-white/10">
                            <div class="flex items-center justify-between gap-4">
                                <div class="font-medium text-gray-950 dark:text-white">{{ $event['event'] ?? 'unknown_event' }}</div>
                                <div class="text-xs text-gray-500">{{ $event['at'] ?? 'n/a' }}</div>
                            </div>
                            @if (! empty($event['failure_reason'] ?? null))
                                <div class="mt-2 text-sm text-danger-600 dark:text-danger-400">{{ $event['failure_reason'] }}</div>
                            @endif
                        </div>
                    @empty
                        <div class="text-sm text-gray-500">No lifecycle events found for this lookup.</div>
                    @endforelse
                </div>
            </x-filament::section>

            @if (! empty($inspection['warnings'] ?? []))
                <x-filament::section>
                    <x-slot name="heading">
                        Warnings
                    </x-slot>

                    <div class="space-y-2">
                        @foreach ($inspection['warnings'] as $warning)
                            <div class="rounded-lg border border-warning-200 bg-warning-50 px-4 py-3 text-sm text-warning-800 dark:border-warning-500/30 dark:bg-warning-500/10 dark:text-warning-200">
                                {{ $warning }}
                            </div>
                        @endforeach
                    </div>
                </x-filament::section>
            @endif
        @endif
    </div>
</x-filament-panels::page>
