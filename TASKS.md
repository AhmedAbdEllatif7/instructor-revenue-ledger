# Project Tasks

## Phase 1 — Project Setup

- [x] Create Laravel 11 project
- [x] Create GitHub repository
- [x] Connect local repository to GitHub
- [x] Create PROJECT.md
- [x] Create AGENTS.md
- [x] Create documentation structure
- [x] Create initial commit
- [x] add docs
- [x] dockerize the project

## Phase 2 — Requirements Analysis

- [x] Extract explicit requirements
- [x] Identify unspecified business rules
- [x] Define subscription rules
- [x] Define revenue allocation rules
- [x] Define platform share
- [x] Define instructor earning rules
- [x] Define rounding rules
- [x] Define refund rules
- [x] Define payout lifecycle
- [x] Define provider behavior
- [x] Define idempotency strategy

## Phase 3 — Domain & Database Design

- [x] Define domain entities
- [x] Define relationships
- [x] Define database constraints
- [x] Define indexes
- [x] Review schema for large-scale data

## Phase 4 — Revenue Allocation

- [ ] Implement allocation logic
- [ ] Add allocation tests
- [ ] Handle rounding
- [ ] Document allocation strategy

## Phase 5 — Ledger & Balances

- [ ] Implement instructor earnings
- [ ] Implement balance calculation
- [ ] Add financial invariants
- [ ] Add tests

## Phase 6 — Payout System

- [ ] Implement payout model
- [ ] Implement payout state transitions
- [ ] Implement payout command
- [ ] Implement payout jobs
- [ ] Implement idempotency
- [ ] Handle concurrency
- [ ] Handle retries

## Phase 7 — Mock Payment Provider

- [ ] Provider interface
- [ ] Successful payment
- [ ] Permanent failure
- [ ] Timeout after success
- [ ] Status lookup
- [ ] Tests

## Phase 8 — Refunds

- [ ] Define refund policy
- [ ] Implement refund handling
- [ ] Handle already-paid instructor earnings
- [ ] Add tests

## Phase 9 — Filament

- [ ] Instructor balance screen
- [ ] Payout history screen

## Phase 10 — Documentation

- [ ] README
- [ ] ARCHITECTURE.md
- [ ] DECISIONS.md
- [ ] AI_USAGE.md

## Phase 11 — Final Testing

- [ ] Full test suite
- [ ] Duplicate payout scenario
- [ ] Duplicate job scenario
- [ ] Worker retry scenario
- [ ] Provider timeout scenario
- [ ] Delayed confirmation scenario
- [ ] Refund scenario
- [ ] Rounding scenario

## Phase 12 — Submission

- [ ] Clean repository
- [ ] Verify setup instructions
- [ ] Verify tests
- [ ] Capture test screenshots
- [ ] Record video
- [ ] Final repository review