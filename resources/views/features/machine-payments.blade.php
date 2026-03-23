@extends('layouts.public')

@section('title', 'Machine Payments Protocol — Multi-Rail Agent Payments | ' . config('brand.name', 'Zelta') . '')

@section('seo')
    @include('partials.seo', [
        'title' => 'Machine Payments Protocol — Multi-Rail Agent Payments',
        'description' => 'HTTP 402 payments with Stripe, Tempo, Lightning, and Card rails. AI agent commerce with MCP transport binding and spending limits.',
        'keywords' => 'machine payments, MPP, HTTP 402, Stripe, Tempo, Lightning, AI agent payments, MCP, spending limits, multi-rail',
    ])

    {{-- Schema.org Markup --}}
    <x-schema type="software" />
    <x-schema type="breadcrumb" :data="[
        ['name' => 'Home', 'url' => url('/')],
        ['name' => 'Features', 'url' => url('/features')],
        ['name' => 'Machine Payments Protocol', 'url' => url('/features/machine-payments')]
    ]" />
@endsection

@section('content')

    <!-- Hero -->
    <section class="bg-fa-navy relative overflow-hidden">
        <div class="absolute inset-0 bg-grid-pattern"></div>
        <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-20 lg:py-24">
            <div class="text-center">
                <div class="flex justify-center mb-6">
                    <div class="w-20 h-20 bg-white/10 rounded-2xl flex items-center justify-center">
                        <svg class="w-10 h-10 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path>
                        </svg>
                    </div>
                </div>
                <div class="inline-flex items-center px-3 py-1 bg-white/10 backdrop-blur-sm rounded-full text-sm text-slate-300 mb-6">
                    <span class="w-2 h-2 bg-emerald-400 rounded-full mr-2"></span>
                    v6.4.0 &middot; Multi-Rail Payments
                </div>
                <h1 class="text-4xl md:text-5xl lg:text-6xl font-bold text-white mb-6">
                    Machine Payments Protocol
                </h1>
                <p class="text-lg md:text-xl text-slate-300 max-w-3xl mx-auto mb-8">
                    HTTP 402 payments with Stripe, Tempo, Lightning, and Card rails. Let AI agents pay for APIs using the best available payment method — fiat or crypto.
                </p>
                <div class="flex flex-wrap justify-center gap-4">
                    <a href="{{ url('/api/v1/mpp/status') }}" class="btn btn-outline">View Protocol Status</a>
                    <a href="{{ url('/features/x402-protocol') }}" class="btn btn-outline">Compare with x402</a>
                </div>
            </div>
        </div>
    </section>

    <!-- How It Works -->
    <section class="py-16 lg:py-20 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <h2 class="text-3xl font-bold text-slate-900 text-center mb-12">How It Works</h2>
            <div class="grid md:grid-cols-3 gap-8">
                <div class="text-center">
                    <div class="w-16 h-16 bg-red-100 rounded-2xl flex items-center justify-center mx-auto mb-4">
                        <span class="text-2xl font-bold text-red-600">402</span>
                    </div>
                    <h3 class="text-lg font-semibold text-slate-900 mb-2">Challenge Issued</h3>
                    <p class="text-slate-600">Server returns <code>WWW-Authenticate: Payment</code> with pricing, rails, and HMAC-bound challenge.</p>
                </div>
                <div class="text-center">
                    <div class="w-16 h-16 bg-blue-100 rounded-2xl flex items-center justify-center mx-auto mb-4">
                        <svg class="w-8 h-8 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    </div>
                    <h3 class="text-lg font-semibold text-slate-900 mb-2">Payment Sent</h3>
                    <p class="text-slate-600">Client selects rail (Stripe, Tempo, Lightning, Card), pays off-band, retries with <code>Authorization: Payment</code>.</p>
                </div>
                <div class="text-center">
                    <div class="w-16 h-16 bg-emerald-100 rounded-2xl flex items-center justify-center mx-auto mb-4">
                        <svg class="w-8 h-8 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                    </div>
                    <h3 class="text-lg font-semibold text-slate-900 mb-2">Resource Delivered</h3>
                    <p class="text-slate-600">Server verifies, settles, returns resource with <code>Payment-Receipt</code> header. RFC 9457 errors for failures.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Multi-Rail Comparison -->
    <section class="py-16 lg:py-20 bg-slate-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <h2 class="text-3xl font-bold text-slate-900 text-center mb-12">Payment Rails</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-slate-100">
                            <th class="p-4 font-semibold text-slate-900">Rail</th>
                            <th class="p-4 font-semibold text-slate-900">Type</th>
                            <th class="p-4 font-semibold text-slate-900">Currencies</th>
                            <th class="p-4 font-semibold text-slate-900">Settlement</th>
                            <th class="p-4 font-semibold text-slate-900">Best For</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        <tr><td class="p-4 font-medium">Stripe SPT</td><td class="p-4">Fiat</td><td class="p-4">USD, EUR, GBP</td><td class="p-4">Instant (PaymentIntents)</td><td class="p-4">SaaS, API monetization</td></tr>
                        <tr><td class="p-4 font-medium">Tempo</td><td class="p-4">Stablecoin</td><td class="p-4">USDC, USDT</td><td class="p-4">~500ms on-chain</td><td class="p-4">DeFi, cross-border</td></tr>
                        <tr><td class="p-4 font-medium">Lightning</td><td class="p-4">Bitcoin</td><td class="p-4">BTC</td><td class="p-4">Instant (preimage)</td><td class="p-4">Micropayments</td></tr>
                        <tr><td class="p-4 font-medium">Card</td><td class="p-4">Fiat</td><td class="p-4">USD, EUR, GBP</td><td class="p-4">Standard card networks</td><td class="p-4">Traditional commerce</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <!-- MCP Integration -->
    <section class="py-16 lg:py-20 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid md:grid-cols-2 gap-12 items-center">
                <div>
                    <h2 class="text-3xl font-bold text-slate-900 mb-6">Native MCP Binding</h2>
                    <p class="text-slate-600 mb-4">
                        MPP defines error code <strong>-32042</strong> as the MCP equivalent of HTTP 402. AI agents using MCP tool calls can pay for tools and resources without HTTP round-trips.
                    </p>
                    <ul class="space-y-3">
                        <li class="flex items-start"><span class="text-emerald-500 mr-2 mt-1">&#10003;</span><span><strong>mpp.payment</strong> — Handle 402 challenges and generate credentials</span></li>
                        <li class="flex items-start"><span class="text-emerald-500 mr-2 mt-1">&#10003;</span><span><strong>mpp.discovery</strong> — Discover MPP-enabled resources</span></li>
                        <li class="flex items-start"><span class="text-emerald-500 mr-2 mt-1">&#10003;</span><span><strong>Spending limits</strong> — Per-agent daily and per-transaction budgets</span></li>
                        <li class="flex items-start"><span class="text-emerald-500 mr-2 mt-1">&#10003;</span><span><strong>Auto-pay</strong> — Agents pay autonomously within budget</span></li>
                    </ul>
                </div>
                <div class="bg-slate-900 rounded-xl p-6 text-sm font-mono text-emerald-400 overflow-x-auto">
