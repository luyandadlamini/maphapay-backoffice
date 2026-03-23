# Machine Payments Protocol (MPP) Integration

## Overview

The Machine Payments Protocol (MPP) enables AI agents and services to make programmatic payments via HTTP 402 responses. Unlike x402 (USDC-on-EVM only), MPP supports multiple payment rails: Stripe SPT (fiat), Tempo stablecoins, Lightning Network, and traditional card networks.

**Spec**: [paymentauth.org](https://paymentauth.org)
**Co-developed by**: Stripe + Tempo Labs

## How It Works

```
1. Client requests resource          →  GET /api/premium-data
2. Server returns 402 challenge      ←  WWW-Authenticate: Payment <base64url>
3. Client pays via selected rail     →  (Stripe/Tempo/Lightning/Card)
4. Client retries with credential    →  Authorization: Payment <base64url>
5. Server verifies + settles
6. Server returns resource           ←  200 OK + Payment-Receipt header
```

## Headers

| Header | Direction | Purpose |
|--------|-----------|---------|
| `WWW-Authenticate: Payment` | Server → Client | 402 challenge with pricing and available rails |
| `Authorization: Payment` | Client → Server | Payment credential with proof |
| `Payment-Receipt` | Server → Client | Settlement confirmation |

## Configuration

```env
MPP_ENABLED=false
MPP_CHALLENGE_HMAC_KEY=your-secret-key
MPP_STRIPE_API_KEY_ID=
MPP_CLIENT_ENABLED=false
MPP_DAILY_LIMIT=5000      # $50.00 daily
MPP_PER_TX_LIMIT=100       # $1.00 per tx
MPP_MCP_ENABLED=true
```

## Payment Rails

| Rail | Currency | Settlement | Status |
|------|----------|-----------|--------|
| **Stripe SPT** | USD, EUR, GBP | PaymentIntents API | Demo ready |
| **Tempo** | USDC, USDT | On-chain (chain 42431) | Demo ready |
| **Lightning** | BTC | BOLT11 preimage | Demo ready |
| **Card** | USD, EUR, GBP | JWE encrypted network tokens | Demo ready |

## MCP Transport Binding

MPP defines error code **-32042** as the MCP equivalent of HTTP 402:

```json
{
  "error": {
    "code": -32042,
    "message": "Payment Required",
    "data": { "challenge": "..." }
  }
}
```

## API Endpoints

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/api/v1/mpp/status` | Public | Protocol status |
| GET | `/api/v1/mpp/supported-rails` | Public | Available payment rails |
| GET | `/.well-known/mpp-configuration` | Public | Discovery document |
| GET | `/api/v1/mpp/resources` | Sanctum | List monetized resources |
| POST | `/api/v1/mpp/resources` | Sanctum | Create monetized resource |
| GET | `/api/v1/mpp/payments` | Sanctum | Payment history |
| GET | `/api/v1/mpp/payments/stats` | Sanctum | Payment statistics |
| POST | `/api/v1/mpp/spending-limits` | Sanctum | Set agent spending limit |

## MCP Tools

- `mpp.payment` — Handle 402 challenges, select rail, generate credentials
- `mpp.discovery` — Discover MPP-enabled resources and configuration

## Demo Mode

All rail adapters return simulated responses in non-production environments. No external API keys required for testing.

## Relationship to x402

MPP and x402 are parallel protocols:
- **x402**: Custom headers, USDC-on-EVM, facilitator-based settlement
- **MPP**: Standard HTTP auth headers, multi-rail (fiat + crypto), HMAC challenge binding

AP2 (Google) can wrap both as payment methods within its mandate system.
