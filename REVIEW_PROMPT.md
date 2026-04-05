# Code Review & Migration Testing Prompt

You are tasked with reviewing the payment link implementation and testing migrations on the local MySQL server.

## Repositories

### Backend: `/Users/Lihle/Development/Coding/maphapay-backoffice`
### Frontend: `/Users/Lihle/Development/Coding/maphapayrn`

---

## Task 1: Review Backend Code

Review the following files for correctness, security, and best practices:

1. **Migration**: `database/migrations/2026_04_05_120000_add_payment_link_columns_to_money_requests_table.php`
   - Check column types, indexes, and constraints are correct

2. **Model**: `app/Models/MoneyRequest.php`
   - Verify fillable array includes new fields
   - Check PHPDoc matches actual columns

3. **Service**: `app/Domain/Payment/Services/PaymentLinkService.php`
   - Verify token generation logic
   - Check expiry handling (10 days)
   - Validate payment link data retrieval

4. **Controllers**:
   - `app/Http/Controllers/Api/Pay/PaymentLinkController.php` - public token validation
   - `app/Http/Controllers/Pay/PayFallbackController.php` - web fallback
   - `app/Http/Controllers/Api/Compatibility/RequestMoney/RequestMoneyStoreController.php` - response includes payment_token

5. **Handler**: `app/Domain/AuthorizedTransaction/Handlers/RequestMoneyReceivedHandler.php`
   - Verify `paid_at` is set on fulfillment

6. **Routes**:
   - `routes/api-compat.php` - public `/api/pay/r/{token}` route
   - `routes/web.php` - `/u/{username}` and `/r/{token}` routes

7. **Views**: `resources/views/pay/fallback.blade.php`

---

## Task 2: Test Migration on Local MySQL

### Prerequisites
- MySQL server running locally
- Database configured in `.env`

### Steps

```bash
cd /Users/Lihle/Development/Coding/maphapay-backoffice
```

1. **Check current migration status**
```bash
php artisan migrate:status
```

2. **Run the new migration**
```bash
php artisan migrate
```

3. **Verify columns exist**
```bash
php artisan db:seed --class=Database\\Seeders\\EmptySeeder 2>/dev/null || true
mysql -u your_user -p your_database -e "DESCRIBE money_requests;"
```

4. **Rollback to test**
```bash
php artisan migrate:rollback
```

5. **Run again to confirm**
```bash
php artisan migrate
```

---

## Task 3: Run Backend Tests

### Run request-money related tests

```bash
cd /Users/Lihle/Development/Coding/maphapay-backoffice
php artisan test --filter=RequestMoney
```

### Run full test suite (optional - may take long)
```bash
php artisan test
```

---

## Task 4: Review Frontend Code

Review these files in `/Users/Lihle/Development/Coding/maphapayrn`:

1. **QRService**: `src/services/qr.service.ts`
   - Verify HTTPS URLs use `pay.maphapay.com`
   - Check parse function handles both HTTPS and legacy schemes

2. **app.json**: 
   - Verify `associatedDomains` for iOS
   - Verify `intentFilters` for Android

3. **useRequestMoney types**: `src/features/request-money/api/useRequestMoney.ts`
   - Check `payment_link`, `payment_token`, `expires_at` are defined

4. **my-qr.tsx**: `src/app/(modals)/my-qr.tsx`
   - Verify dynamic link logic when payment_token is present

5. **request-money.tsx**: `src/app/(modals)/request-money.tsx`
   - Verify payment_token is passed to my-qr after creation

6. **pay-from-link.tsx**: `src/app/(modals)/pay-from-link.tsx` (new file)
   - Verify deep link modal works

7. **+native-intent.tsx**: `src/app/+native-intent.tsx` (new file)
   - Verify routing for `/u/{username}` and `/r/{token}`

---

## Task 5: Verify TypeScript Compiles

```bash
cd /Users/Lihle/Development/Coding/maphapayrn
npx tsc --noEmit
```

---

## Expected Results

- ✅ Migration runs without errors on local MySQL
- ✅ Columns `payment_token`, `expires_at`, `paid_at` exist in `money_requests` table
- ✅ All backend tests pass
- ✅ TypeScript compiles without errors
- ✅ Code follows Laravel/PHP best practices
- ✅ No security vulnerabilities in public endpoints

---

## Deliverables

1. Report any issues found in code review
2. Confirm migration works on local MySQL
3. Report test results
4. Provide any recommendations for improvements
