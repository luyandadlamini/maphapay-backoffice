{{-- Stat tile for revenue pages. Pass: $label, optional $value, optional $sub --}}
<div class="overflow-hidden rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 sm:p-6">
    <p class="text-sm font-medium leading-6 text-gray-600 dark:text-gray-400">
        {{ $label }}
    </p>
    <div class="mt-2 flex flex-col gap-1">
        <p class="text-2xl font-semibold tracking-tight tabular-nums text-gray-900 dark:text-white sm:text-3xl">
            {{ $value ?? '—' }}
        </p>
        @if (! empty($sub ?? null))
            <p class="text-sm leading-relaxed text-gray-500 dark:text-gray-400">
                {{ $sub }}
            </p>
        @endif
    </div>
</div>
