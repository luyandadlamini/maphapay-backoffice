#!/usr/bin/env bash
# =============================================================================
# Zelta Production Verification Script
# =============================================================================
# Verifies all services, credentials, and integrations are working.
# Run from the project root: ./bin/verify-production.sh
# =============================================================================

set -uo pipefail
# Trap errors to show which line failed
trap 'echo -e "\n${RED}ERROR: Script failed at line $LINENO (exit code $?)${NC}" >&2' ERR

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

# Curl with sane timeouts
c() { curl --connect-timeout 3 --max-time 5 "$@" 2>/dev/null || echo ""; }

# =============================================================================
# Load .env values directly (no PHP needed for config checks)
# =============================================================================
SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
ENV_FILE="${SCRIPT_DIR}/.env"

if [[ ! -f "$ENV_FILE" ]]; then
    echo -e "${RED}ERROR: .env file not found at ${ENV_FILE}${NC}"
    exit 1
fi

# Parse .env into variables (handles quotes, skips comments/blanks)
env_get() {
    local key="$1"
    local val
    val=$(grep -E "^${key}=" "$ENV_FILE" 2>/dev/null | tail -1 | sed "s/^${key}=//" | sed 's/^["'\'']//' | sed 's/["'\''"]$//' | sed 's/^[[:space:]]*//' | sed 's/[[:space:]]*$//' || true)
    echo "$val"
}

# =============================================================================
section "1. Laravel Application"
# =============================================================================

APP_ENV=$(env_get APP_ENV)
APP_DEBUG=$(env_get APP_DEBUG)
APP_URL=$(env_get APP_URL)

# Quick boot test via artisan (known to work)
ARTISAN_VER=$(timeout 10 php artisan --version 2>/dev/null || echo "FAIL")
if [[ "$ARTISAN_VER" != "FAIL" && -n "$ARTISAN_VER" ]]; then
    pass "Laravel booting ($ARTISAN_VER)"
else
    fail "Laravel cannot boot"
fi

[[ "$APP_ENV" == "production" ]] && pass "APP_ENV=production" || warn "APP_ENV=$APP_ENV (expected production)"
[[ "$APP_DEBUG" == "false" ]] && pass "APP_DEBUG=false" || fail "APP_DEBUG=$APP_DEBUG (MUST be false in production!)"
[[ -n "$APP_URL" ]] && pass "APP_URL=$APP_URL" || fail "APP_URL not set"

# Test HTTP via localhost
HTTP_OK=false
for PORT in 443 80 8000; do
    SCHEME="http"
    [[ "$PORT" == "443" ]] && SCHEME="https"
    HTTP_CODE=$(c -s -o /dev/null -w "%{http_code}" -k -H "Host: zelta.app" "${SCHEME}://127.0.0.1:${PORT}/up")
    if [[ "$HTTP_CODE" == "200" ]]; then
        pass "HTTP health /up on port $PORT (HTTP 200)"
        HTTP_OK=true

        HEADERS=$(c -sI -k -H "Host: zelta.app" "${SCHEME}://127.0.0.1:${PORT}/up")
        echo "$HEADERS" | grep -qi "x-content-type-options: nosniff" && pass "Security header: X-Content-Type-Options" || fail "Missing: X-Content-Type-Options"
        echo "$HEADERS" | grep -qi "strict-transport-security" && pass "Security header: HSTS" || warn "Missing: HSTS (may be set by reverse proxy)"
        echo "$HEADERS" | grep -qi "content-security-policy" && pass "Security header: CSP" || fail "Missing: CSP"
        break
    fi
done
if [[ "$HTTP_OK" == "false" ]]; then
    warn "HTTP self-check skipped (no response on 443/80/8000 — test externally)"
fi

# =============================================================================
section "2. Database (MariaDB)"
# =============================================================================

DB_HOST=$(env_get DB_HOST)
DB_DATABASE=$(env_get DB_DATABASE)
DB_USERNAME=$(env_get DB_USERNAME)
DB_PASSWORD=$(env_get DB_PASSWORD)

