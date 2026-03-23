# AP2 Mandates — Agent Payments Protocol

## Overview

AP2 (Agent Payments Protocol) by Google adds verifiable authorization for AI agent commerce. It defines three mandate types that bind agents, users, and merchants with cryptographic credentials.

**Spec**: [github.com/google-agentic-commerce/AP2](https://github.com/google-agentic-commerce/AP2)
**Version**: v0.1-alpha

## Mandate Types

### Cart Mandate (Human-Present)
For shopping scenarios where a user confirms the purchase:
- W3C PaymentRequest-compatible cart items
- Merchant DID + Shopping Agent DID binding
- Requires human confirmation before execution

### Intent Mandate (Human-Not-Present)
For autonomous agent actions with delegated authority:
- Natural language intent description
- Budget constraints and expiry
- No human presence required during execution

### Payment Mandate (Direct Payment)
For direct payment authorization between parties:
- Payer → Payee DID binding
- Payment method preferences (x402, MPP)
- Single-use or session-based

## Lifecycle

```
DRAFT → ISSUED → ACCEPTED → EXECUTED → COMPLETED
                    ↓            ↓
                 EXPIRED      DISPUTED
                    ↓            ↓
                 REVOKED      REVOKED
```

## Verifiable Digital Credentials (VDCs)

Each mandate can be backed by an SD-JWT-VC credential:
- **Cart VDC**: Binds merchant cart contents to user authorization
- **Intent VDC**: Binds autonomous intent to delegator approval
- **Payment VDC**: Binds payment authorization to payer identity

## AP2 Roles

| Role | Description |
|------|-------------|
| Shopping Agent | AI that discovers products and builds carts |
| Credentials Provider | Secure entity managing payment credentials |
| Merchant Endpoint | Seller-side agent or web interface |
| Payment Processor | Processes payment via card networks or blockchain |

## Implementation

### Services
- `MandateService` — Full lifecycle management (create/accept/execute/revoke/dispute)
- `VdcService` — SD-JWT-VC credential issuance and verification
- `AP2PaymentBridgeService` — Bridges mandates to x402/MPP payment systems

### MCP Tools
- `agent_protocol.mandate` — Create, accept, execute, revoke, dispute mandates
- `agent_protocol.vdc` — Issue and verify Verifiable Digital Credentials

### A2A Message Types
Six new message types for mandate lifecycle:
- `mandate.create`, `mandate.accept`, `mandate.execute`
- `mandate.complete`, `mandate.revoke`, `mandate.dispute`

## Payment Method Integration

AP2 wraps x402 and MPP as payment methods:
```php
AP2PaymentMethod::x402('eip155:8453')  // USDC on Base
AP2PaymentMethod::mpp('stripe')         // Stripe SPT via MPP
```
