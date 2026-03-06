@extends('layouts.public')

@section('title', 'RegTech & Compliance - FinAegis')

@section('seo')
    @include('partials.seo', [
        'title' => 'RegTech & Compliance',
        'description' => 'Enterprise-grade regulatory technology with MiFID II reporting, MiCA compliance, Travel Rule enforcement, and multi-jurisdiction adapter support.',
        'keywords' => 'RegTech, compliance, MiFID II, MiCA, Travel Rule, FATF, regulatory reporting, jurisdiction adapters, KYC, AML, FinAegis',
    ])

    {{-- Schema.org Markup --}}
    <x-schema type="software" />
    <x-schema type="breadcrumb" :data="[
        ['name' => 'Home', 'url' => url('/')],
        ['name' => 'Features', 'url' => url('/features')],
        ['name' => 'RegTech & Compliance', 'url' => url('/features/regtech-compliance')]
    ]" />
@endsection

@push('styles')
<style>
    .feature-card {
        transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .feature-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 12px 30px rgba(0,0,0,0.08);
    }
    .gradient-bg {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    }
</style>
@endpush

@section('content')

    <!-- Hero Section -->
    <section class="bg-fa-navy relative overflow-hidden">
        <div class="absolute inset-0 bg-grid-pattern"></div>
        <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-20 lg:py-24">
            <div class="text-center">
                <h1 class="font-display text-5xl lg:text-6xl font-extrabold text-white tracking-tight mb-6">RegTech & Compliance</h1>
                <p class="text-lg text-slate-400 max-w-3xl mx-auto">
                    Navigate the complex global regulatory landscape with confidence. Automated compliance for MiFID II, MiCA, Travel Rule, and more -- across every jurisdiction you operate in.
                </p>
            </div>
        </div>
        <div class="absolute bottom-0 left-0 right-0 h-px bg-gradient-to-r from-transparent via-blue-500/20 to-transparent"></div>
    </section>

    <!-- Overview Cards -->
    <section class="py-20 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <div class="regulation-card bg-white rounded-xl p-8 shadow-md text-center">
                    <div class="w-16 h-16 bg-indigo-100 rounded-lg flex items-center justify-center mx-auto mb-6">
                        <svg class="w-8 h-8 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                    </div>
                    <h3 class="text-xl font-bold mb-3">MiFID II Reporting</h3>
                    <p class="text-gray-600">Automated best execution analysis, instrument reference data management, and transaction reporting to national competent authorities.</p>
                </div>

                <div class="regulation-card bg-white rounded-xl p-8 shadow-md text-center">
                    <div class="w-16 h-16 bg-purple-100 rounded-lg flex items-center justify-center mx-auto mb-6">
                        <svg class="w-8 h-8 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                        </svg>
                    </div>
                    <h3 class="text-xl font-bold mb-3">MiCA Compliance</h3>
                    <p class="text-gray-600">Whitepaper validation, reserve management verification, licensing requirement checks, and stablecoin issuance compliance.</p>
                </div>

                <div class="regulation-card bg-white rounded-xl p-8 shadow-md text-center">
                    <div class="w-16 h-16 bg-green-100 rounded-lg flex items-center justify-center mx-auto mb-6">
                        <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                    <h3 class="text-xl font-bold mb-3">Travel Rule</h3>
                    <p class="text-gray-600">FATF-compliant originator and beneficiary data exchange with configurable threshold enforcement across all supported jurisdictions.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Regulation Detail Sections -->
    <section class="py-20 bg-gray-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <h2 class="text-4xl font-bold text-center text-gray-900 mb-12">Regulatory Frameworks</h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                <div class="bg-indigo-50 rounded-xl p-8">
                    <h3 class="text-2xl font-bold mb-6 text-indigo-900">MiFID II Reporting</h3>
                    <ul class="space-y-4">
                        <li class="flex items-start">
                            <svg class="w-6 h-6 text-indigo-600 mr-3 mt-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4"></path>
                            </svg>
                            <div>
                                <h4 class="font-semibold mb-1">Best Execution Analysis</h4>
                                <p class="text-gray-700">Automated evaluation of execution quality across venues, prices, costs, and speed metrics</p>
                            </div>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-6 h-6 text-indigo-600 mr-3 mt-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4"></path>
                            </svg>
                            <div>
                                <h4 class="font-semibold mb-1">Instrument Reference Data</h4>
                                <p class="text-gray-700">Comprehensive ISIN, LEI, and CFI code management with ESMA FIRDS integration</p>
                            </div>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-6 h-6 text-indigo-600 mr-3 mt-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4"></path>
                            </svg>
                            <div>
                                <h4 class="font-semibold mb-1">Transaction Reporting</h4>
                                <p class="text-gray-700">Automated generation of RTS 25 compliant reports with ARM submission support</p>
                            </div>
                        </li>
                    </ul>
                </div>

                <div class="bg-purple-50 rounded-xl p-8">
                    <h3 class="text-2xl font-bold mb-6 text-purple-900">MiCA Compliance</h3>
                    <ul class="space-y-4">
                        <li class="flex items-start">
                            <svg class="w-6 h-6 text-purple-600 mr-3 mt-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4"></path>
                            </svg>
                            <div>
                                <h4 class="font-semibold mb-1">Whitepaper Validation</h4>
                                <p class="text-gray-700">Automated checks for required disclosures, risk warnings, and regulatory content</p>
                            </div>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-6 h-6 text-purple-600 mr-3 mt-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4"></path>
                            </svg>
                            <div>
                                <h4 class="font-semibold mb-1">Reserve Management</h4>
                                <p class="text-gray-700">Real-time reserve adequacy monitoring for stablecoin and e-money token issuers</p>
                            </div>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-6 h-6 text-purple-600 mr-3 mt-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4"></path>
                            </svg>
                            <div>
                                <h4 class="font-semibold mb-1">Licensing Requirements</h4>
                                <p class="text-gray-700">CASP authorization tracking with renewal reminders and condition monitoring</p>
                            </div>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    <!-- Jurisdiction Adapters -->
    <section class="py-20 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <h2 class="text-4xl font-bold text-center text-gray-900 mb-12">Multi-Jurisdiction Adapters</h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-12 items-center">
                <div>
                    <p class="text-lg text-gray-600 mb-6">
                        The FinAegis RegTech engine uses a pluggable adapter architecture to support jurisdiction-specific compliance rules. Each adapter encapsulates the unique regulatory requirements of its target market, enabling seamless operation across borders.
                    </p>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="bg-gray-50 rounded-lg p-4 text-center">
                            <div class="text-2xl font-bold text-indigo-600 mb-1">US</div>
                            <p class="text-sm text-gray-600">SEC, FinCEN, OFAC</p>
                        </div>
                        <div class="bg-gray-50 rounded-lg p-4 text-center">
                            <div class="text-2xl font-bold text-purple-600 mb-1">EU</div>
                            <p class="text-sm text-gray-600">ESMA, EBA, MiCA</p>
                        </div>
                        <div class="bg-gray-50 rounded-lg p-4 text-center">
                            <div class="text-2xl font-bold text-green-600 mb-1">UK</div>
                            <p class="text-sm text-gray-600">FCA, PRA, HMRC</p>
                        </div>
                        <div class="bg-gray-50 rounded-lg p-4 text-center">
                            <div class="text-2xl font-bold text-pink-600 mb-1">SG</div>
                            <p class="text-sm text-gray-600">MAS, PSA, PDPA</p>
                        </div>
                    </div>
                </div>

                <div class="bg-gray-50 rounded-2xl p-8">
                    <h3 class="text-2xl font-bold mb-6 text-gray-900">Travel Rule Enforcement</h3>
                    <div class="space-y-4">
                        <div class="flex justify-between items-center p-4 bg-white rounded-lg">
                            <span class="text-gray-600">FATF Threshold Detection</span>
                            <span class="text-lg font-bold text-indigo-600">Automatic</span>
                        </div>
                        <div class="flex justify-between items-center p-4 bg-white rounded-lg">
                            <span class="text-gray-600">Originator Verification</span>
                            <span class="text-lg font-bold text-purple-600">Real-time</span>
                        </div>
                        <div class="flex justify-between items-center p-4 bg-white rounded-lg">
                            <span class="text-gray-600">Beneficiary Screening</span>
                            <span class="text-lg font-bold text-green-600">Multi-List</span>
                        </div>
                        <div class="flex justify-between items-center p-4 bg-white rounded-lg">
                            <span class="text-gray-600">VASP Data Exchange</span>
                            <span class="text-lg font-bold text-pink-600">Encrypted</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Technical Features Checklist -->
    <section class="py-20 bg-gray-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <h2 class="text-4xl font-bold text-center text-gray-900 mb-12">Compliance Orchestration</h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                <div class="bg-white rounded-xl p-8">
                    <h3 class="text-2xl font-bold mb-6">Regulatory Reporting</h3>
                    <ul class="space-y-3">
                        <li class="flex items-start">
                            <svg class="w-6 h-6 text-green-500 mr-3 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span>Automated report generation on configurable schedules</span>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-6 h-6 text-green-500 mr-3 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span>Suspicious activity report (SAR) filing workflows</span>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-6 h-6 text-green-500 mr-3 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span>Cross-border transaction monitoring with FATF thresholds</span>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-6 h-6 text-green-500 mr-3 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span>Sanctions list screening with real-time updates</span>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-6 h-6 text-green-500 mr-3 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span>Audit trail with tamper-proof event sourcing</span>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-6 h-6 text-green-500 mr-3 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span>Compliance dashboard with risk scoring and alerts</span>
                        </li>
                    </ul>
                </div>

                <div class="bg-white rounded-xl p-8">
                    <h3 class="text-2xl font-bold mb-6">Orchestration Engine</h3>
                    <ul class="space-y-3">
                        <li class="flex items-start">
                            <svg class="w-6 h-6 text-green-500 mr-3 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span>Unified compliance pipeline for all regulations</span>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-6 h-6 text-green-500 mr-3 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span>Pluggable jurisdiction adapters with hot-reload</span>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-6 h-6 text-green-500 mr-3 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span>Rule versioning for regulation change management</span>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-6 h-6 text-green-500 mr-3 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span>Pre-transaction compliance checks with fail-fast</span>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-6 h-6 text-green-500 mr-3 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span>Webhook notifications for compliance events</span>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-6 h-6 text-green-500 mr-3 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span>RegTech API for third-party compliance integration</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="py-20 gradient-bg text-white">
        <div class="max-w-4xl mx-auto text-center px-4 sm:px-6 lg:px-8">
            <h2 class="text-4xl font-bold mb-6">Stay Compliant, Stay Ahead</h2>
            <p class="text-xl mb-8 text-purple-100">
                Automate your regulatory obligations and focus on building your business. Our RegTech engine handles the complexity so you do not have to.
            </p>
            <div class="flex flex-col sm:flex-row gap-4 justify-center">
                <a href="{{ route('register') }}" class="bg-white text-indigo-600 px-8 py-4 rounded-lg text-lg font-semibold hover:bg-gray-100 transition">
                    Get Started
                </a>
                <a href="{{ route('developers') }}" class="border-2 border-white text-white px-8 py-4 rounded-lg text-lg font-semibold hover:bg-white hover:text-indigo-600 transition">
                    Compliance API Docs
                </a>
            </div>
        </div>
    </section>

@endsection
