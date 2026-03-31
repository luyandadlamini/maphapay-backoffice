<x-filament-panels::page>
    <div class="grid gap-6">
        {{-- Treasury Balance Cards --}}
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            @foreach($treasuryBalances as $balance)
                <div class="bg-white dark:bg-gray-800 rounded-xl p-6 border border-gray-200 dark:border-gray-700">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $balance['name'] }}</p>
                            <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1">{{ $balance['formatted'] }}</p>
                        </div>
                        <div class="w-12 h-12 rounded-full bg-primary-100 dark:bg-primary-900 flex items-center justify-center">
                            <span class="text-lg font-bold text-primary-600 dark:text-primary-400">{{ $balance['code'] }}</span>
                        </div>
                    </div>
                    <p class="text-xs text-gray-400 mt-2 capitalize">{{ $balance['type'] }} Currency</p>
                </div>
            @endforeach
        </div>

        {{-- Treasury Table --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700">
            <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Treasury Asset Breakdown</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Current treasury balances by asset</p>
            </div>
            <div class="p-6">
                <table class="w-full">
                    <thead>
                        <tr class="text-left text-sm text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                            <th class="pb-3 font-medium">Currency</th>
                            <th class="pb-3 font-medium">Type</th>
                            <th class="pb-3 font-medium text-right">Balance</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach($treasuryBalances as $balance)
                            <tr class="text-gray-900 dark:text-white">
                                <td class="py-4">
                                    <div class="flex items-center gap-3">
                                        <span class="w-10 h-10 rounded-full bg-primary-100 dark:bg-primary-900 flex items-center justify-center">
                                            <span class="text-sm font-bold text-primary-600 dark:text-primary-400">{{ $balance['code'] }}</span>
                                        </span>
                                        <div>
                                            <p class="font-medium">{{ $balance['code'] }}</p>
                                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $balance['name'] }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-4">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium capitalize
                                        @if($balance['type'] === 'crypto')
                                            bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200
                                        @elseif($balance['type'] === 'fiat')
                                            bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200
                                        @else
                                            bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-200
                                        @endif">
                                        {{ $balance['type'] }}
                                    </span>
                                </td>
                                <td class="py-4 text-right font-semibold">
                                    {{ $balance['formatted'] }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-filament-panels::page>
