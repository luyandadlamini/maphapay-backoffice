<x-filament-widgets::widget>
    <x-filament::section heading="Scenario vs Actuals (90 days)">
        @if(empty($rows))
            <p class="text-sm text-gray-500 dark:text-gray-400">
                No scenarios with results yet. Run a pricing scenario to see comparisons.
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="text-xs uppercase text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                        <tr>
                            <th class="py-2 pr-4">Scenario</th>
                            <th class="py-2 pr-4 text-right">Last Run</th>
                            <th class="py-2 pr-4 text-right">Scenario Revenue</th>
                            <th class="py-2 pr-4 text-right">90-Day Actual</th>
                            <th class="py-2 text-right">Delta</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach($rows as $row)
                            <tr>
                                <td class="py-2 pr-4 font-medium text-gray-900 dark:text-white">
                                    {{ $row['name'] }}
                                </td>
                                <td class="py-2 pr-4 text-right text-gray-500 dark:text-gray-400 text-xs">
                                    {{ $row['last_run_at'] ?? '—' }}
                                </td>
                                <td class="py-2 pr-4 text-right font-mono">
                                    {{ $row['scenario_revenue'] }}
                                </td>
                                <td class="py-2 pr-4 text-right font-mono">
                                    {{ $row['actual_revenue'] }}
                                </td>
                                <td class="py-2 text-right font-mono font-semibold {{ $row['delta_positive'] ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                                    {{ $row['delta_positive'] ? '+' : '-' }}{{ $row['delta'] }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
