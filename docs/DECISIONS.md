# Engineering & Business Decisions (ADR Log)

This document records the architectural and business decisions made for the **Instructor Revenue Ledger** system, detailing the context, options considered, chosen approach, trade-offs, and invariants enforced.

---

## ADR-001: Monetary Value Representation (Minor Currency Units)

### Status
Accepted

### Problem
Handling monetary values using floating-point arithmetic (`float`/`double`) leads to precision loss and rounding inaccuracies (e.g., `0.1 + 0.2 !== 0.3`). In a financial ledger, even a fraction of a cent discrepancy violates accounting integrity and auditability.

### Options Considered
1. **Floating-point (`FLOAT`/`DOUBLE`):** High risk of rounding drift. Rejected immediately.
2. **Fixed-point Decimal (`DECIMAL(15, 2)` / `DECIMAL(15, 4)`):** Human-readable in database queries, but requires careful handling in application runtimes to avoid accidental float casting.
3. **Integer Cents (`BIGINT` / Minor Currency Units):** Store all monetary amounts as integers representing the smallest currency unit (e.g., 100.00 EGP = `10000` cents).

### Chosen Approach
**Option 3: Store all monetary amounts as `BIGINT` representing minor currency units (cents/piastres).**
Calculations in PHP will utilize integer operations and the `ext-bcmath` extension for any division or proportional splits. Values are converted to major units (divided by 100) strictly at the presentation layer (UI/API).

### Reason
- Eliminates any possibility of floating-point drift across database queries, application services, and serialization.
- Aligns with industry payment standards (Stripe, Adyen, Martin Fowler’s Money Pattern).
- Native integer comparisons and `SUM()` operations in MySQL are fast, exact, and safe.

### Trade-offs
- Developers must always be mindful that amounts are in minor units (e.g., passing `1000` means `10.00`).
- Presentation layers require explicit formatting.

### Impact
- Database columns representing money (`amount`, `balance`, `platform_cut`, `payout_amount`) will all use `BIGINT UNSIGNED` or `BIGINT SIGNED`.
- Zero floating-point math anywhere in the codebase.

---

## ADR-002: Revenue Allocation Strategy (Consumption-Based / Watch Time)

### Status
Accepted

### Problem
A student subscription grants access to the entire platform and multiple instructors' courses. When a student pays upfront for a term, the platform must determine how to divide the net subscription revenue among instructors fairly and deterministically.

### Options Considered
1. **Equal Split across Enrolled Courses:** Divide revenue equally among instructors whose courses the student enrolled in. (Problem: An instructor whose course was opened once gets the same share as one whose course was watched for 50 hours).
2. **Catalog Proportion:** Split based on the number of courses each instructor has on the platform. (Unfair to high-engagement instructors).
3. **Proportional Watch Time (Consumption-Based):** Allocate revenue based on the exact minutes of course content the student watched during that billing month (similar to Spotify / Medium).

### Chosen Approach
**Option 3: Proportional Watch Time Allocation.**
For each recognized subscription billing period:
1. Platform retains its contracted share (e.g., 30% **Platform Cut**).
2. The remaining 70% forms the **Instructor Revenue Pool**.
3. Each instructor receives:
   $$\text{Instructor Share} = \text{floor}\left( \text{Instructor Pool} \times \frac{\text{Instructor Watch Minutes}}{\text{Total Student Watch Minutes in Period}} \right)$$

**Edge Case (Zero Watch Time in Period):**
If the student watches 0 minutes during an active month, 100% of the recognized monthly amount is retained by the platform as **Unclaimed Subscription Revenue (Breakage)**, since no instructor provided instructional delivery during that period.

### Reason
- Most equitable distribution of subscription income: instructors are rewarded directly for actual student engagement.
- Protects the platform from paying for inactive subscriptions.

### Trade-offs
- Requires recording student consumption (watch time logs per instructor per period).
- Allocation jobs must run at the close of each accounting period (e.g., monthly).

---

## ADR-003: Timing of Earnings Recognition (Monthly Accrual / Deferred Revenue)

### Status
Accepted

