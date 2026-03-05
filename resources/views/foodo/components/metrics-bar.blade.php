@props(['showAlert' => false, 'alertMessage' => ''])

<div class="bg-foodo-surface-dark text-white p-3 flex items-center justify-between text-xs border-b border-stone-800 shrink-0">
    <div class="flex items-center gap-6">
        <div class="flex items-center gap-2">
            <span class="material-symbols-outlined text-stone-400 text-sm foodo-icon-thin">analytics</span>
            <span class="font-bold">May 2025</span>
        </div>
        <div class="h-4 w-px bg-stone-700"></div>
        <div>
            <span class="text-stone-400 mr-1">EBITDA</span>
            <span class="font-black text-emerald-400">€304,369</span>
            <span class="bg-emerald-500/20 text-emerald-400 px-1.5 py-0.5 rounded ml-1 text-[10px] font-bold">14.4%</span>
        </div>
        <div>
            <span class="text-stone-400 mr-1">YoY</span>
            <span class="font-black text-rose-400">-0.6%</span>
        </div>
        <div>
            <span class="text-stone-400 mr-1">Labor</span>
            <span class="font-black text-amber-400">36.3%</span>
        </div>
        <div>
            <span class="text-stone-400 mr-1">B/E</span>
            <span class="font-black text-emerald-400">+18.9%</span>
        </div>
    </div>
    <div class="flex items-center gap-3">
        <div class="bg-rose-500/20 border border-rose-500/30 text-rose-300 px-2 py-1 rounded flex items-center gap-1.5">
            <span class="w-1.5 h-1.5 rounded-full bg-rose-500 animate-pulse"></span>
            <span class="font-bold text-[10px] uppercase tracking-wider">Critical</span>
        </div>
    </div>
</div>

@if($showAlert)
<div class="bg-rose-900/10 border-b border-rose-200 p-2 flex items-center justify-center gap-2 text-rose-800 shrink-0">
    <span class="material-symbols-outlined text-sm foodo-icon">warning</span>
    <span class="text-[11px] font-bold">{{ $alertMessage }}</span>
</div>
@endif