<pre>// MCP error -32042 (Payment Required)
{
  "error": {
    "code": -32042,
    "message": "Payment Required",
    "data": {
      "challenge": "Payment eyJ...",
      "rails": ["stripe", "tempo"],
      "amount_cents": 50,
      "currency": "USD"
    }
  }
}</pre>
                </div>
            </div>
        </div>
    </section>

    <!-- Protocol Comparison -->
    <section class="py-16 lg:py-20 bg-slate-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <h2 class="text-3xl font-bold text-slate-900 text-center mb-12">MPP vs x402 vs AP2</h2>
            <div class="grid md:grid-cols-3 gap-8">
                <div class="bg-white rounded-xl p-6 shadow-sm border border-slate-200">
                    <h3 class="text-xl font-bold text-slate-900 mb-3">x402</h3>
                    <p class="text-sm text-slate-500 mb-4">Coinbase</p>
                    <ul class="text-slate-600 space-y-2 text-sm">
                        <li>USDC on EVM chains + Solana</li>
                        <li>Custom payment headers</li>
                        <li>Facilitator-based settlement</li>
                        <li>Crypto-native</li>
                    </ul>
                </div>
                <div class="bg-white rounded-xl p-6 shadow-sm border-2 border-blue-500">
                    <h3 class="text-xl font-bold text-blue-600 mb-3">MPP</h3>
                    <p class="text-sm text-slate-500 mb-4">Stripe + Tempo Labs</p>
                    <ul class="text-slate-600 space-y-2 text-sm">
                        <li>Stripe, Tempo, Lightning, Card</li>
                        <li>Standard HTTP auth headers</li>
                        <li>HMAC-SHA256 challenge binding</li>
                        <li>Fiat + crypto multi-rail</li>
                    </ul>
                </div>
                <div class="bg-white rounded-xl p-6 shadow-sm border border-slate-200">
                    <h3 class="text-xl font-bold text-slate-900 mb-3">AP2</h3>
                    <p class="text-sm text-slate-500 mb-4">Google</p>
                    <ul class="text-slate-600 space-y-2 text-sm">
                        <li>Cart, Intent, Payment Mandates</li>
                        <li>Verifiable Digital Credentials</li>
                        <li>Wraps x402 + MPP as methods</li>
                        <li>Authorization + accountability</li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA -->
    <section class="py-16 lg:py-20 bg-fa-navy">
        <div class="max-w-3xl mx-auto px-4 text-center">
            <h2 class="text-3xl font-bold text-white mb-6">Three Protocols, One Platform</h2>
            <p class="text-slate-300 mb-8">{{ config('brand.name', 'Zelta') }} supports x402, MPP, and AP2 mandates. Your AI agents choose the best payment method for each transaction.</p>
            <div class="flex flex-wrap justify-center gap-4">
                <a href="{{ url('/features/x402-protocol') }}" class="btn btn-outline">x402 Protocol</a>
                <a href="{{ url('/features/ai-framework') }}" class="btn btn-outline">AI Framework</a>
                <a href="{{ url('/features') }}" class="btn btn-outline">All Features</a>
            </div>
        </div>
    </section>

@endsection
