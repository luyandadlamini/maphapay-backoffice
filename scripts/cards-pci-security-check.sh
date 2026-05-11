#!/usr/bin/env bash
# PCI-oriented static greps for card domains (docs/cards/08-processor-gateway.md §11).
# Scoped to CardIssuance + CardSubscriptions + demo reveal view — full-repo numeric
# literals elsewhere produce false positives against the doc's global grep.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

fail=0

run_grep() {
  local label="$1"
  shift
  if "$@"; then
    echo "FAIL: $label"
    fail=1
  fi
}

# 1) PAN-shaped quoted digit runs (12–19 digits) in card PCI scope
if grep -rEn '["'\'']?[0-9]{12,19}["'\'']?' \
  --include='*.php' \
  app/Domain/CardSubscriptions \
  app/Domain/CardIssuance \
  2>/dev/null; then
  echo 'FAIL: PAN-shaped numeric literal in CardSubscriptions or CardIssuance PHP'
  fail=1
fi

# 1b) Blade demo reveal (synthetic demo PAN is allowed only in demo view; keep heuristic narrow)
if grep -rEn '["'\'']?[0-9]{12,19}["'\'']?' \
  --include='*.blade.php' \
  resources/views/demo-cards \
  2>/dev/null | grep -v '4111 1111 1111' | grep -v 'pci-allow'; then
  echo 'FAIL: unexpected PAN-shaped content in demo-cards Blade'
  fail=1
fi

# 2) Dangerous identifiers in CardSubscriptions PHP
run_grep 'PAN/CVV variable names in CardSubscriptions' \
  grep -rEn '(\$pan|->pan|cvv|cardNumber|card_number)' --include='*.php' app/Domain/CardSubscriptions/

# 3) Logging card_number
run_grep 'Log::…card_number in app/' \
  grep -rEn 'Log::.*card_number' --include='*.php' app/

if [[ "$fail" -ne 0 ]]; then
  echo 'cards-pci-security-check: FAILED'
  exit 1
fi

echo 'cards-pci-security-check: OK'