### Problem
Students can subscribe to monthly, 3-month, or annual plans, paying for the full term up front on day one. If instructors are credited the entire annual payment immediately, handling mid-term student refunds becomes problematic (requiring clawbacks of money already paid to instructors).

### Options Considered
1. **Immediate Recognition (Cash Basis):** Credit instructors with the entire multi-month payment on day 1. (High risk of negative balances if refund occurs).
2. **Monthly Accrual (Deferred Revenue):** Treat upfront payments as **Deferred Revenue (Unearned Liability)**. Recognize and allocate revenue in equal monthly tranches over the subscription term.

### Chosen Approach
**Option 2: Monthly Accrual via Deferred Revenue.**
- When an upfront payment is made (e.g., 1,200 EGP for 12 months), the entire amount is booked into `Deferred Revenue`.
- At the end of each monthly cycle, $\frac{1}{12}$ (100 EGP) is recognized, platform fee is deducted, and the remainder is allocated to instructors based on that month's watch time.
- **Refund Behavior:**
  - If a student cancels/refunds in month 3, months 1 and 2 remain recognized and paid to instructors for services rendered.
  - Months 3 through 12 (unrecognized tranches) are refunded directly from `Deferred Revenue` back to the student without touching instructor balances or requiring clawbacks.

### Reason
- Standard accounting principle (GAAP/IFRS Revenue Recognition).
- Clean, non-destructive refund handling. Instructors are never subjected to clawbacks for historical months.

### Trade-offs
- Requires an automated monthly recognition job to release tranches throughout the subscription lifecycle.

---

## ADR-004: Remainder & Rounding Precision (Floor Allocation + Platform Absorption)

### Status
Accepted

### Problem
Proportional division often produces fractional minor units (sub-cents/sub-piastres). For example, splitting 100.00 EGP across 3 instructors yields 33.3333... EGP each. Summing 33.33 * 3 = 99.99 leaves 1 cent unaccounted for. Money must never be created or lost.

### Options Considered
1. **Largest Remainder Method (Hare-Niemeyer):** Distribute leftover cents to instructors with the highest fractional remainder. (Adds algorithmic complexity; introduces bias).
2. **Round to Nearest:** Sum might exceed total pool, violating financial invariants. (Unacceptable).
3. **Floor Allocation with Platform Absorption:** Calculate each instructor share using integer `floor()`. The platform absorbs the residual cents into the platform fee.

### Chosen Approach
**Option 3: Floor Allocation + Platform Absorption.**
$$\text{Instructor Share}_i = \text{floor}(\text{Calculated Share}_i)$$
$$\text{Platform Cut} = \text{Total Recognized Amount} - \sum \text{Instructor Share}_i$$

### Reason
- Strictly guarantees the invariant:
  $$\text{Total Recognized Amount} = \text{Platform Cut} + \sum \text{Instructor Shares}$$
- Eliminates the possibility of over-allocating funds.
- Extremely simple, deterministic, and fully auditable.

### Trade-offs
- The platform may gain a few marginal sub-cents due to rounding down, which is acceptable as processing overhead.

---

## ADR-005: Double-Entry Immutable Ledger Architecture

### Status
Accepted

### Problem
Mutable balance columns (e.g., `UPDATE instructors SET balance = balance + 100`) are vulnerable to race conditions, offer zero auditability, and make debugging financial discrepancies nearly impossible.

### Options Considered
1. **Single-entry balance table with log table:** Store current balance in a user row and insert activity logs. (Risk of logs diverging from balance).
2. **Double-entry append-only ledger:** Every financial transaction creates at least two balanced entries (Debit and Credit) whose sum is exactly zero. Records are strictly immutable (no `UPDATE` or `DELETE`).

### Chosen Approach
**Option 2: Double-entry append-only ledger.**
- **Accounts:**
  - `Assets:Cash:PaymentGateway`
  - `Liabilities:Customer:DeferredRevenue`
  - `Liabilities:Instructor:Payable`
  - `Revenues:Platform:Commission`
  - `Revenues:Platform:Breakage`
