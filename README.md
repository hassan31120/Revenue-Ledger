# Instructor Revenue Ledger

The financial core of an LMS. Students buy subscriptions; a subscription grants
access to courses from several instructors; the platform keeps a commission and
the rest belongs to those instructors, who are paid periodically through an
external provider.

The system answers three questions correctly, always:

```
how much has each instructor earned?
how much have they already been paid?
how much is still outstanding?
```

And it keeps answering them correctly when payout processing runs twice, two
workers race, jobs retry, workers crash, the provider fails, **the provider times
out after already moving the money**, refunds happen, and amounts do not divide
evenly.

> **Start with `ARCHITECTURE.md`, supplied alongside this repository.** This
> README gets the system running; that document explains why it is built this way.

---

## Requirements

| | |
|---|---|
| PHP | 8.2+ (developed on 8.3) |
| MySQL | 8.0+ — **required**, see below |
| Laravel | 11 |
| Filament | 3 |
| Pest | 3 |
| Node / npm | **not needed at all** |

**MySQL 8+ is not optional.** The financial invariants are enforced by `CHECK`
constraints, a `STORED` generated column used as a partial unique index, and
`BEFORE UPDATE`/`DELETE` triggers. SQLite supports none of them faithfully, so
the suite runs on MySQL too — a green SQLite run would prove nothing about the
behaviour that matters here.

**There is no asset build step.** Filament ships precompiled CSS and JS, which
`composer install` republishes automatically. No npm, no Vite, no Docker, no
Redis, no queue dashboard.

---

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Create the two databases:

```bash
mysql -u root -e "CREATE DATABASE instructor_revenue_ledger CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE DATABASE instructor_revenue_ledger_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

Point `.env` at them (`DB_DATABASE=instructor_revenue_ledger`, plus credentials),
then:

```bash
php artisan migrate --seed
```

That seeds 12 instructors, 36 courses, 150 students and 200 subscriptions, and
allocates every one of them — so a fresh database already has a ledger, real
balances and payable amounts to work with.

Admin panel: **`/admin`** — `admin@example.test` / `password`.

### A note on `composer audit`

Laravel 11 is past its security-support window. Two advisories have no fix
anywhere in the 11.x line, so `composer.json` ignores them **by explicit ID, with
a written reason on each** — advisory checking itself stays on. Both are
unreachable here: the application has no user-supplied email validation feeding a
mail header, and issues no temporary signed URLs. `composer audit` exits 0 and
lists them as ignored rather than hiding them.

---

## Seeing it work

Every failure mode is reachable from the command line in about a minute.

### Money is conserved

```bash
php artisan ledger:verify
```

Recomputes every balance from the ledger, checks that each subscription, refund
and payout is fully accounted for, and exits non-zero on any discrepancy.

### A normal payout run

```bash
php artisan payouts:process --dry-run    # shows who would be paid, changes nothing
php artisan payouts:process
php artisan queue:work --queue=payouts --stop-when-empty
php artisan ledger:verify
```

### The case that matters — the provider takes the money, then times out

```bash
php artisan migrate:fresh --seed

QUEUE_CONNECTION=sync PAYOUTS_MOCK_MODE=timeout_after_success \
  php artisan payouts:process --min-amount=1 --limit=3
```

The provider really did move the money. Check what the system claims:

```bash
mysql -u root instructor_revenue_ledger -e "
  SELECT status, COUNT(*) FROM payouts GROUP BY status;
  SELECT COUNT(*) AS money_moved_at_provider FROM mock_provider_payments;
  SELECT COUNT(*) AS ledger_debits FROM ledger_entries WHERE entry_type='payout';"
```

Three payouts `unknown`, three payments at the provider, and **zero ledger
debits** — the system does not claim anybody was paid. Now try to pay them again:

```bash
QUEUE_CONNECTION=sync PAYOUTS_MOCK_MODE=success php artisan payouts:process --min-amount=1
```

Those three instructors are skipped: their open-payout slot is occupied. Resolve
it properly:

```bash
php artisan payouts:reconcile --force --sync
php artisan ledger:verify
```

They become `paid`, with exactly one ledger debit each.

### A permanent failure

```bash
QUEUE_CONNECTION=sync PAYOUTS_MOCK_MODE=permanent_failure \
  php artisan payouts:process --min-amount=1 --limit=2
```

Marked `failed`, no ledger entry, and the balance stays payable — a later run
opens a *new* payout for it.

### A refund clawing money back from an already-paid instructor

```bash
php artisan tinker --execute='
$paid = App\Models\Payout::where("status","paid")->first();
$sub  = App\Models\Subscription::whereHas("revenueAllocations",
            fn($q) => $q->where("instructor_id", $paid->instructor_id))->first();
