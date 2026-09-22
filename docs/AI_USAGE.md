# AI Usage

The quest permits AI tools and asks for enough detail to understand the workflow
and the level of ownership behind this submission. This document answers the six
points in the brief, in order.

---

## 1. How AI was used during the task

AI (Claude, via Claude Code) was used heavily, and as an **implementer working
from a reviewed architecture** rather than as an autocomplete.

The single most important constraint on the process: **AI was explicitly forbidden
from writing any code until a written plan had been produced and approved.** The
first instruction it received ended with "Do NOT implement anything yet… First
inspect the existing repository and environment, understand the project, and
produce a detailed implementation plan."

That ordering is what makes the rest defensible. The schema, the rounding policy,
the payout state machine, the concurrency strategy and the refund rules all
existed as reviewable prose — with trade-offs stated — before a line of code was
committed to. Implementation then followed the plan in twelve phases, each ending
with migrations run and the test suite green before the next began.

AI was used for:

- inspecting the environment and reporting what was actually installed
- producing the architecture plan and its alternatives
- writing the migrations, models, actions, services, jobs, commands, Filament
  resources and tests
- writing this documentation
- running the system and verifying every documented claim against real output

AI was **not** used to decide the things listed in §4.

---

## 2. The main prompts and workflow relied on

Four prompts carried the whole task. The workflow matters more than the wording.

**Prompt 1 — the brief, with a hard stop.** The full challenge requirements, plus
the environment (macOS, Herd, Laravel 11, MySQL, Pest, Filament v3, no Docker),
plus an explicit instruction to inspect first, plan second, and implement nothing.
It also specified *what the plan had to contain* — sixteen named sections from
"current repository assessment" through "implementation phases".

**Prompt 2 — approval.** The plan was reviewed and approved before implementation
started. Three open questions were answered by the AI proposing defaults and
documenting them as explicit, reversible assumptions rather than burying them.

**Prompt 3 — a decision escalation.** Laravel 11 could not be installed cleanly.
The AI stopped, presented three options with consequences, and waited. See §4.

**Prompt 4 — "continue".** Phase-by-phase implementation, with the AI reporting
findings and stopping at genuine decision points.

### The workflow rules that shaped the output

These mattered more than any individual prompt:

| Rule | Effect |
|---|---|
| **Plan before code** | Architecture was reviewable and challengeable while it was still cheap to change |
| **Verify, don't assert** | Every documented number was produced by running the system, not written from memory |
| **Each phase ends green** | No phase built on an unproven one |
| **The database enforces invariants** | Claims are backed by constraints, not by hoping the PHP is right |
| **Escalate real decisions** | The AI stopped and asked rather than guessing on anything with business consequences |

The "verify, don't assert" rule produced the most value. Five real findings came
from running the system rather than trusting its description — see §5.

---

## 3. Fully generated vs. manually designed or modified

**Honestly: the code is essentially all AI-generated.** Claiming otherwise would
be easy to disprove and not worth doing.

What that does and does not mean:

| | |
|---|---|
| Production code, tests, migrations | Generated |
| Architecture | Proposed by AI as a written plan; reviewed and approved before implementation |
| Requirements and constraints | Supplied — Laravel 11, Herd, no Docker, no unnecessary infrastructure, Pest, Filament v3 |
| Process constraints | Supplied — plan-first, phase-by-phase, tests green before proceeding |
| Decisions at escalation points | Made personally — see §4 |

### Where the ownership actually sits

Not in typing. No file under `app/`, `database/` or `tests/` was hand-written or
hand-edited; stating otherwise would be trivially disprovable and pointless.

It sits in four places:

**The constraints imposed before anything was built.** Laravel 11 and MySQL on
Herd; Pest and Filament v3; no Docker, no Sail, no Redis, no queue dashboard, no
npm build step. Those boundaries are why the result is a system that can be read
and defended rather than a pile of infrastructure.

**The requirement that architecture exist as prose first.** The plan had to state
the schema, the rounding policy, the payout lifecycle, the concurrency strategy
and the refund rules — with trade-offs — before implementation was permitted.
Every argument in §6 was settled while it was still cheap to change.

**The decisions at escalation points.** Enumerated in §4. The AI was instructed
to stop and present options rather than guess whenever a choice had business
consequences, and it did.

**The standard of proof demanded.** Nothing was accepted on description. Every
number in this submission was produced by running the system — which is how the
five findings in §5 surfaced, including two schema defects that would otherwise
have shipped.

### What I did not do

I did not write the allocator, the ledger service, the payout state machine or
the tests. I set what they had to satisfy, reviewed the design that came back,
decided the open questions, and required that the result be demonstrated rather
than asserted. That is the honest description of the level of ownership here.

---

## 4. The engineering decisions I personally made

These were points where the work stopped and waited for a decision:

**Laravel 11, despite it being past security support.** The install failed:
every release matching the original constraint was blocked by advisories, and two
have no fix anywhere in the 11.x line. Three options were presented — pin the
newest patched 11.x and ignore only the two unfixable advisories by explicit ID
with written reasons; move to Laravel 12; or disable advisory checking entirely.

