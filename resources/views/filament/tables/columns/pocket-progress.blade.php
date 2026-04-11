<div class="flex items-center gap-2">
    <div class="w-24 h-2 bg-gray-200 rounded-full overflow-hidden">
        <div
            class="h-full bg-primary-500 rounded-full"
            style="width: {{ min(100, (float) $getState()) }}%"
        ></div>
    </div>
    <span class="text-sm text-gray-600">{{ round((float) $getState(), 1) }}%</span>
</div>
