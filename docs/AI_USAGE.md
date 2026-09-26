# AI Usage & Collaboration Log

## 1. Overview & Engineering Philosophy

This project was developed through an active pair-programming collaboration between the lead engineer and an AI agent (Google Antigravity / Gemini & Claude models). 

In high-risk financial domain applications—such as subscription revenue allocation and payout engines—AI was **never** utilized as a black-box code generator. Instead, it operated under strict engineering rules defined in `AGENTS.md`:
- **Correctness and data integrity over convenience.**
- **Zero floating-point calculations** anywhere in the system.
- **Explicit ADR documentation** before implementing critical business rules.
- **Verification via automated test suites** executing against real MySQL instances in Docker.

---

## 2. Key Architectural Decisions Shaped by AI-Human Collaboration

### A. Accrual Accounting vs. Cash Basis (ADR-003)
- **Context:** Handling 3-month and Annual subscription plans where cash is received upfront.
- **Exploration:** Initial naive suggestions often credit instructors immediately on day 1. Through collaboration, we identified that doing so creates a critical vulnerability if a student requests a refund in month 2 or 3, necessitating aggressive and unpopular instructor clawbacks.
- **Outcome:** We established a strict **Deferred Revenue (Unearned Liability)** model. Upfront cash sits in deferred revenue and is recognized in equal monthly tranches, matching the GAAP/IFRS revenue recognition standard.

### B. Remainder Rounding & Sub-Cent Allocation (ADR-004)
- **Context:** Splitting integer cents across multiple instructors often produces recurring fractions.
- **Exploration:** We evaluated the Hare-Niemeyer (Largest Remainder) method versus a deterministic Floor Allocation. While Largest Remainder distributes odd cents among instructors, it introduces bias and stateful tracking overhead.
- **Outcome:** We selected **Floor Allocation with Platform Absorption**. Each instructor receives `floor(share)`, and the platform absorbs the sub-cent residue into the platform fee. This guarantees that $\sum \text{Allocations} \equiv \text{Recognized Amount}$ with zero over-allocation risk.

### C. External Provider Timeout & Reconciliation (ADR-007)
- **Context:** External payment gateways can accept a transfer, execute it, but drop the network connection before returning an HTTP 200 response.
- **Exploration:** Treating a timeout as a failure triggers a retry, causing an instructor to be paid twice. Treating a timeout as a success risks marking an unfulfilled payout as completed.
- **Outcome:** We architected a dedicated `PENDING_RECONCILIATION` state combined with a Two-Phase Escrow Hold (`liabilities:instructor:payout_in_flight`). When a timeout occurs, funds remain safely in escrow, retries are halted, and a scheduled `payout:reconcile` artisan command polls the gateway status endpoint with the original `idempotency_key` to resolve the final state.

### D. Zero Clawback Refund Strategy (ADR-008)
- **Context:** Mid-term student refunds.
- **Outcome:** Formulated a policy where historical months for which content was delivered remain untouched in instructor accounts. The refund is drawn exclusively from unearned `Customer Deferred Revenue` directly back to the payment gateway.

---

## 3. Rejected Approaches & Architectural Alternatives

| Rejected Approach | Reason for Rejection | Chosen Solution |
| :--- | :--- | :--- |
| **`FLOAT` / `DOUBLE` columns** | Rounding errors (`0.1 + 0.2 != 0.3`) accumulate and violate balance sheet balance. | `BIGINT UNSIGNED` minor currency units (cents/piastres) + `ext-bcmath`. |
| **Mutable `balance` columns** | High risk of race conditions, lost updates, and zero audit history. | Immutable Double-Entry Ledger (`LedgerTransaction` + `LedgerEntry`). |
| **Blind queue retry on timeout** | Gateway may have already transferred funds; retry causes double payment. | `PENDING_RECONCILIATION` state machine + status reconciliation command. |
| **Application-only locks** | Multi-server or multi-worker race conditions bypass app memory locks. | Database-level composite unique constraints + `SELECT ... FOR UPDATE`. |
| **Instructor Clawbacks on Refund** | Instructors delivered educational value; taking money back creates negative balances. | Refund only unearned deferred revenue tranches. |
| **Full CRUD Filament UI** | Manual editing/deleting of ledger rows destroys financial auditability. | Strictly Read-Only administrative screens (`canCreate/Edit/Delete = false`). |

---

## 4. Verification & Testing Workflow

Every feature followed a disciplined implementation lifecycle:
1. **Design & Analysis:** Inspect dependencies and clarify business rules.
2. **Database Constraints:** Enforce uniqueness and foreign keys at the MySQL level.
3. **Domain Implementation:** Build clean service/action classes with transactional boundaries (`DB::transaction`).
4. **Automated Testing:** Run Feature and Unit tests via `docker compose exec instructor-revenue-ledger php artisan test`.
5. **Regression Verification:** Run the entire test suite (38 tests, 155 assertions) across all bounded contexts.

---

## 5. Conclusion

The combination of human domain requirements and AI assistance enabled rapid exploration of edge cases (race conditions, network splits, sub-cent rounding) while maintaining high engineering rigor, resulting in a production-grade, highly resilient financial ledger.