app(App\Actions\RefundSubscription::class)->handle($sub, "rf_demo");
echo "refunded subscription #{$sub->id}\n";'
```

The instructor's balance goes negative — they owe the platform — and no payout
runs for them until future earnings cover it. No historical row is touched.

### Proving the ledger cannot be rewritten

```bash
mysql -u root instructor_revenue_ledger -e "UPDATE ledger_entries SET amount_minor = 1;"
```

```
ERROR 1644 (45000): ledger_entries is append-only: rows cannot be updated.
                    Post a correcting entry instead.
```

---

## Commands

| Command | Purpose |
|---|---|
| `revenue:allocate` | Allocate revenue for subscriptions not yet allocated |
| `payouts:process` | Queue payouts for instructors with a payable balance |
| `payouts:reconcile` | Resolve payouts whose outcome is unknown, by asking the provider |
| `ledger:verify` | Recompute balances from the ledger and verify money is conserved |

Useful flags: `--dry-run`, `--instructor=`, `--min-amount=`, `--limit=` on
`payouts:process`; `--force`, `--sync`, `--stale-minutes=` on `payouts:reconcile`.

Scheduled in `routes/console.php`: allocation and payouts daily, reconciliation
every five minutes, verification nightly.

---

## Tests

```bash
php artisan test
```

**156 tests, 9,390 assertions**, in three suites:

| Suite | What it covers |
|---|---|
| `Unit` | The money allocator and formatting — pure, no database |
| `Feature` | Schema constraints, ledger, allocation, provider, payouts, refunds, admin |
| `Concurrency` | Two workers on **two real MySQL connections** |

The tests assert financial invariants rather than chasing coverage. Highlights:

- a **property test** over 3,000 randomised splits asserting no minor unit is ever
  created or lost
- `UPDATE` and `DELETE` against the ledger both throw
- timeout-after-success resolves to exactly one debit, never two
- the job's `failed()` handler moves a payout to `unknown`, **never** `failed`
- several partial refunds land on identical totals to one refund of the same size
- a second connection is refused when it tries to open a second payout, even with
  every application-level check bypassed
- a chaos test running all four provider outcomes, retries, duplicate delivery and
  refunds together, then asserting six system-wide invariants

```bash
php artisan test --testsuite=Concurrency   # the interesting ones
./vendor/bin/pint --test                   # formatting
```

---

## Configuration

All of it lives in `config/revenue.php`:

| Setting | Default | Meaning |
|---|---|---|
| `currency` | `EGP` | All amounts are integer minor units of this |
| `platform_fee_bps` | `3000` | Commission in basis points (30.00%) |
| `payouts.minimum_minor` | `10000` | Don't pay below EGP 100.00 |
| `payouts.reconcile_delay_seconds` | `60` | Wait before resolving an unknown payout |
| `payouts.not_found_grace_seconds` | `300` | How long before "no record" means no money |
| `payouts.stale_processing_minutes` | `15` | After this, assume the worker died |
| `provider.mock_mode` | `success` | `success`, `permanent_failure`, `timeout_after_success`, `timeout_before_success` |

`platform_fee_bps` is **snapshotted onto each subscription at purchase**, so
changing it affects future subscriptions only and can never rewrite history.

---

## Structure

```
app/
  Actions/        AllocateSubscriptionRevenue, ClaimInstructorPayout,
                  SettlePayout, ReconcilePayout, RefundSubscription
  Services/       Ledger (the only write path for money),
                  PayoutRecorder (the only path into a terminal payout state),
                  Payments/ (the provider interface and its mock)
  Support/        MoneyAllocator (pure), RevenueSplit, Money, LedgerEntryDraft
  Jobs/           ProcessInstructorPayout, ReconcilePayoutJob
  Console/        the four commands above
  Enums/          PayoutStatus (with its transition rules), LedgerEntryType, …
  Filament/       two read-only screens
```

No financial logic lives in a controller, a Filament resource, or a model event
callback. There are no repositories and no DTO layer. The one interface in the
project is `PaymentProvider`, which is a genuine external seam.

---

## Further reading

| Document | Where | Contents |
|---|---|---|
| [`docs/AI_USAGE.md`](docs/AI_USAGE.md) | In this repository | How AI was used, what was decided by hand, what differentiates this solution, and the trade-offs chosen deliberately |
| `ARCHITECTURE.md` | Supplied alongside | Domain, schema, money, rounding, ledger, idempotency, concurrency, state machine, timeouts, refunds, scaling, trade-offs, limitations, and the mid-term plan-change design |
| `VIDEO_SCRIPT.md` | Supplied alongside | Structure for the walkthrough |
