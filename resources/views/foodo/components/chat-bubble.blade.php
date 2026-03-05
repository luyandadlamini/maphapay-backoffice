@props(['role' => 'ai', 'time' => ''])

@if($role === 'ai')
<div class="flex gap-4">
    <div class="w-8 h-8 rounded-full bg-orange-100 flex items-center justify-center text-foodo-primary-dark shrink-0 mt-1">
        <span class="material-symbols-outlined text-lg foodo-icon">auto_awesome</span>
    </div>
    <div class="flex flex-col gap-1 max-w-4xl w-full">
        <div class="flex items-baseline gap-2">
            <span class="font-bold text-stone-800 text-sm">AI Advisor</span>
            <span class="text-xs text-stone-400">{{ $time }}</span>
        </div>
        <div class="chat-bubble-ai bg-foodo-surface-subtle border border-stone-200 p-5 shadow-sm text-sm text-stone-700 leading-relaxed">
            {!! $slot !!}
        </div>
    </div>
</div>
@else
<div class="flex gap-4 flex-row-reverse">
    <div class="w-8 h-8 rounded-full bg-foodo-primary-dark flex items-center justify-center text-white shrink-0 mt-1">
        <span class="material-symbols-outlined text-lg foodo-icon">person</span>
    </div>
    <div class="flex flex-col gap-1 items-end max-w-2xl w-full">
        <div class="flex items-baseline gap-2">
            <span class="font-bold text-stone-800 text-sm">Restaurant Manager</span>
            <span class="text-xs text-stone-400">{{ $time }}</span>
        </div>
        <div class="chat-bubble-user bg-orange-50 border border-orange-200 p-4 shadow-sm text-sm text-stone-800 leading-relaxed">
            {!! $slot !!}
        </div>
    </div>
</div>
@endif
