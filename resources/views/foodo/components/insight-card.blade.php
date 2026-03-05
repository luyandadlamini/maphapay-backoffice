@props([
    'severity' => 'rose',
    'icon' => 'warning',
    'title',
    'chipLabel' => 'Urgent',
    'recommendation' => null,
])

@php
    $colors = [
        'rose'    => ['border' => 'border-l-rose-500', 'iconBg' => 'bg-rose-100 text-rose-600', 'chip' => 'bg-rose-100 text-rose-700', 'recBg' => 'bg-rose-50 border-rose-100', 'recLabel' => 'text-rose-400'],
        'purple'  => ['border' => 'border-l-purple-500', 'iconBg' => 'bg-purple-100 text-purple-600', 'chip' => 'bg-purple-100 text-purple-700', 'recBg' => 'bg-purple-50 border-purple-100', 'recLabel' => 'text-purple-400'],
        'emerald' => ['border' => 'border-l-emerald-500', 'iconBg' => 'bg-emerald-100 text-emerald-600', 'chip' => 'bg-emerald-100 text-emerald-700', 'recBg' => 'bg-emerald-50 border-emerald-100', 'recLabel' => 'text-emerald-500'],
        'amber'   => ['border' => 'border-l-amber-500', 'iconBg' => 'bg-amber-100 text-amber-600', 'chip' => 'bg-amber-100 text-amber-700', 'recBg' => 'bg-amber-50 border-amber-100', 'recLabel' => 'text-amber-400'],
    ];
    $c = $colors[$severity] ?? $colors['rose'];
@endphp

<div class="bg-white p-5 rounded-xl border-l-4 {{ $c['border'] }} shadow-sm group hover:shadow-md transition-all">
    <div class="flex justify-between items-start mb-2">
        <div class="flex items-center gap-2">
            <span class="w-6 h-6 rounded-md {{ $c['iconBg'] }} flex items-center justify-center">
                <span class="material-symbols-outlined text-sm foodo-icon">{{ $icon }}</span>
            </span>
            <h3 class="font-bold text-stone-800 text-sm">{{ $title }}</h3>
        </div>
        <span class="px-2.5 py-1 rounded-md text-[10px] font-black uppercase tracking-wider leading-none {{ $c['chip'] }}">{{ $chipLabel }}</span>
    </div>

    @if($slot->isNotEmpty())
        <p class="text-xs text-stone-600 mb-3 leading-relaxed">{{ $slot }}</p>
    @endif

    @if($recommendation)
        <div class="{{ $c['recBg'] }} p-3 rounded-lg border">
            <p class="text-[10px] font-black {{ $c['recLabel'] }} uppercase tracking-widest mb-1 flex items-center gap-1">
                <span class="material-symbols-outlined text-xs foodo-icon">lightbulb</span> Recommendation
            </p>
            <p class="text-xs font-semibold text-stone-700">{{ $recommendation }}</p>
        </div>
    @endif
</div>
