# Mobile Trust Boundaries, Deep Links / QR, And Hardening Implementation Plan

Date: 2026-04-07

## Summary

Implement the mobile trust model as an end-to-end binding between RN client behavior and backend policy.

The plan assumes:

- current secure storage and biometric flows remain in place
- backend attestation scaffolding is reusable, but production enforcement is not yet enabled by default
- the first slice prioritizes risky-action hardening and canonical link/QR authority over broad UI changes

## Phase 1: Trust Inventory

- inventory all mobile action classes that can move money or change sensitive state
- classify each by required trust signals:
  - session
  - local biometric/PIN
  - device recognition
  - app integrity
- identify every QR/deep-link entry point and its authority model

Done when:

- each risky mobile action has a declared trust requirement,
- and each link/QR flow is classified as canonical, legacy-compatible, or unsupported.

## Phase 2: Attestation Client Integration

- add real app-integrity collection on the RN client
- connect iOS to App Attest and Android to Play Integrity through the chosen Expo/native path
- submit attestation tokens on high-risk actions or session-establishment refresh points
- replace the current default-disabled/demo attestation posture with explicitly enabled production enforcement before treating attestation as a real control

Done when:

- the mobile app can produce real attestation signals in supported environments,
- and those signals are attached to backend requests that need them under enabled production policy.

## Phase 3: Server Trust Policy

- persist attestation results and request-context trust metadata
- introduce a server policy that resolves `allow`, `step_up`, `degrade`, or `deny`
- bind that policy to first-slice high-risk actions:
  - send money
  - request-money acceptance
  - merchant pay
  - card-cancellation and similar sensitive wallet/card actions

Done when:

- risky actions no longer rely on local auth alone,
- and trust policy is enforced server-side with auditable results.

## Phase 4: Canonical Deep-Link And QR Authority

- standardize on opaque HTTPS token links and server-resolved merchant/payment authority
- restrict or retire legacy `maphapay://` payment authority paths
- remove any flow where raw query payloads are treated as authoritative without server resolution
- make amount-binding rules explicit and consistent
- propagate payment-link token references through the final payment authorization path instead of using them only for UI prefill

Done when:

- QR/deep-link flows resolve payment authority from server state,
- payment-link tokens are submitted and validated in the final payment path,
- and legacy payloads no longer silently act as trusted payment instructions.

## Phase 5: Pinning And Device-Posture Policy

- decide whether client-side certificate pinning is mandatory in the first production hardening slice
- if yes, consume the backend SSL-pin surface on the mobile client
- define rooted/jailbroken-device policy by action class

Done when:

- pinning is either implemented or explicitly deferred with a documented decision,
- and rooted-device behavior is governed rather than undefined.

## Phase 6: Outage And Degraded-Mode Handling

- define policy for App Attest / Play Integrity outage
- define retry and fallback rules for weak-network trust checks
- ensure trust degradation does not accidentally widen access to high-risk actions

Done when:

- attestation outages have explicit allow/deny/step-up behavior,
- and degraded mode is testable instead of implicit.

## Test Plan

- RN tests for canonical link parsing vs legacy compatibility parsing
- end-to-end tests for high-risk actions with and without attestation signals
- server tests for trust-policy decisions across `allow`, `step_up`, `degrade`, and `deny`
- tests for amount-binding behavior on payment links and merchant QR flows
- tests for attestation outage handling
- tests for fresh-install token cleanup and biometric-gated session unlock regressions

## Assumptions

- first slice does not require full offline payments or full anti-tamper implementation
- the immediate goal is a defensible production trust boundary for risky mobile flows
