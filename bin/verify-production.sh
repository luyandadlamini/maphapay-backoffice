#!/usr/bin/env bash
# =============================================================================
# Zelta Production Verification Script
# =============================================================================
# Verifies all services, credentials, and integrations are working.
# Run from the project root: ./bin/verify-production.sh
# =============================================================================

set -euo pipefail

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

PASS=0
FAIL=0
WARN=0

pass() { ((PASS++)); echo -e "  ${GREEN}✓${NC} $1"; }
fail() { ((FAIL++)); echo -e "  ${RED}✗${NC} $1"; }
warn() { ((WARN++)); echo -e "  ${YELLOW}!${NC} $1"; }
section() { echo -e "\n${CYAN}━━━ $1 ━━━${NC}"; }

# Curl with sane timeouts to prevent hanging
c() { curl --connect-timeout 3 --max-time 5 "$@" 2>/dev/null || echo ""; }

# =============================================================================
section "1. Laravel Application"
# =============================================================================

# Test via artisan (avoids self-request DNS/SSL issues)
APP_CHECK=$(php artisan tinker --execute="echo app()->version();" 2>/dev/null || echo "FAIL")
if [[ "$APP_CHECK" != "FAIL" && -n "$APP_CHECK" ]]; then
    pass "Laravel booting (version: $(echo "$APP_CHECK" | tail -1))"
else
    fail "Laravel cannot boot"
fi

# Test HTTP via localhost (try common ports)
HTTP_OK=false
for PORT in 80 8000 443; do
    SCHEME="http"
    [[ "$PORT" == "443" ]] && SCHEME="https"
    HTTP_CODE=$(c -s -o /dev/null -w "%{http_code}" -k -H "Host: zelta.app" "${SCHEME}://127.0.0.1:${PORT}/up")
    if [[ "$HTTP_CODE" == "200" ]]; then
        pass "HTTP health /up on port $PORT (HTTP 200)"
        HTTP_OK=true

        # Check security headers
        HEADERS=$(c -sI -k -H "Host: zelta.app" "${SCHEME}://127.0.0.1:${PORT}/up")
        echo "$HEADERS" | grep -qi "x-content-type-options: nosniff" && pass "Security header: X-Content-Type-Options" || fail "Missing: X-Content-Type-Options"
        echo "$HEADERS" | grep -qi "strict-transport-security" && pass "Security header: HSTS" || warn "Missing: HSTS (may be set by reverse proxy)"
        echo "$HEADERS" | grep -qi "content-security-policy" && pass "Security header: CSP" || fail "Missing: CSP"
        break
    fi
done
if [[ "$HTTP_OK" == "false" ]]; then
    warn "HTTP self-check skipped (no local listener on 80/8000/443 — test externally)"
fi

# =============================================================================
section "2. Database (MariaDB)"
# =============================================================================

DB_CHECK=$(php artisan tinker --execute="try { \$pdo = DB::connection()->getPdo(); echo 'OK:' . DB::connection()->getDatabaseName(); } catch(Exception \$e) { echo 'FAIL:' . \$e->getMessage(); }" 2>/dev/null || echo "FAIL:unknown")
if [[ "$DB_CHECK" == *"OK:"* ]]; then
    DB_NAME=$(echo "$DB_CHECK" | grep -o 'OK:.*' | cut -d: -f2)
    pass "MariaDB connection (database: $DB_NAME)"
else
    fail "MariaDB connection: $DB_CHECK"
fi

MIGRATE_STATUS=$(php artisan migrate:status 2>/dev/null | grep -c "Pending" || echo "0")
if [[ "$MIGRATE_STATUS" == "0" ]]; then
    pass "All migrations applied"
else
    warn "$MIGRATE_STATUS pending migrations"
fi

# =============================================================================
section "3. Redis"
# =============================================================================

REDIS_CHECK=$(php artisan tinker --execute="try { \Illuminate\Support\Facades\Redis::ping(); echo 'OK'; } catch(Exception \$e) { echo 'FAIL:' . \$e->getMessage(); }" 2>/dev/null || echo "FAIL")
if [[ "$REDIS_CHECK" == *"OK"* ]]; then
    pass "Redis connection"
else
    fail "Redis connection: $REDIS_CHECK"
fi

