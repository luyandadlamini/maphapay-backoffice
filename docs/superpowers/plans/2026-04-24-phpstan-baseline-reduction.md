# PHPStan Baseline Reduction Roadmap

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reduce 22 PHPStan baseline files (suppressing ~10,600 errors across Level 6–8) to zero by fixing root causes rather than suppressing them. Work in priority order: Minor Accounts domain, Revenue/Analytics domain, then remaining baselines by error volume. Target: zero baselines in 90 days.

**Architecture:** No new feature code. Fix pattern: read the error, fix the source (add null checks, add `@return` PHPDoc, add `@var` hints, cast types), re-run PHPStan, remove the baseline entry. Baseline files are removed only when every entry in them is fixed.

**Tech Stack:** PHP 8.4, Laravel 12, PHPStan Level 8, Pest.

**Finding addressed:** SECURITY-P2-001

---

## Baseline Inventory (as of 2026-04-24)

| File | Errors | Domain / Scope |
|------|--------|---------------|
| `phpstan-baseline-level6.neon` | 7,612 | Codebase-wide — L6 regressions |
| `phpstan-baseline.neon` | 1,499 | Mixed — general suppression bucket |
| `phpstan-baseline-level8.neon` | 807 | L8 null-access patterns |
| `phpstan-baseline-pre-existing.neon` | 258 | Legacy suppression |
| `phpstan-baseline-fraud.neon` | 81 | Fraud domain |
| `phpstan-baseline-baas-models.neon` | 64 | BaaS domain |
| `phpstan-baseline-level8-new.neon` | 63 | L8 new-since-last-run |
| `phpstan-baseline-regtech-models.neon` | 50 | RegTech domain |
| `phpstan-baseline-ai-tests.neon` | 48 | AI domain tests |
| `phpstan-baseline-ai-models.neon` | 45 | AI domain models |
| `phpstan-baseline-baas-v2-tests.neon` | 32 | BaaS v2 tests |
| `phpstan-baseline-keymanagement.neon` | 23 | Key management |
| `phpstan-baseline-ai-unit-tests.neon` | 21 | AI unit tests |
| `phpstan-baseline-privacy.neon` | 12 | Privacy domain |
| `phpstan-baseline-v61.neon` | 11 | v6.1 suppression |
| `phpstan-baseline-regtech-unit-tests.neon` | ~5 | RegTech tests |
| `phpstan-baseline-baas-unit-tests.neon` | ~5 | BaaS unit tests |
| `phpstan-baseline-trustcert.neon` | ~5 | TrustCert domain |
| `phpstan-baseline-commerce.neon` | ~5 | Commerce domain |
| `phpstan-baseline-compliance-projector.neon` | ~5 | Compliance projector |
| `phpstan-baseline-phase12.neon` | 3 | Phase 12 (Minor Cards) |
| `phpstan-baseline-regtech-unit-tests.neon` | ~3 | RegTech |

**Total suppressions:** ~10,600 (estimated from line counts / 6 lines per entry)

---

## Error Fix Patterns

The top recurring errors across all baselines and their standard fixes:

| Error Pattern | Fix |
|---|---|
| `Cannot access property $uuid on App\Models\User\|null` | `assert($user !== null, '...')` or `$user = $request->user(); assert($user instanceof User);` |
| `Cannot call method hasRole() on User\|null` | Same null assertion or `$request->user()?->hasRole(...)` with null coalescing |
| `Cannot call method toIso8601String() on Carbon\|null` | Add null check: `$date?->toIso8601String() ?? ''` |
| `Cannot call method diffInDays() on Carbon\|null` | Add null check: `$date !== null ? $date->diffInDays(...) : 0` |
| `BelongsTo<Model, $this(...)> but returns BelongsTo<Model, Model>` | Add `@return BelongsTo<RelatedModel, static>` PHPDoc to relation methods |
| `Unable to resolve template type TValue in call to function expect` | Add `/** @var SomeType $var */` before the `expect()` call |
| `Unable to resolve template type TKey in call to function collect` | Add `@var Collection<int, SomeType>` annotation |
| `Parameter #1 ... expects string, string\|null given` | Add `is_string($var) ? $var : ''` guard or `(string) $var` cast |

