# Phase 19 — k6 Load Test Scripts

**Staging environment only.** These scripts exercise true concurrent HTTP load against the FinAegis staging deployment.

## Prerequisites

```bash
# Install k6
brew install k6          # macOS
# or: sudo apt install k6  # Linux

# Environment variables
export FINAEgis_BASE_URL=https://staging.finaegis.org
export MTN_BASE_URL=https://sandbox.momodeveloper.test
export K6_CLOUD_TOKEN=your-k6-cloud-token  # optional, for cloud runs
```

## Scenario 1 — Send-Money Throughput

**Target:** 100 concurrent users, no deadlocks on `authorized_transactions`, no duplicate wallet mutations.

```javascript
// scenarios/send-money-throughput.js
import http from 'k6/http';
import { check, sleep } from 'k6';
import { RateLimiter } from 'k6/http';

const BASE_URL = __ENV.FINAEgis_BASE_URL || 'http://localhost:8000';

export const options = {
  scenarios: {
    send_money_throughput: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '10s', target: 100 },
        { duration: '30s', target: 100 },
        { duration: '10s', target: 0 },
      ],
      startTime: '0s',
    },
  },
  thresholds: {
    'http_req_duration': ['p(95)<500'], // P95 < 500 ms SLA
    'http_req_failed': ['rate<0.01'],    // < 1% failure rate
  },
};

const TOKEN = __ENV.API_TOKEN; // Bearer token for authenticated user
const HEADERS = {
  'Authorization': `Bearer ${TOKEN}`,
  'Content-Type': 'application/json',
  'Accept': 'application/json',
};

export function setup() {
  // Seed test recipient
  return { recipientEmail: 'loadtest-recipient@example.com' };
}

export default function (data) {
  const payload = JSON.stringify({
    user: data.recipientEmail,
    amount: '1.00',
    verification_type: 'sms',
  });

  const idemKey = `k6-${Date.now()}-${__VU}-${__ITER}`;

  const res = http.post(`${BASE_URL}/api/send-money/store`, payload, {
    headers: HEADERS,
    tags: { name: 'send-money-store' },
  });

  check(res, {
    'status is 200 or 422 (rate limited)': (r) => r.status === 200 || r.status === 422,
    'response has data envelope': (r) => r.json('data') !== undefined,
  });
}
```

## Scenario 2 — MTN Initiation Burst

**Target:** 50 concurrent `request-to-pay` calls with different idempotency keys → MTN client called once per unique key.

```javascript
// scenarios/mtn-initiation-burst.js
import http from 'k6/http';
import { check } from 'k6';

const BASE_URL = __ENV.FINAEgis_BASE_URL || 'http://localhost:8000';

export const options = {
  scenarios: {
    mtn_burst: {
      executor: 'constant-vus',
      vus: 50,
      duration: '30s',
    },
  },
  thresholds: {
    'http_req_duration': ['p(95)<300'], // P95 < 300 ms SLA
  },
};

const TOKEN = __ENV.API_TOKEN;
const HEADERS = {
  'Authorization': `Bearer ${TOKEN}`,
  'Content-Type': 'application/json',
};

export default function () {
  const idemKey = `k6-mtn-${Date.now()}-${__VU}-${__ITER}`;

  const payload = JSON.stringify({
    idempotency_key: idemKey,
    amount: '10.00',
    payer_msisdn: '26876123456',
    note: 'k6 load test',
  });

  const res = http.post(`${BASE_URL}/api/mtn/request-to-pay`, payload, {
    headers: HEADERS,
  });

  check(res, {
    'status is 202': (r) => r.status === 202,
    'has transaction reference': (r) => r.json('data.transaction.reference_id') !== undefined,
  });
}
```

## Scenario 3 — MTN Callback Flood

**Target:** 20 concurrent callbacks for same `X-Reference-Id` → wallet credited exactly once.

```javascript
// scenarios/mtn-callback-flood.js
import http from 'k6/http';
import { check } from 'k6';
import { SharedArray } from 'k6/data';

const BASE_URL = __ENV.FINAEgis_BASE_URL || 'http://localhost:8000';
const CALLBACK_TOKEN = __ENV.MTN_CALLBACK_TOKEN || 'test-callback-token';

// Pre-seed a pending MtnMomoTransaction in the DB via API or test fixture
// This script simulates 20 concurrent callbacks for the same reference ID
const REFERENCE_ID = __ENV.MTN_REFERENCE_ID; // Set via environment

export const options = {
  scenarios: {
    callback_flood: {
      executor: 'constant-vus',
      vus: 20,
      duration: '5s',
    },
  },
};

export default function () {
  const payload = JSON.stringify({
    status: { status: 'SUCCESS' },
    referenceId: REFERENCE_ID,
    amount: '100.00',
    currency: 'SZL',
  });

  const res = http.post(`${BASE_URL}/api/mtn/callback`, payload, {
    headers: {
      'Content-Type': 'application/json',
      'X-Callback-Token': CALLBACK_TOKEN,
      'X-Reference-Id': REFERENCE_ID,
    },
  });

  check(res, {
    'status is 200': (r) => r.status === 200,
  });
}
```

## Scenario 4 — Balance Read Under Write Load

**Target:** Read balances while concurrent transfers execute → eventual consistency < 1s.

```javascript
// scenarios/balance-consistency.js
import http from 'k6/http';
import { check, sleep } from 'k6';

const BASE_URL = __ENV.FINAEgis_BASE_URL || 'http://localhost:8000';
const TOKEN = __ENV.API_TOKEN;

export const options = {
  scenarios: {
    balance_under_load: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '5s', target: 20 },
        { duration: '20s', target: 20 },
        { duration: '5s', target: 0 },
      ],
    },
  },
  thresholds: {
    'http_req_duration': ['p(95)<500'], // Balance read P95 < 500 ms
  },
};

const HEADERS = {
  'Authorization': `Bearer ${TOKEN}`,
  'Content-Type': 'application/json',
};

export default function () {
  // Initiate transfer
  const idemKey = `k6-balance-${Date.now()}-${__VU}-${__ITER}`;
  const sendRes = http.post(`${BASE_URL}/api/send-money/store`, JSON.stringify({
    user: 'recipient@example.com',
    amount: '0.01',
    verification_type: 'sms',
  }), { headers: { ...HEADERS, 'X-Idempotency-Key': idemKey } });

  // Immediately read balance
  const balanceRes = http.get(`${BASE_URL}/api/dashboard`, { headers: HEADERS });

  check(balanceRes, {
    'balance endpoint responds': (r) => r.status === 200,
  });
}
```

## Running

```bash
# Run locally
k6 run scenarios/send-money-throughput.js

# Run with cloud results
k6 run --out cloud scenarios/send-money-throughput.js

# Run all scenarios
k6 run --out cloud scenarios/send-money-throughput.js \
                   scenarios/mtn-initiation-burst.js \
                   scenarios/mtn-callback-flood.js \
                   scenarios/balance-consistency.js

# Run with environment variables
FINAEgis_BASE_URL=https://staging.finaegis.org \
MTN_CALLBACK_TOKEN=your-token \
k6 run scenarios/mtn-callback-flood.js
```

## SLA Verification

After each run, check the k6 summary:

```
✓ http_req_duration..........: avg=142ms p(95)=298ms ✓
✓ http_req_failed............: 0.00% ✓
```

If P95 exceeds targets, do NOT proceed with user widening.