I chose the first. It honours the stated Laravel 11 requirement, keeps advisory
checking switched on for everything else, and leaves an auditable record in
`composer.json` of exactly what was accepted and why. Both remaining advisories
are unreachable in this codebase — there is no user-supplied email validation
feeding a mail header, and no temporary signed URLs. `composer audit` exits 0 and
lists them as ignored rather than hiding them.

*I rejected Laravel 12 (deviates from the brief) and rejected disabling advisory
blocking (too blunt — it would silence future advisories too).*

**The process itself.** Requiring a full architectural plan before implementation,
and requiring each phase to be green before the next. This is the decision that
most shaped the result: it is why the trade-offs are written down rather than
discovered in review.

**Infrastructure restraint.** No Docker, no Sail, no Redis, no Horizon — specified
up front, against the temptation to add them because a financial system "should"
have them.

**Where the project lives and how it is versioned** — `~/Herd/…`, served by Herd,
with the repository created and pushed personally.

**What the repository contains, and what it does not.** `AI_USAGE.md` ships
inside the repository because the brief asks for it at `/docs/AI_USAGE.md`. The
architecture document and the presentation script are supplied alongside rather
than committed, because the repository is also deployed — and deployment
artefacts and review artefacts are not the same thing.

### Decisions I reviewed and let stand

These were proposed with reasoning rather than escalated as questions. I read the
reasoning and accepted it, which is a weaker form of ownership than originating
the idea — but a stronger one than not having noticed. They are listed here
because I expect to defend them, not because I invented them:

| Decision | Why I accepted it |
|---|---|
| **Earn revenue at payment, claw back pro-rata on refund** | The term is paid upfront, so the money is genuinely earned at that moment. Daily accrual would need a recurring job and would make payouts lag for no gain here. It also forces the hard case — refunding an instructor who has already been paid — to be handled rather than designed around |
| **`unknown` is a first-class payout state** | The alternative is to collapse it into `failed`, which is a positive claim that no money moved. After a timeout that claim is not available |
| **A balance projection alongside the ledger** | Summing tens of millions of rows per payout run is not viable, and the brief rules out a mutable balance as sole truth. Writing the projection in the same transaction and auditing it with `ledger:verify` satisfies both |
| **Cumulative-target refund arithmetic** | Allocating each partial refund independently lets rounding drift across a sequence. Computing the total that should exist and posting the difference cannot drift |
| **Tests against MySQL rather than SQLite** | The invariants *are* database features. A green SQLite suite would be a green suite that tests nothing that matters |
| **The platform fee floors** | Rounding direction is a policy decision, and the conservative direction is the one where the platform never rounds in its own favour |

### What I would change with more time

Two things, both named in §6 as limitations rather than discovered in review: a
resolution workflow for payouts that stay `unknown` indefinitely, and an
archival policy for `payout_attempts`, which is the table that will grow fastest
and is currently unbounded.

---

## 5. What differentiates this solution

Six things I would point to, in descending order of how much they matter:

**1. The double-payment guarantee is structural, not procedural.** MySQL has no
partial indexes, so a `STORED` generated column stands in for one:

```sql
open_instructor_id GENERATED ALWAYS AS (
  CASE WHEN status IN ('pending','processing','unknown') THEN instructor_id END
) STORED,
UNIQUE KEY payouts_one_open_per_instructor (open_instructor_id)
```

Terminal payouts yield `NULL`, and MySQL permits unlimited NULLs in a unique
index — so payout history is unbounded while at most one live payout per
instructor can exist. **Delete every application-level check and it still holds.**
There is a test that bypasses all of them from a second connection and watches the
database refuse the second payout.

**2. `unknown` counts as open.** This is the detail that makes the timeout case
actually safe, and it is easy to miss. An instructor whose payout outcome is
unresolved is *frozen* — they cannot be paid again until reconciliation settles
it. A design that treats `unknown` as merely "not yet successful" will pay twice.

**3. A provider with its own state, not a stub.** The mock keeps its own records
keyed by idempotency key, so after `timeout_after_success` it can truthfully
answer "yes, I already moved that money". A stub returning canned values cannot
test this case at all. There is a test asserting that a timeout-after-success and
a timeout-before-success are *indistinguishable to the caller at the moment they
happen* — which is precisely why a timeout may never be recorded as a failure.

**4. Refunds use cumulative targets, not per-refund allocation.** Allocating
30 + 30 + 40 separately can claw back a minor unit more than allocating 100 once,
because each split rounds independently. So the system computes the clawback that
*should* exist for everything refunded so far, subtracts what is already posted,
and writes the difference. A test proves three partial refunds land on totals
identical to one refund of the same size.

**5. Concurrency is tested across two real MySQL connections.** Asserting
concurrency safety from a single connection proves nothing — a session sees its
own uncommitted writes and never blocks on its own locks. A second connection is
configured specifically so "another worker" is genuinely another session.

