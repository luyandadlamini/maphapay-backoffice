<div class="flex items-center gap-2">
    <div class="w-24 h-2 bg-gray-200 rounded-full overflow-hidden">
        <div
            class="h-full bg-primary-500 rounded-full"
            style="width: {{ min(100, $record->progress_percentage) }}%"
        ></div>
    </div>
    <span class="text-sm text-gray-600">{{ round($record->progress_percentage, 1) }}%</span>
</div>