CACHE_CHECK=$(php artisan tinker --execute="try { cache()->put('__verify__', 'ok', 10); echo cache()->get('__verify__'); cache()->forget('__verify__'); } catch(Exception \$e) { echo 'FAIL'; }" 2>/dev/null || echo "FAIL")
if [[ "$CACHE_CHECK" == *"ok"* ]]; then
    pass "Cache read/write (Redis)"
else
    fail "Cache read/write"
fi

# =============================================================================
section "4. RAILGUN Bridge"
# =============================================================================

BRIDGE_URL=$(php artisan tinker --execute="echo config('privacy.railgun.bridge_url');" 2>/dev/null | tail -1 || echo "")
BRIDGE_SECRET=$(php artisan tinker --execute="echo config('privacy.railgun.bridge_secret');" 2>/dev/null | tail -1 || echo "")

if [[ -n "$BRIDGE_URL" && "$BRIDGE_URL" == http* ]]; then
    # Health endpoint (public, no auth)
    BRIDGE_HEALTH=$(c -s "${BRIDGE_URL}/health")
    if echo "$BRIDGE_HEALTH" | grep -q '"engine_ready":true'; then
        pass "RAILGUN bridge health (engine ready)"
        NETWORKS=$(echo "$BRIDGE_HEALTH" | grep -o '"loaded_networks":\[[^]]*\]' || echo "")
        [[ -n "$NETWORKS" ]] && pass "RAILGUN networks: $NETWORKS"
    elif echo "$BRIDGE_HEALTH" | grep -q '"status":"initializing"'; then
        warn "RAILGUN bridge is still initializing"
    elif [[ -n "$BRIDGE_HEALTH" ]]; then
        warn "RAILGUN bridge responded but engine not ready"
    else
        fail "RAILGUN bridge not responding at $BRIDGE_URL"
    fi

    # Auth test (should return 401 without token)
    AUTH_TEST=$(c -s -o /dev/null -w "%{http_code}" -X POST "${BRIDGE_URL}/wallet/create")
    if [[ "$AUTH_TEST" == "401" ]]; then
        pass "RAILGUN bridge auth enforced (401 without token)"
    elif [[ -z "$AUTH_TEST" || "$AUTH_TEST" == "000" ]]; then
        fail "RAILGUN bridge not reachable for auth test"
    else
        warn "RAILGUN bridge auth returned HTTP $AUTH_TEST (expected 401)"
    fi

    # Auth with correct token
    if [[ -n "$BRIDGE_SECRET" ]]; then
        AUTH_OK=$(c -s -o /dev/null -w "%{http_code}" \
            -H "Authorization: Bearer ${BRIDGE_SECRET}" \
            -H "Content-Type: application/json" \
            -d '{}' -X POST "${BRIDGE_URL}/wallet/create")
        if [[ "$AUTH_OK" == "422" || "$AUTH_OK" == "400" ]]; then
            pass "RAILGUN bridge accepts valid token (HTTP $AUTH_OK = validation error, auth OK)"
        elif [[ "$AUTH_OK" == "503" ]]; then
            warn "RAILGUN bridge auth OK but engine not ready (503)"
        else
            warn "RAILGUN bridge with valid token returned HTTP $AUTH_OK"
        fi
    fi
else
    fail "RAILGUN bridge URL not configured"
fi

# =============================================================================
section "5. Alchemy RPC"
# =============================================================================