**6. Immutability is enforced by the database.** `BEFORE UPDATE` and
`BEFORE DELETE` triggers on `ledger_entries`:

```
mysql> UPDATE ledger_entries SET amount_minor = 999999;
ERROR 1644 (45000): ledger_entries is append-only: rows cannot be updated.
```

An ORM callback or a trait can be bypassed by a migration or a console one-liner
in production. A trigger cannot.

### Findings that came from running it rather than trusting it

Evidence the work was verified rather than accepted as written:

- **A DST bug.** MySQL rejected `2026-04-24 00:00:00` — the spring-forward instant
  in Africa/Cairo, a local time that does not exist. Laravel was writing UTC and
  MySQL interpreting it as local. The connection is now pinned to UTC.
- **`TIMESTAMP` cannot hold subscription end dates.** It stops at 2038-01-19, so
  an annual plan sold in 2037 fails to save. Those columns are now `DATETIME`.
- **Laravel 11 is past security support** — surfaced by the install failing.
- **An orphaned-record bug in a factory**, where `definition()` created a row
  before state overrides could replace it.
- **The effective platform commission is 29.9991%, not 30%** — the
  floor-favours-instructors rounding policy visible in real data.

---

## 6. Trade-offs and deliberate choices

Each of these was chosen with a known cost.

**One in-flight payout per instructor.** New earnings wait for the current payout
to settle. This is what makes double payment structurally impossible, and the cost
is real: an instructor stuck in `unknown` is not paid until reconciliation
resolves it. Accepted deliberately — an unresolved payment is exactly when a
financial system should stop and involve a human.

**The ledger debit is written only on confirmed success.** So while a payout is
`processing` or `unknown`, the balance still reads as payable even though money
may already have moved. The alternative — debit on reservation, reverse on failure
— makes `outstanding` instantly accurate but requires reversal entries for the
*common* case, and still needs the same unique index to be safe.

**A ledger plus a balance projection.** Summing tens of millions of rows on every
payout run is not viable, but a mutable balance column as the sole source of truth
is exactly what the brief warns against. The resolution: the projection is written
in the same transaction as every ledger entry, and `ledger:verify` recomputes all
of it from the ledger and exits non-zero on any drift. It is a cache of a pure
function — drift is a bug, not an expected divergence.

**Single-sided ledger with the platform as a real account**, rather than full
double-entry. The customer-cash counter-party is outside this system's boundary,
so double-entry would add a layer with no reviewer-visible benefit. Modelling the
platform as an account preserves the property that matters: conservation is
checkable, and `ledger:verify` checks it.

**The platform fee floors.** Rounding dust falls into the instructor pool, never
out of it. The platform never rounds in its own favour. This is why the effective
commission is 29.9991% rather than a clean 30% — a number I would rather explain
than explain why instructors are consistently a fraction short.

**A stored ULID as the provider idempotency key**, rather than one derived from
instructor + ledger cutoff. The derived version is more elegant and gives a
stronger guarantee, but it breaks legitimate retries: a payout that genuinely
failed would recompute the same key and be deduped by the provider against a
payment that never happened.

**Tests run on MySQL, not SQLite.** The invariants *are* database features —
`CHECK` constraints, a generated-column unique index, triggers, row locks. A green
SQLite suite would prove nothing about the behaviour being tested.

**MySQL-specific schema.** Portability traded for invariants the database enforces
itself.

**One interface in the entire project.** `PaymentProvider`, because it is a
genuine external seam. No repositories, no DTO layer, no abstraction without a
second implementation or a test double.

**Deliberately excluded:** Redis, Horizon, Sail, Docker, table partitioning, read
replicas, and any npm/Vite build step. Each would be infrastructure to defend
rather than architecture to explain.

### Known limitations, stated rather than hidden

- A negative balance is recoverable only by netting against future earnings; an
  instructor who never earns again keeps the debt.
- A permanently `unknown` payout needs a human. There is visibility, but no
  resolution UI.
- Single currency; amounts carry a currency code but there is no FX handling.
- Equal weighting only — the allocator takes arbitrary integer weights and the
  schema stores them, but the resolver always returns 1.
- `payout_attempts` grows without bound and needs an archival policy.
- No authorization model on the admin panel.

---

## Preparing for the review session

The brief says the session may ask me to explain any part, modify it live, justify
decisions, and discuss alternatives. The three questions I would expect, and would
want to answer without notes:

1. **Why does the platform fee floor?** (Rounding direction is a policy, and this
   one is deliberately conservative toward instructors.)
2. **Why is `unknown` not `failed`?** (A timeout is not evidence that no money
   moved, and treating it as such is how a system pays twice.)
3. **Why is the unique index on a generated column?** (MySQL lacks partial
   indexes; terminal states yield NULL, so history stays unbounded while only one
   live payout can exist.)

The architecture document accompanying this submission covers all of it in depth.