if [[ -n "$DB_HOST" && -n "$DB_DATABASE" ]]; then
    # Test with mysqladmin or mysql CLI
    if command -v mysqladmin &>/dev/null; then
        DB_PING=$(mysqladmin ping -h "$DB_HOST" -u "$DB_USERNAME" -p"$DB_PASSWORD" 2>/dev/null || echo "FAIL")
        if [[ "$DB_PING" == *"alive"* ]]; then
            pass "MariaDB connection (database: $DB_DATABASE)"
        else
            fail "MariaDB ping failed"
        fi
    elif command -v mysql &>/dev/null; then
        DB_TEST=$(mysql -h "$DB_HOST" -u "$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" -e "SELECT 1;" 2>/dev/null || echo "FAIL")
        if [[ "$DB_TEST" == *"1"* ]]; then
            pass "MariaDB connection (database: $DB_DATABASE)"
        else
            fail "MariaDB connection failed"
        fi
    else
        warn "No mysql CLI — checking via artisan"
        MIGRATE=$(timeout 15 php artisan migrate:status 2>&1 | head -3 || echo "FAIL")
        if [[ "$MIGRATE" != *"FAIL"* && "$MIGRATE" != *"error"* ]]; then
            pass "MariaDB connection via artisan"
        else
            fail "MariaDB connection failed"
        fi
    fi
else
    fail "Database not configured in .env"
fi

MIGRATE_STATUS=$(timeout 15 php artisan migrate:status 2>/dev/null | grep -c "Pending" | tr -d '[:space:]' || echo "0")
if [[ "$MIGRATE_STATUS" == "0" ]]; then
    pass "All migrations applied"
else
    warn "$MIGRATE_STATUS pending migrations"
fi

# =============================================================================
section "3. Redis"
# =============================================================================

REDIS_HOST=$(env_get REDIS_HOST)
REDIS_PORT=$(env_get REDIS_PORT)
REDIS_PASSWORD=$(env_get REDIS_PASSWORD)
[[ -z "$REDIS_PORT" ]] && REDIS_PORT="6379"

if command -v redis-cli &>/dev/null; then
    if [[ -n "$REDIS_PASSWORD" ]]; then
        REDIS_PING=$(redis-cli -h "${REDIS_HOST:-127.0.0.1}" -p "$REDIS_PORT" -a "$REDIS_PASSWORD" --no-auth-warning ping 2>/dev/null || echo "FAIL")
    else
        REDIS_PING=$(redis-cli -h "${REDIS_HOST:-127.0.0.1}" -p "$REDIS_PORT" ping 2>/dev/null || echo "FAIL")
    fi
    if [[ "$REDIS_PING" == "PONG" ]]; then
        pass "Redis connection"
    else
        fail "Redis connection: $REDIS_PING"
    fi
else
    warn "redis-cli not found — cannot verify Redis"
fi

CACHE_DRIVER=$(env_get CACHE_STORE)
[[ -z "$CACHE_DRIVER" ]] && CACHE_DRIVER=$(env_get CACHE_DRIVER)
[[ "$CACHE_DRIVER" == "redis" ]] && pass "Cache driver: redis" || warn "Cache driver: ${CACHE_DRIVER:-not set}"

# =============================================================================
section "4. RAILGUN Bridge"
# =============================================================================

BRIDGE_URL=$(env_get RAILGUN_BRIDGE_URL)
BRIDGE_SECRET=$(env_get RAILGUN_BRIDGE_SECRET)

if [[ -n "$BRIDGE_URL" && "$BRIDGE_URL" == http* ]]; then
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

    AUTH_TEST=$(c -s -o /dev/null -w "%{http_code}" -X POST "${BRIDGE_URL}/wallet/create")
    if [[ "$AUTH_TEST" == "401" ]]; then
        pass "RAILGUN bridge auth enforced (401 without token)"
    elif [[ -z "$AUTH_TEST" || "$AUTH_TEST" == "000" ]]; then
        fail "RAILGUN bridge not reachable for auth test"
    else
        warn "RAILGUN bridge auth returned HTTP $AUTH_TEST (expected 401)"
    fi

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

