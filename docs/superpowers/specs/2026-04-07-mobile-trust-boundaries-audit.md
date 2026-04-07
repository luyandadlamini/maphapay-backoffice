# Mobile Trust Boundaries, Deep Links / QR, And Hardening Audit

Date: 2026-04-07

## Summary

This section validates the source audit's claims about mobile trust posture, QR/deep-link safety, device/app integrity, and high-risk mobile flow hardening against the current backend and companion React Native app.

Main conclusion:

- the platform already has meaningful mobile-security foundations, including secure token storage, biometric-gated convenience flows, backend biometric verification endpoints, universal-link/app-link configuration, and backend-side attestation/pinning scaffolding,
- but the mobile trust boundary is still under-specified where it matters most: real device/app integrity enforcement on the client, high-assurance server binding of integrity results to risky actions, and signed or server-authoritative QR/deep-link trust semantics.

The correct recommendation is not "mobile security is absent." The correct recommendation is "finish the bridge between existing backend security capability and actual mobile-client enforcement, then narrow QR/deep-link flows to trusted formats only."

## Evidence Reviewed

Primary mobile/backend evidence:

- [`/Users/Lihle/Development/Coding/maphapayrn/src/core/storage/secureStorage.ts`](/Users/Lihle/Development/Coding/maphapayrn/src/core/storage/secureStorage.ts)
- [`/Users/Lihle/Development/Coding/maphapayrn/src/features/auth/store/authStore.ts`](/Users/Lihle/Development/Coding/maphapayrn/src/features/auth/store/authStore.ts)
- [`/Users/Lihle/Development/Coding/maphapayrn/src/services/qr.service.ts`](/Users/Lihle/Development/Coding/maphapayrn/src/services/qr.service.ts)
- [`/Users/Lihle/Development/Coding/maphapayrn/src/features/scan/resolveQrForPayment.ts`](/Users/Lihle/Development/Coding/maphapayrn/src/features/scan/resolveQrForPayment.ts)
- [`/Users/Lihle/Development/Coding/maphapayrn/src/app/(modals)/pay-from-link.tsx`](/Users/Lihle/Development/Coding/maphapayrn/src/app/(modals)/pay-from-link.tsx)
- [`/Users/Lihle/Development/Coding/maphapayrn/app.json`](/Users/Lihle/Development/Coding/maphapayrn/app.json)
- [`/Users/Lihle/Development/Coding/maphapayrn/README.md`](/Users/Lihle/Development/Coding/maphapayrn/README.md)
- [`app/Http/Controllers/Api/Commerce/MobileCommerceController.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Http/Controllers/Api/Commerce/MobileCommerceController.php)
- [`app/Http/Controllers/Api/Compatibility/VerificationProcess/ChallengeBiometricController.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Http/Controllers/Api/Compatibility/VerificationProcess/ChallengeBiometricController.php)
- [`app/Http/Controllers/Api/Compatibility/VerificationProcess/VerifyBiometricController.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Http/Controllers/Api/Compatibility/VerificationProcess/VerifyBiometricController.php)
- [`app/Domain/Mobile/Services/BiometricJWTService.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Domain/Mobile/Services/BiometricJWTService.php)
- [`app/Domain/Mobile/Services/AppleAttestationVerifier.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Domain/Mobile/Services/AppleAttestationVerifier.php)
- [`app/Domain/Mobile/Services/GoogleIntegrityVerifier.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Domain/Mobile/Services/GoogleIntegrityVerifier.php)
- [`app/Http/Controllers/Api/MobileController.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Http/Controllers/Api/MobileController.php)
- [`config/mobile.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/config/mobile.php)
- [`docs/MOBILE_LAUNCH_HANDOVER.md`](/Users/Lihle/Development/Coding/maphapay-backoffice/docs/MOBILE_LAUNCH_HANDOVER.md)

External reference anchors:

- OWASP MASVS as the mobile verification baseline[^masvs]
- Expo App Integrity documentation for current Expo support posture[^expo-app-integrity]
- Apple App Attest validation guidance[^apple-app-attest]
- Google Play Integrity overview and verdict guidance[^google-play-integrity][^google-play-verdicts]

## Claim Validation

### 1. "Mobile hardening is under-specified."

Verdict: `Partial`

What the code shows:

- auth and refresh tokens are stored in `expo-secure-store`
- the app explicitly wipes stale Keychain items on fresh install
- transaction-PIN secrets can be stored behind biometric authentication prompts
- auth initialization can require local biometric unlock before hydrating a persisted session
- backend biometric challenge/verify endpoints exist for governed money-movement verification

What remains missing:

- no actual Expo App Integrity or equivalent client integration is present in the RN app
- no evidence of jailbreak/root handling in the mobile client
- no evidence of certificate-pinning consumption in the RN client
- no explicit release/OTA hardening policy appears in the app code

