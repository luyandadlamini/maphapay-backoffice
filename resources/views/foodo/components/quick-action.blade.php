@props([
    'icon',
    'title',
    'description',
    'color' => 'red',
])

<div class="bg-white p-3 rounded-xl border border-stone-200 flex gap-3 items-center group cursor-pointer hover:border-{{ $color }}-300 transition-colors">
    <div class="w-10 h-10 rounded-lg bg-{{ $color }}-50 text-{{ $color }}-500 flex items-center justify-center shrink-0">
        <span class="material-symbols-outlined foodo-icon">{{ $icon }}</span>
    </div>
    <div class="flex-1">
        <h4 class="text-xs font-bold text-stone-800">{{ $title }}</h4>
        <p class="text-[10px] text-stone-500 leading-tight mt-0.5">{{ $description }}</p>
    </div>
    <span class="material-symbols-outlined text-stone-300 group-hover:text-{{ $color }}-400 text-sm foodo-icon-thin">chevron_right</span>
</div>
