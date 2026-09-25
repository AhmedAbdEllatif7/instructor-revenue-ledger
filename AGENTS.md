# AGENTS.md

## Project Context

This repository implements the "Instructor Revenue Ledger" technical
challenge for Career 180.

Read `PROJECT.md` before making architectural or business-logic changes.

The challenge specification is the source of truth for explicit requirements.

---

## Engineering Rules

Before implementing a feature:

1. Understand the business requirement.
2. Identify ambiguities.
3. Check `docs/DECISIONS.md` for existing decisions.
4. If a required business rule has not been decided yet, stop and explain
   the ambiguity instead of silently inventing a rule.
5. Prefer small, focused changes.
6. Add or update tests for business-critical behavior.
7. Keep financial calculations deterministic and testable.

---

## Financial Domain Rules

Money-related logic must be treated as high-risk code.

Do not use floating-point arithmetic for financial calculations.

Do not silently round monetary values.

Every rounding rule must be explicitly documented and tested.

Every balance-affecting operation must have a clear audit trail.

---

## Payout Safety

Payout processing must be safe against:

- Duplicate command execution
- Duplicate job execution
- Queue retries
- Concurrent workers
- Provider timeouts
- Provider success followed by an unavailable response

Never assume a provider timeout means that the payment failed.

Unknown payment state must be handled explicitly.

---

## Idempotency

Any operation that can move money must have an explicit idempotency strategy.

Do not rely only on application-level checks.

Where appropriate, enforce important invariants at the database level.

---

## Database

Use MySQL as required by the challenge.

Database constraints should be used to protect critical invariants.

Do not fetch large datasets into memory when the operation can be performed
incrementally or in batches.

Consider indexing and query behavior for tens of millions of records.

---

## Laravel

Follow Laravel 11 conventions.

Prefer:

- Form Requests for validation
- Service/Action classes for domain operations where appropriate
- Database transactions for atomic state changes
- Queued Jobs for asynchronous payout processing
- Artisan Commands for payout orchestration
- Policies/authorization where required
- Dependency injection
- Interfaces where external integrations benefit from abstraction

Avoid:

- Large controllers
- Business logic hidden inside controllers
- Duplicated business rules
- Unnecessary abstractions
- Generic "helper" classes without a clear responsibility

---

## Testing

Pest is the testing framework.

Financial calculations and payout behavior must be covered by tests.

At minimum, tests must cover:

- Revenue allocation
- Rounding behavior
- Running payout processing twice
- Duplicate job execution
- Job retry
- Provider permanent failure
- Provider timeout after successful payment
- Delayed provider confirmation
- Refund behavior

Tests should verify business outcomes, not implementation details.

---

## Documentation

Important engineering decisions must be documented in:

`docs/DECISIONS.md`

Architecture decisions belong in:

`docs/ARCHITECTURE.md`

AI usage belongs in:

`docs/AI_USAGE.md`

Do not modify documentation to hide rejected approaches or AI involvement.

---

## AI Agent Behavior

Before changing architecture:

- Explain the problem.
- Identify relevant existing code.
- Identify affected invariants.
- Propose the change.
- Explain alternatives and trade-offs.
- Wait for confirmation when the change involves a significant business
  or architectural decision.

Do not invent missing requirements.

Do not claim that a design is production-safe without explaining the
failure modes it addresses.

When reviewing code, actively look for:

- Race conditions
- Duplicate processing
- Transaction boundary problems
- Incorrect money calculations
- Missing database constraints
- Unsafe retries
- Incorrect handling of unknown external states
- N+1 queries
- Large memory usage
- Missing tests