Corrected finding:

- mobile hardening is not absent,
- but it remains incomplete and uneven between backend capability and mobile-client enforcement.

### 2. "Device/app integrity controls are missing."

Verdict: `Partial`

What the code shows:

- backend services and config exist for Apple App Attest and Google Play Integrity scaffolding
- backend docs describe attestation payloads and configuration toggles
- the biometric JWT service explicitly calls out future integration with App Attest and Play Integrity

What remains missing:

- attestation is disabled by default via `MOBILE_ATTESTATION_ENABLED=false` unless explicitly enabled
- backend handover docs state that, in the current default mode, any non-empty attestation is accepted
- the Apple verifier is only a baseline byte-pattern/rpIdHash-style check, not full production-grade App Attest certificate-chain validation
- the React Native app does not currently include `@expo/app-integrity` or another visible attestation client
- no evidence shows attestation results are being attached to real high-risk mobile requests
- attestation support is therefore scaffolded, but not proven end-to-end in production flow terms or enabled as a real control by default

Corrected finding:

- attestation scaffolding exists on the backend,
- but app/device integrity is not yet an end-to-end enforced control.

### 3. "Deep-link and QR trust boundaries are under-specified."

Verdict: `Confirmed`

What the code shows:

- the app configures `maphapay://` scheme plus iOS associated domains and Android app links for `pay.maphapay.com`
- the QR service prefers HTTPS universal links for current peer flows and keeps legacy custom-scheme QR compatibility
- the QR resolution path does perform server validation before navigation in some scan flows
- pay-from-link fetches server state by token before proceeding

What remains missing:

- backend QR parsing for commerce still accepts and unpacks raw query-string payloads without signature or server-issued token semantics
- QR semantics are inconsistent across paths: some use HTTPS token links, some still accept legacy custom schemes, and some rely on ad hoc server parsing
- there is no evidence of signed merchant QR payloads or a strict "server lookup only" rule for merchant acceptance
- the pay-from-link screen explicitly lets the requested amount be changed, which weakens amount-binding semantics unless the backend re-authorizes the final amount independently

The source audit is correct that QR/deep-link trust boundaries are a real fraud surface.

### 4. "Biometric or local auth exists, but it is not the same as device trust."

Verdict: `Confirmed`

What the code shows:

- the RN app uses local biometrics to unlock a stored PIN or gate session hydration
- backend verification flows use challenge/signature semantics for specific transaction verification

What remains missing:

- local biometric success does not prove the app instance is genuine or the device is trustworthy
- attestation and session binding are not yet clearly enforced as a combined control for risky actions

This is the right architectural distinction, and the source audit is correct on it.

## Corrected Findings

### What already exists

- secure token storage via `expo-secure-store`
- fresh-install keychain cleanup
- biometric-gated local secrets and session unlock
- universal-link and app-link configuration
- backend biometric verification endpoints
- backend attestation verifiers/config scaffolding, default-disabled for production enforcement
- backend SSL pin distribution endpoint

### What is materially missing

- real app-side attestation collection and submission
- clear server policy that binds attestation result, device, session, and user to high-risk actions
- one canonical QR/deep-link trust model
- signed or server-issued merchant QR authority
- client-side certificate-pinning consumption
- explicit rooted/jailbroken-device policy

## Recommendation

Treat this section as a trust-binding problem, not just a mobile-feature problem:

- keep local biometrics as a convenience/authentication signal,
- add real app/device integrity as a separate signal,
- bind both to server-side high-risk action policy,
- and collapse QR/deep-link handling onto server-authoritative, tokenized formats wherever possible.

That direction aligns with MASVS and with current Expo/Apple/Google integrity guidance: sensitive flows should not rely on local auth alone, and attestation verdicts should be used as risk signals that shape server behavior rather than as loose documentation-only aspirations.[^masvs][^expo-app-integrity][^apple-app-attest][^google-play-integrity]

## Final Verdict

The right rewrite for this section is:

"MaphaPay already has several real mobile-security building blocks, especially around secure storage and backend-side verification services. The unresolved issue is that app/device trust, QR/deep-link authority, and end-to-end enforcement are not yet unified into one production mobile trust model."

## Footnotes

[^masvs]: OWASP MASVS: <https://mas.owasp.org/MASVS/>
[^expo-app-integrity]: Expo App Integrity documentation: <https://docs.expo.dev/versions/latest/sdk/app-integrity/>
[^apple-app-attest]: Apple Developer Documentation, validating apps that connect to your server: <https://developer.apple.com/documentation/devicecheck/validating-apps-that-connect-to-your-server>
[^google-play-integrity]: Android Developers, Play Integrity API overview: <https://developer.android.com/google/play/integrity/overview>
[^google-play-verdicts]: Android Developers, Play Integrity verdicts: <https://developer.android.com/google/play/integrity/verdicts>
