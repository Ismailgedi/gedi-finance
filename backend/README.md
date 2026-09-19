# Gedi Finance Backend - Phase 1

This package contains the custom backend foundation for Gedi Finance v2, designed for a fresh Laravel 13 application.

## Included

- People / customers / suppliers foundation
- Financial accounts: Cash, Bank, EVC, eDahab, JEEB
- Categories
- Central transaction ledger
- Optional transaction items
- Loans
- Attachments metadata
- Audit log foundation
- Balance service
- Transaction service for income, expense, credit sales, customer payments, loans, debts, and account transfers
- API route and request-validation scaffolding
- Database seeder for default accounts and categories
- PostgreSQL `.env` template without secrets

## Important

This archive does NOT include `vendor/` or a real `.env` file. Do not package database passwords into source control.

The package is intended to be applied on top of the Laravel 13.31 project already created for Gedi Finance.

## Database

Local PostgreSQL is expected to run at:

- host: 127.0.0.1
- port: 5432
- database: gedi_finance
- username: gedi

Use the supplied `.env.postgres.example` values locally. Change the password to your actual local password if needed.

## Phase 1 design principle

The `transactions` table is the financial source of truth. Do not store a manually editable customer balance. Customer and account balances are derived from transaction effects.
