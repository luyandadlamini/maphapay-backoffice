<x-foodo.layouts.app :title="'Dashboard'" :active-page="'dashboard'">
    <x-foodo.page-header title="Welcome back, Manager" />

    <div class="grid grid-cols-12 gap-6 pb-10">
        {{-- Left Column: Roadmap + Chart --}}
        <div class="col-span-12 xl:col-span-7 flex flex-col gap-6">

            {{-- AI Strategic Action Roadmap --}}
            <section class="bg-white rounded-2xl border border-stone-200 shadow-sm overflow-hidden border-purple-500/30 ring-1 ring-purple-500/10">
                <div class="bg-gradient-to-r from-foodo-surface-dark to-stone-800 p-5 text-white flex justify-between items-center relative overflow-hidden">
                    <div class="relative z-10 flex items-center gap-3">
                        <div class="w-10 h-10 rounded-lg bg-white/10 flex items-center justify-center backdrop-blur-md border border-white/10">
                            <span class="material-symbols-outlined text-purple-400 foodo-icon">psychology</span>
                        </div>
                        <div>
                            <h2 class="text-lg font-bold">AI Strategic Action Roadmap</h2>
                            <div class="flex items-center gap-2 mt-0.5">
                                <span class="bg-purple-600 text-white text-[9px] font-black px-1.5 py-0.5 rounded uppercase tracking-wider">AI Analysis</span>
                                <span class="text-[10px] text-stone-400 font-medium">Updated 5m ago</span>
                            </div>
                        </div>
                    </div>
                    <div class="absolute right-0 top-0 h-full w-48 bg-gradient-to-l from-purple-500/20 to-transparent"></div>
                </div>

                <div class="p-6 space-y-4 bg-stone-50/50">
                    <x-foodo.insight-card
                        severity="rose"
                        icon="warning"
                        title="Highly Volatile Weekly Sales Pattern"
                        chip-label="Urgent"
                        recommendation="Conduct a detailed audit of operational practices to stabilize sales fluctuations immediately."
                    >
                        Weekly sales show extreme volatility (-62.25% drop). Immediate operational audit required.
                    </x-foodo.insight-card>

                    <x-foodo.insight-card
                        severity="purple"
                        icon="trending_up"
                        title="Maximize Sales in High Week Flux Periods"
                        chip-label="Strategic"
                        recommendation="Focus resources on maximizing sales during weeks showing upward trends."
                    >
                        Sales range from €122k to €427k. Opportunity to exploit high-demand weeks.
                    </x-foodo.insight-card>

                    <x-foodo.insight-card
                        severity="emerald"
                        icon="campaign"
                        title="Optimize Week 7 Campaign Strategies"
                        chip-label="Opportunity"
                        recommendation="Review campaign effectiveness and align promotions with strategic capacity."
                    />
                </div>

                <div class="bg-stone-50 p-3 border-t border-stone-100 text-center">
                    <button class="text-[10px] font-black text-foodo-text-muted hover:text-foodo-primary uppercase tracking-widest transition-colors flex items-center justify-center gap-1 w-full">
                        View All Roadmap Items <span class="material-symbols-outlined text-sm">arrow_downward</span>
                    </button>
                </div>
            </section>

            {{-- Sales & Profitability Chart --}}
            <section class="bg-white rounded-2xl border border-stone-200 shadow-sm p-6">
                <div class="flex justify-between items-end mb-6">
                    <div>
                        <h3 class="text-lg font-black text-foodo-text flex items-center gap-2">
                            <span class="material-symbols-outlined text-foodo-primary foodo-icon">monitoring</span>
                            Sales & Profitability
                        </h3>
                        <p class="text-[11px] font-bold text-stone-400 uppercase tracking-wider mt-1">Real-time Performance · Last 12 Weeks</p>
                    </div>
                    <div class="flex gap-2">
                        <div class="bg-stone-100 p-1 rounded-lg flex gap-1">
                            <button class="px-3 py-1 bg-white shadow-sm rounded-md text-[10px] font-black text-stone-900 uppercase">Sales</button>
                            <button class="px-3 py-1 text-[10px] font-black text-stone-400 hover:text-stone-600 uppercase transition-colors">Margin</button>
                        </div>
                    </div>
                </div>
                <div class="h-[280px] w-full">
                    <canvas id="salesChart"></canvas>
                </div>
            </section>
        </div>

        {{-- Right Column: KPIs + Top Performer + Quick Actions --}}
        <div class="col-span-12 xl:col-span-5 flex flex-col gap-6">
            {{-- KPI Grid --}}
            <div class="grid grid-cols-2 gap-4">
                <x-foodo.kpi-card
                    label="Wk Sales"
                    value="€377,309"
                    trend="-10.0% vs prev"
                    trend-direction="down"
                    border-color="foodo-primary"
                />
                <x-foodo.kpi-card
                    label="Feb Total"
                    value="€1.6M"
                    trend="On Track"
                    trend-direction="up"
                    chip-label="Month"
                    border-color="stone-800"
                />

                {{-- Top Performer Card --}}
                <div class="bg-white rounded-2xl border border-stone-200 shadow-sm p-4 col-span-2 bg-foodo-primary-dark text-white border-none relative overflow-hidden">
                    <div class="absolute right-0 top-0 opacity-10 pointer-events-none">
                        <span class="material-symbols-outlined text-9xl foodo-icon">emoji_events</span>
                    </div>
                    <div class="relative z-10 flex justify-between items-start">
                        <div>
                            <h3 class="text-xs font-bold text-orange-200 uppercase tracking-widest mb-1">Top Performer</h3>
                            <p class="text-xl font-black">0,5 Ekstra pilst.</p>
                            <p class="text-[10px] text-white/60">Category: Alkoholis</p>
                        </div>
                        <div class="text-right">
                            <p class="text-2xl font-black text-white">€70k</p>
                            <p class="text-[10px] font-bold text-orange-200">THIS WEEK</p>
                        </div>
                    </div>
                    <div class="mt-4 pt-3 border-t border-white/10 flex gap-4 relative z-10">
                        <div>
                            <span class="block text-[10px] font-bold text-white/50 uppercase">Sold</span>
                            <span class="font-bold">19,000</span>
                        </div>
                        <div>
                            <span class="block text-[10px] font-bold text-white/50 uppercase">Share</span>
                            <span class="font-bold">22%</span>
                        </div>
                        <button class="ml-auto bg-white/10 hover:bg-white/20 text-white px-3 py-1 rounded text-[10px] font-bold uppercase transition-colors">
                            Details
                        </button>
                    </div>
                </div>
            </div>

            {{-- Quick Actions --}}
            <section class="bg-white rounded-2xl border border-stone-200 shadow-sm flex-1 flex flex-col bg-stone-50">
                <div class="p-4 border-b border-stone-200 bg-white rounded-t-2xl flex justify-between items-center">
                    <h3 class="font-bold text-sm text-foodo-text flex items-center gap-2">
                        <span class="material-symbols-outlined text-stone-400 foodo-icon-thin">checklist</span>
                        Quick Actions
                    </h3>
                    <span class="bg-foodo-primary text-white text-[10px] font-black w-5 h-5 flex items-center justify-center rounded-full">3</span>
                </div>
                <div class="p-4 space-y-3 overflow-y-auto max-h-[400px] foodo-scrollbar">
                    <x-foodo.quick-action
                        icon="upload_file"
                        title="Upload P&L Data"
                        description="Missing 10 months of financial data."
                        color="red"
                    />
                    <x-foodo.quick-action
                        icon="group_add"
                        title="Staffing Alert: Saturday"
                        description="Projected +20% traffic this weekend."
                        color="blue"
                    />
                    <x-foodo.quick-action
                        icon="storefront"
                        title="Diversify Channels"
                        description="High dependency on dine-in (63%)."
                        color="orange"
                    />
                </div>
                <div class="p-4 mt-auto border-t border-stone-200">
                    <button class="w-full py-3 rounded-xl bg-stone-800 text-white text-xs font-bold uppercase tracking-widest hover:bg-stone-900 transition-colors shadow-lg">
                        Go to Action Center
                    </button>
                </div>
            </section>
        </div>
    </div>

    @push('scripts')
        @vite(['resources/js/foodo/charts.js'])
    @endpush
</x-foodo.layouts.app>
