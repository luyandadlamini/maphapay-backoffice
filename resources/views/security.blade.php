@extends('layouts.public')

@section('title', 'Security - Bank-Grade Protection | FinAegis')

@section('seo')
    @include('partials.seo', [
        'title' => 'Security - Bank-Grade Protection | FinAegis',
        'description' => 'FinAegis security overview - Bank-grade security meets blockchain immutability. Learn about our security measures and best practices.',
        'keywords' => 'FinAegis security, bank-grade security, blockchain security, secure banking, cybersecurity, data protection',
    ])
    
    {{-- Schema.org Markup --}}
    <x-schema type="service" :data="[
        'name' => 'FinAegis Security',
        'description' => 'Bank-grade security for the FinAegis platform',
        'category' => 'Financial Security'
    ]" />
    <x-schema type="breadcrumb" :data="[
        ['name' => 'Home', 'url' => url('/')],
        ['name' => 'Security', 'url' => url('/security')]
    ]" />
@endsection

@push('styles')
<style>
    .security-feature {
        transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .security-feature:hover {
        transform: translateY(-3px);
        box-shadow: 0 12px 30px rgba(0,0,0,0.08);
    }
</style>
@endpush

@section('content')
    <!-- Hero Section -->
    <section class="bg-fa-navy relative overflow-hidden">
        <div class="absolute inset-0 bg-grid-pattern"></div>
        <div class="absolute top-1/3 right-1/4 w-80 h-80 bg-teal-500/8 rounded-full blur-[100px]"></div>
        <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-20 lg:py-24">
            <div class="text-center">
                <div class="inline-flex items-center justify-center w-16 h-16 bg-white/[0.04] border border-white/[0.08] rounded-2xl mb-6">
                    <svg class="w-8 h-8 text-teal-400" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z"/>
                    </svg>
                </div>
                <h1 class="font-display text-5xl lg:text-6xl font-extrabold text-white tracking-tight mb-6">Security <span class="text-gradient">Architecture</span></h1>
                <p class="text-lg text-slate-400 max-w-2xl mx-auto">
                    Multi-layered security with HMAC integrity verification, HSM key management, Shamir secret sharing, and comprehensive audit trails.
                </p>
            </div>
        </div>
        <div class="absolute bottom-0 left-0 right-0 h-px bg-gradient-to-r from-transparent via-blue-500/20 to-transparent"></div>
    </section>

    <!-- Development Notice -->
    <section class="py-8 bg-amber-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="bg-white rounded-lg shadow p-6 border-l-4 border-amber-400">
                <div class="flex items-start">
                    <div class="flex-shrink-0">
                        <svg class="w-6 h-6 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                        </svg>
                    </div>
                    <div class="ml-3">
                        <h3 class="text-lg font-semibold text-gray-900">Security Implementation Status</h3>
                        <p class="mt-2 text-gray-600">
                            FinAegis implements production-grade security patterns throughout the codebase.
                            Features below are marked as <strong>implemented</strong> or <strong>planned</strong>.
                            The platform is under active development with new security hardening in every release.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Current State Section -->
    <section class="py-20 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-16">
                <h2 class="text-4xl font-bold text-gray-900 mb-4">Enterprise Security Standards</h2>
                <p class="text-xl text-gray-600">Comprehensive security at every level</p>
            </div>
            
            <div class="max-w-4xl mx-auto">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    <!-- Infrastructure Security -->
                    <div class="bg-green-50 rounded-xl p-8">
                        <h3 class="text-xl font-semibold text-green-900 mb-4 flex items-center">
                            <svg class="w-6 h-6 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            Infrastructure Security
                        </h3>
                        <ul class="space-y-3 text-gray-700">
                            <li class="flex items-start">
                                <span class="text-green-600 mr-2">•</span>
                                End-to-end encryption (TLS 1.3)
                            </li>
                            <li class="flex items-start">
                                <span class="text-green-600 mr-2">•</span>
                                DDoS protection & rate limiting
                            </li>
                            <li class="flex items-start">
                                <span class="text-green-600 mr-2">•</span>
                                Multi-region data redundancy
                            </li>
                            <li class="flex items-start">
                                <span class="text-green-600 mr-2">•</span>
                                24/7 security monitoring
                            </li>
                            <li class="flex items-start">
                                <span class="text-green-600 mr-2">•</span>
                                Regular penetration testing
                            </li>
                            <li class="flex items-start">
                                <span class="text-green-600 mr-2">•</span>
                                ISO 27001 compliance ready
                            </li>
                        </ul>
                    </div>
                    
                    <!-- Application Security -->
                    <div class="bg-blue-50 rounded-xl p-8">
                        <h3 class="text-xl font-semibold text-blue-900 mb-4 flex items-center">
                            <svg class="w-6 h-6 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                            </svg>
                            Application Security
                        </h3>
                        <ul class="space-y-3 text-gray-700">
                            <li class="flex items-start">
                                <span class="text-blue-600 mr-2">•</span>
                                Multi-factor authentication (2FA)
                            </li>
                            <li class="flex items-start">
                                <span class="text-blue-600 mr-2">•</span>
                                Advanced password policies
                            </li>
                            <li class="flex items-start">
                                <span class="text-blue-600 mr-2">•</span>
                                Session security & timeout
                            </li>
                            <li class="flex items-start">
                                <span class="text-blue-600 mr-2">•</span>
                                CSRF & XSS protection
                            </li>
                            <li class="flex items-start">
                                <span class="text-blue-600 mr-2">•</span>
                                SQL injection prevention
                            </li>
                            <li class="flex items-start">
                                <span class="text-blue-600 mr-2">•</span>
                                API authentication & rate limiting
                            </li>
                        </ul>
                    </div>
                </div>
                
                <!-- Compliance & Standards -->
                <div class="mt-8 bg-purple-50 rounded-xl p-8">
                    <h3 class="text-xl font-semibold text-purple-900 mb-4 flex items-center">
                        <svg class="w-6 h-6 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                        </svg>
                        Compliance & Standards
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div class="bg-white rounded-lg p-4">
                            <h4 class="font-semibold text-gray-900 mb-2">GDPR Enhanced</h4>
                            <p class="text-sm text-gray-600">ROPA, DPIA, breach notification, consent management v2, and data retention policies (v3.5.0)</p>
                        </div>
                        <div class="bg-white rounded-lg p-4">
                            <h4 class="font-semibold text-gray-900 mb-2">SOC 2 Type II</h4>
                            <p class="text-sm text-gray-600">Continuous control monitoring, evidence collection, and audit readiness tooling (v3.5.0)</p>
                        </div>
                        <div class="bg-white rounded-lg p-4">
                            <h4 class="font-semibold text-gray-900 mb-2">PCI DSS Readiness</h4>
                            <p class="text-sm text-gray-600">Payment card industry compliance with scoping, gap analysis, and remediation tracking (v3.5.0)</p>
                        </div>
                        <div class="bg-white rounded-lg p-4">
                            <h4 class="font-semibold text-gray-900 mb-2">Financial Compliance</h4>
                            <p class="text-sm text-gray-600">KYC/AML procedures, MiFID II, MiCA, and Travel Rule regulatory reporting</p>
                        </div>
                        <div class="bg-white rounded-lg p-4">
                            <h4 class="font-semibold text-gray-900 mb-2">Multi-Region Deployment</h4>
                            <p class="text-sm text-gray-600">Data sovereignty compliance with multi-region deployment support (v3.5.0)</p>
                        </div>
                        <div class="bg-white rounded-lg p-4">
                            <h4 class="font-semibold text-gray-900 mb-2">Industry Standards</h4>
                            <p class="text-sm text-gray-600">ISO 27001 readiness and comprehensive security framework alignment</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Security Features -->
    <section class="py-20 bg-gray-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-12">
                <h2 class="text-4xl font-bold text-gray-900 mb-4">Security Features & Roadmap</h2>
                <p class="text-xl text-gray-600">Implemented security measures and upcoming enhancements</p>
            </div>
            
            <!-- Currently Implemented -->
            <div class="mb-16">
                <h3 class="text-2xl font-bold text-gray-900 mb-8 text-center">
                    <span class="inline-flex items-center px-4 py-2 bg-green-100 text-green-800 rounded-full">
                        Currently Implemented
                    </span>
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                    <!-- Performance Monitoring -->
                    <div class="security-feature bg-white rounded-xl shadow-lg p-8">
                        <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mb-6">
                            <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-gray-900 mb-4">Performance Monitoring</h3>
                        <p class="text-gray-600">
                            Near real-time system monitoring with 5-minute granularity, tracking performance metrics and system health.
                        </p>
                    </div>

                    <!-- 2FA -->
                    <div class="security-feature bg-white rounded-xl shadow-lg p-8">
                        <div class="w-16 h-16 bg-indigo-100 rounded-full flex items-center justify-center mb-6">
                            <svg class="w-8 h-8 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-gray-900 mb-4">Two-Factor Authentication</h3>
                        <p class="text-gray-600">
                            Available for all users with enhanced security options for administrative accounts.
                        </p>
                    </div>

                    <!-- Rate Limiting -->
                    <div class="security-feature bg-white rounded-xl shadow-lg p-8">
                        <div class="w-16 h-16 bg-purple-100 rounded-full flex items-center justify-center mb-6">
                            <svg class="w-8 h-8 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-gray-900 mb-4">Advanced Rate Limiting</h3>
                        <p class="text-gray-600">
                            Dynamic rate limiting with user trust levels and tier-aware throttling, protecting against DDoS and brute force attacks.
                        </p>
                    </div>

                    <!-- IP Blocking -->
                    <div class="security-feature bg-white rounded-xl shadow-lg p-8">
                        <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mb-6">
                            <svg class="w-8 h-8 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-gray-900 mb-4">IP Blocking</h3>
                        <p class="text-gray-600">
                            Automatic IP blocking after 10 failed attempts, with temporary and permanent blacklist support.
                        </p>
                    </div>

                    <!-- Session Management -->
                    <div class="security-feature bg-white rounded-xl shadow-lg p-8">
                        <div class="w-16 h-16 bg-yellow-100 rounded-full flex items-center justify-center mb-6">
                            <svg class="w-8 h-8 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-gray-900 mb-4">Session Security</h3>
                        <p class="text-gray-600">
                            Maximum 5 concurrent sessions per user with automatic cleanup of old sessions.
                        </p>
                    </div>

                    <!-- Audit Logging -->
                    <div class="security-feature bg-white rounded-xl shadow-lg p-8">
                        <div class="w-16 h-16 bg-blue-100 rounded-full flex items-center justify-center mb-6">
                            <svg class="w-8 h-8 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-gray-900 mb-4">Audit Logging</h3>
                        <p class="text-gray-600">
                            Comprehensive audit trails for all transactions and security-relevant events.
                        </p>
                    </div>

                    <!-- Biometric Auth -->
                    <div class="security-feature bg-white rounded-xl shadow-lg p-8">
                        <div class="w-16 h-16 bg-indigo-100 rounded-full flex items-center justify-center mb-6">
                            <svg class="w-8 h-8 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11c0 3.517-1.009 6.799-2.753 9.571m-3.44-2.04l.054-.09A13.916 13.916 0 008 11a4 4 0 118 0c0 1.017-.07 2.019-.203 3m-2.118 6.844A21.88 21.88 0 0015.171 17m3.839 1.132c.645-2.266.99-4.659.99-7.132A8 8 0 008 4.07M3 15.364c.64-1.319 1-2.8 1-4.364 0-1.457.39-2.823 1.07-4"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-gray-900 mb-2">Biometric Authentication</h3>
                        <span class="inline-block px-2 py-1 bg-green-100 text-green-700 text-xs rounded-full mb-3">Implemented v2.2.0</span>
                        <p class="text-gray-600">
                            Fingerprint and facial recognition authentication via BiometricAuthenticationService with JWT-based biometric tokens.
                        </p>
                    </div>

                    <!-- Hardware Security Keys -->
                    <div class="security-feature bg-white rounded-xl shadow-lg p-8">
                        <div class="w-16 h-16 bg-purple-100 rounded-full flex items-center justify-center mb-6">
                            <svg class="w-8 h-8 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-gray-900 mb-2">Hardware Security Keys</h3>
                        <span class="inline-block px-2 py-1 bg-green-100 text-green-700 text-xs rounded-full mb-3">Implemented v2.1.0</span>
                        <p class="text-gray-600">
                            FIDO2/WebAuthn hardware wallet support via HardwareWalletManager with Ledger and Trezor signing services.
                        </p>
                    </div>

                    <!-- Zero-Knowledge Proofs -->
                    <div class="security-feature bg-white rounded-xl shadow-lg p-8">
                        <div class="w-16 h-16 bg-teal-100 rounded-full flex items-center justify-center mb-6">
                            <svg class="w-8 h-8 text-teal-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-gray-900 mb-2">Zero-Knowledge Proofs</h3>
                        <span class="inline-block px-2 py-1 bg-green-100 text-green-700 text-xs rounded-full mb-3">Implemented v2.4.0</span>
                        <p class="text-gray-600">
                            Privacy-preserving ZK-KYC verification, Proof of Innocence, Merkle tree commitments, and delegated proofs.
                        </p>
                    </div>

                    <!-- Passkey Authentication -->
                    <div class="security-feature bg-white rounded-xl shadow-lg p-8">
                        <div class="w-16 h-16 bg-cyan-100 rounded-full flex items-center justify-center mb-6">
                            <svg class="w-8 h-8 text-cyan-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-gray-900 mb-2">Passkey Authentication</h3>
                        <span class="inline-block px-2 py-1 bg-green-100 text-green-700 text-xs rounded-full mb-3">Implemented v2.7.0</span>
                        <p class="text-gray-600">
                            Passwordless authentication using FIDO2 passkeys via PasskeyAuthenticationService for seamless, phishing-resistant login.
                        </p>
                    </div>

                    <!-- SOC 2 Type II -->
                    <div class="security-feature bg-white rounded-xl shadow-lg p-8">
                        <div class="w-16 h-16 bg-emerald-100 rounded-full flex items-center justify-center mb-6">
                            <svg class="w-8 h-8 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-gray-900 mb-2">SOC 2 Type II Compliance</h3>
                        <span class="inline-block px-2 py-1 bg-green-100 text-green-700 text-xs rounded-full mb-3">Implemented v3.5.0</span>
                        <p class="text-gray-600">
                            SOC 2 Type II certification tooling with continuous control monitoring, evidence collection, and audit readiness.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Roadmap Features -->
            <div>
                <h3 class="text-2xl font-bold text-gray-900 mb-8 text-center">
                    <span class="inline-flex items-center px-4 py-2 bg-yellow-100 text-yellow-800 rounded-full">
                        On Our Roadmap
                    </span>
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                    <!-- AI Fraud Detection -->
                    <div class="security-feature bg-white rounded-xl shadow-lg p-8 opacity-75">
                        <div class="w-16 h-16 bg-green-50 rounded-full flex items-center justify-center mb-6">
                            <svg class="w-8 h-8 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-gray-900 mb-2">AI Fraud Detection</h3>
                        <span class="inline-block px-2 py-1 bg-yellow-100 text-yellow-700 text-xs rounded-full mb-3">In Development</span>
                        <p class="text-gray-600">
                            Machine learning models for real-time fraud detection and prevention.
                        </p>
                    </div>

                    <!-- 24/7 SOC -->
                    <div class="security-feature bg-white rounded-xl shadow-lg p-8 opacity-75">
                        <div class="w-16 h-16 bg-red-50 rounded-full flex items-center justify-center mb-6">
                            <svg class="w-8 h-8 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.618 5.984A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016zM12 9v2m0 4h.01"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-gray-900 mb-2">24/7 Security Operations</h3>
                        <span class="inline-block px-2 py-1 bg-yellow-100 text-yellow-700 text-xs rounded-full mb-3">Future</span>
                        <p class="text-gray-600">
                            Dedicated security operations center for incident response.
                        </p>
                    </div>

                    <!-- Real-time Monitoring Upgrade -->
                    <div class="security-feature bg-white rounded-xl shadow-lg p-8 opacity-75">
                        <div class="w-16 h-16 bg-blue-50 rounded-full flex items-center justify-center mb-6">
                            <svg class="w-8 h-8 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-gray-900 mb-2">Real-time Monitoring</h3>
                        <span class="inline-block px-2 py-1 bg-yellow-100 text-yellow-700 text-xs rounded-full mb-3">Upgrade Planned</span>
                        <p class="text-gray-600">
                            Enhance monitoring from 5-minute to sub-second granularity.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>


    <!-- User Security Tips -->
    <section class="py-20 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-12">
                <h2 class="text-4xl font-bold text-gray-900 mb-4">Protect Your Account</h2>
                <p class="text-xl text-gray-600">Best practices to keep your account secure</p>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-8 max-w-4xl mx-auto">
                <div class="bg-indigo-50 rounded-lg p-6">
                    <h3 class="text-lg font-semibold text-indigo-900 mb-4">Do's</h3>
                    <ul class="space-y-3">
                        <li class="flex items-start">
                            <svg class="w-5 h-5 text-green-600 mr-2 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span class="text-gray-700">Enable two-factor authentication (2FA)</span>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-5 h-5 text-green-600 mr-2 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span class="text-gray-700">Use a unique, strong password</span>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-5 h-5 text-green-600 mr-2 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span class="text-gray-700">Verify email sender addresses</span>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-5 h-5 text-green-600 mr-2 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span class="text-gray-700">Keep your devices updated</span>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-5 h-5 text-green-600 mr-2 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span class="text-gray-700">Review account activity regularly</span>
                        </li>
                    </ul>
                </div>
                
                <div class="bg-red-50 rounded-lg p-6">
                    <h3 class="text-lg font-semibold text-red-900 mb-4">Don'ts</h3>
                    <ul class="space-y-3">
                        <li class="flex items-start">
                            <svg class="w-5 h-5 text-red-600 mr-2 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                            <span class="text-gray-700">Share your password or API keys</span>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-5 h-5 text-red-600 mr-2 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                            <span class="text-gray-700">Click on suspicious links</span>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-5 h-5 text-red-600 mr-2 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                            <span class="text-gray-700">Use public WiFi for banking</span>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-5 h-5 text-red-600 mr-2 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                            <span class="text-gray-700">Install unverified browser extensions</span>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-5 h-5 text-red-600 mr-2 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                            <span class="text-gray-700">Ignore security warnings</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    <!-- Security Contact -->
    <section class="py-20 gradient-bg text-white">
        <div class="max-w-4xl mx-auto text-center px-4 sm:px-6 lg:px-8">
            <h2 class="text-4xl font-bold mb-6">Security First Approach</h2>
            <p class="text-xl mb-8 text-purple-100">
                We take security seriously. Our team works around the clock to ensure your assets and data are protected.
            </p>
            <div class="flex flex-col sm:flex-row gap-4 justify-center">
                <a href="{{ route('support') }}" class="bg-white text-indigo-600 px-8 py-4 rounded-lg text-lg font-semibold hover:bg-gray-100 transition">
                    Contact Security Team
                </a>
                <a href="{{ route('compliance') }}" class="border-2 border-white text-white px-8 py-4 rounded-lg text-lg font-semibold hover:bg-white hover:text-indigo-600 transition">
                    View Compliance
                </a>
            </div>
        </div>
    </section>
@endsection