ALCHEMY_KEY=$(php artisan tinker --execute="echo env('ALCHEMY_API_KEY');" 2>/dev/null | tail -1 || echo "")
if [[ -n "$ALCHEMY_KEY" && ${#ALCHEMY_KEY} -gt 5 ]]; then
    # Test Polygon RPC
    POLYGON_BLOCK=$(c -s -X POST -H "Content-Type: application/json" \
        -d '{"jsonrpc":"2.0","method":"eth_blockNumber","params":[],"id":1}' \
        "https://polygon-mainnet.g.alchemy.com/v2/${ALCHEMY_KEY}")
    if echo "$POLYGON_BLOCK" | grep -q '"result"'; then
        BLOCK_HEX=$(echo "$POLYGON_BLOCK" | grep -o '"result":"[^"]*"' | cut -d'"' -f4)
        pass "Alchemy Polygon RPC (block: $BLOCK_HEX)"
    else
        fail "Alchemy Polygon RPC not responding"
    fi

    # Test Ethereum RPC
    ETH_BLOCK=$(c -s -X POST -H "Content-Type: application/json" \
        -d '{"jsonrpc":"2.0","method":"eth_blockNumber","params":[],"id":1}' \
        "https://eth-mainnet.g.alchemy.com/v2/${ALCHEMY_KEY}")
    if echo "$ETH_BLOCK" | grep -q '"result"'; then
        pass "Alchemy Ethereum RPC"
    else
        fail "Alchemy Ethereum RPC not responding"
    fi
else
    fail "Alchemy API key not configured"
fi

# =============================================================================
section "6. Pimlico (ERC-4337 Bundler)"
# =============================================================================

PIMLICO_KEY=$(php artisan tinker --execute="echo config('relayer.pimlico.api_key');" 2>/dev/null | tail -1 || echo "")
if [[ -n "$PIMLICO_KEY" && ${#PIMLICO_KEY} -gt 3 ]]; then
    PIMLICO_RESP=$(c -s -X POST -H "Content-Type: application/json" \
        -d '{"jsonrpc":"2.0","method":"eth_chainId","params":[],"id":1}' \
        "https://api.pimlico.io/v2/137/rpc?apikey=${PIMLICO_KEY}")
    if echo "$PIMLICO_RESP" | grep -q '"result"'; then
        pass "Pimlico bundler API (Polygon)"
    elif echo "$PIMLICO_RESP" | grep -q '"error"'; then
        ERROR=$(echo "$PIMLICO_RESP" | grep -o '"message":"[^"]*"' | head -1)
        fail "Pimlico bundler: $ERROR"
    else
        fail "Pimlico bundler not responding"
    fi
else
    fail "Pimlico API key not configured"
fi

# =============================================================================
section "7. CoinGecko (Exchange Rates)"
# =============================================================================

CG_KEY=$(php artisan tinker --execute="echo config('exchange.providers.coingecko.api_key');" 2>/dev/null | tail -1 || echo "")
CG_ENABLED=$(php artisan tinker --execute="echo config('exchange.providers.coingecko.enabled') ? 'true' : 'false';" 2>/dev/null | tail -1 || echo "false")
if [[ "$CG_ENABLED" == *"true"* && -n "$CG_KEY" ]]; then
    CG_RESP=$(c -s -H "x-cg-demo-api-key: ${CG_KEY}" "https://api.coingecko.com/api/v3/ping")
    if echo "$CG_RESP" | grep -q "gecko_says"; then
        pass "CoinGecko API (ping OK)"
    else
        fail "CoinGecko API not responding"
    fi

    CG_PRICE=$(c -s -H "x-cg-demo-api-key: ${CG_KEY}" \
        "https://api.coingecko.com/api/v3/simple/price?ids=usd-coin&vs_currencies=eur")
    if echo "$CG_PRICE" | grep -q "eur"; then
        pass "CoinGecko USDC/EUR price fetch"
    else
        warn "CoinGecko price fetch failed (rate limit?)"
    fi
else
    warn "CoinGecko not enabled or API key missing"
fi

# =============================================================================
section "8. Pusher (Broadcasting)"
# =============================================================================

PUSHER_KEY=$(php artisan tinker --execute="echo config('broadcasting.connections.pusher.key');" 2>/dev/null | tail -1 || echo "")
PUSHER_CLUSTER=$(php artisan tinker --execute="echo config('broadcasting.connections.pusher.options.cluster');" 2>/dev/null | tail -1 || echo "")
if [[ -n "$PUSHER_KEY" && -n "$PUSHER_CLUSTER" ]]; then
    PUSHER_HTTP=$(c -s -o /dev/null -w "%{http_code}" \
        "https://sockjs-${PUSHER_CLUSTER}.pusher.com/pusher/info?app_key=${PUSHER_KEY}")
    if [[ "$PUSHER_HTTP" == "200" ]]; then
        pass "Pusher WebSocket endpoint reachable"
    elif [[ -n "$PUSHER_HTTP" && "$PUSHER_HTTP" != "000" && "$PUSHER_HTTP" != "" ]]; then
        pass "Pusher API reachable (HTTP $PUSHER_HTTP)"
    else
        fail "Pusher not reachable"
    fi
else
    fail "Pusher not configured"
fi

# =============================================================================
section "9. Firebase (Push Notifications)"
# =============================================================================

FB_CREDS=$(php artisan tinker --execute="echo config('firebase.projects.app.credentials');" 2>/dev/null | tail -1 || echo "")
if [[ -n "$FB_CREDS" ]]; then
    if [[ -f "storage/firebase-credentials.json" ]]; then
        pass "Firebase credentials file exists"
        if python3 -m json.tool storage/firebase-credentials.json > /dev/null 2>&1; then
            pass "Firebase credentials valid JSON"
            grep -q "project_id" storage/firebase-credentials.json && \
                pass "Firebase credentials contain project_id" || \
                fail "Firebase credentials missing project_id"
        else
            fail "Firebase credentials not valid JSON"
        fi
    else
        fail "Firebase credentials file missing (storage/firebase-credentials.json)"
    fi
else
    warn "Firebase credentials not configured (FIREBASE_CREDENTIALS)"
fi

# =============================================================================
section "10. Stripe"
# =============================================================================

STRIPE_KEY=$(php artisan tinker --execute="echo config('cashier.secret') ?: config('services.stripe.secret');" 2>/dev/null | tail -1 || echo "")
if [[ -n "$STRIPE_KEY" && "$STRIPE_KEY" == sk_live_* ]]; then
    STRIPE_RESP=$(c -s -u "${STRIPE_KEY}:" "https://api.stripe.com/v1/balance")
    if echo "$STRIPE_RESP" | grep -q '"available"'; then
        pass "Stripe API (live mode, balance OK)"
    elif echo "$STRIPE_RESP" | grep -q '"error"'; then
        ERROR=$(echo "$STRIPE_RESP" | grep -o '"message":"[^"]*"' | head -1)
        fail "Stripe API: $ERROR"
    else
        fail "Stripe API not responding"
    fi
elif [[ -n "$STRIPE_KEY" && "$STRIPE_KEY" == sk_test_* ]]; then
    warn "Stripe in TEST mode"
else
    warn "Stripe secret key not configured"
fi

# =============================================================================
section "11. TrustCert Signing Keys"
# =============================================================================

TC_CA=$(php artisan tinker --execute="echo config('trustcert.ca.ca_signing_key') ? 'SET' : 'EMPTY';" 2>/dev/null | tail -1 || echo "EMPTY")
TC_CRED=$(php artisan tinker --execute="echo config('trustcert.signing.credential_signing_key') ? 'SET' : 'EMPTY';" 2>/dev/null | tail -1 || echo "EMPTY")
TC_PRES=$(php artisan tinker --execute="echo config('trustcert.signing.presentation_signing_key') ? 'SET' : 'EMPTY';" 2>/dev/null | tail -1 || echo "EMPTY")

[[ "$TC_CA" == *"SET"* ]] && pass "TrustCert CA signing key" || fail "TrustCert CA signing key missing"
[[ "$TC_CRED" == *"SET"* ]] && pass "TrustCert credential signing key" || fail "TrustCert credential signing key missing"
[[ "$TC_PRES" == *"SET"* ]] && pass "TrustCert presentation signing key" || fail "TrustCert presentation signing key missing"

# =============================================================================
section "12. Privacy / RAILGUN Config"
# =============================================================================

PP_ENABLED=$(php artisan tinker --execute="echo config('privacy.privacy_pools.enabled') ? 'true' : 'false';" 2>/dev/null | tail -1 || echo "false")
ZK_PROV=$(php artisan tinker --execute="echo config('privacy.zk.provider');" 2>/dev/null | tail -1 || echo "")
MK_PROV=$(php artisan tinker --execute="echo config('privacy.merkle.provider');" 2>/dev/null | tail -1 || echo "")

[[ "$PP_ENABLED" == *"true"* ]] && pass "Privacy pools enabled" || fail "Privacy pools disabled"
[[ "$ZK_PROV" == *"railgun"* ]] && pass "ZK provider: railgun" || warn "ZK provider: $ZK_PROV (expected railgun)"
[[ "$MK_PROV" == *"railgun"* ]] && pass "Merkle provider: railgun" || warn "Merkle provider: $MK_PROV (expected railgun)"

# =============================================================================
section "13. X402 Protocol"
# =============================================================================

X402_ON=$(php artisan tinker --execute="echo config('x402.enabled') ? 'true' : 'false';" 2>/dev/null | tail -1 || echo "false")
X402_ADDR=$(php artisan tinker --execute="echo config('x402.pay_to_address');" 2>/dev/null | tail -1 || echo "")

[[ "$X402_ON" == *"true"* ]] && pass "X402 protocol enabled" || warn "X402 protocol disabled"
[[ -n "$X402_ADDR" && "$X402_ADDR" == 0x* ]] && pass "X402 pay-to: ${X402_ADDR:0:10}..." || warn "X402 pay-to address not set"

# =============================================================================
section "14. HSM / Key Management"
# =============================================================================

HSM_ON=$(php artisan tinker --execute="echo config('keymanagement.hsm.enabled') ? 'true' : 'false';" 2>/dev/null | tail -1 || echo "false")
HSM_PROV=$(php artisan tinker --execute="echo config('keymanagement.hsm.provider');" 2>/dev/null | tail -1 || echo "")

[[ "$HSM_ON" == *"true"* ]] && pass "HSM enabled ($HSM_PROV)" || warn "HSM disabled"

# =============================================================================
section "15. Queue & Supervisor"
# =============================================================================

if command -v supervisorctl &> /dev/null; then
    SUPERVISOR_STATUS=$(sudo supervisorctl status 2>/dev/null || supervisorctl status 2>/dev/null || echo "")
    if [[ -n "$SUPERVISOR_STATUS" ]]; then
        while IFS= read -r line; do
            [[ -z "$line" ]] && continue
            PROC=$(echo "$line" | awk '{print $1}')
            STATE=$(echo "$line" | awk '{print $2}')
            if [[ "$STATE" == "RUNNING" ]]; then
                pass "Supervisor: $PROC RUNNING"
            elif [[ "$STATE" == "STARTING" ]]; then
                warn "Supervisor: $PROC STARTING"
            else
                fail "Supervisor: $PROC $STATE"
            fi
        done <<< "$SUPERVISOR_STATUS"
    else
        warn "No supervisor processes found"
    fi
else
    warn "supervisorctl not available"
fi

# =============================================================================
section "16. Artisan Health Check"
# =============================================================================

ARTISAN_HEALTH=$(php artisan system:health-check 2>&1 || echo "COMMAND_FAILED")
if [[ "$ARTISAN_HEALTH" == *"COMMAND_FAILED"* ]]; then
    warn "system:health-check command not available or failed"
else
    ARTISAN_FAIL=$(echo "$ARTISAN_HEALTH" | grep -ci "fail\|error\|unhealthy" || echo "0")
    if [[ "$ARTISAN_FAIL" -gt 0 ]]; then
        fail "Artisan health check: $ARTISAN_FAIL issues"
        echo "$ARTISAN_HEALTH" | grep -i "fail\|error\|unhealthy" | head -5 | sed 's/^/    /'
    else
        pass "Artisan health check passed"
    fi
fi

# =============================================================================
section "17. Pending Integrations"
# =============================================================================

ONDATO_ID=$(php artisan tinker --execute="echo config('services.ondato.application_id') ?: 'EMPTY';" 2>/dev/null | tail -1 || echo "EMPTY")
[[ "$ONDATO_ID" != *"EMPTY"* && -n "$ONDATO_ID" ]] && pass "Ondato KYC configured" || warn "Ondato KYC pending"

MARQETA_URL=$(php artisan tinker --execute="echo config('cardissuance.marqeta.base_url');" 2>/dev/null | tail -1 || echo "")
if [[ "$MARQETA_URL" == *"sandbox"* ]]; then
    warn "Marqeta on SANDBOX URL"
elif [[ -n "$MARQETA_URL" ]]; then
    pass "Marqeta production URL"
else
    warn "Marqeta not configured"
fi

# =============================================================================
# Summary
# =============================================================================
echo ""
echo -e "${CYAN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
TOTAL=$((PASS + FAIL + WARN))
echo -e "  ${GREEN}Passed:${NC}   $PASS"
echo -e "  ${RED}Failed:${NC}   $FAIL"
echo -e "  ${YELLOW}Warnings:${NC} $WARN"
echo -e "  Total:    $TOTAL"
echo -e "${CYAN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"

if [[ "$FAIL" -gt 0 ]]; then
    echo -e "\n${RED}Some checks failed. Review above.${NC}"
    exit 1
else
    echo -e "\n${GREEN}All critical checks passed.${NC}"
    exit 0
fi
