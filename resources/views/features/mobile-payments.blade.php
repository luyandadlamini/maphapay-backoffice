@extends('layouts.public')

@section('title', 'Mobile Payments - FinAegis')

@section('seo')
    @include('partials.seo', [
        'title' => 'Mobile Payments',
        'description' => 'Next-generation mobile payment infrastructure with Payment Intents, Passkey Authentication, P2P transfers, and ERC-4337 gasless transactions.',
        'keywords' => 'mobile payments, payment intents, passkey authentication, FIDO2, WebAuthn, P2P transfers, QR code, gasless transactions, ERC-4337, FinAegis',
    ])

    {{-- Schema.org Markup --}}
    <x-schema type="software" />
    <x-schema type="breadcrumb" :data="[
        ['name' => 'Home', 'url' => url('/')],
        ['name' => 'Features', 'url' => url('/features')],
        ['name' => 'Mobile Payments', 'url' => url('/features/mobile-payments')]
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
                <h1 class="font-display text-5xl lg:text-6xl font-extrabold text-white tracking-tight mb-6">Mobile Payments</h1>
                <p class="text-lg text-slate-400 max-w-3xl mx-auto">
                    A complete mobile payment infrastructure built for the next generation of finance. From Payment Intents to gasless ERC-4337 transactions, everything your users need in the palm of their hand.
                </p>
            </div>
        </div>
        <div class="absolute bottom-0 left-0 right-0 h-px bg-gradient-to-r from-transparent via-blue-500/20 to-transparent"></div>
    </section>

    <!-- Overview Cards -->
    <section class="py-20 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <div class="feature-card bg-white rounded-xl p-8 shadow-md text-center">
                    <div class="w-16 h-16 bg-indigo-100 rounded-lg flex items-center justify-center mx-auto mb-6">
                        <svg class="w-8 h-8 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path>
                        </svg>
                    </div>
                    <h3 class="text-xl font-bold mb-3">Payment Intents</h3>
                    <p class="text-gray-600">Full lifecycle management with create, submit, and cancel flows. Track every payment from initiation to settlement.</p>
                </div>

                <div class="feature-card bg-white rounded-xl p-8 shadow-md text-center">
                    <div class="w-16 h-16 bg-purple-100 rounded-lg flex items-center justify-center mx-auto mb-6">
                        <svg class="w-8 h-8 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11c0 3.517-1.009 6.799-2.753 9.571m-3.44-2.04l.054-.09A13.916 13.916 0 008 11a4 4 0 118 0c0 1.017-.07 2.019-.203 3m-2.118 6.844A21.88 21.88 0 0015.171 17m3.839 1.132c.645-2.266.99-4.659.99-7.132A8 8 0 008 4.07M3 15.364c.64-1.319 1-2.8 1-4.364 0-1.457.39-2.823 1.07-4"></path>
                        </svg>
                    </div>
                    <h3 class="text-xl font-bold mb-3">Passkey Authentication</h3>
                    <p class="text-gray-600">FIDO2/WebAuthn-based passwordless authentication. Biometric login with hardware-backed security for every transaction.</p>
                </div>

                <div class="feature-card bg-white rounded-xl p-8 shadow-md text-center">
                    <div class="w-16 h-16 bg-green-100 rounded-lg flex items-center justify-center mx-auto mb-6">
                        <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                        </svg>
                    </div>
                    <h3 class="text-xl font-bold mb-3">Gasless Transactions</h3>
                    <p class="text-gray-600">ERC-4337 account abstraction enables gas-free blockchain transactions. Users never need to hold ETH for fees.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Payment Intent Lifecycle -->
    <section class="py-20 bg-gray-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <h2 class="text-4xl font-bold text-center text-gray-900 mb-12">Payment Intent Lifecycle</h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-12 items-center">
                <div>
                    <p class="text-lg text-gray-600 mb-6">
                        The Payment Intent system provides a structured, auditable approach to mobile payments. Every transaction moves through clearly defined states, enabling robust error handling, compliance checks, and user confirmation flows.
                    </p>
                    <div class="space-y-4">
                        <div class="flex items-start">
                            <div class="flex-shrink-0 w-10 h-10 bg-indigo-100 rounded-full flex items-center justify-center mr-4">
                                <span class="text-indigo-600 font-bold">1</span>
                            </div>
                            <div>
                                <h4 class="font-semibold mb-1">Create Intent</h4>
                                <p class="text-gray-600">Initialize a payment with amount, currency, recipient, and metadata. The system validates parameters and reserves funds.</p>
                            </div>
                        </div>
                        <div class="flex items-start">
                            <div class="flex-shrink-0 w-10 h-10 bg-purple-100 rounded-full flex items-center justify-center mr-4">
                                <span class="text-purple-600 font-bold">2</span>
                            </div>
                            <div>
                                <h4 class="font-semibold mb-1">Submit Payment</h4>
                                <p class="text-gray-600">User confirms the payment with biometric or passkey authentication. The system processes the transfer atomically.</p>
                            </div>
                        </div>
                        <div class="flex items-start">
                            <div class="flex-shrink-0 w-10 h-10 bg-green-100 rounded-full flex items-center justify-center mr-4">
                                <span class="text-green-600 font-bold">3</span>
                            </div>
                            <div>
                                <h4 class="font-semibold mb-1">Settlement or Cancellation</h4>
                                <p class="text-gray-600">Payment settles instantly or can be cancelled before submission. Full receipt generation with digital signatures.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-2xl p-8 shadow-lg">
                    <h3 class="text-2xl font-bold mb-6 text-gray-900">Payment Capabilities</h3>
                    <div class="space-y-4">
                        <div class="flex justify-between items-center p-4 bg-gray-50 rounded-lg">
                            <span class="text-gray-600">P2P Transfers</span>
                            <span class="text-lg font-bold text-indigo-600">Instant</span>
                        </div>
                        <div class="flex justify-between items-center p-4 bg-gray-50 rounded-lg">
                            <span class="text-gray-600">QR Code Payments</span>
                            <span class="text-lg font-bold text-purple-600">Scan-to-Pay</span>
                        </div>
                        <div class="flex justify-between items-center p-4 bg-gray-50 rounded-lg">
                            <span class="text-gray-600">Receipt Generation</span>
                            <span class="text-lg font-bold text-green-600">Automatic</span>
                        </div>
                        <div class="flex justify-between items-center p-4 bg-gray-50 rounded-lg">
                            <span class="text-gray-600">Activity Feed</span>
                            <span class="text-lg font-bold text-pink-600">Real-time</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- P2P and QR Code -->
    <section class="py-20 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <h2 class="text-4xl font-bold text-center text-gray-900 mb-12">Peer-to-Peer and QR Payments</h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                <div class="bg-indigo-50 rounded-xl p-8">
                    <h3 class="text-2xl font-bold mb-6 text-indigo-900">P2P Transfer System</h3>
                    <ul class="space-y-4">
                        <li class="flex items-start">
                            <svg class="w-6 h-6 text-indigo-600 mr-3 mt-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4"></path>
                            </svg>
                            <div>
                                <h4 class="font-semibold mb-1">Contact-Based Sending</h4>
                                <p class="text-gray-700">Send to contacts by username, email, or phone number with instant resolution</p>
                            </div>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-6 h-6 text-indigo-600 mr-3 mt-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4"></path>
                            </svg>
                            <div>
                                <h4 class="font-semibold mb-1">Split Payments</h4>
                                <p class="text-gray-700">Divide bills among multiple recipients with automatic calculations</p>
                            </div>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-6 h-6 text-indigo-600 mr-3 mt-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4"></path>
                            </svg>
                            <div>
                                <h4 class="font-semibold mb-1">Payment Requests</h4>
                                <p class="text-gray-700">Request money from other users with customizable messages and amounts</p>
                            </div>
                        </li>
                    </ul>
                </div>

                <div class="bg-purple-50 rounded-xl p-8">
                    <h3 class="text-2xl font-bold mb-6 text-purple-900">QR Code Receive Addresses</h3>
                    <ul class="space-y-4">
                        <li class="flex items-start">
                            <svg class="w-6 h-6 text-purple-600 mr-3 mt-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4"></path>
                            </svg>
                            <div>
                                <h4 class="font-semibold mb-1">Dynamic QR Codes</h4>
                                <p class="text-gray-700">Generate unique receive addresses with embedded amount and metadata</p>
                            </div>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-6 h-6 text-purple-600 mr-3 mt-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4"></path>
                            </svg>
                            <div>
                                <h4 class="font-semibold mb-1">Merchant Integration</h4>
                                <p class="text-gray-700">Point-of-sale compatible QR codes for in-store payments</p>
                            </div>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-6 h-6 text-purple-600 mr-3 mt-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4"></path>
                            </svg>
                            <div>
                                <h4 class="font-semibold mb-1">Multi-Chain Support</h4>
                                <p class="text-gray-700">QR addresses supporting multiple blockchain networks and tokens</p>
                            </div>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    <!-- Technical Features Checklist -->
    <section class="py-20 bg-gray-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <h2 class="text-4xl font-bold text-center text-gray-900 mb-12">Technical Capabilities</h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                <div class="bg-white rounded-xl p-8">
                    <h3 class="text-2xl font-bold mb-6">Authentication and Security</h3>
                    <ul class="space-y-3">
                        <li class="flex items-start">
                            <svg class="w-6 h-6 text-green-500 mr-3 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span>FIDO2/WebAuthn passkey registration and verification</span>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-6 h-6 text-green-500 mr-3 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span>Biometric JWT tokens for transaction signing</span>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-6 h-6 text-green-500 mr-3 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span>Device binding and hardware-backed key storage</span>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-6 h-6 text-green-500 mr-3 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span>Multi-factor authentication with passkey fallback</span>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-6 h-6 text-green-500 mr-3 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span>Session management with secure token rotation</span>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-6 h-6 text-green-500 mr-3 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span>Network status monitoring and offline resilience</span>
                        </li>
                    </ul>
                </div>

                <div class="bg-white rounded-xl p-8">
                    <h3 class="text-2xl font-bold mb-6">ERC-4337 and Gas Abstraction</h3>
                    <ul class="space-y-3">
                        <li class="flex items-start">
                            <svg class="w-6 h-6 text-green-500 mr-3 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span>Smart Account creation and management</span>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-6 h-6 text-green-500 mr-3 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span>UserOperation signing with biometric JWT</span>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-6 h-6 text-green-500 mr-3 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span>Gas Station network for fee sponsorship</span>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-6 h-6 text-green-500 mr-3 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span>Bundler integration for batched operations</span>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-6 h-6 text-green-500 mr-3 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span>Wallet balance aggregation across chains</span>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-6 h-6 text-green-500 mr-3 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span>Activity feed with real-time push notifications</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="py-20 gradient-bg text-white">
        <div class="max-w-4xl mx-auto text-center px-4 sm:px-6 lg:px-8">
            <h2 class="text-4xl font-bold mb-6">Build Mobile-First Finance</h2>
            <p class="text-xl mb-8 text-purple-100">
                Integrate our mobile payment APIs and deliver frictionless financial experiences to your users today.
            </p>
            <div class="flex flex-col sm:flex-row gap-4 justify-center">
                <a href="{{ route('register') }}" class="bg-white text-indigo-600 px-8 py-4 rounded-lg text-lg font-semibold hover:bg-gray-100 transition">
                    Get Started
                </a>
                <a href="{{ route('developers') }}" class="border-2 border-white text-white px-8 py-4 rounded-lg text-lg font-semibold hover:bg-white hover:text-indigo-600 transition">
                    View API Docs
                </a>
            </div>
        </div>
    </section>

@endsection