ALCHEMY_KEY=$(env_get ALCHEMY_API_KEY)
if [[ -n "$ALCHEMY_KEY" && ${#ALCHEMY_KEY} -gt 5 ]]; then
    POLYGON_BLOCK=$(c -s -X POST -H "Content-Type: application/json" \
        -d '{"jsonrpc":"2.0","method":"eth_blockNumber","params":[],"id":1}' \
        "https://polygon-mainnet.g.alchemy.com/v2/${ALCHEMY_KEY}")
    if echo "$POLYGON_BLOCK" | grep -q '"result"'; then
        BLOCK_HEX=$(echo "$POLYGON_BLOCK" | grep -o '"result":"[^"]*"' | cut -d'"' -f4)
        pass "Alchemy Polygon RPC (block: $BLOCK_HEX)"
    else
        fail "Alchemy Polygon RPC not responding"
    fi

    ETH_BLOCK=$(c -s -X POST -H "Content-Type: application/json" \
        -d '{"jsonrpc":"2.0","method":"eth_blockNumber","params":[],"id":1}' \
        "https://eth-mainnet.g.alchemy.com/v2/${ALCHEMY_KEY}")
    if echo "$ETH_BLOCK" | grep -q '"result"'; then
        pass "Alchemy Ethereum RPC"
    else
        fail "Alchemy Ethereum RPC not responding"
    fi

    ARB_BLOCK=$(c -s -X POST -H "Content-Type: application/json" \
        -d '{"jsonrpc":"2.0","method":"eth_blockNumber","params":[],"id":1}' \
        "https://arb-mainnet.g.alchemy.com/v2/${ALCHEMY_KEY}")
    if echo "$ARB_BLOCK" | grep -q '"result"'; then
        pass "Alchemy Arbitrum RPC"
    else
        fail "Alchemy Arbitrum RPC not responding"
    fi

    BASE_BLOCK=$(c -s -X POST -H "Content-Type: application/json" \
        -d '{"jsonrpc":"2.0","method":"eth_blockNumber","params":[],"id":1}' \
        "https://base-mainnet.g.alchemy.com/v2/${ALCHEMY_KEY}")
    if echo "$BASE_BLOCK" | grep -q '"result"'; then
        pass "Alchemy Base RPC"
    else
        warn "Alchemy Base RPC not responding (needed for X402)"
    fi
else
    fail "Alchemy API key not configured"
fi

# =============================================================================
section "6. Pimlico (ERC-4337 Bundler)"
# =============================================================================

PIMLICO_KEY=$(env_get PIMLICO_API_KEY)
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

CG_KEY=$(env_get COINGECKO_API_KEY)
CG_ENABLED=$(env_get COINGECKO_ENABLED)
if [[ "$CG_ENABLED" == "true" && -n "$CG_KEY" ]]; then
    CG_RESP=$(c -s -H "x-cg-demo-api-key: ${CG_KEY}" "https://api.coingecko.com/api/v3/ping")
    if echo "$CG_RESP" | grep -q "gecko_says"; then
        pass "CoinGecko API (ping OK)"
    else
        fail "CoinGecko API not responding"
    fi
else
    warn "CoinGecko not enabled or API key missing"
fi

# =============================================================================
section "8. Pusher (Broadcasting)"
# =============================================================================

PUSHER_KEY=$(env_get PUSHER_APP_KEY)
PUSHER_CLUSTER=$(env_get PUSHER_APP_CLUSTER)
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

FB_CREDS=$(env_get FIREBASE_CREDENTIALS)
if [[ -n "$FB_CREDS" ]]; then
    # Resolve path relative to project root
    FB_PATH="$FB_CREDS"
    [[ ! "$FB_PATH" = /* ]] && FB_PATH="${SCRIPT_DIR}/${FB_PATH}"
    if [[ -f "$FB_PATH" ]]; then
        pass "Firebase credentials file exists"
        if python3 -m json.tool "$FB_PATH" > /dev/null 2>&1; then
            pass "Firebase credentials valid JSON"
            grep -q "project_id" "$FB_PATH" && \
                pass "Firebase credentials contain project_id" || \
                fail "Firebase credentials missing project_id"
        else
            fail "Firebase credentials not valid JSON"
        fi
    else
        fail "Firebase credentials file missing ($FB_CREDS)"
    fi
else
    warn "Firebase credentials not configured (FIREBASE_CREDENTIALS)"
fi

# =============================================================================
section "10. Stripe"
# =============================================================================

STRIPE_KEY=$(env_get STRIPE_SECRET)
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

TC_CA=$(env_get TRUSTCERT_CA_SIGNING_KEY)
TC_CRED=$(env_get TRUSTCERT_CREDENTIAL_SIGNING_KEY)
TC_PRES=$(env_get TRUSTCERT_PRESENTATION_SIGNING_KEY)

[[ -n "$TC_CA" ]] && pass "TrustCert CA signing key" || fail "TrustCert CA signing key missing"
[[ -n "$TC_CRED" ]] && pass "TrustCert credential signing key" || fail "TrustCert credential signing key missing"
[[ -n "$TC_PRES" ]] && pass "TrustCert presentation signing key" || fail "TrustCert presentation signing key missing"

# =============================================================================
section "12. Privacy / RAILGUN Config"
# =============================================================================

PP_ENABLED=$(env_get PRIVACY_POOLS_ENABLED)
ZK_PROV=$(env_get ZK_PROVIDER)
MK_PROV=$(env_get MERKLE_PROVIDER)

[[ "$PP_ENABLED" == "true" ]] && pass "Privacy pools enabled" || fail "Privacy pools disabled"
[[ "$ZK_PROV" == *"railgun"* ]] && pass "ZK provider: railgun" || warn "ZK provider: ${ZK_PROV:-not set}"
[[ "$MK_PROV" == *"railgun"* ]] && pass "Merkle provider: railgun" || warn "Merkle provider: ${MK_PROV:-not set}"

# =============================================================================
section "13. X402 Protocol"
# =============================================================================

X402_ON=$(env_get X402_ENABLED)
X402_ADDR=$(env_get X402_PAY_TO_ADDRESS)

[[ "$X402_ON" == "true" ]] && pass "X402 protocol enabled" || warn "X402 protocol disabled"
[[ -n "$X402_ADDR" && "$X402_ADDR" == 0x* ]] && pass "X402 pay-to: ${X402_ADDR:0:10}..." || warn "X402 pay-to address not set"

# =============================================================================
section "14. HSM / Key Management"
# =============================================================================

HSM_ON=$(env_get HSM_ENABLED)
HSM_PROV=$(env_get HSM_PROVIDER)

[[ "$HSM_ON" == "true" ]] && pass "HSM enabled ($HSM_PROV)" || warn "HSM disabled"

AWS_KMS_ARN=$(env_get AWS_KMS_KEY_ARN)
[[ -n "$AWS_KMS_ARN" ]] && pass "AWS KMS Key ARN configured" || warn "AWS_KMS_KEY_ARN not set"

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

ARTISAN_HEALTH=$(timeout 15 php artisan system:health-check 2>&1 || echo "COMMAND_FAILED")
if [[ "$ARTISAN_HEALTH" == *"COMMAND_FAILED"* ]]; then
    warn "system:health-check command not available or failed"
else
    ARTISAN_FAIL=$(echo "$ARTISAN_HEALTH" | grep -ci "fail\|error\|unhealthy" | tr -d '[:space:]' || echo "0")
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

ONDATO_ID=$(env_get ONDATO_APPLICATION_ID)
[[ -n "$ONDATO_ID" ]] && pass "Ondato KYC configured" || warn "Ondato KYC pending"

MARQETA_URL=$(env_get MARQETA_BASE_URL)
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
