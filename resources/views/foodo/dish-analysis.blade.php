<x-foodo.layouts.app :title="'Dish Analysis'" :active-page="'dish'">
    {{-- Header --}}
    <header class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 bg-foodo-surface-dark text-white p-6 rounded-2xl shadow-xl mb-6">
        <div>
            <div class="flex items-center gap-2 mb-1 opacity-70">
                <span class="material-symbols-outlined text-sm foodo-icon-thin">calendar_month</span>
                <p class="text-[11px] font-bold uppercase tracking-wider">{{ now()->format('l, F d, Y') }}</p>
            </div>
            <h1 class="text-2xl font-black flex items-center gap-3">
                <span class="material-symbols-outlined text-foodo-primary-vibrant foodo-icon">restaurant</span>
                Dish Analysis
                <span class="text-stone-400 font-medium ml-2 text-base">/ {{ $dishName ?? 'Siciliečių' }}</span>
            </h1>
        </div>
        <div class="flex items-center gap-3">
            <div class="bg-white/10 backdrop-blur px-4 py-2 rounded-lg border border-white/10 flex items-center gap-3 cursor-pointer hover:bg-white/20 transition-all">
                <span class="text-xs font-black uppercase tracking-wider">Kitchen 1</span>
                <span class="material-symbols-outlined text-sm">expand_more</span>
            </div>
            <a href="{{ route('foodo.chat') }}" class="bg-foodo-primary-vibrant hover:bg-foodo-primary-dark text-white px-5 py-2 rounded-lg text-xs font-black uppercase tracking-widest flex items-center gap-2 shadow-lg transition-all">
                <span class="material-symbols-outlined text-lg foodo-icon">auto_awesome</span> AI Advisor
            </a>
        </div>
    </header>

    {{-- Main Analysis Card --}}
    <div class="flex justify-center w-full">
        <div class="w-full max-w-4xl bg-white rounded-2xl shadow-xl overflow-hidden border border-stone-200" x-data="{ expanded: false }">

            {{-- Orange Gradient Header --}}
            <div class="bg-gradient-to-r from-orange-400 to-orange-500 p-8 text-white flex justify-between items-center relative overflow-hidden">
                <div class="absolute top-0 right-0 w-64 h-64 bg-white opacity-10 rounded-full translate-x-1/3 -translate-y-1/3"></div>
                <div class="relative z-10 flex items-center gap-6">
                    <div class="w-16 h-16 rounded-full bg-white/20 flex items-center justify-center backdrop-blur-sm border border-white/30">
                        <span class="material-symbols-outlined text-4xl foodo-icon">warning</span>
                    </div>
                    <div>
                        <h2 class="text-3xl font-black mb-1">Almost There</h2>
                        <p class="text-white/80 font-medium text-lg">{{ $dishName ?? 'Siciliečių Pizza' }}</p>
                    </div>
                </div>
                <div class="relative z-10 flex flex-col items-center">
                    <div class="w-20 h-20 rounded-full border-4 border-white/30 flex items-center justify-center relative">
                        <svg class="absolute inset-0 transform -rotate-90" width="100%" height="100%">
                            <circle cx="50%" cy="50%" r="36" fill="none" stroke="white" stroke-width="4"
                                stroke-dasharray="226" stroke-dashoffset="65" stroke-linecap="round"/>
                        </svg>
                        <div class="flex flex-col items-center leading-none">
                            <span class="text-2xl font-black">71</span>
                            <span class="text-[10px] font-bold opacity-80">/ 100</span>
                        </div>
                    </div>
                    <span class="mt-2 text-[10px] font-black uppercase tracking-widest bg-white/20 px-2 py-1 rounded">Quality Score</span>
                </div>
            </div>

            {{-- Content --}}
            <div class="p-6 md:p-8 space-y-8">

                {{-- Priority Improvement --}}
                <div class="bg-orange-50 border border-orange-100 rounded-xl p-5 flex flex-col md:flex-row gap-5 items-start">
                    <div class="flex-shrink-0">
                        <span class="bg-orange-400 text-white w-10 h-10 rounded-lg flex items-center justify-center font-black text-lg shadow-sm">#1</span>
                    </div>
                    <div class="flex-grow">
                        <p class="text-[10px] font-black text-orange-400 uppercase tracking-widest mb-1">Focus on this first</p>
                        <h3 class="text-lg font-bold text-stone-800 mb-2">9-12 o'clock: 2 chicken chunks visible (ref has 3)</h3>
                        <div class="flex gap-2 items-start text-stone-600 text-sm">
                            <span class="material-symbols-outlined text-orange-500 text-base mt-0.5 foodo-icon">arrow_forward</span>
                            <p><strong class="text-orange-700">BEFORE BAKING:</strong> Add 1 chicken chunk at the 10 o'clock position, closer to the outer ring.</p>
                        </div>
                    </div>
                </div>

                {{-- Score Breakdown --}}
                <div class="flex flex-wrap gap-4 items-center justify-between border-b border-stone-100 pb-6">
                    <div class="flex gap-4">
                        @foreach ([['Plating', 68, 'orange'], ['Portion', 75, 'emerald'], ['Quality', 70, 'orange']] as [$label, $score, $color])
                            <div class="flex items-center gap-2">
                                <span class="text-xs font-bold text-stone-500 uppercase">{{ $label }}</span>
                                <span class="bg-{{ $color }}-100 text-{{ $color }}-700 px-2 py-0.5 rounded text-sm font-black">{{ $score }}</span>
                            </div>
                            @if(!$loop->last)
                                <div class="w-px h-6 bg-stone-200"></div>
                            @endif
                        @endforeach
                    </div>
                    <span class="bg-amber-100 text-amber-800 px-3 py-1 rounded-full font-bold text-xs flex items-center gap-1">
                        <span class="material-symbols-outlined text-sm foodo-icon">trending_up</span> 4.0 pts to PASS
                    </span>
                </div>

                {{-- Image Comparison --}}
                <div class="space-y-4">
                    <div class="flex justify-center gap-2">
                        <button class="bg-foodo-surface-dark text-white px-4 py-1.5 rounded-full text-xs font-bold uppercase tracking-wider shadow-md">Side by Side</button>
                        <button class="bg-stone-100 text-stone-500 hover:bg-stone-200 px-4 py-1.5 rounded-full text-xs font-bold uppercase tracking-wider transition-colors">Slider</button>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="space-y-2">
                            <div class="text-center text-[10px] font-black text-stone-400 uppercase tracking-widest">Submitted</div>
                            <div class="aspect-square bg-stone-100 rounded-xl overflow-hidden border border-stone-200 shadow-inner relative">
                                <div class="w-full h-full bg-gradient-to-br from-amber-200 to-orange-300 flex items-center justify-center text-stone-500">
                                    <span class="material-symbols-outlined text-6xl opacity-30 foodo-icon">local_pizza</span>
                                </div>
                                <div class="absolute top-[20%] left-[25%] w-8 h-8 rounded-full border-2 border-orange-500 bg-orange-500/20 animate-pulse"></div>
                                <div class="absolute bottom-4 left-4 bg-black/70 text-white text-[10px] px-2 py-1 rounded backdrop-blur-sm">Jan 16, 2026 at 08:04</div>
                            </div>
                        </div>
                        <div class="space-y-2">
                            <div class="text-center text-[10px] font-black text-stone-400 uppercase tracking-widest">Standard</div>
                            <div class="aspect-square bg-stone-100 rounded-xl overflow-hidden border-4 border-emerald-500/20 shadow-inner relative">
                                <div class="w-full h-full bg-gradient-to-br from-amber-100 to-orange-200 flex items-center justify-center text-stone-400">
                                    <span class="material-symbols-outlined text-6xl opacity-30 foodo-icon">local_pizza</span>
                                </div>
                                <div class="absolute top-4 right-4 bg-emerald-500 text-white text-[10px] font-bold px-2 py-1 rounded shadow-lg uppercase tracking-wider">Reference</div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Tips --}}
                <div class="grid gap-4">
                    <div class="bg-stone-50 rounded-xl p-4 flex gap-4 border border-stone-100">
                        <div class="w-10 h-10 rounded-full bg-white border border-stone-200 flex items-center justify-center shadow-sm text-amber-500">
                            <span class="material-symbols-outlined foodo-icon">chef_hat</span>
                        </div>
                        <div>
                            <h4 class="text-[10px] font-black text-stone-400 uppercase tracking-widest mb-1">Chef's Tips</h4>
                            <p class="text-sm font-medium text-stone-700">The cheese distribution and mushroom placement match the standard. The crust edges are nicely puffed.</p>
                        </div>
                    </div>
                    <div class="bg-blue-50/50 rounded-xl p-4 flex gap-4 border border-blue-100/50">
                        <div class="w-10 h-10 rounded-full bg-blue-100 flex items-center justify-center shadow-sm text-blue-600">
                            <span class="material-symbols-outlined foodo-icon">photo_camera</span>
                        </div>
                        <div>
                            <h4 class="text-[10px] font-black text-blue-400 uppercase tracking-widest mb-1">Photo Tips</h4>
                            <p class="text-sm font-medium text-stone-700 mb-1">For better AI analysis next time:</p>
                            <p class="text-xs text-stone-500 leading-relaxed">Shadow at 6 o'clock darkens near edge — Move light to 11-12 o'clock. Shoot from overhead (90°) to show topping distribution.</p>
                        </div>
                    </div>
                </div>

                {{-- Expandable --}}
                <button @click="expanded = !expanded" class="w-full py-3 border-t border-b border-stone-100 flex justify-between items-center text-stone-500 hover:text-stone-800 transition-colors">
                    <span class="text-xs font-bold" x-text="expanded ? 'Hide improvements' : '2 more improvements'"></span>
                    <span class="material-symbols-outlined text-lg transition-transform" :class="expanded && 'rotate-180'">expand_more</span>
                </button>

                <div x-show="expanded" x-collapse class="space-y-3">
                    <div class="bg-amber-50 border border-amber-100 rounded-xl p-4">
                        <p class="text-[10px] font-black text-amber-500 uppercase tracking-widest mb-1">#2 Improvement</p>
                        <p class="text-sm font-medium text-stone-700">Sauce distribution is uneven — concentrate more in center area.</p>
                    </div>
                    <div class="bg-amber-50 border border-amber-100 rounded-xl p-4">
                        <p class="text-[10px] font-black text-amber-500 uppercase tracking-widest mb-1">#3 Improvement</p>
                        <p class="text-sm font-medium text-stone-700">Olive placement — shift 2 olives from 3 o'clock to 7 o'clock for balance.</p>
                    </div>
                </div>

                {{-- Chef Verification --}}
                <div class="bg-stone-50 rounded-2xl p-6 flex flex-col sm:flex-row justify-between items-center gap-4">
                    <div>
                        <h4 class="font-bold text-sm text-stone-800">Chef Verification</h4>
                        <p class="text-xs text-stone-500">Is this AI assessment correct?</p>
                    </div>
                    <div class="flex gap-3">
                        <button class="flex items-center gap-2 px-6 py-2.5 rounded-lg bg-emerald-100 text-emerald-700 hover:bg-emerald-200 transition-colors text-sm font-black shadow-sm">
                            <span class="material-symbols-outlined text-lg foodo-icon">check</span> Correct
                        </button>
                        <button class="flex items-center gap-2 px-6 py-2.5 rounded-lg bg-orange-100 text-orange-700 hover:bg-orange-200 transition-colors text-sm font-black shadow-sm">
                            <span class="material-symbols-outlined text-lg foodo-icon">close</span> Incorrect
                        </button>
                    </div>
                </div>

                {{-- CTA --}}
                <div class="flex justify-center pt-4">
                    <button class="bg-foodo-surface-dark hover:bg-stone-800 text-white px-8 py-3 rounded-lg font-bold flex items-center gap-2 shadow-xl transition-transform hover:-translate-y-0.5">
                        <span class="material-symbols-outlined foodo-icon">photo_camera</span> Analyze Another Dish
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- Footer --}}
    <footer class="pt-8 pb-12 text-center text-[10px] font-black text-stone-400 tracking-[0.4em] uppercase">
        Foodo Insights © 2025-2026 — Dish Analysis Command Center
    </footer>
</x-foodo.layouts.app>
