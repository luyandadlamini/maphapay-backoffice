@props(['title' => 'Welcome back, Manager', 'subtitle' => null])

<header class="flex flex-col xl:flex-row justify-between items-start xl:items-end gap-6 mb-8">
    <div>
        <div class="flex items-center gap-2 mb-1">
            <span class="h-2 w-2 rounded-full bg-foodo-primary-vibrant animate-pulse"></span>
            <p class="text-[10px] font-black uppercase tracking-[0.2em] text-foodo-primary">Live Command Center</p>
        </div>
        <h1 class="text-3xl font-black text-foodo-text tracking-tight">{{ $title }}</h1>
        <p class="text-stone-500 font-medium text-sm mt-1">{{ $subtitle ?? 'Today is ' . now()->format('l, M d') . ' · Week ' . now()->format('W') }}</p>
    </div>
    <div class="flex items-center gap-3 bg-white p-1.5 rounded-xl border border-stone-200 shadow-sm">
        <div class="px-4 py-2 bg-stone-100 rounded-lg text-xs font-bold text-stone-600 cursor-pointer hover:bg-stone-200 transition-colors flex items-center gap-2">
            All Restaurants <span class="material-symbols-outlined text-sm">expand_more</span>
        </div>
        <div class="h-6 w-px bg-stone-200"></div>
        <div class="px-3 flex flex-col items-center">
            <span class="text-[10px] font-black text-emerald-600 uppercase tracking-wider">Active</span>
            <span class="text-lg font-black leading-none text-emerald-700">26</span>
        </div>
        <div class="h-6 w-px bg-stone-200"></div>
        <a href="{{ route('foodo.chat') }}" class="bg-foodo-surface-dark text-white px-5 py-2.5 rounded-lg text-xs font-bold flex items-center gap-2 hover:bg-black transition-colors shadow-lg shadow-stone-900/20">
            <span class="material-symbols-outlined text-sm text-foodo-primary-vibrant foodo-icon">auto_awesome</span>
            AI Advisor
        </a>
    </div>
</header>
