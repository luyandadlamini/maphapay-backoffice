<div class="space-y-6">
    @if(($report['discrepancies_found'] ?? 0) > 0)
        <div class="bg-red-50 border border-red-200 rounded-lg p-4">
            <h3 class="text-lg font-semibold text-red-900 mb-2">
                {{ $report['discrepancies_found'] }} Discrepancies Found
            </h3>
            <p class="text-red-700">
                Total discrepancy amount: ${{ number_format($report['total_discrepancy_amount'] / 100, 2) }}
            </p>
        </div>
    @else
        <div class="bg-green-50 border border-green-200 rounded-lg p-4">
            <h3 class="text-lg font-semibold text-green-900">
                ✓ All Balances Reconciled Successfully
            </h3>
        </div>
    @endif
    
    <div class="grid grid-cols-2 gap-4">
        <div>
            <h4 class="font-semibold text-gray-700">Report Date</h4>
            <p>{{ $report['date'] }}</p>
        </div>
        <div>
            <h4 class="font-semibold text-gray-700">Accounts Checked</h4>
            <p>{{ $report['accounts_checked'] }}</p>
        </div>
        <div>
            <h4 class="font-semibold text-gray-700">Start Time</h4>
            <p>{{ $report['start_time'] }}</p>
        </div>
        <div>
            <h4 class="font-semibold text-gray-700">Duration</h4>
            <p>{{ $report['duration_minutes'] ?? 0 }} minutes</p>
        </div>
    </div>
    
    @if(!empty($report['settlement_summary']))
        <div class="mt-6">
            <h3 class="text-lg font-semibold mb-3">Settlement Summary</h3>
            <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
                @foreach($report['settlement_summary'] as $status => $count)
                    <div class="rounded-lg border border-gray-200 p-3">
                        <p class="text-xs uppercase tracking-wide text-gray-500">{{ str_replace('_', ' ', $status) }}</p>
                        <p class="text-lg font-semibold text-gray-900">{{ $count }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if(!empty($report['recent_provider_callbacks']))
        <div class="mt-6">
            <h3 class="text-lg font-semibold mb-3">Recent Provider Callbacks</h3>
            <div class="space-y-3">
                @foreach($report['recent_provider_callbacks'] as $callback)
                    <div class="rounded-lg border border-gray-200 p-4 text-sm">
                        <p><span class="font-medium">Provider:</span> {{ $callback['custodian_name'] }}</p>
                        <p><span class="font-medium">Event:</span> {{ $callback['event_type'] }} @if(!empty($callback['normalized_event_type']))<span class="text-gray-500">({{ $callback['normalized_event_type'] }})</span>@endif</p>
                        <p><span class="font-medium">Provider Reference:</span> {{ $callback['provider_reference'] ?? 'n/a' }}</p>
                        <p><span class="font-medium">Finality / Settlement / Reconciliation:</span> {{ $callback['finality_status'] ?? 'pending' }} / {{ $callback['settlement_status'] ?? 'pending' }} / {{ $callback['reconciliation_status'] ?? 'pending' }}</p>
                        @if(!empty($callback['provider_operation']))
                            <div class="mt-3 rounded-md border border-blue-200 bg-blue-50 p-3">
                                <p class="font-semibold text-blue-900">Canonical Provider Operation</p>
                                <p><span class="font-medium">Provider:</span> {{ $callback['provider_operation']['provider_name'] ?? 'n/a' }}</p>
                                <p><span class="font-medium">Reference:</span> {{ $callback['provider_operation']['provider_reference'] ?? 'n/a' }}</p>
                                <p><span class="font-medium">Finality / Settlement / Reconciliation:</span> {{ $callback['provider_operation']['finality_status'] ?? 'pending' }} / {{ $callback['provider_operation']['settlement_status'] ?? 'pending' }} / {{ $callback['provider_operation']['reconciliation_status'] ?? 'pending' }}</p>
                                <p><span class="font-medium">Settlement Reference:</span> {{ $callback['provider_operation']['settlement_reference'] ?? 'n/a' }}</p>
                                <p><span class="font-medium">Ledger Posting Reference:</span> {{ $callback['provider_operation']['ledger_posting_reference'] ?? 'n/a' }}</p>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if(!empty($report['discrepancies']))
        <div class="mt-6">
            <h3 class="text-lg font-semibold mb-3">Discrepancy Details</h3>
            
            <div class="space-y-4">
                @foreach($report['discrepancies'] as $discrepancy)
                    <div class="border rounded-lg p-4 
                        @if($discrepancy['type'] === 'balance_mismatch') bg-red-50 border-red-200
                        @else bg-yellow-50 border-yellow-200
                        @endif">
                        
                        <h4 class="font-semibold mb-2">
                            {{ ucwords(str_replace('_', ' ', $discrepancy['type'])) }}
                        </h4>
                        
                        <div class="text-sm space-y-1">
                            <p><span class="font-medium">Account:</span> {{ $discrepancy['account_uuid'] }}</p>
                            <p><span class="font-medium">Internal Reference:</span> {{ $discrepancy['internal_reference'] ?? 'n/a' }}</p>
                            <p><span class="font-medium">Provider Family:</span> {{ $discrepancy['provider_family'] ?? 'n/a' }}</p>
                            <p><span class="font-medium">Provider Reference:</span> {{ $discrepancy['provider_reference'] ?? 'n/a' }}</p>
                            <p><span class="font-medium">Reconciliation Reference:</span> {{ $discrepancy['reconciliation_reference'] ?? 'n/a' }}</p>
                            <p><span class="font-medium">Ledger Posting Reference:</span> {{ $discrepancy['ledger_posting_reference'] ?? 'pending-ledger-core' }}</p>
                            <p><span class="font-medium">Settlement Reference:</span> {{ $discrepancy['settlement_reference'] ?? 'n/a' }}</p>
                            @if(!empty($discrepancy['provider_operation']))
                                <div class="mt-3 rounded-md border border-blue-200 bg-blue-50 p-3">
                                    <p class="font-semibold text-blue-900">Canonical Provider Operation</p>
                                    <p><span class="font-medium">Provider:</span> {{ $discrepancy['provider_operation']['provider_name'] ?? 'n/a' }}</p>
                                    <p><span class="font-medium">Reference:</span> {{ $discrepancy['provider_operation']['provider_reference'] ?? 'n/a' }}</p>
                                    <p><span class="font-medium">Finality / Settlement / Reconciliation:</span> {{ $discrepancy['provider_operation']['finality_status'] ?? 'pending' }} / {{ $discrepancy['provider_operation']['settlement_status'] ?? 'pending' }} / {{ $discrepancy['provider_operation']['reconciliation_status'] ?? 'pending' }}</p>
                                    <p><span class="font-medium">Settlement Reference:</span> {{ $discrepancy['provider_operation']['settlement_reference'] ?? 'n/a' }}</p>
                                    <p><span class="font-medium">Ledger Posting Reference:</span> {{ $discrepancy['provider_operation']['ledger_posting_reference'] ?? 'n/a' }}</p>
                                </div>
                            @endif
                            
                            @if($discrepancy['type'] === 'balance_mismatch')
                                <p><span class="font-medium">Asset:</span> {{ $discrepancy['asset_code'] }}</p>
                                <p><span class="font-medium">Internal Balance:</span> ${{ number_format($discrepancy['internal_balance'] / 100, 2) }}</p>
                                <p><span class="font-medium">External Balance:</span> ${{ number_format($discrepancy['external_balance'] / 100, 2) }}</p>
                                <p><span class="font-medium">Difference:</span> ${{ number_format($discrepancy['difference'] / 100, 2) }}</p>
                            @elseif($discrepancy['type'] === 'stale_data')
                                <p><span class="font-medium">Custodian:</span> {{ $discrepancy['custodian_id'] }}</p>
                                <p><span class="font-medium">Last Synced:</span> {{ $discrepancy['last_synced_at'] }}</p>
                            @else
                                <p>{{ $discrepancy['message'] ?? '' }}</p>
                            @endif
                            
                            <p class="text-xs text-gray-600 mt-2">
                                Detected at: {{ $discrepancy['detected_at'] }}
                            </p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
    
    @if(!empty($report['recommendations']))
        <div class="mt-6">
            <h3 class="text-lg font-semibold mb-3">Recommendations</h3>
            <ul class="list-disc list-inside space-y-1">
                @foreach($report['recommendations'] as $recommendation)
                    <li>{{ $recommendation }}</li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
