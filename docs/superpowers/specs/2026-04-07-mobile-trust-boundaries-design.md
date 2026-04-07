# Mobile Trust Boundaries, Deep Links / QR, And Hardening Design

Date: 2026-04-07

## Summary

This design converts the current mobile security pieces into one explicit trust model for high-risk mobile actions.

The goal is not to replace the current RN app architecture. The goal is to:

- define the trust signals the server will rely on,
- separate local authentication from device/app integrity,
- standardize trusted deep-link and QR formats,
- and enforce policy on high-risk actions using those signals.

## Current State

The current mobile stack already provides:

- secure token storage
- biometric-protected local secret storage
- session unlock with local biometrics
- universal links and app links
- server-validated scan flows in some QR paths
- backend biometric challenge/verify flows
- backend scaffolding for App Attest / Play Integrity verification
- backend SSL-pin distribution

The current stack does **not** yet clearly define:

- a single trust envelope for risky mobile requests
- a single canonical QR/deep-link authority model
- or one enforcement policy combining local auth, device, session, and attestation state

## Target State

The target mobile trust model uses four distinct signal classes:

- `SessionTrust`
- `DeviceTrust`
- `AppIntegrityTrust`
- `InteractionTrust`

They are intentionally separate:

- local biometric/PIN proves user interaction
- session proves authenticated continuity
- device trust proves recognized device posture
- app integrity proves request provenance from a legitimate app instance

This matches MASVS and current Apple/Google/Expo guidance.[^masvs][^expo-app-integrity][^apple-app-attest][^google-play-integrity]

## Core Decisions

### 1. Local auth is not app integrity

Biometric or PIN unlock remains useful, but it must only count as `InteractionTrust`.

It must not be treated as proof of:

- genuine app binary
- uncompromised device
- trusted release channel

### 2. High-risk actions require a trust envelope

For actions such as:

- send money
- request-money acceptance
- merchant pay
- card cancellation
- sensitive wallet-management actions

the server should evaluate a trust envelope containing at least:

- authenticated user/session
- selected device identifier
- attestation verdict or attestation status
- recent biometric/PIN verification state
- optional risk signals from fraud/device profiling

### 3. Attestation is enforced server-side, not inferred

Backend attestation scaffolding already exists, but production enforcement is not enabled by default today. The missing piece is enforced usage.

First-slice decision:

- add client collection and submission of attestation tokens for risky actions
- have the server persist the attestation result as a request-context signal
- use policy to decide `allow`, `step_up`, `degrade`, or `deny`

Current-state caveat:

- `MOBILE_ATTESTATION_ENABLED` is currently default-disabled
- current handover docs explicitly describe demo-mode acceptance of any non-empty attestation when enforcement is off
- the current Apple verifier is a baseline structural check, not full production-grade App Attest validation

Do not rely on client-only checks or documentation promises.

### 4. QR and deep links become server-authoritative

The target state should reduce QR/deep-link trust to two authoritative patterns:

- server-issued HTTPS links with opaque tokens
- server-resolved merchant references where the server decides merchant identity and payable constraints

Legacy custom-scheme payload parsing remains transitional compatibility only.

Required direction:

- no free-form merchant payment payload should be treated as authoritative on-device
- merchant and request tokens should be looked up server-side
- payload signing may be used for offline/static use cases, but server-issued opaque tokens are preferred for the first hardening slice

### 5. Amount-binding must be explicit

If a QR or payment link encodes an amount, the system must define whether it is:

- fixed and non-editable
- suggested but user-editable
- or merchant-decided server-side

That rule must be enforced by the backend, not just expressed in UI text.

The current pay-from-link flow implies amount mutability, so the first hardening slice must make that policy explicit and consistent.

### 6. Certificate-pinning and rooted-device posture are policy surfaces

The backend already exposes SSL pins, but the client must explicitly consume them or this control is only latent capability.

Likewise, rooted/jailbroken-device handling should be specified as:

- deny
- step-up only
- degraded access
- or monitor-only

This policy must be explicit by action class.

## Public Interface And Data Model Changes

### New backend concepts

- `MobileTrustVerdict`
- `MobileAttestationRecord`
- `HighRiskActionTrustPolicy`
- `TrustedPaymentLink` / canonical link metadata if not already modeled elsewhere

### Existing concepts that remain

- biometric verification endpoints
- `BiometricJWTService`
- `AppleAttestationVerifier`
- `GoogleIntegrityVerifier`
- SSL pin endpoint
- current RN secure storage and local-auth flows

### Existing concepts whose semantics change

- local biometrics become one input into a wider trust envelope
- QR parsing helpers become compatibility helpers, not the source of payment authority
- legacy custom-scheme QR acceptance becomes progressively restricted

## Flow Design

### High-risk mobile action flow

1. app ensures authenticated session exists
2. app gathers local interaction proof as policy requires
3. app submits device/app integrity proof when the action class requires it
4. server resolves trust envelope from user, device, session, attestation, and recent verification context
5. policy returns `allow`, `step_up`, `degrade`, or `deny`
6. only then does the financial action proceed

### Target Trusted Payment Link Flow

1. app receives `https://pay.maphapay.com/...` universal link
2. app extracts opaque token only
3. server resolves token to current payment authority, payee identity, amount-binding rule, expiry, and status
4. client displays the server-derived state
5. final payment authorization submits token reference, not reconstructed client payload authority

Current-state caveat:

- the app currently fetches pay-link state by token for UI display, but the final payment path does not yet propagate that token as server-bound payment authority
- this is therefore a target-state flow, not a claim about the current end-to-end implementation

### QR scan flow

1. scanner reads raw QR data
2. app normalizes raw data only enough to identify candidate type
3. server resolves merchant or request identity authoritatively
4. unsupported or legacy payloads are rejected or downgraded under compatibility policy

## Failure Modes

The design must explicitly handle:

- a legitimate user on an untrusted device
- a trusted device without fresh interaction proof
- deep-link spoofing into a payment flow
- stale or replayed payment-link tokens
- unsigned legacy QR payloads being treated as authoritative
- attestation outage or verifier outage
- client secure storage loss during weak-network or reinstall scenarios

## Testing And Acceptance

The implementation is not complete unless these scenarios are covered:

- high-risk action policy distinguishes local-auth success from attestation success
- attestation-enabled requests can be allowed or denied based on server verdict
- graceful degraded behavior is defined for attestation outages
- universal-link flows resolve authority from server token state only
- legacy QR payloads are explicitly supported, downgraded, or rejected according to policy
- amount-binding behavior is testable and consistent across QR, pay-link, and merchant-pay flows
- certificate-pinning consumption is either implemented client-side or explicitly deferred with a tracked gap

## Assumptions

- first slice focuses on trust policy and canonical flow hardening, not a full mobile rewrite
- ledger and provider sections remain authoritative for money-state and provider-state semantics
- this section defines the trust boundary around those actions from the mobile channel

## Footnotes

[^masvs]: OWASP MASVS: <https://mas.owasp.org/MASVS/>
[^expo-app-integrity]: Expo App Integrity documentation: <https://docs.expo.dev/versions/latest/sdk/app-integrity/>
[^apple-app-attest]: Apple Developer Documentation, validating apps that connect to your server: <https://developer.apple.com/documentation/devicecheck/validating-apps-that-connect-to-your-server>
[^google-play-integrity]: Android Developers, Play Integrity API overview: <https://developer.android.com/google/play/integrity/overview>