- **Transactions & Entries:**
  - A `LedgerTransaction` groups related `LedgerEntry` records.
  - Invariant: $\sum \text{debits} - \sum \text{credits} = 0$ for every transaction.
  - Tables are **Append-Only**. Corrections must be made via explicit Reversing Entries.
- **Instructor Balance:**
  - An instructor's available balance is calculated as $\sum \text{credits} - \sum \text{debits}$ of their specific `Liabilities:Instructor:Payable` account.

### Reason
- Gold standard in financial accounting.
- Guarantees complete audit trail from subscription payment to payout.
- Naturally enforces mathematical correctness at the database level.

---

## ADR-006: Payout Processing & Concurrency Safety

### Status
Accepted

### Problem
Payout operations interact with real money. The payout command and background workers may execute concurrently, be re-triggered manually, or encounter retries. Under no circumstance may an instructor be paid twice for the same billing cycle.

### Options Considered
1. **Application-level flags:** Check `if (!$payout->is_paid)` before paying. (Fails under concurrent execution due to race conditions).
2. **Pessimistic DB Locks + Idempotency Keys + State Machine:**
   - Database-level unique constraint on `(instructor_id, period_key)` or `idempotency_key`.
   - Explicit state transitions: `DRAFT` $\to$ `PROCESSING` $\to$ `COMPLETED` / `FAILED` / `PENDING_RECONCILIATION`.
   - `SELECT ... FOR UPDATE` during payout creation and ledger reservation.

### Chosen Approach
**Option 2: Pessimistic Locking + Idempotency Keys + Database Constraints.**
1. **Unique Constraint:** A database unique constraint on `payouts(instructor_id, period_key)` prevents creating two payouts for the same instructor and period.
2. **Two-phase Ledger Hold:** When a payout begins, a `LedgerTransaction` moves funds from `Liabilities:Instructor:Payable` to `Liabilities:Instructor:PayoutInFlight`. This atomically deducts available balance before dispatching the payment job.
3. **Queue Idempotency:** Each queued job includes an immutable `payout_id` and unique `idempotency_key`. Workers check the payout state within a database transaction using `lockForUpdate()`.

### Reason
- Guarantees zero duplicate payouts even under concurrent workers or duplicate scheduler triggers.
- Database constraints serve as the ultimate source of truth, immune to application-level timing issues.

---

## ADR-007: External Payment Provider Timeout & Uncertainty Reconciliation

### Status
Accepted

### Problem
External payment APIs are unreliable: a request may succeed on the provider's side, but the network connection times out before returning a response. Assuming failure and retrying will double-pay the instructor. Assuming success without confirmation risks ledger divergence.

### Options Considered
1. **Auto-retry on Timeout:** Retrying blindly causes duplicate payouts. (Rejected).
2. **Mark as Failed on Timeout:** Risks money leaving the provider while the ledger marks it unfulfilled. (Rejected).
3. **Transition to `PENDING_RECONCILIATION` + Polling/Status Check:**
   - When a network timeout or unknown response occurs, the payout is marked `PENDING_RECONCILIATION`.
   - Retries are blocked.
   - An artisan reconciliation command (`payouts:reconcile`) queries the provider's status endpoint with the original `idempotency_key` to resolve the true state.

### Chosen Approach
**Option 3: `PENDING_RECONCILIATION` state with automated status lookup.**
- The mock provider will explicitly simulate:
  1. Instant Success.
  2. Permanent Failure.
  3. Timeout after moving funds (simulated network failure).
  4. Status query method: `getTransferStatus(idempotency_key)`.
- If timeout occurs:
  - Payout status becomes `PENDING_RECONCILIATION`.
  - In-flight balance hold remains locked.
  - The reconciliation worker queries `getTransferStatus()`.
  - If provider says SUCCESS $\to$ Transition to `COMPLETED`.
  - If provider says NOT_FOUND / FAILED $\to$ Release ledger hold and transition to `FAILED`.

### Reason
- Eliminates the risk of duplicate payments caused by network timeouts.
- Completely satisfies the challenge requirement regarding unreliable provider responses.