---

## Sprint 1 — Minor Accounts Domain (Week 1–2)

**Target files:** `phpstan-baseline-phase12.neon` (3 errors — eliminate entirely), plus Minor Account entries in `phpstan-baseline-level8.neon` (46 errors).

### Phase 12 Baseline (3 errors — quick win)

These are `BelongsTo<..., $this(...)>` return type mismatches on relation methods. The fix is a `@return` PHPDoc annotation.

- [ ] **Step 1.1 — Fix MinorCardLimit::account() return type**

Open `app/Domain/Account/Models/MinorCardLimit.php`. Find the `account()` method. Add a `@return` annotation:

```php
/**
 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Domain\Account\Models\Account, static>
 */
public function account(): BelongsTo
```

- [ ] **Step 1.2 — Fix MinorCardRequest::minorAccount() return type**

Same pattern in `app/Domain/Account/Models/MinorCardRequest.php`:

```php
/**
 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Domain\Account\Models\Account, static>
 */
public function minorAccount(): BelongsTo
```

- [ ] **Step 1.3 — Fix Card::minorAccount() return type**

Same pattern in `app/Domain/CardIssuance/Models/Card.php`:

```php
/**
 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Domain\Account\Models\Account, static>
 */
public function minorAccount(): BelongsTo
```

- [ ] **Step 1.4 — Verify phase12 baseline is now empty**

```bash
XDEBUG_MODE=off vendor/bin/phpstan analyse --memory-limit=2G \
  app/Domain/Account/Models/MinorCardLimit.php \
  app/Domain/Account/Models/MinorCardRequest.php \
  app/Domain/CardIssuance/Models/Card.php
```

Expected: 0 errors for these files. Remove `phpstan-baseline-phase12.neon` from `phpstan.neon` and delete the file:

```bash
# After confirming zero errors:
grep -n "phpstan-baseline-phase12" phpstan.neon  # find the line
# Edit phpstan.neon to remove that line, then:
rm phpstan-baseline-phase12.neon
```

- [ ] **Step 1.5 — Fix Minor Account null-access errors in phpstan-baseline-level8.neon**

```bash
grep -A2 "Domain/Account\|MinorAccount\|Minor" phpstan-baseline-level8.neon | grep "path:" | sort -u
```

For each file listed, open the file, find the flagged line, apply the appropriate fix pattern from the table above. Re-run PHPStan on that file after each fix:

```bash
XDEBUG_MODE=off vendor/bin/phpstan analyse --memory-limit=2G <file>
```

Remove fixed entries from `phpstan-baseline-level8.neon` as you go.

- [ ] **Step 1.6 — Commit Minor domain fixes**

```bash
git add app/Domain/Account/Models/ \
        app/Domain/CardIssuance/Models/Card.php \
        phpstan-baseline-phase12.neon \
        phpstan-baseline-level8.neon \
        phpstan.neon
git commit -m "fix(P2): eliminate phpstan-baseline-phase12 and reduce level8 baseline (Minor domain)

Adds @return BelongsTo<Model, static> PHPDoc to relation methods in
MinorCardLimit, MinorCardRequest, and Card. Adds null guards for
Minor Account null-access patterns in level8 baseline.

Partial fix for SECURITY-P2-001.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Sprint 2 — Revenue / Analytics Domain (Week 3–4)

- [ ] **Step 2.1 — List Revenue and Analytics errors**

```bash
grep -B1 -A3 "Domain/Revenue\|Domain/Analytics\|Revenue\|RevenueTarget\|RevenueStream" \
  phpstan-baseline.neon phpstan-baseline-level8.neon | head -60
```

Apply the standard fix patterns. Revenue/Analytics errors tend to be null-access on Carbon dates and unresolved return types on query builder chains.

- [ ] **Step 2.2 — Fix Carbon null patterns**

For each `Cannot call method toIso8601String() on Carbon\Carbon|null` or similar:

```php
// BEFORE
$activity->lastActivityAtIso  // from a Carbon|null property

