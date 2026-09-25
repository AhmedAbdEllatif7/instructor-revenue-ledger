# Instructor Revenue Ledger

## Purpose

This project is a technical challenge implementation for a Full Stack
Laravel Engineer hiring quest.

The system models subscription revenue allocation, instructor earnings,
and instructor payouts for an online learning platform.

The primary focus is financial correctness, idempotency, failure handling,
data integrity, concurrency, and clear engineering decisions.

## Core Business Flow

Student
    ↓
Subscription
    ↓
Subscription Payment
    ↓
Revenue Allocation
    ↓
Instructor Earnings
    ↓
Payout
    ↓
External Payment Provider

## Required Capabilities

- Subscription modeling
- Revenue allocation
- Instructor earnings/balances
- Instructor payout processing
- Mock payment provider
- Provider timeout handling
- Payout idempotency
- Queue retry safety
- Refund handling
- Rounding handling
- Automated tests
- Read-only Filament screen

## Technical Stack

- Laravel 11
- PHP
- MySQL
- Pest
- Livewire 3
- Alpine.js
- Filament 3
- Docker (optional)

## Engineering Priorities

1. Financial correctness
2. Data integrity
3. Idempotency
4. Failure handling
5. Concurrency safety
6. Testability
7. Maintainability
8. Scalability

## Important Constraint

Do not implement assumptions silently.

Any business rule that is not explicitly defined by the challenge
must be documented as an engineering/business decision before implementation.