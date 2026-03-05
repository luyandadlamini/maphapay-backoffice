<x-foodo.layouts.app :title="'AI Advisor'" :active-page="'chat'" :main-class="'flex flex-col !p-0 !overflow-hidden'">
    {{-- Header --}}
    <header class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4 bg-foodo-surface-dark text-white p-6 shrink-0">
        <div>
            <div class="flex items-center gap-2 mb-1 opacity-70">
                <span class="material-symbols-outlined text-sm foodo-icon-thin">calendar_month</span>
                <p class="text-[11px] font-bold uppercase tracking-wider">{{ now()->format('l, F d, Y') }}</p>
            </div>
            <h1 class="text-2xl font-black">AI Advisor <span class="text-stone-400 font-medium ml-2 text-base">Conversational Insights</span></h1>
        </div>
        <div class="flex items-center gap-3">
            <div class="bg-white/10 backdrop-blur px-4 py-2 rounded-lg border border-white/10 flex items-center gap-3 cursor-pointer hover:bg-white/20 transition-all">
                <span class="text-xs font-black uppercase tracking-wider">All Restaurants</span>
                <span class="material-symbols-outlined text-sm">expand_more</span>
            </div>
            <div class="bg-emerald-500/20 text-emerald-400 px-4 py-2 rounded-lg text-xs font-black border border-emerald-500/30 flex items-center gap-2">
                <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span> 26 ACTIVE
            </div>
        </div>
    </header>

    {{-- Chat Container --}}
    <div class="flex-1 bg-white rounded-none border-t border-stone-200 flex flex-col overflow-hidden" x-data="foodoChat()">
        {{-- Metrics Bar --}}
        <x-foodo.metrics-bar :show-alert="true" alert-message="Year-over-year revenue decline detected across primary channels." />

        {{-- Chat Messages --}}
        <div class="flex-1 overflow-y-auto p-6 space-y-6 bg-white foodo-scrollbar" id="chat-container">
            <template x-for="(msg, idx) in messages" :key="idx">
                <div>
                    {{-- AI Message --}}
                    <template x-if="msg.role === 'ai'">
                        <div class="flex gap-4">
                            <div class="w-8 h-8 rounded-full bg-orange-100 flex items-center justify-center text-foodo-primary-dark shrink-0 mt-1">
                                <span class="material-symbols-outlined text-lg foodo-icon">auto_awesome</span>
                            </div>
                            <div class="flex flex-col gap-1 max-w-4xl w-full">
                                <div class="flex items-baseline gap-2">
                                    <span class="font-bold text-stone-800 text-sm">AI Advisor</span>
                                    <span class="text-xs text-stone-400" x-text="msg.time"></span>
                                </div>
                                <div class="chat-bubble-ai bg-foodo-surface-subtle border border-stone-200 p-5 shadow-sm text-sm text-stone-700 leading-relaxed" x-html="msg.content"></div>
                            </div>
                        </div>
                    </template>
                    {{-- User Message --}}
                    <template x-if="msg.role === 'user'">
                        <div class="flex gap-4 flex-row-reverse">
                            <div class="w-8 h-8 rounded-full bg-foodo-primary-dark flex items-center justify-center text-white shrink-0 mt-1">
                                <span class="material-symbols-outlined text-lg foodo-icon">person</span>
                            </div>
                            <div class="flex flex-col gap-1 items-end max-w-2xl w-full">
                                <div class="flex items-baseline gap-2">
                                    <span class="font-bold text-stone-800 text-sm">Manager</span>
                                    <span class="text-xs text-stone-400" x-text="msg.time"></span>
                                </div>
                                <div class="chat-bubble-user bg-orange-50 border border-orange-200 p-4 shadow-sm text-sm text-stone-800 leading-relaxed" x-html="msg.content"></div>
                            </div>
                        </div>
                    </template>
                </div>
            </template>

            {{-- Typing indicator --}}
            <div x-show="sending" class="flex gap-4">
                <div class="w-8 h-8 rounded-full bg-orange-100 flex items-center justify-center text-foodo-primary-dark shrink-0 mt-1">
                    <span class="material-symbols-outlined text-lg foodo-icon">auto_awesome</span>
                </div>
                <div class="chat-bubble-ai bg-foodo-surface-subtle border border-stone-200 px-5 py-4 shadow-sm">
                    <div class="flex gap-1">
                        <div class="w-2 h-2 bg-stone-400 rounded-full animate-bounce" style="animation-delay: 0ms"></div>
                        <div class="w-2 h-2 bg-stone-400 rounded-full animate-bounce" style="animation-delay: 150ms"></div>
                        <div class="w-2 h-2 bg-stone-400 rounded-full animate-bounce" style="animation-delay: 300ms"></div>
                    </div>
                </div>
            </div>

            <div class="h-4"></div>
        </div>

        {{-- Input --}}
        <div class="p-4 bg-white border-t border-stone-100 shrink-0">
            <div class="relative max-w-5xl mx-auto">
                <input
                    x-model="input"
                    @keydown.enter="send()"
                    class="w-full pl-4 pr-32 py-4 bg-stone-50 border border-stone-200 rounded-xl focus:ring-2 focus:ring-foodo-primary/30 focus:border-foodo-primary text-sm shadow-sm outline-none transition-all placeholder:text-stone-400 font-foodo"
                    placeholder="Ask a question about your restaurants..."
                    type="text"
                />
                <button
                    @click="send()"
                    :disabled="sending || !input.trim()"
                    class="absolute right-2 top-2 bottom-2 bg-foodo-primary-dark hover:bg-foodo-primary text-white px-6 rounded-lg font-bold text-xs uppercase tracking-wider transition-colors flex items-center gap-2 shadow-md disabled:opacity-50"
                >
                    <span class="material-symbols-outlined text-lg foodo-icon">send</span> Send
                </button>
            </div>
            <div class="text-center mt-3">
                <p class="text-[10px] text-stone-400">Foodo Insights — Proprietary and Confidential. AI responses should be verified.</p>
            </div>
        </div>
    </div>

    @push('scripts')
        @vite(['resources/js/foodo/chat.js'])
    @endpush
</x-foodo.layouts.app>
