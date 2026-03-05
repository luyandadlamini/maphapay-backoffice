@props([
    'label',
    'value',
    'trend' => null,
    'trendDirection' => 'up',
    'chipLabel' => 'Actual',
    'borderColor' => 'foodo-primary',
])

<div class="bg-white rounded-2xl border border-stone-200 shadow-sm p-4 border-t-4 border-t-{{ $borderColor }}">
    <div class="flex justify-between items-center mb-3">
        <span class="text-[10px] font-black text-stone-400 uppercase tracking-widest">{{ $label }}</span>
        <span class="px-2.5 py-1 rounded-md text-[10px] font-black uppercase tracking-wider leading-none
            {{ $chipLabel === 'Actual' ? 'bg-green-100 text-green-800' : 'bg-stone-100 text-stone-600' }}">
            {{ $chipLabel }}
        </span>
    </div>
    <div class="text-2xl font-black text-foodo-text tracking-tight">{{ $value }}</div>
    @if($trend)
        <div class="mt-2 flex items-center gap-1 text-[11px] font-bold
            {{ $trendDirection === 'up' ? 'text-emerald-600' : 'text-rose-500' }}">
            <span class="material-symbols-outlined text-sm foodo-icon">
                {{ $trendDirection === 'up' ? 'trending_up' : 'trending_down' }}
            </span>
            {{ $trend }}
        </div>
    @endif
</div>