// AFTER — use null-safe operator
$metric->date?->toIso8601String() ?? '—'
```

- [ ] **Step 2.3 — Verify and commit Revenue domain fixes**

```bash
XDEBUG_MODE=off vendor/bin/phpstan analyse app/Domain/Analytics/ app/Domain/Revenue/ --memory-limit=2G
git add -A
git commit -m "fix(P2): eliminate Revenue/Analytics PHPStan baseline entries

Null-safe Carbon calls and @return annotations in WalletRevenueActivityMetrics,
RevenueTargetResource, and related Revenue domain classes.

Partial fix for SECURITY-P2-001.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Sprint 3 — Small Domain Baselines (Week 5–6)

Target: eliminate the smallest files first (quick-win psychology + real error reduction).

- [ ] **Step 3.1 — Eliminate phpstan-baseline-phase12.neon** *(done in Sprint 1)*

- [ ] **Step 3.2 — Eliminate phpstan-baseline-v61.neon** (11 errors)

```bash
cat phpstan-baseline-v61.neon
```

Fix each entry, re-run PHPStan on the affected file, remove file and phpstan.neon include line.

- [ ] **Step 3.3 — Eliminate phpstan-baseline-privacy.neon** (12 errors)

Same process. Privacy domain errors are typically cast/type annotation issues.

- [ ] **Step 3.4 — Eliminate phpstan-baseline-trustcert.neon, commerce.neon, compliance-projector.neon**

Handle each in turn. These are likely small (< 10 errors each based on line count).

- [ ] **Step 3.5 — Eliminate phpstan-baseline-ai-unit-tests.neon** (21 errors)

These are test files — `Unable to resolve template type TValue in expect()`. Fix by adding `@var` annotations before `expect()` calls:

```php
/** @var SomeType $result */
$result = $this->getSomeResult();
expect($result)->toBeInstanceOf(SomeType::class);
```

- [ ] **Step 3.6 — Eliminate phpstan-baseline-keymanagement.neon** (23 errors)

- [ ] **Step 3.7 — Commit after each baseline eliminated**

```bash
git commit -m "fix(P2): eliminate phpstan-baseline-<name>.neon

Zero remaining errors in <domain>. Baseline file removed.

Partial fix for SECURITY-P2-001."
```

---

## Sprint 4 — Medium Domain Baselines (Week 7–9)

- [ ] **Step 4.1 — Eliminate phpstan-baseline-baas-v2-tests.neon** (32 errors)
- [ ] **Step 4.2 — Eliminate phpstan-baseline-ai-models.neon** (45 errors)
- [ ] **Step 4.3 — Eliminate phpstan-baseline-ai-tests.neon** (48 errors)
- [ ] **Step 4.4 — Eliminate phpstan-baseline-regtech-models.neon** (50 errors)
- [ ] **Step 4.5 — Eliminate phpstan-baseline-baas-models.neon** (64 errors)
- [ ] **Step 4.6 — Eliminate phpstan-baseline-fraud.neon** (81 errors)

For each baseline, the process is identical:
1. `cat <baseline>` — read all entries
2. Group by error message type
3. Fix in bulk by type (all null-access User patterns in one pass, etc.)
4. Re-run PHPStan on the affected directory
5. Remove file and phpstan.neon include line
6. Commit

---

## Sprint 5 — Core Baselines (Week 10–13)

These are the large catch-all baselines. They cannot be deleted in one pass; work file-by-file.

### phpstan-baseline-level8-new.neon (63 errors)

- [ ] **Step 5.1 — Fix level8-new errors by pattern**

```bash
grep "message:" phpstan-baseline-level8-new.neon | sort | uniq -c | sort -rn | head -20
```

These are errors added since the last full baseline refresh — likely null-safety on `User|null` from Sanctum auth. Fix pattern:

```php
// In controllers where $request->user() returns User|null:
$user = $request->user();
assert($user instanceof \App\Models\User);
// now $user is non-null for the rest of the method
```

