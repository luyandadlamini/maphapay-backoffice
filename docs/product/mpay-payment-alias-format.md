# MaphaPay ID — `@mpay` alias format

**Status:** Canonical (May 2026)  
**Applies to:** Marketing site, mobile app, public APIs, EPS/Open Banking alias directory design

## Summary

MaphaPay’s universal payment address is a **MaphaPay ID**: `{localpart}@mpay`.

- **Default marketing / UX template:** `you@mpay`
- **Domain suffix:** `@mpay` only (lowercase in UI)
- **Do not use:** `@mapha` or other suffixes for new aliases

## Formats

| Audience | Pattern | Examples |
|----------|---------|----------|
| Personal | `{localpart}@mpay` | `you@mpay`, `lihle@mpay`, `thabo@mpay` |
| Merchant | `{business-localpart}@mpay` | `market@mpay`, `cafe@mpay` |

Phone numbers and QR codes remain alternate ways to pay; they resolve to the same **payment party** as a MaphaPay ID.

## Smart alias receive routing

One permanent MaphaPay ID (e.g. `lihle@mpay`) maps to a **receive routing profile**, not a single account. Users configure rules for where inbound money lands (wallet, linked bank, savings product, merchant settlement, etc.). See the architecture review section *Smart Alias Receive Routing* in `docs/review/upi-fineract-payment-hub-architecture-review.md`.

## Implementation notes

- Validate and normalise local parts at claim time (length, charset, reserved words).
- Store the full alias string; index by domain + local part for resolution.
- Merchant aliases may require verification before public display.
- API and mobile copy should say **MaphaPay ID**, not “username” or “UPI ID”, unless explaining UPI analogy in marketing only.

## Related

- Architecture: `docs/review/upi-fineract-payment-hub-architecture-review.md` (§3 — Smart Alias Receive Routing)
- Marketing brief (Kimi): `maphapayrn/docs/marketing/MAPHAPAY-MARKETING-WEBSITE-DESIGN-BRIEF.md` (§3 — MaphaPay ID)
