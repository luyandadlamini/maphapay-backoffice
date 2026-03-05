@props(['active' => 'dashboard'])

<aside class="w-64 bg-white border-r border-stone-200 flex flex-col fixed h-full z-50">
    {{-- Logo --}}
    <div class="h-20 flex items-center px-6 border-b border-stone-100">
        <div class="flex items-center gap-3">
            <div class="h-10 w-10 bg-foodo-primary rounded-xl flex items-center justify-center text-white shadow-lg shadow-orange-500/30">
                <span class="font-black text-xl">F</span>
            </div>
            <div class="flex flex-col">
                <span class="font-black text-lg leading-none tracking-tight">Foodo</span>
                <span class="text-[10px] font-bold text-stone-400 uppercase tracking-widest mt-0.5">Insights</span>
            </div>
        </div>
    </div>

    {{-- Navigation --}}
    <nav class="flex-1 px-4 py-6 space-y-1 overflow-y-auto foodo-scrollbar">
        @php
            $links = [
                ['id' => 'dashboard', 'icon' => 'grid_view', 'label' => 'Dashboard', 'route' => 'foodo.dashboard'],
                ['id' => 'ceo', 'icon' => 'query_stats', 'label' => 'CEO View', 'route' => null],
                ['id' => 'data', 'icon' => 'database', 'label' => 'Data Sources', 'route' => null],
                ['id' => 'analytics', 'icon' => 'monitoring', 'label' => 'Analytics', 'route' => null],
                ['id' => 'chat', 'icon' => 'auto_awesome', 'label' => 'AI Advisor', 'route' => 'foodo.chat'],
                ['id' => 'dish', 'icon' => 'restaurant', 'label' => 'Dish Analysis', 'route' => 'foodo.dish.demo'],
            ];
        @endphp

        @foreach ($links as $link)
            <a href="{{ $link['route'] ? route($link['route']) : '#' }}"
               class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-bold transition-all duration-200
                      {{ $active === $link['id']
                          ? 'bg-foodo-primary-dark text-white shadow-lg shadow-orange-900/20'
                          : 'text-stone-500 hover:bg-stone-100 hover:text-foodo-primary' }}">
                <span class="material-symbols-outlined text-[20px] {{ $active !== $link['id'] ? 'foodo-icon-thin' : 'foodo-icon' }}">{{ $link['icon'] }}</span>
                {{ $link['label'] }}
            </a>
        @endforeach

        {{-- Module toggles --}}
        <div class="mt-8 mb-2 px-4 flex justify-between items-center">
            <span class="text-[10px] font-black uppercase tracking-widest text-stone-400">Modules</span>
            <button class="text-stone-400 hover:text-foodo-primary transition-colors">
                <span class="material-symbols-outlined text-sm">add</span>
            </button>
        </div>

        @foreach (['Operations', 'Live Kitchen'] as $module)
            <label class="flex items-center gap-3 px-4 py-2 hover:bg-stone-50 rounded-lg cursor-pointer group transition-colors">
                <div class="w-4 h-4 rounded border-2 border-stone-300 text-foodo-primary flex items-center justify-center group-hover:border-foodo-primary transition-colors">
                    <div class="w-2 h-2 bg-foodo-primary rounded-sm"></div>
                </div>
                <span class="text-xs font-bold text-stone-600 group-hover:text-stone-900">{{ $module }}</span>
            </label>
        @endforeach
    </nav>

    {{-- User card --}}
    <div class="p-4 bg-stone-50 border-t border-stone-100">
        <div class="flex items-center gap-3 p-2 rounded-lg hover:bg-white transition-colors cursor-pointer border border-transparent hover:border-stone-200">
            <div class="w-9 h-9 rounded-full bg-stone-300 border-2 border-white shadow-sm flex items-center justify-center text-[10px] font-black text-stone-600">JD</div>
            <div class="flex-1 min-w-0">
                <p class="text-xs font-black text-stone-800 truncate">Manager Pro</p>
                <p class="text-[10px] font-semibold text-stone-500 truncate">admin@foodo.com</p>
            </div>
            <span class="material-symbols-outlined text-stone-400 text-lg foodo-icon-thin">settings</span>
        </div>
    </div>
</aside>