### phpstan-baseline-pre-existing.neon (258 errors)

- [ ] **Step 5.2 — Triage pre-existing errors**

```bash
grep "path:" phpstan-baseline-pre-existing.neon | sort | uniq -c | sort -rn | head -20
```

Identify the top 5 files by error count. Fix those files first — often eliminates 50% of entries.

### phpstan-baseline.neon (1,499 errors)

- [ ] **Step 5.3 — Triage the general baseline**

```bash
grep "message:" phpstan-baseline.neon | sed 's/.*message: //' | sort | uniq -c | sort -rn | head -20
grep "path:" phpstan-baseline.neon | sort | uniq -c | sort -rn | head -20
```

Fix by the most common error type across all files, then by the file with the most entries.

### phpstan-baseline-level8.neon (807 errors)

- [ ] **Step 5.4 — Triage and reduce level8 baseline**

By Sprint 5 the Minor and Revenue domain entries are already cleared. The remaining ~700 errors follow the same null-safety pattern. Work directory by directory:

```bash
grep "path:" phpstan-baseline-level8.neon | sed 's/.*path: //' | sort | uniq -c | sort -rn | head -20
```

---

## Sprint 6 — Level 6 Baseline (Week 14+)

`phpstan-baseline-level6.neon` (7,612 errors) is the largest file. These are Level 6 regressions, not Level 8. Many will be Eloquent collection type inference issues that require `@var` annotations or stub overrides rather than code changes.

- [ ] **Step 6.1 — Categorize level6 errors**

```bash
grep "message:" phpstan-baseline-level6.neon | sed 's/.*message: //' | sort | uniq -c | sort -rn | head -30
```

The top categories (from audit):
- `Unable to resolve template type TValue in call to function expect` (~46) — add `@var` before `expect()`
- `Unable to resolve template type TKey/TValue in call to function collect` (~68) — add typed `Collection` annotation
- `Call to an undefined method GuzzleHttp\Promise\PromiseInterface|Response::successful()` (~16) — this is a Laravel HTTP client stub issue; add a PHPStan stub or use `@var Response $response`

- [ ] **Step 6.2 — Fix bulk patterns**

For the GuzzleHttp `PromiseInterface|Response` pattern (affects HTTP client mocking in tests):

```php
// BEFORE
$response = Http::get($url);
$response->successful();  // PHPStan: method not found on Promise

// AFTER — assert the type at the boundary
/** @var \Illuminate\Http\Client\Response $response */
$response = Http::get($url);
```

- [ ] **Step 6.3 — Track progress with a counter**

```bash
# After each fix batch, count remaining entries:
grep -c "message:" phpstan-baseline-level6.neon
```

Goal: reduce from 7,612 to 0 over multiple sprints.

---

## Operational Rules

1. **Never add new entries to any baseline.** If a new PHPStan error appears, fix it before the PR merges.
2. **Remove the baseline file** (and its `phpstan.neon` include line) as soon as it reaches 0 entries.
3. **PHPStan CI gate:** The CI step `XDEBUG_MODE=off vendor/bin/phpstan analyse --memory-limit=2G` must pass on every PR. A new suppression is a PR blocker.
4. **Commit granularity:** One commit per baseline file eliminated, or per domain-batch of errors fixed.

---

## Tracking Progress

Run this command weekly to measure overall reduction:

```bash
total=0
for f in phpstan-baseline*.neon; do
  n=$(grep -c "message:" "$f" 2>/dev/null || echo 0)
  total=$((total + n))
  echo "$n $f"
done | sort -rn
echo "---"
echo "Total: $total"
```

Add the output to your PR description when eliminating a baseline.

---

## Self-Review Checklist

- [x] SECURITY-P2-001 (baseline reduction) — 6 sprints, 90-day window
- [x] Priority order: Minor → Revenue → small baselines → large baselines
- [x] Fix-pattern table covers all top recurring error types
- [x] Rule: no new baseline entries in any PR
- [x] `phpstan-baseline-phase12.neon` (3 errors) called out as a quick first win
